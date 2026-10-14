<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_budget();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../budget-dashboard.php');
    exit;
}

$ppmp_id = (int)($_POST['ppmp_id'] ?? 0);
fy_past_stop(ppmp_fy($ppmp_id), 'PPMP', '../ppmp-file-view.php?id=' . $ppmp_id);   // a past year's PPMP is for viewing only
$note    = trim($_POST['note'] ?? '');
$u       = current_user();

if ($note === '') {
    flash('error', 'Please provide a note explaining what needs to be revised.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp_id);
    exit;
}

$stmt = db()->prepare("SELECT dept_code, fy, submitted_by FROM ppmps WHERE id = ?");
$stmt->execute([$ppmp_id]);
$ppmp = $stmt->fetch();

if (!$ppmp) {
    flash('error', 'PPMP not found.');
    header('Location: ../budget-dashboard.php');
    exit;
}

// Bounce the PPMP back to draft so the department can edit it again
db()->prepare("UPDATE ppmps
                SET status = 'draft',
                    budget_reviewed_at = NOW(),
                    budget_reviewed_by = ?,
                    budget_note = ?
                WHERE id = ?")
    ->execute([$u['full_name'], $note, $ppmp_id]);

// Record the revision request so the department sees it
db()->prepare('INSERT INTO revision_requests (dept_code, ppmp_id, fy, note, requested_by) VALUES (?, ?, ?, ?, ?)')
    ->execute([$ppmp['dept_code'], $ppmp_id, $ppmp['fy'], "[Budget review] $note", $u['full_name']]);
require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
doc_snapshot('ppmp', (string)$ppmp_id, 'revision', (string)$note);

log_activity('Budget requested revision', 'ppmp', "{$ppmp['dept_code']} · FY {$ppmp['fy']}",
    "Sent back for revision. Reason: $note");

// Bell + email to every user of the department (v106: it used to email only the one who
// submitted, when that person could be found; the person who submitted is one of them)
$title = "Revision requested on your FY {$ppmp['fy']} PPMP";
$body  = "The Budget Unit has requested changes. Note: $note";
$link  = "ppmp-edit.php?fy={$ppmp['fy']}";

notify_mail_reset();
notify_by_dept($ppmp['dept_code'], 'revision', $title, $body, $link);

flash('success', "{$ppmp['dept_code']} FY {$ppmp['fy']} sent back to the department for revision." . notify_mail_note());

header('Location: ../budget-dashboard.php');
exit;
