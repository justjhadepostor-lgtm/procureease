<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_admin();   // only the Procurement Unit
ensure_admin_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pending-users.php');
    exit;
}

$u = current_user();
$target_id = (int)($_POST['user_id'] ?? 0);
$newRole   = $_POST['new_role'] ?? '';   // 'dept_admin' | 'user'
$return    = $_POST['return_to'] ?? 'pending-users.php';

// Only these two roles can be set through this control
if (!in_array($newRole, ['dept_admin', 'user'], true)) {
    flash('error', 'Invalid role.');
    header('Location: ../' . $return);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, username, full_name, role, dept_code FROM users WHERE id = ?');
$stmt->execute([$target_id]);
$target = $stmt->fetch();

if (!$target) {
    flash('error', 'User not found.');
    header('Location: ../' . $return);
    exit;
}

// Never touch admin or budget accounts through this control
if (in_array($target['role'], ['admin', 'budget'], true)) {
    flash('error', 'You cannot change the role of an admin or budget account here.');
    header('Location: ../' . $return);
    exit;
}

// Enforce: only ONE department admin per department
if ($newRole === 'dept_admin') {
    $chk = $pdo->prepare("SELECT full_name FROM users
                           WHERE dept_code = ? AND role = 'dept_admin' AND id <> ?");
    $chk->execute([$target['dept_code'], $target_id]);
    $existing = $chk->fetchColumn();
    if ($existing) {
        flash('error', "{$target['dept_code']} already has a department admin ({$existing}). Demote them first before assigning a new one.");
        header('Location: ../' . $return);
        exit;
    }
}

$pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $target_id]);

if ($newRole === 'dept_admin') {
    log_activity('Assigned department admin', 'account', $target['username'],
        "Made {$target['full_name']} the department admin of {$target['dept_code']}.");
    // v104: by email too (and the bell) — the flash says whether the email went
    $__mailed = notify_user($target_id, 'account',
        'You are now a Department Admin',
        "The Procurement Unit Head has made you the department admin for {$target['dept_code']}. You can now track all PPMPs in your department"
        . " and submit your department's documents to the Procurement Unit.",
        'user-dashboard.php');
    $__em = (string)($pdo->query('SELECT email FROM users WHERE id = ' . (int)$target_id)->fetchColumn() ?: '');
    flash('success', "{$target['full_name']} is now the department admin for {$target['dept_code']}."
        . ($__mailed ? " An email was sent to $__em." : ($__em === '' ? ' (No email address on the account, so only the bell notice was sent.)'
                                                         : " The email to $__em could not be sent — check Mail settings; the bell notice was sent.")));
} else {
    log_activity('Set normal user', 'account', $target['username'],
        "Set {$target['full_name']} back to a normal user in {$target['dept_code']}.");
    notify_user($target_id, 'account',
        'Your role has changed',
        "Your role has been set to normal user for {$target['dept_code']}.",
        'user-dashboard.php');
    flash('success', "{$target['full_name']} is now a normal user.");
}

header('Location: ../' . $return);
exit;
