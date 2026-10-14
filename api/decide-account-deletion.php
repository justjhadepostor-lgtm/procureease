<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_admin();
ensure_admin_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pending-users.php');
    exit;
}

$u = current_user();
$req_id = (int)($_POST['request_id'] ?? 0);
$decision = $_POST['decision'] ?? '';   // 'approve' | 'deny'
$pdo = db();

$stmt = $pdo->prepare("SELECT * FROM account_deletion_requests WHERE id = ? AND status = 'pending'");
$stmt->execute([$req_id]);
$req = $stmt->fetch();

if (!$req) {
    flash('error', 'Request not found or already handled.');
    header('Location: ../pending-users.php?tab=deletions');
    exit;
}

if ($decision === 'approve') {
    try {
        $pdo->beginTransaction();
        $uid = (int)$req['user_id'];

        // Reassign or clear this user's PPMPs? We keep the PPMPs (dept-owned) but null the submitter link.
        // Delete the user account itself.
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        // Clean up their personal prefs / reminders / notifications
        foreach (['user_prefs', 'submission_reminders', 'notifications'] as $tbl) {
            try { $pdo->prepare("DELETE FROM $tbl WHERE user_id = ?")->execute([$uid]); } catch (Throwable $t) {}
        }

        $pdo->prepare("UPDATE account_deletion_requests SET status = 'approved', decided_at = NOW(), decided_by = ? WHERE id = ?")
            ->execute([$u['full_name'], $req_id]);
        $pdo->commit();

        log_activity('Approved account deletion', 'account', $req['username'],
            "Deleted the account of {$req['full_name']} ({$req['dept_code']}).");
        flash('success', "Account for {$req['full_name']} has been permanently deleted.");
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('approve account deletion failed: ' . $t->getMessage());
        flash('error', 'Could not delete the account. Please try again.');
    }
} elseif ($decision === 'deny') {
    $pdo->prepare("UPDATE account_deletion_requests SET status = 'denied', decided_at = NOW(), decided_by = ? WHERE id = ?")
        ->execute([$u['full_name'], $req_id]);
    log_activity('Denied account deletion', 'account', $req['username'],
        "Denied the deletion request from {$req['full_name']}.");

    // Notify the user their request was denied
    notify_user((int)$req['user_id'], 'account',
        'Account deletion request denied',
        'Your request to delete your account was reviewed and denied by the administrator. Your account remains active.',
        'user-dashboard.php');

    flash('success', "Deletion request from {$req['full_name']} was denied.");
}

header('Location: ../pending-users.php?tab=deletions');
exit;
