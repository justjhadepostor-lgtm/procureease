<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/otp.php';
ensure_admin_tables();

// Already signed in? Straight to the dashboard.
if (is_logged_in()) {
    $u = current_user();
    $dashboards = [
        'admin'  => 'admin-home.php',
        'budget' => 'budget-home.php',
        'user'   => 'user-dashboard.php',
    ];
    header('Location: ' . ($dashboards[$u['role']] ?? 'user-dashboard.php'));
    exit;
}

$pageTitle = 'Sign in with email';
$error   = $_SESSION['login_error']   ?? '';
$success = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_success']);

$notice = '';
$step   = ($_GET['step'] ?? '') === 'code' && !empty($_SESSION['email_signin']) ? 'code' : 'email';

/** Look up an active account by email. */
function email_signin_lookup(string $email): array {
    try {
        $s = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $s->execute([$email]);
        $u = $s->fetch();
    } catch (Throwable $t) {
        return [null, 'A database error occurred. Please try again.'];
    }
    if (!$u) return [null, 'No ProcureEase account uses that email address. Please register first.'];

    $status = $u['status'] ?? 'active';
    if ($status === 'pending')  return [null, 'Your account is awaiting admin approval. You will receive an email once it is activated.'];
    if ($status === 'blocked')  return [null, 'Your account has been blocked by the Procurement Unit. Please contact them if you believe this is a mistake.'];
    if ($status === 'rejected') return [null, 'Your account was rejected by the administrator. Please contact the Procurement Unit.'];
    return [$u, ''];
}

// ─── Step 1: email submitted → send the code ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'email') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        [$user, $why] = email_signin_lookup($email);
        if (!$user) {
            $error = $why;
        } else {
            $result = send_new_otp($email, 'login');
            $_SESSION['email_signin']     = $email;
            $_SESSION['last_otp_preview'] = $result['code'];
            $_SESSION['last_otp_sent']    = $result['sent'];
            header('Location: email-signin.php?step=code');
            exit;
        }
    }
}

// ─── Step 2: code submitted → sign in ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'code') {
    $code  = trim($_POST['code'] ?? '');
    $email = $_SESSION['email_signin'] ?? '';

    if ($email === '') {
        $error = 'Your session expired. Please start again.';
        $step  = 'email';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Please enter the 6-digit code from your email.';
        $step  = 'code';
    } elseif (!verify_otp($email, $code, 'login')) {
        // v80: at most 5 wrong codes, then that code stops working (config/otp-guard.php)
        require_once __DIR__ . '/config/otp-guard.php';
        $error = otp_guard_fail($email, 'login');
        $step  = 'code';
    } else {
        [$user, $why] = email_signin_lookup($email);
        if (!$user) {
            $error = $why;
            $step  = 'email';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role']    = $user['role'];
            unset($_SESSION['email_signin'], $_SESSION['last_otp_preview'], $_SESSION['last_otp_sent']);

            log_activity('Signed in with email code', 'session', '', "Signed in as $email using a one-time code.");

            $dashboards = [
                'admin'  => 'admin-home.php',
                'budget' => 'budget-home.php',
                'user'   => 'user-dashboard.php',
            ];
            header('Location: ' . ($dashboards[$user['role']] ?? 'user-dashboard.php'));
            exit;
        }
    }
}

// ─── Resend ───
if (($_GET['resend'] ?? '') === '1' && !empty($_SESSION['email_signin'])) {
    $email  = $_SESSION['email_signin'];
    $result = send_new_otp($email, 'login');
    $_SESSION['last_otp_preview'] = $result['code'];
    $_SESSION['last_otp_sent']    = $result['sent'];
    $notice = "A new code was sent to $email.";
    $step   = 'code';
}

$signinEmail = $_SESSION['email_signin'] ?? '';
$otpPreview  = $_SESSION['last_otp_preview'] ?? '';
$otpSent     = $_SESSION['last_otp_sent'] ?? false;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in with email — ProcureEase</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<style>
  html, body { min-height: 100vh; }
  body {
    background: var(--page);
    display: grid; place-items: center;
    padding: 24px;
    min-height: 100vh;
  }
  .login-shell {
    width: 100%; max-width: 860px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 22px;
    box-shadow: 0 24px 60px rgba(32, 39, 89, 0.12);
    overflow: hidden;
    display: grid;
    grid-template-columns: 1fr 1fr;
  }

  /* ─── Left brand panel ─── */
  .login-brand {
    background: linear-gradient(155deg, #66a3bf 0%, #121358 55%, #093c5d 100%);
    padding: 44px 40px;
    color: white;
    display: flex; flex-direction: column;
    position: relative;
    overflow: hidden;
  }
  .login-brand::before {
    content: ''; position: absolute;
    top: -80px; right: -80px;
    width: 260px; height: 260px;
    background: radial-gradient(circle, rgba(255,106,28,0.18), transparent 70%);
    border-radius: 50%;
  }
  .login-brand::after {
    content: ''; position: absolute;
    bottom: -100px; left: -60px;
    width: 240px; height: 240px;
    background: radial-gradient(circle, rgba(66,116,217,0.20), transparent 70%);
    border-radius: 50%;
  }
  .brand-logos {
    display: flex; align-items: center; gap: 16px;
    margin-bottom: 28px; position: relative; z-index: 1;
    margin-top: auto;
  }
  .brand-logos img {
    width: 68px; height: 68px; object-fit: contain;
    background: white; border-radius: 14px; padding: 7px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.2);
  }
  .brand-logos .logo-divider {
    width: 1px; height: 44px;
    background: rgba(255,255,255,0.25);
  }
  .brand-body { position: relative; z-index: 1; margin: 0 0 auto; }
  .brand-name {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 34px; font-weight: 700;
    letter-spacing: -0.02em; margin-bottom: 10px;
    line-height: 1.05;
  }
  .brand-name span { color: #9cc6e8; }   /* "Ease": light navy — readable on the gradient, as on the sign-in page */
  .brand-tag {
    font-size: 14px; line-height: 1.6;
    color: rgba(255,255,255,0.82); margin-bottom: 20px;
  }
  .brand-meta {
    font-size: 12px; color: rgba(255,255,255,0.6);
    border-top: 1px solid rgba(255,255,255,0.15);
    padding-top: 16px; line-height: 1.6;
  }
  .brand-meta strong { color: rgba(255,255,255,0.9); font-weight: 600; }

  /* ─── Right form panel ─── */
  .login-panel {
    padding: 44px 40px;
    display: flex; flex-direction: column; justify-content: center;
  }
  .panel-head { margin-bottom: 22px; }
  .panel-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 22px; font-weight: 700;
    color: var(--ink); margin-bottom: 4px;
  }
  .panel-sub { font-size: 13.5px; color: var(--muted); }

  .field { margin-bottom: 16px; }
  .field label {
    display: block; font-size: 13px; font-weight: 600;
    color: var(--ink); margin-bottom: 6px;
  }
  .input-wrap { position: relative; }
  .input {
    width: 100%; padding: 12px 14px;
    border: 1px solid var(--border); border-radius: 10px;
    background: white; font-size: 14px;
    font-family: inherit; color: var(--ink);
    transition: border-color 0.15s, box-shadow 0.15s;
  }
  .input:focus {
    outline: none; border-color: var(--navy-500);
    box-shadow: 0 0 0 3px rgba(66, 116, 217, 0.15);
  }
  .toggle-pw {
    position: absolute; right: 12px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    color: var(--muted); cursor: pointer;
    padding: 4px;
  }
  .btn-google {
    display: flex; align-items: center; justify-content: center; gap: 11px;
    width: 100%; box-sizing: border-box;
    background: #fff; color: #3c4043;
    border: 1.5px solid #dadce0; border-radius: 10px;
    padding: 12px 18px; font-size: 14px; font-weight: 600;
    font-family: 'Inter', sans-serif; text-decoration: none;
    transition: background 0.15s, box-shadow 0.15s, border-color 0.15s;
  }
  .btn-google:hover { background: #f8faff; border-color: #c9d3e0; box-shadow: 0 1px 4px rgba(60,64,67,0.15); }
  .choice-or { display: flex; align-items: center; gap: 12px; margin: 16px 0; color: var(--navy-500); font-size: 12px; }
  .choice-or::before, .choice-or::after { content: ''; flex: 1; height: 1px; background: var(--navy-50); }

  .otp-input {
    width: 100%; padding: 18px 14px;
    border: 1px solid var(--border); border-radius: 12px;
    background: white;
    font-size: 26px; font-weight: 700;
    text-align: center; letter-spacing: 0.4em;
    font-family: 'Space Grotesk', sans-serif;
    color: var(--ink);
  }
  .otp-hint { font-size: 12.5px; color: var(--muted); text-align: center; line-height: 1.6; margin-bottom: 16px; }
  .otp-hint strong { color: var(--ink); }
  .demo-code { background: var(--orange-50); border: 1px solid var(--orange-100); color: var(--orange-600); border-radius: 9px; padding: 10px 14px; font-size: 12.5px; text-align: center; margin-bottom: 14px; }
  .demo-code b { font-size: 17px; letter-spacing: 3px; font-family: 'Space Grotesk', sans-serif; }
  .alt-login { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--navy-50); }

  .btn-choice {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    width: 100%; box-sizing: border-box;
    background: #fff; color: #2f578a;
    border: 1.5px solid #66a3bf; border-radius: 10px;
    padding: 12px 18px; font-size: 14px; font-weight: 600;
    font-family: 'Inter', sans-serif; text-decoration: none;
    transition: all 0.15s;
  }
  .btn-choice:hover { border-color: #2f578a; background: var(--navy-50); }

  .btn-signin {
    width: 100%; padding: 13px; margin-top: 6px;
    background: #121358; color: white;
    border: none; border-radius: 10px;
    font-size: 15px; font-weight: 700;
    cursor: pointer; font-family: 'Space Grotesk', sans-serif;
    transition: background 0.15s, transform 0.08s;
    letter-spacing: 0.01em;
  }
  .btn-signin:hover { background: #093c5d; }
  .btn-signin:active { transform: translateY(1px); }
  .login-links {
    text-align: center; margin-top: 20px;
    font-size: 13.5px;
  }
  .login-links a {
    color: var(--navy-900); text-decoration: none; font-weight: 600;
  }
  .login-links a:hover { color: var(--ink); text-decoration: underline; }
  .link-divider { margin: 0 12px; color: var(--muted); opacity: 0.5; }
  .flash { margin-bottom: 16px; }

  @media (max-width: 720px) {
    .login-shell { grid-template-columns: 1fr; max-width: 420px; }
    .login-brand { padding: 32px 30px; }
    .brand-body { margin: 24px 0 0; }
    .brand-name { font-size: 28px; }
    .login-panel { padding: 32px 30px; }
  }
</style>
<script src="assets/loader.js?v=<?= @filemtime(__DIR__ . '/assets/loader.js') ?>"></script><?php /* v97: the loading icon */ ?>
</head>
<body>

<div class="login-shell">
  <!-- Brand panel -->
  <div class="login-brand">
    <div class="brand-logos">
      <img src="assets/ascot-logo.png" alt="Aurora State College of Technology">
      <div class="logo-divider"></div>
      <img src="assets/procurement-logo.png" alt="ASCOT Procurement Unit">
    </div>
    <div class="brand-body">
      <div class="brand-name">Procure<span>Ease</span></div>
      <div class="brand-tag">
        Automated PPMP Consolidation System for the Procurement Unit of Aurora State College of Technology.
      </div>
      <div class="brand-meta">
        <strong>Aurora State College of Technology</strong><br>
        Procurement Unit · Brgy. Zabali, Baler, Aurora<br>
        Governed by RA 12009 (NGPA)
      </div>
    </div>
  </div>

  <!-- Form panel -->
  <div class="login-panel">
    <div class="panel-head">
      <div class="panel-title"><?= $step === 'code' ? 'Enter your code' : 'Sign in with email' ?></div>
      <div class="panel-sub">
        <?= $step === 'code'
              ? 'We sent a 6-digit code to your email address.'
              : "Enter your email and we'll send you a one-time code." ?>
      </div>
    </div>

    <?php if ($success): ?><div class="flash flash-success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($notice):  ?><div class="flash flash-success"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error):   ?><?php $__blk = stripos($error, 'has been blocked') !== false;   /* v96: a blocked account: bold red */ ?>
      <div class="flash flash-error<?= $__blk ? ' flash-blocked' : '' ?>"<?= $__blk ? ' role="alert" style="color: #b91c1c; background: #fef2f2; border-color: #f87171; font-weight: 700;"' : '' ?>><?= e($error) ?></div><?php endif; ?>

    <?php if ($step === 'code'): ?>
      <!-- ─── Step 2: the code ─── -->
      <div class="otp-hint">
        Code sent to <strong><?= e($signinEmail) ?></strong><br>
        It expires in 10 minutes.
      </div>

      <?php if ($otpPreview && !$otpSent): ?>
        <div class="demo-code">
          Email isn't connected yet, so here's your code:<br>
          <b><?= e($otpPreview) ?></b>
        </div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
      <?= csrf_field() /* v79 */ ?>
        <input type="hidden" name="step" value="code">
        <div class="field">
          <label for="code">6-digit code</label>
          <input type="text" id="code" name="code" class="otp-input"
                 inputmode="numeric" pattern="\d{6}" maxlength="6"
                 placeholder="••••••" required autofocus autocomplete="one-time-code">
        </div>
        <button type="submit" class="btn-signin">Sign in</button>
      </form>

      <div class="login-links">
        <a href="email-signin.php?resend=1">Send a new code</a>
        <span style="color:var(--border); margin:0 6px;">·</span>
        <a href="email-signin.php?step=email">Use another email</a>
      </div>

    <?php else: ?>
      <!-- ─── Step 1: the email ─── -->
      <form method="POST" autocomplete="off">
      <?= csrf_field() /* v79 */ ?>
        <input type="hidden" name="step" value="email">
        <div class="field">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" class="input"
                 value="<?= e($signinEmail) ?>" placeholder="you@example.com"
                 required autofocus autocomplete="email">
        </div>
        <button type="submit" class="btn-signin">Send code</button>
      </form>

      <div class="alt-login">
        <a href="index.php" class="btn-choice">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          <span>Log in with username &amp; password</span>
        </a>
      </div>

      <div class="login-links">
        Don't have an account? <a href="register.php">Create one</a>
        <div style="margin-top:10px;"><a href="index.php">← Back to sign in</a></div>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
