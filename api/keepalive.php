<?php
/* Someone is working on a page without saving yet: keep the session alive
   (Settings > Security > Session timeout counts from the last activity). */
require_once __DIR__ . '/../config/auth.php';
require_login();
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'timeout_min' => session_timeout_min()]);
