<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_login();
ensure_admin_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user-settings.php');
    exit;
}

$u = current_user();
$__acct = (string)($u['school_code'] ?? $u['dept_code'] ?? '');   // v81: the account's school, not the program it is working on
$reason = trim($_POST['reason'] ?? '');

// Admins can't request self-deletion through this flow
if (($u['role'] ?? '') === 'admin') {
    flash('error', 'Admin accounts cannot be deleted through this form.');
    header('Location: ../user-settings.php');
    exit;
}

$pdo = db();

// Already has a pending request?
$chk = $pdo->prepare("SELECT COUNT(*) FROM account_deletion_requests WHERE user_id = ? AND status = 'pending'");
$chk->execute([(int)$u['id']]);
if ((int)$chk->fetchColumn() > 0) {
    flash('error', 'You already have a pending account-deletion request awaiting admin approval.');
    header('Location: ../user-settings.php');
    exit;
}

try {
    $pdo->prepare('INSERT INTO account_deletion_requests (user_id, username, full_name, dept_code, reason)
                   VALUES (?, ?, ?, ?, ?)')
        ->execute([(int)$u['id'], $u['username'], $u['full_name'], ($u['school_code'] ?? $u['dept_code'] ?? ''), $reason ?: null]);

    log_activity('Requested account deletion', 'account', $u['username'], $reason ? "Reason: $reason" : 'No reason given.');

    // Notify all admins
    notify_by_role('admin', 'account',
        "Account deletion request from {$u['full_name']}",
        "{$u['full_name']} ({$u['username']}, {$__acct}) has requested that their account be deleted."
        . ($reason ? " Reason: $reason" : ''),
        'pending-users.php?tab=deletions');

    flash('success', 'Your account-deletion request has been sent to the administrator for approval.');
} catch (Throwable $t) {
    error_log('account deletion request failed: ' . $t->getMessage());
    flash('error', 'Could not submit your request. Please try again.');
}

header('Location: ../user-settings.php');
exit;
