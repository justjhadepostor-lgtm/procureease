<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
// Ensure schema is fully migrated even on the very first page load, before login.
ensure_admin_tables();

// If already logged in, redirect to the right dashboard
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
$pageTitle = 'Sign in';
require_once __DIR__ . '/config/google-oauth.php';
$googleReady = google_is_configured();
$error   = $_SESSION['login_error']   ?? '';
$success = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — ProcureEase</title>
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
  }
  .brand-logos img {
    width: 68px; height: 68px; object-fit: contain;
  }
  .brand-logos .logo-divider {
    width: 1px; height: 44px;
    background: rgba(255,255,255,0.25);
  }
  .brand-body { position: relative; z-index: 1; }
  .brand-name {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 34px; font-weight: 700;
    letter-spacing: -0.02em; margin-bottom: 10px;
    line-height: 1.05;
  }
  .brand-name span { color: #9cc6e8; }   /* "Ease": light navy — 3.75:1 or more on the gradient behind it (was #1e5285, 1.0:1) */
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

  /* Developer credit — sign-in screen only, never on a printed document.
     A ticker: a fixed label, a rule, then the names running past it. */
  .dev-credit {
    margin-top: 26px;
    display: flex; align-items: stretch;
    height: 34px;
    border: 1px solid var(--border); border-radius: 10px;
    background: var(--navy-50);
    overflow: hidden;
  }
  .dev-credit .dc-label {
    flex: 0 0 auto;
    display: flex; align-items: center;
    padding: 0 11px;
    border-right: 1px solid var(--border);
    font-size: 9.5px; font-weight: 700;
    letter-spacing: 0.1em; text-transform: uppercase;
    color: var(--navy-700); white-space: nowrap;
  }
  /* The names are faded out rather than cut off at each end, so one does
     not appear or vanish on a hard edge. */
  .dev-credit .dc-window {
    /* min-width: 0 is load-bearing. A flex item defaults to min-width:auto,
       which means it refuses to be narrower than its contents — and its
       contents here are the whole doubled list. Without this the ticker
       pushes the sign-in card, and the page behind it, out to its own
       width instead of scrolling inside the space it was given. */
    flex: 1 1 auto; min-width: 0; position: relative; overflow: hidden;
    -webkit-mask-image: linear-gradient(90deg, transparent 0, #000 20px,
                        #000 calc(100% - 20px), transparent 100%);
            mask-image: linear-gradient(90deg, transparent 0, #000 20px,
                        #000 calc(100% - 20px), transparent 100%);
  }
  /* Taken out of the flow on purpose. A doubled list is far wider than
     the card, and while it is a normal child every ancestor is asked to
     be wide enough to hold it — the grid then hands the whole width to
     this column and the brand panel beside it collapses. Positioned
     absolutely it is painted inside the window and measured by nobody. */
  .dev-credit .dc-track {
    position: absolute; top: 0; left: 0; height: 100%;
    display: flex; width: max-content;
    animation: dc-roll 22s linear infinite;
    will-change: transform;
  }
  .dev-credit .dc-run { display: flex; align-items: center; }
  .dev-credit .dc-name {
    font-size: 12px; color: var(--ink); white-space: nowrap;
  }
  .dev-credit .dc-dot {
    flex: 0 0 auto; width: 3px; height: 3px; border-radius: 50%;
    background: var(--navy-500); margin: 0 15px;
  }
  /* Half the track is exactly one copy of the list, so the restart lands
     on the same pixel it started from. */
  @keyframes dc-roll {
    from { transform: translateX(0); }
    to   { transform: translateX(-50%); }
  }
  .dev-credit:hover .dc-track { animation-play-state: paused; }

  @media (max-width: 480px) {
    .dev-credit .dc-name { font-size: 11.5px; }
    .dev-credit .dc-label { padding: 0 9px; font-size: 9px; }
  }
  /* Asked for less movement: it stops, and the names simply wrap. */
  @media (prefers-reduced-motion: reduce) {
    .dev-credit { height: auto; min-height: 34px; }
    .dev-credit .dc-window {
      -webkit-mask-image: none; mask-image: none; padding: 5px 12px;
    }
    .dev-credit .dc-track { position: static; animation: none; width: 100%; }
    .dev-credit .dc-run { flex-wrap: wrap; justify-content: center; }
    .dev-credit .dc-run + .dc-run { display: none; }
  }
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
      <div class="panel-title">Welcome back</div>
      <div class="panel-sub">Sign in to continue to your dashboard.</div>
    </div>

    <?php if ($success): ?>
      <div class="flash flash-success"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <?php $__blk = stripos($error, 'has been blocked') !== false;   /* v96: a blocked account: bold red */ ?>
      <?php /* v132: every sign-in error in red (a blocked account: bold too) */ ?>
      <div class="flash flash-error<?= $__blk ? ' flash-blocked' : '' ?>" id="loginError" role="alert" style="color: #b91c1c; background: #fef2f2; border-color: #f87171;<?= $__blk ? ' font-weight: 700;' : '' ?>"><?= e($error) ?></div>
      <?php require_once __DIR__ . '/config/setup-lock.php';
            if ((strpos($error, 'Invalid') !== false || strpos($error, 'database error') !== false) && !setup_is_locked()): /* v80: only while setup.php still runs */ ?>
        <div style="font-size: 12px; color: var(--muted); margin: 8px 0 12px;">
          Just installed and this is your first sign-in?
          <a href="setup.php" style="color: var(--navy-900); font-weight: 600; text-decoration: underline;">Run setup</a>
          to repair seed accounts.
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <form method="POST" action="login.php" autocomplete="off">
      <?= csrf_field() /* v79 */ ?>
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" class="input"
               placeholder="e.g. ictu-01-001" autocomplete="off" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <div class="input-wrap">
          <input type="password" id="password" name="password" class="input"
                 placeholder="Enter your password" autocomplete="new-password" required>
          <button type="button" class="toggle-pw" onclick="togglePw()" aria-label="Show password">
            <svg id="pwIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
      <button type="submit" class="btn-signin">Sign in</button>
    </form>

    <div class="choice-or"><span>or</span></div>
    <form action="google-auth.php" method="GET" style="margin: 0;">
      <input type="hidden" name="mode" value="signin">
      <button type="submit" class="btn-google">
        <svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24s.92 7.54 2.56 10.78l7.97-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        <span>Sign in with Gmail</span>
      </button>
    </form>

    <div class="login-links">
      <a href="forgot-password.php">Forgot your password?</a>
      <span class="link-divider">|</span>
      <a href="register.php">Create an account</a>
    </div>

    <?php
      // The four of us. Written once; the ticker lays the list out twice so
      // it can loop with no seam, and the second copy is kept from screen
      // readers so the names are announced once.
      $__devs = ['Jhon Lloyd Bautista', 'Jhade Postor',
                 'Jasmine Tobias', 'John Lennon Resueno'];
    ?>
    <div class="dev-credit">
      <span class="dc-label">Developed by</span>
      <div class="dc-window">
        <div class="dc-track">
          <?php for ($__c = 0; $__c < 2; $__c++): ?>
            <div class="dc-run"<?= $__c ? ' aria-hidden="true"' : '' ?>>
              <?php foreach ($__devs as $__dev): ?>
                <span class="dc-name"><?= e($__dev) ?></span>
                <span class="dc-dot" aria-hidden="true"></span>
              <?php endforeach; ?>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const EYE_OPEN  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  const EYE_CLOSED = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

  function togglePw() {
    const pw  = document.getElementById('password');
    const btn = document.querySelector('.toggle-pw');
    const willReveal = pw.type === 'password';
    pw.type = willReveal ? 'text' : 'password';
    btn.innerHTML = willReveal ? EYE_CLOSED : EYE_OPEN;
    btn.setAttribute('aria-label', willReveal ? 'Hide password' : 'Show password');
  }

  // Wipe any browser-autofilled values so the user or admin types them
  window.addEventListener('load', () => {
    setTimeout(() => {
      document.getElementById('username').value = '';
      document.getElementById('password').value = '';
      document.getElementById('username').focus();
    }, 10);
  });
</script>
</body>
</html>
