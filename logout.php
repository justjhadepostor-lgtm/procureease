<?php
require_once __DIR__ . '/config/auth.php';
log_activity('Signed out', 'session', '', 'Ended session and signed out.');
session_unset();
session_destroy();
header('Location: index.php');
exit;
