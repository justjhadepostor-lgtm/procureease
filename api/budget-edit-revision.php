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
    flash('error', 'The revision note cannot be empty.');
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

// Find the current (unresolved) revision request for this PPMP
$req = db()->prepare("SELECT id FROM revision_requests
                       WHERE ppmp_id = ? AND resolved = 0
                    ORDER BY requested_at DESC LIMIT 1");
$req->execute([$ppmp_id]);
$revId = (int)$req->fetchColumn();

if ($revId === 0) {
    flash('error', 'There is no active revision request on this PPMP to edit.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp_id);
    exit;
}

// Update the note on the revision request + the PPMP's budget_note
db()->prepare("UPDATE revision_requests SET note = ?, requested_by = ?, requested_at = NOW() WHERE id = ?")
    ->execute(["[Budget review] $note", $u['full_name'], $revId]);
db()->prepare("UPDATE ppmps SET budget_note = ? WHERE id = ?")
    ->execute([$note, $ppmp_id]);

log_activity('Edited revision note', 'ppmp', "{$ppmp['dept_code']} · FY {$ppmp['fy']}",
    "Updated revision note: $note");

// Tell the department that the note was updated
$title = "Revision note updated on your FY {$ppmp['fy']} PPMP";
$body  = "The Budget Unit updated the revision request. New note: $note";
$link  = "ppmp-edit.php?fy={$ppmp['fy']}";

// v106: every user of the department, by bell and email (it used to be only the one who submitted)
notify_mail_reset();
notify_by_dept($ppmp['dept_code'], 'revision', $title, $body, $link);

flash('success', 'Revision note updated.' . notify_mail_note());
header('Location: ../ppmp-file-view.php?id=' . $ppmp_id);
exit;
