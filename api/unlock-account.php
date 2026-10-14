<?php
/* The Procurement Unit unlocks an account locked after wrong passwords, and
   clears its count of wrong passwords (User Management › Sign-in lockouts, v79). */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/login-guard.php';
require_once __DIR__ . '/../config/notify.php';
require_admin();

$back = '../pending-users.php#lockouts';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }

$row = login_guard_row((int)($_POST['user_id'] ?? 0));
if (!$row) { flash('error', 'That account could not be found.'); header('Location: ' . $back); exit; }

$state = login_lock_state($row);
login_unlock((int)$row['id']);
$me = current_user();
if ($state['locked']) {
    log_activity('Unlocked an account', 'security', (string)$row['username'],
        "Unlocked {$row['full_name']} (@{$row['username']}), locked after wrong passwords.");
    notify_user((int)$row['id'], 'security', 'Your account was unlocked',
        "The Procurement Unit ({$me['full_name']}) unlocked your account. You can sign in again.", 'index.php');
    flash('success', "Unlocked {$row['full_name']} (@{$row['username']}). They can sign in again.");
} else {
    log_activity('Cleared wrong-password count', 'security', (string)$row['username'],
        "Cleared the count of wrong passwords of {$row['full_name']} (@{$row['username']}).");
    flash('success', "Cleared the wrong passwords of {$row['full_name']} (@{$row['username']}).");
}
header('Location: ' . $back);
exit;
