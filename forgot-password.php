<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/otp.php';
require_once __DIR__ . '/config/login-guard.php';   // v79: Sign-in lockouts
require_once __DIR__ . '/config/phone.php';
require_once __DIR__ . '/config/twilio.php';       // SMS Gateway for Android (v110)
// Note: No require_login() - this page must be accessible to logged-out users
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/db.php';
ensure_admin_tables();

// If they're already signed in, sign them out first so the reset flow works cleanly
if (is_logged_in() && !isset($_GET['step'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
}

// v104: "Cancel" while entering the code — nothing is changed, back to sign in
if (isset($_GET['cancel'])) {
    unset($_SESSION['reset_pending'], $_SESSION['last_otp_preview'], $_SESSION['last_otp_sent'], $_SESSION['reset_method']);
    header('Location: index.php'); exit;
}

$step   = $_GET['step'] ?? 'lookup';
/* v121: step 1 asks for the email first; "Try another way" asks for the mobile number instead */
$way    = $_POST['way'] ?? ((trim($_POST['mobile'] ?? '') !== '' && trim($_POST['email'] ?? '') === '') ? 'mobile' : ($_GET['way'] ?? 'email'));
$way    = $way === 'mobile' ? 'mobile' : 'email';

/* v108: the verification code is sent to the account's email only, never shown on the screen */
const FP_NOT_SENT = 'The verification code could not be sent to your email right now, so nothing was changed. Please try again in a moment, or contact the Procurement Unit.';
/** Cancels the reset codes of an email that could not be sent (no one could have them). */
function fp_cancel_codes(string $email): void {
    try { db()->prepare("UPDATE email_otps SET used_at = NOW() WHERE email = ? AND purpose = 'reset' AND used_at IS NULL")->execute([$email]); }
    catch (Throwable $t) {}
}
/* v110: a mobile number gets its code by text message only — never by email instead */
const FP_SMS_OFF  = 'Sending the code by text message is not set up yet. Please enter your email address instead, or contact the Procurement Unit.';
const FP_SMS_FAIL = 'The verification code could not be sent to your mobile number right now, so nothing was changed. Please try again in a moment, or enter your email address instead.';
$error  = '';
$notice = '';
// v79: sent here from the sign-in page because the account is locked after wrong passwords
$lockedNotice = (string)($_SESSION['reset_locked_notice'] ?? '');
unset($_SESSION['reset_locked_notice']);

// ─── Step 1: email or mobile lookup → send OTP ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'lookup') {
    $email = trim($_POST['email'] ?? '');
    $mobileRaw = trim($_POST['mobile'] ?? '');
    $mobile = $mobileRaw === '' ? '' : ph_mobile_local($mobileRaw);
    
    if ($email === '' && $mobileRaw === '') {
        $error = 'Please enter your email address or mobile number.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($mobileRaw !== '' && $mobile === '') {
        $error = 'Please enter a valid mobile number (09XXXXXXXXX).';
    } else {
        try {
            $user = null;
            if ($email !== '') {
                $stmt = db()->prepare('SELECT id, username, email, contact FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $user = $stmt->fetch();
            }
            // v101: the mobile number is the account's Contact number (Registration / Profile).
            // One account only — a number two accounts share cannot tell them apart.
            if (!$user && $mobile !== '') {
                $__hits = [];
                $stmt = db()->query("SELECT id, username, email, contact FROM users WHERE contact IS NOT NULL AND contact <> ''");
                foreach ($stmt->fetchAll() as $__r) {
                    if (ph_mobile_local((string)$__r['contact']) === $mobile) $__hits[] = $__r;
                }
                if (count($__hits) === 1) $user = $__hits[0];
                elseif (count($__hits) > 1) $error = 'More than one account has that mobile number. Please use your email address instead.';
            }
        } catch (Throwable $ex) {
            error_log('forgot-password lookup failed: ' . $ex->getMessage());
            $error = 'A database error occurred. Please try again in a moment.';
            $user  = null;
        }

        if (!$error) {
            if ($user && trim((string)($user['email'] ?? '')) === '' && $email !== '') {
                $error = 'This account has no email address, so the code cannot be sent. Please contact the Procurement Unit.';
            } elseif (!$user) {
                $error = 'No account found with that email or mobile number. Please check the spelling or contact the Procurement Unit.';
            } else {
                try {
                    $sent = false;
                    $sentMethod = '';
                    // v110: by text only when the number typed is the account's own number;
                    // then the code goes to that number and nowhere else
                    $acctMobile = ph_mobile_local((string)($user['contact'] ?? ''));
                    $viaSms = $mobile !== '' && $acctMobile !== '' && $acctMobile === $mobile && $email === '';

                    if ($viaSms) {
                        $otpKey = trim((string)($user['email'] ?? ''));
                        if ($otpKey === '') {
                            $otpKey = 'user_' . (int)$user['id'] . '@local';
                        }
                        if (!sms_gateway_enabled()) {
                            $error = FP_SMS_OFF;
                        } else {
                            $smsResult = send_otp_via_sms($acctMobile, $otpKey, 'reset');
                            if (!empty($smsResult['sent'])) {
                                $sent = true;
                                $sentMethod = 'SMS';
                            } else {
                                error_log('forgot-password SMS failed: ' . ($smsResult['error'] ?? 'Unknown error'));
                                fp_cancel_codes($otpKey);
                                $error = FP_SMS_FAIL;
                            }
                        }
                    } elseif (trim((string)($user['email'] ?? '')) === '') {
                        $error = 'This account has no email address, so the code cannot be sent. Please contact the Procurement Unit.';
                    } else {
                        $result = send_new_otp((string)$user['email'], 'reset');
                        $sent = !empty($result['sent']);
                        $sentMethod = 'email';
                    }

                    if ($sent) {
                        $_SESSION['reset_pending'] = [
                            'username' => $user['username'],
                            'email'    => $user['email'] ?? '',
                            'mobile'   => $sentMethod === 'SMS' ? $acctMobile : '',
                        ];
                        unset($_SESSION['last_otp_preview']);
                        $_SESSION['last_otp_sent'] = true;
                        $_SESSION['reset_method'] = $sentMethod;
                        header('Location: forgot-password.php?step=verify');
                        exit;
                    } else {
                        if ($sentMethod === 'email') fp_cancel_codes((string)$user['email']);
                        unset($_SESSION['reset_pending'], $_SESSION['last_otp_preview'], $_SESSION['last_otp_sent'], $_SESSION['reset_method']);
                        if ($error === '') $error = FP_NOT_SENT;
                    }
                } catch (Throwable $ex) {
                    error_log('forgot-password send OTP failed: ' . $ex->getMessage());
                    $error = 'Could not generate a verification code. Please try again.';
                }
            }
        }
    }
}

// ─── v107, Step 2: the code alone — the new password is asked only once it is right ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'verify' && !isset($_POST['new_password'])) {
    $code = trim($_POST['code'] ?? '');
    if (empty($_SESSION['reset_pending'])) {
        $error = 'Your session expired. Please start again.';
        $step  = 'lookup';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Please enter the 6-digit code.';
        $step  = 'verify';
    } else {
        $pending = $_SESSION['reset_pending'];
        try {
            $otpKey = !empty($pending['email']) ? $pending['email'] : null;
            if ($otpKey === null) {
                $u2 = db()->prepare('SELECT id FROM users WHERE username = ?');
                $u2->execute([$pending['username']]);
                $otpKey = 'user_' . $u2->fetchColumn() . '@local';
            }
            if (verify_otp($otpKey, $code, 'reset')) {
                // the code is right (and used up): the new password may be set for the next 15 minutes
                $_SESSION['reset_pending']['verified_at'] = time();
                header('Location: forgot-password.php?step=newpw');
                exit;
            }
            // v80: at most 5 wrong codes, then that code stops working (config/otp-guard.php)
            require_once __DIR__ . '/config/otp-guard.php';
            $error = otp_guard_fail($otpKey, 'reset');
            $step  = 'verify';
        } catch (Throwable $ex) {
            error_log('forgot-password verify failed: ' . $ex->getMessage());
            $error = 'Something went wrong checking the code. Please try again.';
            $step  = 'verify';
        }
    }
}

// ─── v107, Step 3: the new password, after the code was verified ───
const FP_VERIFIED_FOR = 900;   // seconds the verified code holds
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'newpw') {
    $newpw   = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $vAt = (int)($_SESSION['reset_pending']['verified_at'] ?? 0);
    if (empty($_SESSION['reset_pending'])) {
        $error = 'Your session expired. Please start again.';
        $step  = 'lookup';
    } elseif ($vAt <= 0 || time() - $vAt > FP_VERIFIED_FOR) {
        unset($_SESSION['reset_pending']['verified_at']);
        $error = 'Please enter your verification code first' . ($vAt > 0 ? ' — the verified code has expired. Get another code.' : '.');
        $step  = 'verify';
    } elseif (password_problem($newpw) !== '') {
        $error = password_problem($newpw);
        $step  = 'newpw';
    } elseif ($newpw !== $confirm) {
        $error = 'Passwords do not match.';
        $step  = 'newpw';
    } else {
        $pending = $_SESSION['reset_pending'];
        try {
            db()->prepare('UPDATE users SET password_hash = ? WHERE username = ?')
                ->execute([password_hash($newpw, PASSWORD_DEFAULT), $pending['username']]);
            // v79: a new password set through the emailed code also lifts a lock from wrong passwords
            try {
                require_once __DIR__ . '/config/login-guard.php';
                login_guard_columns();
                db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE username = ?')
                    ->execute([$pending['username']]);
            } catch (Throwable $t) {}
            unset($_SESSION['reset_pending'], $_SESSION['last_otp_preview'], $_SESSION['last_otp_sent'], $_SESSION['reset_method']);
            $_SESSION['login_success'] = 'Password reset successfully. Please sign in with your new password.';
            header('Location: index.php');
            exit;
        } catch (Throwable $ex) {
            error_log('forgot-password reset failed: ' . $ex->getMessage());
            $error = 'Something went wrong updating the password. Please try again.';
            $step  = 'newpw';
        }
    }
}

// ─── Step 2 as it was (the code and the new password together) — still understood ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'verify' && isset($_POST['new_password'])) {
    $code    = trim($_POST['code'] ?? '');
    $newpw   = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($_SESSION['reset_pending'])) {
        $error = 'Your session expired. Please start again.';
        $step  = 'lookup';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Please enter the 6-digit code shown above.';
        $step  = 'verify';
    } elseif (password_problem($newpw) !== '') {
        $error = password_problem($newpw);
        $step  = 'verify';
    } elseif ($newpw !== $confirm) {
        $error = 'Passwords do not match.';
        $step  = 'verify';
    } else {
        $pending = $_SESSION['reset_pending'];
        try {
            // Use same key convention as when we generated the code
            $otpKey = !empty($pending['email']) ? $pending['email'] : null;
            if ($otpKey === null) {
                // Need to look up the user again to get their id for the placeholder key
                $u2 = db()->prepare('SELECT id FROM users WHERE username = ?');
                $u2->execute([$pending['username']]);
                $uid = $u2->fetchColumn();
                $otpKey = 'user_' . $uid . '@local';
            }
            if (verify_otp($otpKey, $code, 'reset')) {
                $hash = password_hash($newpw, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET password_hash = ? WHERE username = ?')
                    ->execute([$hash, $pending['username']]);
                // v79: a new password set through the emailed code also lifts a
                // lock from wrong passwords (config/login-guard.php)
                try {
                    require_once __DIR__ . '/config/login-guard.php';
                    login_guard_columns();
                    db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE username = ?')
                        ->execute([$pending['username']]);
                } catch (Throwable $t) {}
                unset($_SESSION['reset_pending'], $_SESSION['last_otp_preview'], $_SESSION['last_otp_sent'], $_SESSION['reset_method']);
                $_SESSION['login_success'] = 'Password reset successfully. Please sign in with your new password.';
                header('Location: index.php');
                exit;
            } else {
                // v80: at most 5 wrong codes, then that code stops working (config/otp-guard.php)
                require_once __DIR__ . '/config/otp-guard.php';
                $error = otp_guard_fail($otpKey, 'reset');
                $step  = 'verify';
            }
        } catch (Throwable $ex) {
            error_log('forgot-password verify+reset failed: ' . $ex->getMessage());
            $error = 'Something went wrong updating the password. Please try again.';
            $step  = 'verify';
        }
    }
}

// ─── Resend link ──────────────────────────────────────────
if (($_GET['resend'] ?? '') === '1' && !empty($_SESSION['reset_pending'])) {
    $email = $_SESSION['reset_pending']['email'] ?? '';
    $otpKey = $email;
    if (empty($otpKey)) {
        $u2 = db()->prepare('SELECT id FROM users WHERE username = ?');
        $u2->execute([$_SESSION['reset_pending']['username']]);
        $uid = $u2->fetchColumn();
        $otpKey = 'user_' . $uid . '@local';
    }
    // v110: a code sent by text is sent again by text only; an emailed one, by email
    $mobile = ph_mobile_local((string)($_SESSION['reset_pending']['mobile'] ?? ''));
    $sentMethod = (($_SESSION['reset_method'] ?? '') === 'SMS' && $mobile !== '') ? 'SMS' : 'email';
    $result = ['sent' => false];
    if ($sentMethod === 'SMS') {
        if (sms_gateway_enabled()) $result = send_otp_via_sms($mobile, $otpKey, 'reset');
    } else {
        $result = send_new_otp($otpKey, 'reset');
    }
    unset($_SESSION['reset_pending']['verified_at']);   // v107: the new code is verified again
    unset($_SESSION['last_otp_preview']);
    $_SESSION['reset_method'] = $sentMethod;
    if (empty($result['sent'])) {
        fp_cancel_codes($otpKey);
        $_SESSION['last_otp_sent'] = false;
        $error = $sentMethod === 'SMS' ? FP_SMS_FAIL : FP_NOT_SENT;
    } else {
        $_SESSION['last_otp_sent'] = true;
        $notice = $sentMethod === 'SMS'
            ? 'A new code was sent to your mobile number.'
            : 'A new code was sent to your email.';
    }
    $step   = 'verify';
}

if ($step === 'verify' && empty($_SESSION['reset_pending'])) {
    $step = 'lookup';
}
// v107: the new password only after the code was verified (and not too long ago)
if ($step === 'newpw') {
    if (empty($_SESSION['reset_pending'])) $step = 'lookup';
    elseif (time() - (int)($_SESSION['reset_pending']['verified_at'] ?? 0) > FP_VERIFIED_FOR) {
        if (!empty($_SESSION['reset_pending']['verified_at']) && $error === '') $error = 'The verified code has expired. Get another code.';
        unset($_SESSION['reset_pending']['verified_at']);
        $step = 'verify';
    }
}

$maskedEmail = '';
if (!empty($_SESSION['reset_pending']['email'])) {
    $e = $_SESSION['reset_pending']['email'];
    [$local, $dom] = explode('@', $e, 2) + [null, null];
    if ($local && $dom) {
        $maskedEmail = mb_substr($local, 0, 1) . str_repeat('•', max(1, mb_strlen($local) - 2)) . mb_substr($local, -1) . '@' . $dom;
    } else {
        $maskedEmail = $e;
    }
}
$otpMailed  = $_SESSION['last_otp_sent']    ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — ProcureEase</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<style>
  /* v104: the page scrolls when it is taller than the window (the code step is long) */
  html, body { min-height: 100vh; overflow-x: hidden; overflow-y: auto; }
  body { background: var(--page); display: grid; place-items: center; padding: 24px; }
  .login-wrap { width: 100%; max-width: 440px; text-align: center; }
  .login-avatar {
    width: 140px; height: 140px; margin: 0 auto -8px;
    object-fit: contain; display: block;
  }
  .login-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 26px; font-weight: 700;
    color: var(--ink); letter-spacing: -0.02em; margin-bottom: 6px;
  }
  .login-sub { font-size: 14px; color: var(--muted); margin-bottom: 22px; }
  .login-card {
    background: white; border: 1px solid var(--border);
    border-radius: 16px; padding: 28px 28px;
    text-align: left;
  }
  .field { margin-bottom: 14px; }
  .field label {
    display: block; font-size: 13px; font-weight: 600;
    color: var(--ink); margin-bottom: 6px;
  }
  .input {
    width: 100%; padding: 12px 14px;
    border: 1px solid var(--border); border-radius: 10px;
    background: white; font-size: 14px;
    font-family: inherit; color: var(--ink);
  }
  .input:focus {
    outline: none; border-color: var(--navy-500);
    box-shadow: 0 0 0 3px rgba(66, 116, 217, 0.15);
  }
  .btn-signin {
    width: 100%; padding: 12px; margin-top: 6px;
    background: #121358; color: white;
    border: none; border-radius: 10px;
    font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: inherit;
    transition: background 0.15s;
  }
  .btn-signin:hover { background: #093c5d; }
  .login-links {
    text-align: center; margin-top: 16px;
    font-size: 13px; color: var(--muted);
  }
  .login-links a { color: var(--navy-900); text-decoration: none; font-weight: 600; }
  .login-links a:hover { text-decoration: underline; }
  .step-note {
    font-size: 13px; color: var(--muted); line-height: 1.6;
    margin-bottom: 16px;
  }
  .step-note strong { color: var(--ink); }
  .otp-input {
    width: 100%; padding: 16px 14px;
    border: 1px solid var(--border); border-radius: 12px;
    background: white;
    font-size: 24px; font-weight: 700;
    text-align: center; letter-spacing: 0.4em;
    font-family: 'Space Grotesk', sans-serif;
    color: var(--ink);
  }
  .otp-input:focus {
    outline: none; border-color: var(--orange-500);
    box-shadow: 0 0 0 3px rgba(255, 106, 28, 0.15);
  }
  .dev-preview {
    margin-top: 14px; padding: 12px 14px;
    background: var(--navy-50); border: 1px dashed var(--navy-500);
    border-radius: 10px;
    font-size: 12.5px; color: var(--muted); text-align: center;
    line-height: 1.5;
  }
  .dev-preview b {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 20px; font-weight: 700;
    letter-spacing: 0.3em; color: var(--navy-700);
    display: inline-block; margin: 4px 0;
  }
  .pw-wrap { position: relative; display: flex; align-items: center; }
  .pw-eye {
    position: absolute; right: 12px; background: none; border: none;
    cursor: pointer; color: var(--muted); padding: 4px;
    display: flex; align-items: center; justify-content: center;
  }
  .pw-eye:hover { color: var(--ink); }
  .pw-eye .eye-off { display: none; }
  .pw-eye[aria-pressed="true"] .eye-on { display: none; }
  .pw-eye[aria-pressed="true"] .eye-off { display: block; }
  .field-hint { font-size: 11.5px; color: var(--muted); margin-top: 4px; }
</style>
<script src="assets/loader.js?v=<?= @filemtime(__DIR__ . '/assets/loader.js') ?>"></script><?php /* v97: the loading icon */ ?>
<script>
  const PW_MIN = <?= password_min_length() ?>;
  const PW_RULES = {
    len: (v) => v.length >= PW_MIN,
    upper: (v) => /[A-Z]/.test(v),
    lower: (v) => /[a-z]/.test(v),
    digit: (v) => /[0-9]/.test(v),
    symbol: (v) => /[^A-Za-z0-9]/.test(v)
  };

  function checkStrength(){
    const v = document.getElementById('new_password').value;

    // Build list of missing requirements in order
    const missing = [];
    if (!PW_RULES.len(v)) missing.push('at least ' + PW_MIN + ' characters');
    if (!PW_RULES.upper(v)) missing.push('at least 1 uppercase letter');
    if (!PW_RULES.lower(v)) missing.push('at least 1 lowercase letter');
    if (!PW_RULES.digit(v)) missing.push('at least 1 number');
    if (!PW_RULES.symbol(v)) missing.push('at least 1 special character');

    const hint = document.getElementById('pwHint');
    if (!v) {
      hint.textContent = '';
    } else if (missing.length === 0) {
      hint.textContent = '✓ Password meets all requirements';
      hint.style.color = '#16a34a';
    } else {
      // Show only the first missing requirement
      hint.textContent = 'Add ' + missing[0];
      hint.style.color = '#dc2626';
    }

    checkMatch();
    return missing.length === 0;
  }

  function checkMatch(){
    const pw = document.getElementById('new_password').value;
    const cf = document.getElementById('confirm_password');
    const out = document.getElementById('pwMatch');
    if (!cf || !out) return true;
    if (!cf.value){ out.textContent = ''; return false; }
    const same = (pw === cf.value);
    out.textContent = same ? '✓ Passwords match' : '✕ Passwords do not match';
    out.style.color = same ? '#16a34a' : '#dc2626';
    return same;
  }

  // The eye beside Password and Confirm password shows what was typed; clicking again hides it
  document.querySelectorAll('.pw-eye').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = document.getElementById(b.dataset.for); if (!i) return;
      var show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      var what = b.dataset.for === 'new_password' ? 'password' : 'confirm password';
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      b.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + what);
      b.title = (show ? 'Hide ' : 'Show ') + what;
      i.focus();
      try { var n = i.value.length; i.setSelectionRange(n, n); } catch (e) {}
    });
  });

  // Block the submit unless the password is genuinely strong
  (function(){
    const form = document.querySelector('form[method="POST"]');
    if (!form) return;
    form.addEventListener('submit', function(e){
      const pwField = document.getElementById('new_password');
      if (!pwField) return;
      const v = pwField.value;
      const missing = [];
      if (!PW_RULES.len(v)) missing.push('at least ' + PW_MIN + ' characters');
      if (!PW_RULES.upper(v)) missing.push('at least 1 uppercase letter');
      if (!PW_RULES.lower(v)) missing.push('at least 1 lowercase letter');
      if (!PW_RULES.digit(v)) missing.push('at least 1 number');
      if (!PW_RULES.symbol(v)) missing.push('at least 1 special character');
      if (missing.length > 0) {
        e.preventDefault();
        alert('Password must have ' + missing.join(', ') + '.');
      }
    });
  })();
</script>
</head>
<body>
<div class="login-wrap">
  <img src="assets/procurement-logo.png" class="login-avatar" alt="ASCOT Procurement Unit">
  <h1 class="login-title">Reset your password</h1>
  <p class="login-sub">
    <?= $step === 'newpw' ? 'Code verified — set your new password' : ($step === 'verify' ? ((($_SESSION['reset_method'] ?? 'email') === 'SMS') ? 'Enter the 6-digit code sent to your phone' : 'Enter the 6-digit code sent to your email') : 'We\'ll send you a verification code') ?>
  </p>

  <div class="login-card">
    <?php if ($lockedNotice !== '' && $step === 'lookup'): ?>
      <div class="flash flash-error" id="lockedNotice" style="margin-bottom: 12px;">🔒 <?= e($lockedNotice) ?>
        Reset your password below to unlock it right away, or wait and sign in again after that time.</div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="flash flash-error" style="margin-bottom: 12px;"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($notice): ?>
      <div class="flash flash-success" style="margin-bottom: 12px;"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($step === 'lookup'): ?>
      <?php if ($way === 'email'): /* v121: the email first */ ?>
      <p class="step-note">
        Enter the email address on your account. The verification code will be sent to your email.
      </p>
      <form method="POST" autocomplete="off">
      <?= csrf_field() /* v79 */ ?>
        <input type="hidden" name="step" value="lookup">
        <input type="hidden" name="way" value="email">
        <div class="field">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" class="input"
                 placeholder="you@example.com"
                 autocomplete="off" required autofocus>
        </div>
        <button type="submit" class="btn-signin">Get verification code</button>
      </form>
      <div class="login-links" id="fpOtherWayRow" style="margin-top: 14px;">
        <span>Didn't receive a code?</span>
        <a href="forgot-password.php?way=mobile" id="fpOtherWay">Try another way</a>
      </div>
      <?php else: /* v121: "Try another way" — the mobile number */ ?>
      <p class="step-note">
        Enter the mobile number on your account.
        <?php if (sms_gateway_enabled()): ?>
          The verification code will be sent to it by text message (SMS).
        <?php else: /* v110 */ ?>
          Sending the code by text message is not set up yet.
        <?php endif; ?>
      </p>
      <form method="POST" autocomplete="off">
      <?= csrf_field() /* v79 */ ?>
        <input type="hidden" name="step" value="lookup">
        <input type="hidden" name="way" value="mobile">
        <div class="field">
          <label for="mobile">Mobile number</label>
          <input type="tel" id="mobile" name="mobile" class="input"
                 placeholder="09171234567"
                 pattern="09[0-9]{9}" maxlength="16" inputmode="numeric"
                 autocomplete="off" required autofocus>
        </div>
        <button type="submit" class="btn-signin">Get verification code</button>
      </form>
      <div class="login-links" id="fpOtherWayRow" style="margin-top: 14px;">
        <span>Didn't receive a code?</span>
        <a href="forgot-password.php?way=email" id="fpOtherWay">Try another way</a>
      </div>
      <?php endif; ?>

    <?php elseif ($step === 'newpw'): /* v107: the code was verified — now the new password */ ?>
      <div class="flash flash-success" id="fpVerified" style="margin-bottom: 14px;">✓ Code verified for account <strong><?= e($_SESSION['reset_pending']['username'] ?? '') ?></strong>. Set your new password.</div>
      <form method="POST" autocomplete="off">
      <?= csrf_field() /* v79 */ ?>
        <input type="hidden" name="step" value="newpw">
        <div class="field">
          <label for="new_password">New password</label>
          <div class="pw-wrap">
            <input type="password" id="new_password" name="new_password" class="input"
                   placeholder="At least <?= password_min_length() ?> characters" required minlength="<?= password_min_length() ?>"
                   autocomplete="new-password" oninput="checkStrength()" autofocus>
            <button type="button" class="pw-eye" data-for="new_password" aria-label="Show password" aria-pressed="false" title="Show password">
              <svg class="eye-on" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <div class="field-hint" id="pwHint"></div>
        </div>
        <div class="field">
          <label for="confirm_password">Confirm new password</label>
          <div class="pw-wrap">
            <input type="password" id="confirm_password" name="confirm_password" class="input"
                   placeholder="Retype your new password" required minlength="<?= password_min_length() ?>"
                   autocomplete="new-password" oninput="checkMatch()">
            <button type="button" class="pw-eye" data-for="confirm_password" aria-label="Show confirm password" aria-pressed="false" title="Show confirm password">
              <svg class="eye-on" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <div class="field-hint" id="pwMatch"></div>
        </div>
        <button type="submit" class="btn-signin">Reset password</button>
      </form>
      <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:14px;">
        <a href="forgot-password.php?step=lookup" class="btn btn-ghost" id="fpBack" style="padding:8px 16px; font-size:13px; text-decoration:none;">← Start over</a>
        <a href="forgot-password.php?cancel=1" class="btn btn-ghost" id="fpCancel" style="padding:8px 16px; font-size:13px; text-decoration:none;"
           title="Stop here — your password is not changed">Cancel</a>
      </div>

    <?php else: /* verify: the code */ ?>
      <?php if ($otpMailed): ?>
        <div style="background: var(--navy-50); border: 1.5px solid #66a3bf; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; text-align: center;">
          <div style="font-size: 26px; line-height: 1; margin-bottom: 6px;">
            <?= ($_SESSION['reset_method'] ?? 'email') === 'SMS' ? '📱' : '✉️' ?>
          </div>
          <div style="font-family: 'Space Grotesk', sans-serif; font-size: 14px; font-weight: 700; color: #2f578a; margin-bottom: 4px;">
            <?= ($_SESSION['reset_method'] ?? 'email') === 'SMS' ? 'Check your phone' : 'Check your email' ?>
          </div>
          <div style="font-size: 12.5px; color: var(--muted); line-height: 1.6;">
            We sent a 6-digit code to
            <strong style="color: var(--ink);">
              <?= ($_SESSION['reset_method'] ?? 'email') === 'SMS' 
                  ? (e($_SESSION['reset_pending']['mobile'] ?? 'your mobile number')) 
                  : (e($_SESSION['reset_pending']['email'] ?? 'your email address')) ?>
            </strong>.<br>
            It expires in 10 minutes.
            <?php if (($_SESSION['reset_method'] ?? 'email') === 'email'): ?>
              If it isn't there, check your spam folder.
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <p class="step-note">
        For account <strong><?= e($_SESSION['reset_pending']['username'] ?? '') ?></strong>. The code expires in 10 minutes.
      </p>
      <form method="POST" autocomplete="off">
      <?= csrf_field() /* v79 */ ?>
        <input type="hidden" name="step" value="verify">
        <div class="field">
          <label for="code" style="text-align:center;">Verification code</label>
          <input type="text" id="code" name="code" class="otp-input"
                 placeholder="••••••" maxlength="6" pattern="\d{6}" inputmode="numeric"
                 autocomplete="one-time-code" required autofocus>
        </div>
        <?php /* v107: the new password is asked on the next step, once this code is verified */ ?>
        <button type="submit" class="btn-signin">Verify code</button>
      </form>

      <div class="login-links" style="margin-top: 12px;">
        Need a new code? <a href="forgot-password.php?resend=1">Get another</a>
        <span style="margin: 0 8px; opacity: 0.4;">·</span>
        <a href="forgot-password.php?step=lookup">Start over</a>
      </div>
      <?php /* v104: go back, or stop here — the password stays as it is */ ?>
      <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:14px;">
        <a href="forgot-password.php?step=lookup" class="btn btn-ghost" id="fpBack" style="padding:8px 16px; font-size:13px; text-decoration:none;">← Back</a>
        <a href="forgot-password.php?cancel=1" class="btn btn-ghost" id="fpCancel" style="padding:8px 16px; font-size:13px; text-decoration:none;"
           title="Stop here — your password is not changed">Cancel</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="login-links">
    <a href="index.php">← Back to sign in</a>
  </div>
</div>
<script>
(function () {
  var el = document.getElementById('mobile');
  if (!el) return;
  function norm(v) {
    var d = String(v || '').replace(/\D/g, '');
    if (d.length === 13 && d.indexOf('630') === 0) d = d.slice(2);
    if (d.length >= 12 && d.indexOf('63') === 0) d = '0' + d.slice(2);
    if (d.length === 10 && d.charAt(0) === '9') d = '0' + d;
    return d.slice(0, 11);
  }
  el.addEventListener('input', function () { el.value = norm(el.value); });
  el.addEventListener('paste', function () { setTimeout(function () { el.value = norm(el.value); }, 0); });
})();
</script>
</body>
</html>
