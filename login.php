<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/login-guard.php';   // v79: the wrong-password limit

// Make sure the users.status column (and every other admin-schema column) exists
// before we try to read it. This is idempotent — only ALTERs on first run.
ensure_admin_tables();
login_guard_columns();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter both username and password.';
    header('Location: index.php');
    exit;
}

// Fetch user. Falls back gracefully if the status column is somehow still missing.
$user = null;
try {
    $stmt = db()->prepare('SELECT id, username, password_hash, role, status FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
} catch (Throwable $ex) {
    // PHP 7-safe: use strpos instead of str_contains
    if (strpos($ex->getMessage(), 'status') !== false) {
        try {
            $stmt = db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user) $user['status'] = 'active';
        } catch (Throwable $ex2) {
            error_log('login.php fallback SELECT failed: ' . $ex2->getMessage());
            $_SESSION['login_error'] = 'A database error occurred. Please try again.';
            header('Location: index.php');
            exit;
        }
    } else {
        error_log('login.php SELECT failed: ' . $ex->getMessage());
        $_SESSION['login_error'] = 'A database error occurred. Please try again.';
        header('Location: index.php');
        exit;
    }
}

// v79: an account locked after too many wrong passwords is refused — even
// with the right password — until the time is up, the password is reset
// (Forgot password), or the Procurement Unit unlocks it. A locked account goes
// straight to Forgot password.
$__guard = $user ? login_guard_row((int)$user['id']) : null;
if ($__guard) {
    $__lock = login_lock_state($__guard);
    if ($__lock['locked']) {
        $_SESSION['reset_locked_notice'] = login_locked_message($__lock);
        header('Location: forgot-password.php');
        exit;
    }
}

// v129: no account with that username — say so
if (!$user) {
    $_SESSION['login_error'] = 'The user does not exist.';
    header('Location: index.php');
    exit;
}
if (!password_verify($password, $user['password_hash'])) {
    $_SESSION['login_error'] = 'Invalid username or password.';
    if ($__guard) {
        $__f = login_record_failure($__guard);
        if ($__f['locked']) {
            unset($_SESSION['login_error']);
            $_SESSION['reset_locked_notice'] = 'Too many wrong passwords. This account is locked for ' . LOGIN_LOCK_MINUTES . ' minutes (until '
                                             . login_time_label((int)$__f['state']['until']) . ').';
            header('Location: forgot-password.php');
            exit;
        } elseif ($__f['left'] <= 2) {
            $_SESSION['login_error'] .= ' ' . $__f['left'] . ' more wrong ' . ($__f['left'] === 1 ? 'try' : 'tries')
                                      . ' and this account will be locked for ' . LOGIN_LOCK_MINUTES . ' minutes.';
        }
    }
    header('Location: index.php');
    exit;
}
// the right password: the count of wrong ones starts again
login_record_success((int)$user['id']);

// Block accounts that haven't been approved yet
$status = $user['status'] ?? 'active';
if ($status === 'pending') {
    $_SESSION['login_error'] = 'Your account is awaiting admin approval. You will receive an email once your account is activated.';
    header('Location: index.php');
    exit;
}
if ($status === 'blocked') {
    $_SESSION['login_error'] = 'Your account has been blocked by the Procurement Unit. Please contact them if you believe this is a mistake.';
    header('Location: index.php'); exit;
}
if ($status === 'rejected') {
    $_SESSION['login_error'] = 'Your account was rejected by the administrator. Please contact the Procurement Unit.';
    header('Location: index.php');
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['role']    = $user['role'];

log_activity('Signed in', 'session', '', 'Signed in from web browser.');

$dashboards = [
    'admin'  => 'admin-home.php',
    'budget' => 'budget-home.php',
    'user'   => 'user-dashboard.php',
];
$landing = $dashboards[$user['role']] ?? 'user-dashboard.php';
header('Location: ' . $landing);
exit;
