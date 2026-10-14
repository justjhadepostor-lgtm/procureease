<?php
/* The person asks the Procurement Unit for a different username.
   Nothing changes here — this only records the request. */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_login();
ensure_admin_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../profile.php'); exit; }

$u       = current_user();
$__acct = (string)($u['school_code'] ?? $u['dept_code'] ?? '');   // v81: the account's school, not the program it is working on
$wanted  = strtolower(trim($_POST['requested_username'] ?? ''));
$reason  = trim($_POST['reason'] ?? '');
$pdo     = db();

if ($wanted === '' || !preg_match('/^[a-z0-9._-]{3,32}$/', $wanted)) {
    flash('error', 'Enter the username you would like: 3-32 characters, using letters, digits, dot, dash or underscore.');
    header('Location: ../profile.php'); exit;
}
if ($wanted === strtolower((string)$u['username'])) {
    flash('error', 'That is already your username.');
    header('Location: ../profile.php'); exit;
}
if ($reason === '') {
    flash('error', 'Please say why you need the change — the Procurement Unit decides on that basis.');
    header('Location: ../profile.php'); exit;
}

// Taken already?
$dupe = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
$dupe->execute([$wanted, (int)$u['id']]);
if ($dupe->fetch()) {
    flash('error', "The username \"$wanted\" is already taken. Please choose another one.");
    header('Location: ../profile.php'); exit;
}

// One open request at a time
$chk = $pdo->prepare("SELECT COUNT(*) FROM username_change_requests WHERE user_id = ? AND status = 'pending'");
$chk->execute([(int)$u['id']]);
if ((int)$chk->fetchColumn() > 0) {
    flash('error', 'You already have a username change waiting for the Procurement Unit.');
    header('Location: ../profile.php'); exit;
}

try {
    $pdo->prepare('INSERT INTO username_change_requests
                     (user_id, current_username, requested_username, full_name, dept_code, reason)
                   VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([(int)$u['id'], $u['username'], $wanted, $u['full_name'], ($u['school_code'] ?? $u['dept_code'] ?? ''), $reason]);

    log_activity('Requested username change', 'account', (string)$u['username'],
        "Asked for \"$wanted\". Reason: $reason");

    notify_by_role('admin', 'account',
        "Username change requested by {$u['full_name']}",
        "{$u['full_name']} ({$u['username']}, {$__acct}) is asking for \"$wanted\". Reason: $reason",
        'pending-users.php');

    flash('success', 'Your request was sent to the Procurement Unit. You will be notified of their decision.');
} catch (Throwable $t) {
    flash('error', 'Could not send that request. Please try again.');
}

header('Location: ../profile.php');
exit;
