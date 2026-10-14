<?php
require_once __DIR__ . '/../config/auth.php';
require_login();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']); exit;
}

$u = current_user();
$value = ($_POST['value'] ?? '0') === '1' ? '1' : '0';

try {
    db()->prepare('INSERT INTO user_prefs (user_id, key_name, value) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute([(int)$u['id'], 'dark_mode', $value]);
    echo json_encode(['ok' => true, 'value' => $value]);
} catch (Throwable $t) {
    echo json_encode(['ok' => false, 'error' => 'Save failed']);
}
