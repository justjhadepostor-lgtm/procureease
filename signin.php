<?php
// The sign-in page now lives at index.php; keep this path working for old links.
require_once __DIR__ . '/config/auth.php';
header('Location: index.php');
exit;
