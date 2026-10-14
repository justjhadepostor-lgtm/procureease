<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$to = $_GET['to'] ?? '';
$u  = current_user();

// Mark this user's notification as read
if ($id > 0) {
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
        ->execute([$id, (int)$u['id']]);
}

// Safe redirect — relative URLs only
$fallback = ($u['role'] === 'admin')  ? 'admin-home.php'
          : (($u['role'] === 'budget') ? 'budget-dashboard.php' : 'user-dashboard.php');

// (the pattern's own delimiter is ~ — with # it ended at the # in the list and
//  never matched, so every notification went to the dashboard instead)
if ($to && preg_match('~^[a-zA-Z0-9_\-\./?=&#%+]+$~', $to) && strpos($to, '/') !== 0 && strpos($to, '//') === false) {
    header('Location: ../' . $to);
} else {
    header('Location: ../' . $fallback);
}
exit;
