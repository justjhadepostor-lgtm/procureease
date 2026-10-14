<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_admin();
ensure_admin_tables();
$u = current_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../pending-users.php'); exit; }

$targetId = (int)($_POST['user_id'] ?? 0);
if ($targetId <= 0) {
    flash('error', 'No user specified.');
    header('Location: ../pending-users.php'); exit;
}

// Load the target
$s = $pdo->prepare('SELECT id, username, full_name, role FROM users WHERE id = ?');
$s->execute([$targetId]);
$target = $s->fetch();

if (!$target) {
    flash('error', 'That user no longer exists.');
    header('Location: ../pending-users.php'); exit;
}

// Guardrails: never delete yourself or another admin / the Procurement Unit head
if ((int)$target['id'] === (int)$u['id']) {
    flash('error', 'You cannot delete your own account.');
    header('Location: ../pending-users.php'); exit;
}
if ($target['role'] === 'admin') {
    flash('error', 'Admin accounts cannot be deleted here.');
    header('Location: ../pending-users.php'); exit;
}

try {
    // Delete the account. Department PPMPs and other records are keyed by dept_code,
    // not user id, so they are preserved.
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
    log_activity('Deleted user account', 'user', $target['username'],
        "{$target['full_name']} removed by {$u['full_name']}");
    flash('success', "Account for {$target['full_name']} (@{$target['username']}) has been deleted.");
} catch (Throwable $t) {
    flash('error', 'Could not delete the account: ' . $t->getMessage());
}

header('Location: ../pending-users.php'); exit;
