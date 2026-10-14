<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../admin-dashboard.php');
    exit;
}

$ppmp_id = (int)($_POST['ppmp_id'] ?? 0);
fy_past_stop(ppmp_fy($ppmp_id), 'PPMP', '../ppmp-file-view.php?id=' . $ppmp_id);   // a past year's PPMP is for viewing only
$note    = trim($_POST['note'] ?? '');
$u       = current_user();

$stmt = db()->prepare("SELECT dept_code, fy, status, budget_reviewed_by FROM ppmps WHERE id = ?");
$stmt->execute([$ppmp_id]);
$ppmp = $stmt->fetch();

if (!$ppmp) {
    flash('error', 'PPMP not found.');
    header('Location: ../admin-dashboard.php');
    exit;
}
// Every department must clear the Budget Unit first. The exception is the
// Budget Unit's own PPMP — nobody reviews themselves, so the Procurement
// Unit is its reviewer and approves it straight from 'submitted'.
$ownReview = ppmp_skips_budget($ppmp['dept_code']);
$canApprove = $ppmp['status'] === 'budget_approved'
           || ($ownReview && $ppmp['status'] === 'submitted');
if (!$canApprove) {
    flash('error', 'This PPMP must be approved by the Budget Unit first before final approval.');
    header('Location: ../admin-dashboard.php');
    exit;
}

db()->prepare("UPDATE ppmps
                SET status = 'approved',
                    is_final = 1,
                    admin_reviewed_at = NOW(),
                    admin_reviewed_by = ?,
                    admin_note = ?
                WHERE id = ?")
    ->execute([$u['full_name'], $note ?: null, $ppmp_id]);
require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
doc_snapshot('ppmp', (string)$ppmp_id, 'approved', (string)($note ?? ''));

// Notify the Budget Unit via the activity log so it appears on their dashboard's recent activity
log_activity('Admin approved PPMP', 'ppmp', "{$ppmp['dept_code']} · FY {$ppmp['fy']}",
    "Final approval granted. Notification sent to Budget Unit ({$ppmp['budget_reviewed_by']})."
    . ($note ? " Note: $note" : ''));

// Bell + email to Budget Unit
notify_by_role('budget', 'final',
    "PPMP fully approved: {$ppmp['dept_code']} · FY {$ppmp['fy']}",
    "The Procurement Unit has granted final approval." . ($note ? " Note: $note" : ''),
    "ppmp-file-view.php?id=$ppmp_id");

// Bell + email to the department that submitted it
notify_by_dept($ppmp['dept_code'], 'final',
    "Your PPMP for FY {$ppmp['fy']} is fully approved",
    "Congratulations — your PPMP has received final approval from the Procurement Unit." . ($note ? " Note: $note" : ''),
    "ppmp-file-view.php?id=$ppmp_id");

// Final approval: any edit permission for this PPMP ends (config/edit-permission.php)
require_once __DIR__ . '/../config/edit-permission.php';
pu_edit_close($ppmp_id);

flash('success', "{$ppmp['dept_code']} FY {$ppmp['fy']} fully approved. Budget Unit and the department have been notified.");

header('Location: ../admin-dashboard.php');
exit;
