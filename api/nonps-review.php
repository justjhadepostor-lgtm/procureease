<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/notify.php';
require_admin();
ensure_admin_tables();
$u = current_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../nonps-inbox.php'); exit; }

$sid = (int)($_POST['submission_id'] ?? 0);
$action = $_POST['action'] ?? '';
$note = trim($_POST['note'] ?? '');

$q = $pdo->prepare("SELECT * FROM nonps_submissions WHERE id = ?");
$q->execute([$sid]);
$sub = $q->fetch();
if (!$sub) { flash('error', 'Submission not found.'); header('Location: ../nonps-inbox.php'); exit; }
fy_past_stop((int)$sub['fy'], 'Non-PS list', '../nonps-print.php?id=' . $sid);   // a past year's list is for viewing only

require_once __DIR__ . '/../config/edit-permission.php';
if (in_array($action, ['approve', 'revision'], true)) pu_doc_edit_close('nonps', (string)$sid);   // v77: the PU's edit permission ends

if ($action === 'approve') {
    $pdo->prepare("UPDATE nonps_submissions SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?, review_note = NULL WHERE id = ?")
        ->execute([$u['full_name'], $sid]);
    require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
    doc_snapshot('nonps', (string)$sid, 'approved', '');
    log_activity('Approved Non-PS list', 'nonps', "{$sub['dept_code']} · FY {$sub['fy']}", '');
    // Tell the department — bell + email, same as every other decision
    notify_by_dept($sub['dept_code'], 'approval',
        "Your Non-PS Supplies list for FY {$sub['fy']} is approved",
        "The Procurement Unit approved the list your department submitted.",
        'nonps-list.php');
    flash('success', "Approved {$sub['dept_code']} FY {$sub['fy']}.");
} elseif ($action === 'revision') {
    if ($note === '') { flash('error', 'A note is required.'); header('Location: ../nonps-inbox.php'); exit; }
    $pdo->prepare("UPDATE nonps_submissions SET status = 'revision', reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE id = ?")
        ->execute([$u['full_name'], $note, $sid]);
    require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
    doc_snapshot('nonps', (string)$sid, 'revision', (string)$note);
    log_activity('Sent back Non-PS list', 'nonps', "{$sub['dept_code']} · FY {$sub['fy']}", "Note: $note");
    notify_mail_reset();
    notify_by_dept($sub['dept_code'], 'revision',
        "Your Non-PS Supplies list for FY {$sub['fy']} needs revision",
        "The Procurement Unit sent it back. Note: $note",
        'nonps-list.php');
    flash('success', "Sent back to {$sub['dept_code']}." . notify_mail_note());   // v106: and by email
}

header('Location: ../nonps-inbox.php'); exit;
