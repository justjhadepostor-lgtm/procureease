<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/google-oauth.php';
ensure_admin_tables();

function google_fail(string $msg, string $where = 'index.php'): void {
    $_SESSION['login_error'] = $msg;
    header("Location: $where");
    exit;
}

// The person clicked "Cancel" on Google's screen
if (isset($_GET['error'])) {
    google_fail('Google Sign-In was cancelled.');
}

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
if ($code === '' || $state === '') {
    google_fail('Google Sign-In did not complete. Please try again.');
}

// state carries "<random>|<mode>"
[$stateToken, $mode] = array_pad(explode('|', $state, 2), 2, 'signin');
$expected = $_SESSION['google_state'] ?? '';
unset($_SESSION['google_state']);
if ($expected === '' || !hash_equals($expected, $stateToken)) {
    google_fail('That sign-in link has expired. Please try again.');
}

// Code → token → profile
$token = google_exchange_code($code);
if (!$token || empty($token['access_token'])) {
    google_fail('Could not reach Google to complete sign-in. Please try again.');
}
$profile = google_fetch_profile($token['access_token']);
if (!$profile || empty($profile['email'])) {
    google_fail('Google did not return an email address for that account.');
}

$email    = strtolower(trim($profile['email']));
$fullName = trim($profile['name'] ?? '');
$verified = !empty($profile['verified_email']);

if (!$verified) {
    google_fail('That Google account does not have a verified email address.');
}

// Is there already an account with this email?
$user = null;
try {
    $s = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $s->execute([$email]);
    $user = $s->fetch();
} catch (Throwable $t) {
    google_fail('A database error occurred. Please try again.');
}

// ─── Existing account → sign them in ───
if ($user) {
    $status = $user['status'] ?? 'active';
    if ($status === 'pending') {
        google_fail('Your account is awaiting admin approval. You will receive an email once your account is activated.');
    }
    if ($status === 'blocked') {
        google_fail('Your account has been blocked by the Procurement Unit. Please contact them if you believe this is a mistake.');
    }
    if ($status === 'rejected') {
        google_fail('Your account was rejected by the administrator. Please contact the Procurement Unit.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role']    = $user['role'];

    log_activity('Signed in with Google', 'session', '', "Signed in as $email via Google.");

    $dashboards = [
        'admin'  => 'admin-home.php',
        'budget' => 'budget-dashboard.php',
        'user'   => 'user-dashboard.php',
    ];
    header('Location: ' . ($dashboards[$user['role']] ?? 'user-dashboard.php'));
    exit;
}

// ─── No account yet ───
if ($mode === 'signin') {
    google_fail("No ProcureEase account is linked to $email yet. Please register first.", 'register.php');
}

// Registering: carry the verified Google details into the form.
// Google has already proven the address, so the OTP step is skipped.
$_SESSION['google_signup'] = [
    'email'     => $email,
    'full_name' => $fullName,
];
header('Location: register.php?google=1');
exit;
