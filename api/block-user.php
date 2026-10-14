<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/notify.php';
require_admin();
ensure_admin_tables();

$u   = current_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../pending-users.php'); exit; }

$targetId = (int)($_POST['user_id'] ?? 0);
$do       = $_POST['do'] ?? 'block';

if ($targetId <= 0) {
    flash('error', 'No user specified.');
    header('Location: ../pending-users.php'); exit;
}

$s = $pdo->prepare('SELECT id, username, full_name, role, status, email FROM users WHERE id = ?');
$s->execute([$targetId]);
$target = $s->fetch();

if (!$target) {
    flash('error', 'That user no longer exists.');
    header('Location: ../pending-users.php'); exit;
}

// Guardrails: never block yourself, and never block a fellow admin
if ((int)$target['id'] === (int)$u['id']) {
    flash('error', 'You cannot block your own account.');
    header('Location: ../pending-users.php'); exit;
}
if ($target['role'] === 'admin') {
    flash('error', 'Admin accounts cannot be blocked here.');
    header('Location: ../pending-users.php'); exit;
}

try {
    if ($do === 'unblock') {
        $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$targetId]);
        log_activity('Unblocked user account', 'user', $target['username'],
            "Unblocked {$target['full_name']}");

        notify_user($targetId, 'approval',
            'Your ProcureEase account has been restored',
            "Hi {$target['full_name']}, your account has been unblocked. You can sign in again.",
            'user-dashboard.php');

        flash('success', "Unblocked {$target['full_name']}. They can sign in again.");
    } else {
        $pdo->prepare("UPDATE users SET status = 'blocked' WHERE id = ?")->execute([$targetId]);
        log_activity('Blocked user account', 'user', $target['username'],
            "Blocked {$target['full_name']}");

        notify_user($targetId, 'revision',
            'Your ProcureEase account has been blocked',
            "Hi {$target['full_name']}, your account has been blocked by the Procurement Unit. Please contact them if you believe this is a mistake.",
            'help.php');

        flash('success', "Blocked {$target['full_name']}. They can no longer sign in until you unblock them.");
    }
} catch (Throwable $t) {
    flash('error', 'Could not update that account.');
}

header('Location: ../pending-users.php');
exit;
