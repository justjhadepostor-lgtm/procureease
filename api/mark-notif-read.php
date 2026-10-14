<?php
require_once __DIR__ . '/../config/auth.php';
require_login();
$u = current_user();

$scope = $_POST['scope'] ?? '';
$id    = (int)($_POST['id'] ?? 0);

try {
    if ($scope === 'all') {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')
            ->execute([$u['id']]);
        flash('success', 'All notifications marked as read.');
    } elseif ($id > 0) {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
            ->execute([$id, $u['id']]);
    }
} catch (Throwable $t) { /* silent */ }

// Redirect back where they came from
$back = $_SERVER['HTTP_REFERER'] ?? 'user-dashboard.php';
header('Location: ' . $back);
exit;
