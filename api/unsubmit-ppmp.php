<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_user_role();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user-dashboard.php');
    exit;
}

$u = current_user();
$ppmp_id = (int)($_POST['ppmp_id'] ?? 0);
fy_past_stop(ppmp_fy($ppmp_id), 'PPMP', '../ppmp-file-view.php?id=' . $ppmp_id);   // a past year's PPMP is for viewing only

$stmt = db()->prepare('SELECT dept_code, fy, status, budget_reviewed_at FROM ppmps WHERE id = ?');
$stmt->execute([$ppmp_id]);
$p = $stmt->fetch();

if (!$p) {
    flash('error', 'PPMP not found.');
    header('Location: ../user-dashboard.php');
    exit;
}
// Only whoever may submit for the department may pull the submission back.
// Enforced here as well, so it is a rule and not just a hidden button.
if (!can_submit_for_dept($u)) {
    $head = dept_submitter_name($u);
    flash('error', $head !== ''
        ? "Only your Department Admin ($head) can pull this back from the Budget Unit."
        : 'Only your Department Admin can pull this back from the Budget Unit.');
    header('Location: ../user-dashboard.php');
    exit;
}
if ($p['dept_code'] !== $u['dept_code']) {
    flash('error', 'You can only unsubmit your own department\'s PPMP.');
    header('Location: ../user-dashboard.php');
    exit;
}
if ($p['status'] !== 'submitted') {
    // Already approved/reviewed — can't unsubmit
    flash('error', 'This PPMP can\'t be unsubmitted — it\'s already been reviewed.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp_id);
    exit;
}
if (!empty($p['budget_reviewed_at'])) {
    flash('error', 'Budget Unit has already opened this PPMP. Please ask them to send it back for revision instead.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp_id);
    exit;
}

// Send it back to draft and clear submission stamps
db()->prepare('UPDATE ppmps SET status = ?, submitted_at = NULL, submitted_by = NULL,
                                 is_final = 0 WHERE id = ?')
    ->execute(['draft', $ppmp_id]);
require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
doc_snapshot('ppmp', (string)$ppmp_id, 'unsubmitted');

log_activity('Unsubmitted PPMP', 'ppmp', "{$u['dept_code']} · FY {$p['fy']}",
    "Pulled back the FY {$p['fy']} PPMP from Budget for further editing.");

// The set went together, so it comes back together: the Non-PS list, Annex
// and Market Scoping return to draft too — those the Procurement Unit has
// not acted on yet.
$__back = 0;
foreach (['nonps_submissions' => 'nonps', 'annex_forms' => 'annex', 'market_scoping' => 'scoping'] as $__t => $__dt) {
    try {
        // (v79: which ones, for the version history)
        $__w = "WHERE dept_code = ? AND fy = ? AND status = 'submitted' AND reviewed_at IS NULL AND (archived IS NULL OR archived = 0)";
        $__ids = db()->prepare("SELECT id FROM `$__t` $__w");
        $__ids->execute([$u['dept_code'], (int)$p['fy']]);
        $__ids = $__ids->fetchAll(PDO::FETCH_COLUMN);
        $__q = db()->prepare("UPDATE `$__t` SET status = 'draft', submitted_at = NULL, submitted_by = ''
                               WHERE dept_code = ? AND fy = ? AND status = 'submitted' AND reviewed_at IS NULL
                                 AND (archived IS NULL OR archived = 0)");
        $__q->execute([$u['dept_code'], (int)$p['fy']]);
        $__back += $__q->rowCount();
        foreach ($__ids as $__id) doc_snapshot($__dt, (string)$__id, 'unsubmitted');
    } catch (Throwable $t) {}
}

// …and the APP-CSE (v77: it went to the Procurement Unit), if the PU has not acted on it yet
try {
    require_once __DIR__ . '/../config/appcse-review.php';
    $__ah = appcse_header_row((int)$p['fy'], (string)$u['dept_code']);
    if ($__ah && (string)($__ah['status'] ?? '') === 'submitted' && empty($__ah['reviewed_at']) && (int)($__ah['archived'] ?? 0) !== 1) {
        appcse_set_status((int)$p['fy'], (string)$u['dept_code'], 'draft', (string)($u['full_name'] ?? ''));
        $__back++;
    }
} catch (Throwable $t) {}

// Notify budget users so they know it's been withdrawn
notify_by_role('budget', 'submission',
    "PPMP withdrawn by {$u['dept_name']}",
    "The FY {$p['fy']} PPMP from {$u['dept_code']} was unsubmitted by the department and is back in draft.",
    "budget-dashboard.php");

flash('success', "FY {$p['fy']} PPMP has been pulled back to draft" . ($__back ? ", with the APP-CSE, Non-PS list, Annex and Market Scoping sent with it" : '') . '. You can now continue editing.');
header('Location: ../ppmp-edit.php?fy=' . (int)$p['fy']);
exit;
