<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_user_role();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../user-dashboard.php'); exit; }

$u = current_user();
$request_id = (int)($_POST['request_id'] ?? 0);
$response = $_POST['response'] ?? '';
$returnTo = $_POST['return_to'] ?? 'user-dashboard.php';

if (!in_array($response, ['granted', 'declined'], true)) {
    flash('error', 'Invalid response.');
    header('Location: ../' . $returnTo);
    exit;
}

// Verify the request is for this user's department
$stmt = db()->prepare('SELECT * FROM edit_requests WHERE id = ? AND status = ?');
$stmt->execute([$request_id, 'pending']);
$req = $stmt->fetch();

if ($req) fy_past_stop((int)$req['fy'], 'PPMP', '../' . $returnTo);
if (!$req || $req['dept_code'] !== $u['dept_code']) {
    flash('error', 'Not authorized to respond to that request.');
    header('Location: ../' . $returnTo);
    exit;
}

$stmt = db()->prepare('UPDATE edit_requests SET status = ?, responded_at = NOW() WHERE id = ?');
$stmt->execute([$response, $request_id]);

require_once __DIR__ . '/../config/edit-permission.php';
$__doc  = pu_edit_doc_label($req);                    // "FY 2027 PPMP" / "FY 2027 Non-PS list" / "FY 2027 APP-CSE"
$__type = (string)($req['doc_type'] ?? '');
$__link = $__type === 'nonps' ? 'nonps-review.php?sub=' . (int)$req['doc_ref']
        : ($__type === 'appcse' ? 'appcse-review.php?fy=' . (int)$req['fy'] . '&dept=' . urlencode((string)$req['dept_code'])
        : 'ppmp-file-view.php?id=' . (int)$req['ppmp_id']);
$msg = $response === 'granted'
    ? "Edit access granted for the $__doc. The Procurement Unit has been notified."
    : "Edit request declined for the $__doc.";
log_activity($response === 'granted' ? 'Granted edit access' : 'Declined edit', 'edit_req', "{$u['dept_code']} · FY {$req['fy']}", $response === 'granted' ? 'Granted edit access to the Procurement Unit.' : 'Declined the edit request.');

// Close the loop — the Procurement Unit was promised an answer
notify_by_role('admin', $response === 'granted' ? 'approval' : 'revision',
    $response === 'granted'
        ? "{$u['dept_code']} granted edit access for the $__doc"
        : "{$u['dept_code']} declined your edit request for the $__doc",
    $response === 'granted'
        ? "You can now edit the $__doc of {$u['dept_code']}."
        : "{$u['full_name']} declined the request. Contact the department if it is still needed.",
    $__link);

flash('success', $msg);

header('Location: ../' . $returnTo);
exit;
