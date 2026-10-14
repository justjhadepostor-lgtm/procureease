<?php
/* The Procurement Unit approves or declines a username change.

   On approval the admin types the FINAL username, so the department naming
   convention (ictu-01-001) is kept whatever the person asked for.

   Old activity_logs entries keep the previous username on purpose — that is
   what actually happened at the time. The approval itself is logged with both
   names, which is what joins the two halves of the history together. */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_admin();
ensure_admin_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../pending-users.php'); exit; }

$u        = current_user();
$reqId    = (int)($_POST['request_id'] ?? 0);
$decision = $_POST['decision'] ?? '';
$note     = trim($_POST['note'] ?? '');
$pdo      = db();

$s = $pdo->prepare("SELECT * FROM username_change_requests WHERE id = ? AND status = 'pending'");
$s->execute([$reqId]);
$req = $s->fetch();

if (!$req) {
    flash('error', 'That request was already handled.');
    header('Location: ../pending-users.php'); exit;
}

if ($decision === 'approve') {
    // The admin may override what was asked for, to keep the convention
    $final = strtolower(trim($_POST['approved_username'] ?? '')) ?: strtolower((string)$req['requested_username']);

    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $final)) {
        flash('error', 'Enter a username of 3-32 characters (letters, digits, dot, dash, underscore).');
        header('Location: ../pending-users.php'); exit;
    }

    $dupe = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
    $dupe->execute([$final, (int)$req['user_id']]);
    if ($dupe->fetch()) {
        flash('error', "The username \"$final\" is already taken.");
        header('Location: ../pending-users.php'); exit;
    }

    $old = (string)$req['current_username'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$final, (int)$req['user_id']]);
        $pdo->prepare("UPDATE username_change_requests
                          SET status = 'approved', approved_username = ?, decided_at = NOW(),
                              decided_by = ?, decision_note = ?
                        WHERE id = ?")
            ->execute([$final, $u['full_name'], $note ?: null, $reqId]);
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Could not apply that change.');
        header('Location: ../pending-users.php'); exit;
    }

    // Both names in one entry — this is what ties the old log lines to the new
    log_activity('Changed username', 'account', $final,
        "{$req['full_name']} ({$req['dept_code']}): \"$old\" is now \"$final\". Earlier activity remains recorded under \"$old\"."
        . ($note ? " Note: $note" : ''));

    notify_user((int)$req['user_id'], 'approval',
        'Your username has been changed',
        "Sign in from now on with \"$final\". Your password is unchanged."
        . ($note ? " Note from the Procurement Unit: $note" : ''),
        'profile.php');

    flash('success', "{$req['full_name']} now signs in as \"$final\".");
} else {
    $pdo->prepare("UPDATE username_change_requests
                      SET status = 'denied', decided_at = NOW(), decided_by = ?, decision_note = ?
                    WHERE id = ?")
        ->execute([$u['full_name'], $note ?: null, $reqId]);

    log_activity('Declined username change', 'account', (string)$req['current_username'],
        "{$req['full_name']} asked for \"{$req['requested_username']}\"." . ($note ? " Note: $note" : ''));

    notify_user((int)$req['user_id'], 'revision',
        'Your username change was not approved',
        "The Procurement Unit kept your username as \"{$req['current_username']}\"."
        . ($note ? " Note: $note" : ' Contact them if you need to discuss it.'),
        'profile.php');

    flash('success', "Declined the request from {$req['full_name']}.");
}

header('Location: ../pending-users.php');
exit;
