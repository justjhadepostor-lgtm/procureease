<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/google-oauth.php';

if (!google_is_configured()) {
    $_SESSION['login_error'] = 'Google Sign-In has not been set up yet. Please sign in with your username and password, or contact the Procurement Unit.';
    header('Location: index.php'); exit;
}

// 'signin' or 'register' — decides what we do when Google sends the person back
$mode = ($_GET['mode'] ?? 'signin') === 'register' ? 'register' : 'signin';

// One-time value tying the redirect back to this browser session
$state = bin2hex(random_bytes(16));
$_SESSION['google_state'] = $state;

header('Location: ' . google_auth_url($state, $mode));
exit;
