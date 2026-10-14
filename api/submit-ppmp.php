<?php
require_once __DIR__ . '/../config/auth.php';
require_login();  // ownership is enforced below (own dept only), so admin can submit its own PU PPMP

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../user-dashboard.php'); exit; }

$u = current_user();
$ppmp_id = (int)($_POST['ppmp_id'] ?? 0);
fy_past_stop(ppmp_fy($ppmp_id), 'PPMP', '../ppmp-file-view.php?id=' . $ppmp_id);   // a past year's PPMP is for viewing only
$fy = (int)($_POST['fy'] ?? 0);

$stmt = db()->prepare('SELECT dept_code, fy, status FROM ppmps WHERE id = ?');
$stmt->execute([$ppmp_id]);
$row = $stmt->fetch();
if (!$row || $row['dept_code'] !== $u['dept_code']) {
    flash('error', 'Not authorized to submit that PPMP.');
    header('Location: ../user-dashboard.php');
    exit;
}
$fy = (int)$row['fy'];   // the PPMP's own year, whatever the form said

// An archived PPMP stays where it is until it is restored
require_once __DIR__ . '/../config/archive.php';
if (arc_ppmp_is_archived($ppmp_id)) {
    flash('error', 'This PPMP is in the Archive. Restore it before submitting it.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp_id);
    exit;
}

/* Sent back for revision? Then only the PPMP goes back —
   to whoever sent it back: the Budget Unit (it returned it to draft) or
   the Procurement Unit (it stays with them). The Non-PS list, Annex and
   Market Scoping are not part of a resubmission; each has its own Submit
   when it is the one sent back. */
$__curStatus = (string)($row['status'] ?? 'draft');
$__resubmit  = false;
try {
    $__rv = db()->prepare('SELECT COUNT(*) FROM revision_requests WHERE dept_code = ? AND fy = ? AND resolved = 0');
    $__rv->execute([$u['dept_code'], $fy]);
    $__resubmit = (int)$__rv->fetchColumn() > 0;
} catch (Throwable $t) {}

// ─── Only the Department Admin submits ────────────────────────
// Everyone may prepare and save; the unit head is the one who files.
if (!can_submit_for_dept($u)) {
    $head = dept_submitter_name($u);
    flash('error', $head !== ''
        ? "Only your Department Admin ($head) can submit this. Your work is saved — ask them to send it."
        : 'Only your Department Admin can submit this.');
    header('Location: ../user-dashboard.php');
    exit;
}

// Already on its way (and not sent back): nothing to submit
if ($__curStatus === 'approved') {
    flash('error', 'This PPMP has final approval, so it can no longer be submitted again.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp_id);
    exit;
}
if (!$__resubmit && $__curStatus !== 'draft') {
    flash('error', 'This PPMP has already been submitted.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp_id);
    exit;
}

// v81: a program / major of a school goes with the whole school the first time —
// its Department Admin submits them all at once (My Department). Sent back, it is resubmitted on its own.
require_once __DIR__ . '/../config/academic-units.php';
if (!$__resubmit && au_is_unit((string)$u['dept_code'])) {
    flash('error', 'The programs and majors of ' . au_school_of((string)$u['dept_code']) . ' are submitted all at once. '
        . 'Open My Department and use "Submit whole school" when every one is approved.');
    header('Location: ../dept-tracking.php?fy=' . $fy);
    exit;
}

// ─── REQUIRED: Non-PS Supplies list for the same FY ────────────
// All users must have created a Non-PS list before submitting their PPMP.
try {
    $ns = db()->prepare('SELECT COUNT(*) FROM nonps_submissions WHERE dept_code = ? AND fy = ?');
    $ns->execute([$u['dept_code'], $fy]);
    $hasNonps = ((int)$ns->fetchColumn()) > 0;
    if (!$hasNonps) {
        flash('error',
            "You must create a List of Non-PS Supplies for FY $fy before submitting your PPMP. " .
            "Please go to the Non-PS Supplies page and create your list first (even an empty draft is OK).");
        header('Location: ../nonps-list.php');
        exit;
    }
} catch (Throwable $t) {
    // If the nonps_submissions table doesn't exist yet (fresh install), don't block —
    // ensure_admin_tables() creates it on next dashboard visit.
    error_log('Non-PS pre-submit check skipped: ' . $t->getMessage());
}

// ─── REQUIRED: the department's APP-CSE for the same FY ────────
// The APP-CSE is part of the set (it goes to the Procurement Unit), so it has to carry real quantities
// before the pair can be sent. An untouched catalogue is not a plan.
try {
    $ac = db()->prepare("SELECT COUNT(*) FROM appcse_items
                          WHERE dept_code = ? AND fy = ?
                            AND (`jan`+`feb`+`mar`+`apr`+`may`+`jun`
                               + `jul`+`aug`+`sep`+`oct`+`nov`+`dec`) > 0");
    $ac->execute([$u['dept_code'], $fy]);
    if ((int)$ac->fetchColumn() === 0) {
        flash('error',
            "Your APP-CSE for FY $fy is still empty. Enter the monthly quantities you need, " .
            "then submit — the APP-CSE is sent together with your PPMP, to the Procurement Unit.");
        header('Location: ../appcse-edit.php');
        exit;
    }
} catch (Throwable $t) {
    // Fresh install without the table yet — ensure_admin_tables() creates it
    error_log('APP-CSE pre-submit check skipped: ' . $t->getMessage());
}

// ─── REQUIRED: the whole set goes together ─────────────────────
// The Non-PS list, Annex and Market Scoping are sent with the PPMP, to the
// Procurement Unit. None may be missing (config/companions.php).
require_once __DIR__ . '/../config/companions.php';
// Checked on a resubmission too; only a first submission sends them (below)
$__plan = ppmp_companions((string)$u['dept_code'], $fy);
if (!$__plan['ok']) {
    $__miss = [];
    foreach ($__plan['items'] as $__i) if (!$__i['ok']) $__miss[] = $__i['label'] . ' — ' . $__i['note'];
    flash('error', ($__resubmit
                   ? "Not resubmitted. Your FY $fy APP-CSE, Non-PS list, Annex and Market Scoping have to be complete too. "
                   : "Your FY $fy PPMP is submitted together with the APP-CSE, Non-PS list, Annex and Market Scoping. ")
                 . 'Not ready yet: ' . implode(' · ', $__miss));
    header('Location: ../ppmp-edit.php?fy=' . $fy);
    exit;
}

// ─── REQUIRED: within the allotment ────────────────────────────
// APP-CSE (with its 10%) + Non-PS list (with VAT, logistics, inflation) may not go
// over the Budget Unit's allotment — on a first submission and on a
// resubmission alike. Below it, or no allotment yet, is allowed with a
// warning (config/companions.php).
$__bm = ppmp_budget_match((string)$u['dept_code'], $fy, $ppmp_id);
if (!$__bm['ok']) {
    flash('error', 'Not submitted. ' . $__bm['note']);
    header('Location: ../ppmp-edit.php?fy=' . $fy);
    exit;
}

// A PPMP the Procurement Unit sent back stays with the Procurement Unit;
// one the Budget Unit sent back (a draft again) goes to the Budget Unit.
$__newStatus = ($__resubmit && $__curStatus !== 'draft') ? $__curStatus : 'submitted';
$stmt = db()->prepare('UPDATE ppmps SET status = ?, submitted_at = NOW(), submitted_by = ? WHERE id = ?');
$stmt->execute([$__newStatus, $u['full_name'], $ppmp_id]);
// v79: keep what was submitted (version history, config/doc-versions.php)
require_once __DIR__ . '/../config/doc-versions.php';
doc_snapshot('ppmp', (string)$ppmp_id, 'submitted');
// (v77: the APP-CSE no longer goes with the PPMP — it goes to the Procurement
// Unit with the rest of the set below, and is reviewed there on its own)

// …and the rest of the set, to the Procurement Unit (not on a resubmission)
$__sent = [];
if (!$__resubmit) {
    try { $__sent = ppmp_companions_send($__plan, $u, (string)$u['dept_code'], $fy); }
    catch (Throwable $t) { error_log('companion submit failed: ' . $t->getMessage()); }
}

// Clear any unresolved revision requests for this dept-FY — they've been addressed
try {
    db()->prepare('UPDATE revision_requests SET resolved = 1 WHERE dept_code = ? AND fy = ? AND resolved = 0')
        ->execute([$u['dept_code'], $fy]);
} catch (Throwable $t) { /* older schema — ignore */ }

// Count entries for the log detail
$countStmt = db()->prepare('SELECT COUNT(*) FROM ppmp_entries WHERE ppmp_id = ?');
$countStmt->execute([$ppmp_id]);
$entryCount = (int)$countStmt->fetchColumn();
log_activity($__resubmit ? 'Resubmitted PPMP after revision' : 'Submitted PPMP', 'ppmp', "{$u['dept_code']} · FY $fy",
             ($__resubmit ? 'Resubmitted' : 'Submitted') . " the FY $fy PPMP with $entryCount procurement entries.");

// ─── Tell the reviewer straight away ──────────────────────────
// Normally the Budget Unit reviews first. The Budget Unit's own PPMP
// cannot be reviewed by itself, so that one goes to the Procurement Unit.
require_once __DIR__ . '/../config/notify.php';
$toAdmin = ppmp_skips_budget($u['dept_code']) || $__newStatus !== 'submitted';
if ($__resubmit) {
    notify_by_role($toAdmin ? 'admin' : 'budget', 'resubmission',
        "PPMP resubmitted after revision: {$u['dept_code']} · FY $fy",
        "{$u['full_name']} made the changes asked for and sent the FY $fy PPMP back.",
        "ppmp-file-view.php?id=$ppmp_id");
} else {
    notify_by_role($toAdmin ? 'admin' : 'budget', 'submission',
        "PPMP submitted for review: {$u['dept_code']} · FY $fy",
        "{$u['full_name']} submitted the FY $fy PPMP with $entryCount procurement entries.",
        "ppmp-file-view.php?id=$ppmp_id");
}

$__also = [];
foreach ($__sent as $__l => $__n) $__also[] = $__n > 1 ? "$__l ($__n)" : $__l;
if ($__resubmit) {
    flash('success', "Submitted. Your revised FY $fy PPMP went back to the "
        . ($toAdmin ? 'Procurement Unit' : 'Budget Unit') . ' on its own — your APP-CSE, Non-PS list, Annex and Market Scoping were not touched.');
} else {
    flash('success', 'Submitted. ' . ($toAdmin
        ? "FY $fy PPMP submitted to the Procurement Unit for approval."
        : "FY $fy PPMP submitted to the Budget Unit for review. You'll be notified once it moves on to the Procurement Unit.")
        . ($__also ? ' Sent with them to the Procurement Unit: ' . implode(', ', $__also) . '.' : ''));
}
celebrate_submitted("FY $fy PPMP", $toAdmin ? 'Procurement Unit' : 'Budget Unit');   // v97
require_once __DIR__ . '/../config/head-submit.php'; hs_clear((string)$u['dept_code'], (int)$fy);   // v112: no longer waiting with the Department Admin
// Straight to the PPMP itself, which now shows it as submitted
header('Location: ../ppmp-file-view.php?id=' . $ppmp_id);
exit;
