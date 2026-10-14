<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../admin-dashboard.php'); exit; }

$u = current_user();
$dept = trim($_POST['dept_code'] ?? '');
$ppmp = (int)($_POST['ppmp_id'] ?? 0);
fy_past_stop(ppmp_fy($ppmp), 'PPMP', '../ppmp-file-view.php?id=' . $ppmp);   // a past year's PPMP is for viewing only
$fy   = (int)($_POST['fy'] ?? 0);
$note = trim($_POST['note'] ?? '');

if ($dept === '' || $ppmp === 0 || $fy === 0 || $note === '') {
    flash('error', 'Missing required fields for revision request.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp);
    exit;
}

// Resolve any prior open revision requests for the same file, then insert the new one
db()->prepare('UPDATE revision_requests SET resolved = 1 WHERE dept_code = ? AND fy = ? AND resolved = 0')->execute([$dept, $fy]);

$stmt = db()->prepare('INSERT INTO revision_requests (dept_code, ppmp_id, fy, note, requested_by) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$dept, $ppmp, $fy, $note, $u['full_name']]);
require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
doc_snapshot('ppmp', (string)$ppmp, 'revision', $note);

log_activity('Requested revision', 'ppmp', "$dept · FY $fy", 'Note: ' . $note);

// Sent back: any edit permission for this PPMP ends (config/edit-permission.php)
require_once __DIR__ . '/../config/edit-permission.php';
pu_edit_close($ppmp);

// Tell the department — bell + email. Without this the request sat on their
// dashboard unseen until somebody happened to look.
notify_mail_reset();
notify_by_dept($dept, 'revision',
    "Revision requested on your FY $fy PPMP",
    "The Procurement Unit asked for changes. Note: $note",
    "ppmp-edit.php?fy=$fy");

flash('success', "Revision request sent to $dept for FY $fy." . notify_mail_note());   // v106: and by email
header('Location: ../admin-dashboard.php?dept=' . $dept);
exit;
