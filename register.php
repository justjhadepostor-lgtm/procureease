<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/otp.php';
require_once __DIR__ . '/config/phone.php';
require_once __DIR__ . '/config/twilio.php';
ensure_admin_tables();

if (is_logged_in()) { header('Location: user-dashboard.php'); exit; }

$step  = $_GET['step'] ?? 'form';
$error = '';
$notice = '';
require_once __DIR__ . '/config/google-oauth.php';
$googleReady = google_is_configured();

$form = $_SESSION['pending_register'] ?? [
    'first_name' => '', 'middle_initial' => '', 'last_name' => '',
    'suffix'     => '',   // v104: optional, like the middle initial
    'sex'        => '', 'birthday'       => '',
    'full_name'  => '', 'position'       => '', 'email'     => '',
    'contact'    => '', 'dept_code'      => '', 'sub_unit'  => '',
    'dept_other' => '',   // v99: "Others" — the department typed
];
$form += ['suffix' => ''];   // v104 (a form kept from before has none)
require_once __DIR__ . '/config/dept-other.php';
require_once __DIR__ . '/config/name-suffix.php';   // v104: Suffix

// v81: the Bachelor / Master programs of each school that has them (config/academic-units.php):
// [school code => [[program code, "BSED – Bachelor of Secondary Education (5 majors)"], …]].
// An account is for one program, or (left empty) for the whole school.
require_once __DIR__ . '/config/academic-units.php';
require_once __DIR__ . '/config/admin-schema.php';
ensure_admin_tables();
$deptUnits = [];
foreach (au_all() as $__code => $__r) {
    if (!empty($__r['parent_code']) || !au_has_units((string)$__code)) continue;
    foreach (au_programs_of((string)$__code) as $__pc => $__pn) {
        $__n = count(au_units_of_program((string)$__code, (string)$__pc));
        $__maj = count(array_filter(au_units_of_program((string)$__code, (string)$__pc), fn($r) => !empty($r['major_name'])));
        $deptUnits[(string)$__code][] = [(string)$__pc, $__pc . ' – ' . $__pn . ($__maj ? " ($__maj majors)" : '')];
    }
}

// ─── Departments offered on this form ──────────────────────────
// Only the units the Procurement Unit keeps ACTIVE are offered to new
// registrants. A deactivated unit stays in the database untouched — its PPMPs,
// APPs, Non-PS lists, Annexes and every past record it submitted are kept — it
// simply stops being an option for new accounts.
// Loaded before the POST handler so the submitted code can be verified against
// this exact list: the browser field is only the convenience layer.
$depts = [];
try {
    $depts = db()->query("SELECT code, name, dept_group
                            FROM departments
                           WHERE COALESCE(status, 'active') = 'active'
                             AND (parent_code IS NULL OR parent_code = '')   -- v81: schools and offices; programs are chosen below
                        ORDER BY display_order, name")->fetchAll();
} catch (Throwable $t) {
    // status column not migrated yet — fall back to the full list
    $depts = db()->query('SELECT code, name, dept_group FROM departments ORDER BY display_order, name')->fetchAll();
}
// Lookup keyed by lower-cased code, so a submitted value is matched the same
// way MySQL matches it and is then stored in the department's own casing.
$deptByCode = [];
foreach ($depts as $d) { $deptByCode[strtolower($d['code'])] = $d; }

/** Create the pending account, notify the admins, and send the person to sign-in. */
function create_pending_account(array $pending, bool $viaGoogle = false): void {
    // The Procurement Unit assigns the real username on approval. Until then we
    // park a unique placeholder, since the column has to stay unique.
    // v99: "Others" — no department yet; what was typed is kept for the Procurement Unit
    $__other = ($pending['dept_code'] ?? '') === DEPT_OTHER;
    if ($__other) { $pending['dept_code'] = null; }
    $placeholder = 'pending-' . ($__other ? 'other' : strtolower($pending['dept_code'])) . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

    $ins = db()->prepare('INSERT INTO users (username, password_hash, full_name, position, email, contact, role, status, dept_code, sub_unit)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([
        $placeholder,          $pending['password_hash'],
        $pending['full_name'], $pending['position'],
        $pending['email'],     $pending['contact'],
        'user',                'pending',
        $pending['dept_code'], ($pending['sub_unit'] ?? '') ?: null,
    ]);
    $newUserId = (int)db()->lastInsertId();
    // v104: the suffix (optional)
    if (trim((string)($pending['suffix'] ?? '')) !== '') {
        name_suffix_column();
        try { db()->prepare('UPDATE users SET suffix = ? WHERE id = ?')->execute([(string)$pending['suffix'], $newUserId]); } catch (Throwable $t) {}
    }
    if ($__other) {
        dept_other_column();
        try { db()->prepare('UPDATE users SET dept_other = ? WHERE id = ?')->execute([(string)($pending['dept_other'] ?? ''), $newUserId]); } catch (Throwable $t) {}
    }

    // Keep the separate name parts, sex and birthday alongside the display name
    try {
        db()->prepare('UPDATE users SET first_name = ?, middle_initial = ?, last_name = ?, sex = ?, birthday = ? WHERE id = ?')
            ->execute([
                $pending['first_name']     ?? '',
                $pending['middle_initial'] ?? '',
                $pending['last_name']      ?? '',
                $pending['sex']            ?? '',
                ($pending['birthday'] ?? '') ?: null,
                $newUserId,
            ]);
    } catch (Throwable $t) { /* columns added by ensure_admin_tables() */ }

    // Keep proof of when this account accepted the Terms and Conditions,
    // and the name parts / personal details captured at registration
    try {
        db()->prepare('UPDATE users SET terms_accepted_at = NOW(), first_name = ?, middle_initial = ?, last_name = ?, sex = ?, birthday = ? WHERE id = ?')
            ->execute([
                $pending['first_name']     ?? '',
                $pending['middle_initial'] ?? '',
                $pending['last_name']      ?? '',
                $pending['sex']            ?? '',
                ($pending['birthday'] ?? '') ?: null,
                $newUserId,
            ]);
    } catch (Throwable $t) { /* columns added by ensure_admin_tables() */ }

    require_once __DIR__ . '/config/notify.php';
    notify_by_role('admin', 'submission',
        "New account pending approval: {$pending['full_name']}",
        'Dept: ' . ($__other ? 'Others — ' . ($pending['dept_other'] ?? '') : $pending['dept_code']) . " · Email: {$pending['email']} — assign a username when you approve.",
        'pending-users.php');

    unset($_SESSION['pending_register'], $_SESSION['last_otp_preview'],
          $_SESSION['last_otp_sent'], $_SESSION['register_method'], $_SESSION['google_signup']);

    $_SESSION['login_success'] = $viaGoogle
        ? "Your Google account was verified. Your account is awaiting approval — the Procurement Unit will email you your username."
        : "Email verified. Your account is awaiting approval — the Procurement Unit will email you your username once it is activated.";
    header('Location: index.php');
    exit;
}

// ─── Google sign-up: the address is already proven, so no OTP is needed ───
$googleSignup = $_SESSION['google_signup'] ?? null;
if ($googleSignup) {
    $form['email']     = $googleSignup['email'];
    if ($form['first_name'] === '' && $form['last_name'] === '' && !empty($googleSignup['full_name'])) {
        $parts = preg_split('/\s+/', trim($googleSignup['full_name']));
        // Whatever Google supplies is put through the same rule, so the
        // form never opens already holding something it will refuse.
        $__clean = function ($v) {
            return trim(preg_replace("/[^\p{L} .'\-]/u", '', (string)$v));
        };
        $form['first_name'] = $__clean(array_shift($parts) ?: '');
        $form['last_name']  = $__clean($parts ? array_pop($parts) : '');
    }
}

/**
 * Is this a person's name?
 *
 * Letters, and the marks that genuinely appear inside names: a space in
 * "Dela Cruz", a hyphen in "Ana-Marie", an apostrophe in "D'Souza", a
 * full stop in "Jr.". \p{L} covers ñ and accented letters, which A-Z
 * would refuse. It has to start with a letter, so " 5" or ".x" is out.
 * Digits and every other symbol are refused anywhere in the name.
 */
function reg_name_ok(string $v): bool {
    return $v !== '' && preg_match("/^\p{L}[\p{L} .'\-]*$/u", $v) === 1;
}

/** A middle initial: one or two letters. The full stop is added later. */
function reg_initial_ok(string $v): bool {
    return preg_match('/^\p{L}{1,2}$/u', $v) === 1;
}

require_once __DIR__ . '/config/positions.php';   // reg_positions() — the Position list (kept on User Management)
$__posChoice = null;   // what was picked in the Position list (set when the form is posted)
$__posOther  = '';

/** A Philippine mobile number in 09XXXXXXXXX form. */
function reg_contact_ok(string $v): bool {
    return ph_mobile_ok($v);
}

/* v123: the code goes to the email first; when it cannot be sent there, to the
   contact number by text (SMS Gateway) — and "Try another way" switches between them.
   $first: 'email' or 'SMS'. Returns [method, result, fellBack]. */
function reg_can_sms(string $mobile): bool {
    return $mobile !== '' && sms_gateway_enabled();
}
function reg_send_code(string $first, string $email, string $mobile): array {
    $order = $first === 'SMS' ? ['SMS', 'email'] : ['email', 'SMS'];
    $last = ['email', ['sent' => false, 'code' => ''], false];
    foreach ($order as $i => $way) {
        if ($way === 'SMS' && !reg_can_sms($mobile)) continue;
        $r = $way === 'SMS' ? send_otp_via_sms($mobile, $email, 'register') : send_new_otp($email, 'register');
        if (empty($r['sent'])) error_log('register ' . $way . ' OTP failed: ' . ($r['error'] ?? 'not sent'));
        $last = [$way, $r, $i > 0];
        if (!empty($r['sent'])) break;
    }
    return $last;
}
/** Keeps what was sent for the code step. */
function reg_keep_sent(string $method, array $result): void {
    $sent = !empty($result['sent']);
    $_SESSION['last_otp_preview'] = ($method === 'SMS' && $sent) ? '' : ($result['code'] ?? '');
    $_SESSION['last_otp_sent']    = $sent;
    $_SESSION['register_method']  = $method;
}

// ─── Step 1: form submission — validate + send OTP ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'form') {
    foreach ($form as $k => $v) $form[$k] = trim($_POST[$k] ?? '');
    // Enforce field limits server-side too (in case the browser limit is bypassed)
    $form['first_name']     = mb_substr($form['first_name'], 0, 20);
    $form['last_name']      = mb_substr($form['last_name'],  0, 20);
    $form['middle_initial'] = mb_substr($form['middle_initial'], 0, 2);
    $form['suffix']         = mb_substr($form['suffix'], 0, 5);
    $form['contact']        = ph_mobile_local($form['contact']);
    // Position: one picked from the list, or "Others" with the position typed
    $__posChoice = trim((string)($_POST['position_choice'] ?? ''));
    $__posOther  = mb_substr(preg_replace('/\s+/u', ' ', trim((string)($_POST['position_other'] ?? ''))), 0, 100);
    if ($__posChoice === 'Others')                          $form['position'] = $__posOther;
    elseif (in_array($__posChoice, reg_positions(), true))     $form['position'] = $__posChoice;
    else                                                    $form['position'] = '';
    // What the person actually typed, before anything is tidied. The
    // checks below read these, so the answer is "that is not allowed"
    // rather than a field that silently lost a character.
    $__typedFirst   = $form['first_name'];
    $__typedLast    = $form['last_name'];
    $__typedContact = ph_mobile_local((string)($_POST['contact'] ?? ''));
    // A middle initial is written in capitals. A full stop somebody adds
    // themselves is dropped here and put back when the name is assembled.
    $__typedMiddle  = rtrim($form['middle_initial'], '.');
    $form['middle_initial'] = mb_strtoupper($__typedMiddle);
    // v103: the name as it is written — "jhade postor" / "JHADE POSTOR" → "Jhade Postor"
    $form['first_name'] = name_case($form['first_name']);
    $form['last_name']  = name_case($form['last_name']);
    // v104: the suffix as it is written — "jr" → "Jr.", "iii" → "III" (null: not a suffix)
    $__typedSuffix = $form['suffix'];
    $__suffix = name_suffix_norm($__typedSuffix);
    if ($__suffix !== null) $form['suffix'] = $__suffix;
    // The searchable department field posts a code. Store it in the department's
    // own casing (e.g. "soit" typed by hand becomes "SoIT") so it matches the
    // departments table exactly.
    $__deptKey = strtolower(trim($form['dept_code']));
    if (isset($deptByCode[$__deptKey])) $form['dept_code'] = $deptByCode[$__deptKey]['code'];
    // v99: "Others" — the department is typed (kept only when Others is chosen)
    $__deptIsOther = $form['dept_code'] === DEPT_OTHER;
    $form['dept_other'] = $__deptIsOther ? mb_substr(preg_replace('/\s+/u', ' ', trim((string)($form['dept_other'] ?? ''))), 0, 150) : '';
    if ($__deptIsOther) $form['sub_unit'] = '';
    $pw      = $_POST['password']         ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $agreed  = !empty($_POST['agree_terms']);

    // The rest of the system still works off a single display name.
    // The field itself holds one or two capital letters and nothing else,
    // so that is what is stored and what the box shows if the form comes
    // back. The full stop belongs to the display name, so it is put on
    // here rather than kept in the field.
    $mi = trim($form['middle_initial']);
    $form['full_name'] = trim(preg_replace('/\s+/', ' ',
        $form['first_name'] . ' ' . ($mi !== '' ? $mi . '.' : '') . ' ' . $form['last_name'] . ' ' . $form['suffix']));
    $form['agree_terms'] = $agreed ? '1' : '';

    if (!$agreed) {
        $error = 'Please read and accept the Terms and Conditions to continue.';
    } elseif (!$form['first_name'] || !$form['last_name'] || !$form['email'] || !$form['dept_code']) {
        $error = 'Please fill in all required fields.';
    } elseif (!reg_name_ok($__typedFirst)) {
        $error = 'The first name can only contain letters. Numbers are not allowed.';
    } elseif ($__typedMiddle !== '' && !reg_initial_ok($__typedMiddle)) {
        $error = 'The middle initial can only be one or two letters.';
    } elseif (!reg_name_ok($__typedLast)) {
        $error = 'The last name can only contain letters. Numbers are not allowed.';
    } elseif ($__suffix === null) {
        $error = NAME_SUFFIX_MSG;
    } elseif (trim((string)($_POST['contact'] ?? '')) === '') {
        $error = 'Please enter your contact number (11 digits, for example 09171234567).';   // v104: required
    } elseif ($__typedContact === '' || !reg_contact_ok($__typedContact)) {
        $error = 'The contact number must be a Philippine mobile number, for example 09171234567.';
    } elseif ($__deptIsOther && $form['dept_other'] === '') {
        $error = 'You chose "Others" for Department — please type your department.';
    } elseif (!$__deptIsOther && !isset($deptByCode[strtolower($form['dept_code'])])) {
        // Typed text alone is never accepted — the value has to be one of the
        // active departments on record.
        $error = 'Please pick your department from the search results. If your unit is not listed, contact the Procurement Unit so they can add it.';
    } elseif ($__posChoice !== '' && $__posChoice !== 'Others' && !in_array($__posChoice, reg_positions(), true)) {
        $error = 'Please pick your position from the list, or choose "Others" and type it.';
    } elseif ($__posChoice === 'Others' && $__posOther === '') {
        $error = 'You chose "Others" for Position — please type your position.';
    } elseif ($form['position'] === '') {
        $error = 'Please choose your position from the list, or choose "Others" and type it.';   // v104: required
    } elseif (!$form['sex'] || !in_array($form['sex'], ['Male', 'Female'], true)) {
        $error = 'Please select your sex.';
    } elseif (!$form['birthday'] || !strtotime($form['birthday'])) {
        $error = 'Please enter your birthday.';
    } elseif (strtotime($form['birthday']) > strtotime('2005-12-31')) {
        $error = 'Please enter a valid birthday.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($form['sub_unit'] !== '' && !in_array($form['sub_unit'], array_column($deptUnits[$form['dept_code']] ?? [], 0), true)) {
        // v81: a program of the chosen school, or empty for the whole school
        $error = 'Please pick your Bachelor / Master from the list, or leave it on "Whole school".';
    } elseif (mb_strlen($pw) < password_min_length()) {
        $error = 'Password must be at least ' . password_min_length() . ' characters.';
    } elseif (!preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw)
              || !preg_match('/[0-9]/', $pw) || !preg_match('/[^A-Za-z0-9]/', $pw)) {
        $error = 'Password must include an uppercase letter, a lowercase letter, a number, and a symbol.';
    } elseif ($pw !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $check = db()->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$form['email']]);
        if ($check->fetch()) {
            $error = 'That email address is already registered.';
        } else {
            $__taken = false;
            try {
                $allC = db()->query("SELECT contact FROM users WHERE contact IS NOT NULL AND contact <> ''");
                foreach ($allC->fetchAll(PDO::FETCH_COLUMN) as $__c) {
                    if (ph_mobile_local((string)$__c) === $form['contact']) { $__taken = true; break; }
                }
            } catch (Throwable $t) {}
            if ($__taken) {
                $error = 'That contact number is already registered. Use a different number, or reset your password if this is your account.';
            } else {
            // Cache the form (including hashed password) so the next step can create the user
            $form['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            $_SESSION['pending_register'] = $form;

            // Google already proved this address — create the account straight away
            if ($googleSignup && strcasecmp($form['email'], $googleSignup['email']) === 0) {
                create_pending_account($form, true);
            }

            // v123: the email first; the contact number by text when the email cannot be sent
            [$sentMethod, $result, $__fell] = reg_send_code('email', $form['email'], $form['contact']);
            reg_keep_sent($sentMethod, $result);
            if ($__fell && !empty($result['sent'])) {
                $_SESSION['register_notice'] = 'The code could not be sent to your email, so it was sent to your mobile number instead.';
            }

            header('Location: register.php?step=verify');
            exit;
            }
        }
    }
}

// ─── Step 2: OTP submitted — verify + create user ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'verify') {
    $code = trim($_POST['code'] ?? '');
    if (empty($_SESSION['pending_register'])) {
        $error = 'Your session expired. Please start again.';
        $step = 'form';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Please enter the 6-digit verification code.';
        $step  = 'verify';
    } else {
        $pending = $_SESSION['pending_register'];
        if (verify_otp($pending['email'], $code, 'register')) {
            create_pending_account($pending);
        } else {
            $error = 'That code is invalid or has expired. Try again, or resend a new one.';
            $step  = 'verify';
        }
    }
}

// ─── Resend link ──────────────────────────────────────────
if (($_GET['resend'] ?? '') === '1' && !empty($_SESSION['pending_register'])) {
    $email = $_SESSION['pending_register']['email'];
    $mobile = ph_mobile_local((string)($_SESSION['pending_register']['contact'] ?? ''));
    // v123: "Try another way" (via=sms / via=email); otherwise the same way as before
    $__via  = (string)($_GET['via'] ?? '');
    $__want = $__via === 'sms' ? 'SMS' : ($__via === 'email' ? 'email' : (($_SESSION['register_method'] ?? 'email') === 'SMS' ? 'SMS' : 'email'));
    [$sentMethod, $result, $__fell] = reg_send_code($__want, $email, $mobile);
    reg_keep_sent($sentMethod, $result);
    if (!empty($result['sent'])) {
        if ($__fell) {
            $notice = $sentMethod === 'SMS'
                ? 'The code could not be sent to your email, so a new code was sent to your mobile number instead.'
                : "The code could not be sent to your mobile number, so a new code was sent to $email instead.";
        } else {
            $notice = $sentMethod === 'SMS'
                ? 'A new code was sent to your mobile number.'
                : "A new code was sent to $email.";
        }
    }
    $step = 'verify';
}

if ($step === 'verify' && empty($_SESSION['pending_register'])) {
    $step = 'form';
}
// v123: said once, after the code step opens
if ($notice === '' && !empty($_SESSION['register_notice']) && $step === 'verify') $notice = (string)$_SESSION['register_notice'];
unset($_SESSION['register_notice']);

// ($depts is loaded near the top of this file, before the POST handler.)
$pendingEmail = $_SESSION['pending_register']['email'] ?? '';
$pendingMobile = ph_mobile_local((string)($_SESSION['pending_register']['contact'] ?? ''));
$regMethod    = $_SESSION['register_method'] ?? 'email';
$otpPreview   = $_SESSION['last_otp_preview'] ?? '';
$otpMailed    = $_SESSION['last_otp_sent']    ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $step === 'verify' ? 'Verify Email' : 'Create Account' ?> — ProcureEase</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<style>
  body { background: var(--page); min-height: 100vh; display: grid; place-items: center; padding: 24px 24px 40px; }
  .login-wrap { width: 100%; max-width: <?= $step === 'verify' ? '440' : '540' ?>px; text-align: center; }
  .login-avatar {
    width: <?= $step === 'verify' ? '140' : '108' ?>px;
    height: <?= $step === 'verify' ? '140' : '108' ?>px;
    margin: 0 auto -4px; object-fit: contain; display: block;
  }
  .login-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 26px; font-weight: 700;
    color: var(--ink); letter-spacing: -0.02em; margin-bottom: 6px;
  }
  .login-sub { font-size: 14px; color: var(--muted); margin-bottom: 22px; }
  .login-card {
    background: white; border: 1px solid var(--border);
    border-radius: 16px; padding: 28px 28px 24px;
    text-align: left;
  }
  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
  .form-grid .full { grid-column: 1 / -1; }
  .field { margin: 0; }
  .field label { display: block; font-size: 12.5px; font-weight: 600; color: var(--ink); margin-bottom: 5px; }
  .field label .req { color: var(--navy-900); }
  .input, .select {
    width: 100%; padding: 11px 13px;
    border: 1px solid var(--border); border-radius: 10px;
    background: white; font-size: 14px;
    font-family: inherit; color: var(--ink);
  }
  .select { cursor: pointer; }
  .input:focus, .select:focus {
    outline: none; border-color: var(--navy-500);
    box-shadow: 0 0 0 3px rgba(66, 116, 217, 0.15);
  }
  .field-hint { font-size: 11.5px; color: var(--muted); margin-top: 4px; }
  /* Password: the eye inside the box shows or hides what was typed */
  .pw-wrap { position: relative; }
  .pw-wrap .input { padding-right: 44px; }
  .pw-eye { position: absolute; top: 50%; right: 6px; transform: translateY(-50%); display: grid; place-items: center;
    width: 32px; height: 32px; padding: 0; border: none; border-radius: 8px; background: none; color: var(--muted); cursor: pointer; }
  .pw-eye:hover { background: var(--navy-50); color: var(--ink); }
  .pw-eye:focus-visible { outline: 2px solid var(--navy-500); outline-offset: 1px; }
  .pw-eye[aria-pressed="true"] { color: var(--ink); }
  .pw-eye .eye-off, .pw-eye[aria-pressed="true"] .eye-on { display: none; }   /* one icon at a time: open eye = show, crossed = hide */
  .pw-eye[aria-pressed="true"] .eye-off { display: block; }

  /* ─── Searchable department picker ─── */
  .sr-only {
    position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
    overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
  }
  .combo { position: relative; }
  .combo-input { padding-right: 38px; }
  .combo-input::placeholder { color: var(--muted); opacity: 0.85; }
  .combo.is-chosen .combo-input { font-weight: 600; }
  .combo-toggle {
    position: absolute; top: 50%; right: 6px; transform: translateY(-50%);
    display: grid; place-items: center;
    width: 28px; height: 28px; padding: 0;
    background: none; border: none; border-radius: 7px;
    color: var(--muted); cursor: pointer;
    transition: background 0.15s, color 0.15s, transform 0.2s;
  }
  .combo-toggle:hover { background: var(--navy-50); color: var(--ink); }
  .combo.is-open .combo-toggle { transform: translateY(-50%) rotate(180deg); }
  .combo-list {
    display: none; position: absolute; z-index: 60;
    top: calc(100% + 5px); left: 0; right: 0;
    max-height: 244px; overflow-y: auto;
    margin: 0; padding: 5px; list-style: none;
    background: #fff; border: 1px solid var(--border); border-radius: 11px;
    box-shadow: 0 12px 32px rgba(15, 43, 74, 0.16);
    overscroll-behavior: contain;
  }
  .combo.is-open .combo-list { display: block; }
  .combo-opt {
    display: flex; align-items: baseline; gap: 9px;
    padding: 9px 11px; border-radius: 8px;
    font-size: 13.5px; color: var(--ink); cursor: pointer;
    scroll-margin: 5px;
  }
  .combo-opt:hover, .combo-opt.is-active { background: var(--navy-50); }
  .combo-opt[aria-selected="true"] { background: var(--navy-50); font-weight: 600; }
  .combo-opt mark { background: #fde68a; color: inherit; padding: 0 1px; border-radius: 2px; }
  .combo-code {
    flex-shrink: 0; min-width: 62px;
    font-family: 'Space Grotesk', sans-serif; font-weight: 700;
    font-size: 12px; color: var(--navy-900);
  }
  .combo-name { flex: 1; min-width: 0; line-height: 1.35; }
  .combo-group { font-size: 10.5px; color: var(--muted); margin-top: 2px; }
  .combo-empty { padding: 16px 12px; text-align: center; font-size: 12.5px; color: var(--muted); line-height: 1.6; }
  .combo-empty strong { display: block; color: var(--ink); font-size: 13px; margin-bottom: 3px; }
  .combo-invalid .combo-input { border-color: var(--orange-500); }
  /* ─── Terms and Conditions ─── */
  .terms-agree { margin: 4px 0 18px; }
  .terms-check { display: flex; gap: 10px; align-items: flex-start; font-size: 12.5px; line-height: 1.55; color: var(--ink); cursor: pointer; }
  .terms-check input[type="checkbox"] { width: 16px; height: 16px; margin-top: 1px; flex-shrink: 0; accent-color: #2f578a; cursor: pointer; }
  .terms-check a { color: #2f578a; font-weight: 600; text-decoration: underline; }
  .terms-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
  .terms-modal { background: #fff; border-radius: 16px; width: 100%; max-width: 680px; max-height: 88vh; display: flex; flex-direction: column; box-shadow: 0 24px 60px rgba(0,0,0,0.3); overflow: hidden; }
  .terms-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 20px 24px 14px; border-bottom: 1px solid var(--navy-50); }
  .terms-title { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 19px; color: var(--ink); }
  .terms-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }
  .terms-x { background: none; border: none; font-size: 26px; line-height: 1; color: var(--muted); cursor: pointer; padding: 0 4px; }
  .terms-x:hover { color: var(--ink); }
  .terms-body { padding: 18px 24px; overflow-y: auto; font-size: 13px; line-height: 1.65; color: var(--ink); }
  .terms-body h4 { font-family: 'Space Grotesk', sans-serif; font-size: 13.5px; color: #2f578a; margin: 16px 0 5px; font-weight: 700; }
  .terms-body h4:first-of-type { margin-top: 4px; }
  .terms-body p { margin: 0 0 4px; }
  .terms-eff { font-size: 11.5px; color: var(--muted); font-style: italic; padding-bottom: 10px; border-bottom: 1px dashed var(--navy-50); margin-bottom: 6px; }
  .terms-close-note { margin-top: 18px; padding-top: 12px; border-top: 1px dashed var(--navy-50); font-weight: 600; color: var(--ink); }
  .terms-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 24px; border-top: 1px solid var(--navy-50); background: #f7fbfd; }
  .btn-terms-ghost { background: #fff; color: #2f578a; border: 1.5px solid #66a3bf; border-radius: 9px; padding: 9px 20px; font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; }
  .btn-terms-ghost:hover { border-color: #2f578a; }
  .btn-terms-primary { background: #2f578a; color: #fff; border: none; border-radius: 9px; padding: 9px 24px; font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; }
  .btn-terms-primary:hover { background: #093c5d; }

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

  /* ─── Password strength ─── */
  .pw-meter { margin-top: 8px; }
  .pw-bars { display: flex; gap: 4px; }
  .pw-bar { flex: 1; height: 5px; border-radius: 3px; background: var(--navy-50); transition: background 0.2s; }
  .pw-label { font-size: 11.5px; margin-top: 5px; font-weight: 600; color: var(--muted); }
  .pw-rules { list-style: none; margin: 9px 0 0; padding: 0; font-size: 11.5px; color: var(--muted); line-height: 1.9; }
  .pw-rules li { display: flex; align-items: center; gap: 6px; }
  .pw-rules li.met { color: #16a34a; }
  .pw-dot { font-size: 12px; width: 12px; display: inline-block; text-align: center; }

  .btn-signin {
    width: 100%; padding: 12px; margin-top: 18px;
    background: #121358; color: white;
    border: none; border-radius: 10px;
    font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: inherit;
    transition: background 0.15s;
  }
  .btn-signin:hover { background: #093c5d; }
  .login-links {
    display: flex; flex-wrap: wrap;
    align-items: center; justify-content: center;
    gap: 4px 8px;
    text-align: center; margin-top: 18px;
    font-size: 13px; color: var(--muted);
  }
  /* The prompt keeps its own line when the row is too narrow, so a link
     is never broken across two lines. */
  .login-links .ll-lead { flex: 0 0 auto; }
  .login-links .ll-dot  { color: var(--muted); }
  .login-links a { color: var(--navy-900); text-decoration: none; font-weight: 600; }
  .login-links a:hover { text-decoration: underline; }

  .otp-input {
    width: 100%; padding: 18px 14px;
    border: 1px solid var(--border); border-radius: 12px;
    background: white;
    font-size: 26px; font-weight: 700;
    text-align: center; letter-spacing: 0.4em;
    font-family: 'Space Grotesk', sans-serif;
    color: var(--ink);
  }
  .otp-input:focus {
    outline: none; border-color: var(--orange-500);
    box-shadow: 0 0 0 3px rgba(255, 106, 28, 0.15);
  }
  .otp-note {
    font-size: 13px; color: var(--muted); line-height: 1.6;
    margin-bottom: 18px; text-align: center;
  }
  .otp-note strong { color: var(--ink); }
  .dev-preview {
    margin-top: 16px; padding: 12px 14px;
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
</style>
<script src="assets/loader.js?v=<?= @filemtime(__DIR__ . '/assets/loader.js') ?>"></script><?php /* v97: the loading icon */ ?>
</head>
<body>
<div class="login-wrap">
  <img src="assets/procurement-logo.png" class="login-avatar" alt="ASCOT Procurement Unit">
  <h1 class="login-title"><?= $step === 'verify' ? ($regMethod === 'SMS' ? 'Verify your number' : 'Verify your email') : 'Create your account' ?></h1>
  <p class="login-sub"><?= $step === 'verify' ? ($regMethod === 'SMS' ? 'Enter the 6-digit code we sent to your phone' : 'Enter the 6-digit code we sent to your inbox') : 'Register as a department end-user of ProcureEase' ?></p>

  <div class="login-card">
    <?php if ($error): ?>
      <div class="flash flash-error" style="margin-bottom: 14px;"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($notice): ?>
      <div class="flash flash-success" style="margin-bottom: 14px;"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($step === 'verify'): ?>
      <div class="otp-note">
        A 6-digit verification code was sent to<br>
        <strong><?= e($regMethod === 'SMS' ? $pendingMobile : $pendingEmail) ?></strong>.
        <br><br>
        The code expires in 10 minutes.
      </div>
      <?php if (!$googleSignup): ?>
      <a href="google-auth.php?mode=register" class="btn-google">
        <svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24s.92 7.54 2.56 10.78l7.97-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        <span>Continue with Google</span>
      </a>
      <div class="choice-or"><span>or</span></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
      <?= csrf_field() /* v79 */ ?>
        <input type="hidden" name="step" value="verify">
        <div class="field">
          <label for="code" class="req" style="text-align:center; display:block;">Verification code</label>
          <input type="text" id="code" name="code" class="otp-input"
                 placeholder="••••••" maxlength="6" pattern="\d{6}" inputmode="numeric"
                 autocomplete="one-time-code" required autofocus>
        </div>
        <button type="submit" class="btn-signin">Verify and create account</button>
      </form>
      <?php /* v123: the code by the other way — the contact number by text, or the email */ ?>
      <?php if ($regMethod === 'SMS' || reg_can_sms($pendingMobile)): ?>
      <div class="login-links" id="regOtherWayRow" style="margin-top: 14px;">
        <span>Didn't receive a code?</span>
        <a href="register.php?resend=1&amp;via=<?= $regMethod === 'SMS' ? 'email' : 'sms' ?>" id="regOtherWay"
           title="<?= e($regMethod === 'SMS' ? 'Send the code to ' . $pendingEmail . ' instead' : 'Send the code to ' . $pendingMobile . ' by text message instead') ?>">Try another way</a>
      </div>
      <?php endif; ?>

      <?php if ($otpPreview && !$otpMailed): ?>
        <div class="dev-preview">
          <strong>⚠ Email delivery failed — showing code here as fallback</strong><br>
          Your code is: <b><?= e($otpPreview) ?></b><br>
          <span style="color: var(--muted); font-size: 11.5px;">
            Ask the Procurement Unit to configure <strong>Mail settings</strong> so codes arrive in your Gmail inbox.
          </span>
        </div>
      <?php elseif ($otpPreview && $otpMailed): ?>
        <div class="dev-preview" style="background: var(--green-50); border-color: #86efac; color: #14532d;">
          <strong>✓ Code sent to your Gmail</strong><br>
          <span style="color: #166534; font-size: 12.5px;">
            Check your inbox — and the spam folder just in case.
          </span>
        </div>
      <?php endif; ?>

      <div class="login-links" style="margin-top: 14px;">
        Didn't get it? <a href="register.php?resend=1">Resend code</a>
        <span style="margin: 0 8px; color: var(--muted);">·</span>
        <a href="register.php?step=form" id="regChangeLink"><?= $regMethod === 'SMS' ? 'Change number' : 'Change email' ?></a><?php /* v125: by text — the number */ ?>
      </div>
    <?php else: ?>
      <form method="POST" autocomplete="off">
      <?= csrf_field() /* v79 */ ?>
        <input type="hidden" name="step" value="form">
        <div class="form-grid">
          <div class="field">
            <label for="first_name">First name <span class="req">*</span></label>
            <input type="text" id="first_name" name="first_name" class="input"
                   value="<?= e($form['first_name']) ?>" required autofocus maxlength="20"
                   data-only="name" autocapitalize="words"
                   title="Letters only — no numbers">
          </div>

          <div class="field">
            <label for="middle_initial">Middle initial</label>
            <input type="text" id="middle_initial" name="middle_initial" class="input"
                   value="<?= e($form['middle_initial']) ?>" maxlength="2" placeholder="e.g. D"
                   data-only="initial" style="text-transform: uppercase;"
                   title="One or two letters, in capitals">
          </div>

          <div class="field">
            <label for="last_name">Last name <span class="req">*</span></label>
            <input type="text" id="last_name" name="last_name" class="input"
                   value="<?= e($form['last_name']) ?>" required maxlength="20"
                   data-only="name" autocapitalize="words"
                   title="Letters only — no numbers">
          </div>

          <div class="field">
            <label for="suffix">Suffix</label>
            <input type="text" id="suffix" name="suffix" class="input"
                   value="<?= e($form['suffix']) ?>" maxlength="4" placeholder="e.g. Jr."
                   data-only="suffix"
                   title="Optional — Jr., Sr., II, III, IV">
          </div>

          <div class="field">
            <label for="sex">Sex <span class="req">*</span></label>
            <select id="sex" name="sex" class="input" required>
              <option value="">Select…</option>
              <option value="Male"   <?= $form['sex'] === 'Male'   ? 'selected' : '' ?>>Male</option>
              <option value="Female" <?= $form['sex'] === 'Female' ? 'selected' : '' ?>>Female</option>
            </select>
          </div>

          <div class="field">
            <label for="birthday">Birthday <span class="req">*</span></label>
            <input type="date" id="birthday" name="birthday" class="input"
                   value="<?= e($form['birthday']) ?>" required
                   max="2005-12-31">
          </div>

          <?php
            // What the Position list shows when the form comes back: a listed
            // position, "Others" with what was typed, or nothing yet.
            if ($__posChoice !== null) { $__pc = $__posChoice; $__po = $__posOther; }
            elseif (in_array($form['position'], reg_positions(), true)) { $__pc = $form['position']; $__po = ''; }
            elseif ($form['position'] !== '') { $__pc = 'Others'; $__po = $form['position']; }
            else { $__pc = ''; $__po = ''; }
            if ($__pc !== '' && $__pc !== 'Others' && !in_array($__pc, reg_positions(), true)) $__pc = '';
          ?>
          <div class="field">
            <label for="position_search">Position <span class="req">*</span></label>
            <div class="combo" id="posCombo">
              <input type="text" id="position_search" class="input combo-input"
                     placeholder="Type to search your position…"
                     value="<?= e($__pc) ?>"
                     role="combobox" aria-expanded="false" aria-controls="posList"
                     aria-autocomplete="list" aria-haspopup="listbox"
                     autocomplete="off" autocapitalize="off" spellcheck="false">
              <button type="button" class="combo-toggle" id="posToggle" tabindex="-1"
                      aria-label="Show all positions">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <polyline points="6 9 12 15 18 9"/>
                </svg>
              </button>
              <ul class="combo-list" id="posList" role="listbox" aria-label="Positions"></ul>
            </div>
            <input type="hidden" id="position_choice" name="position_choice" value="<?= e($__pc) ?>">
            <input type="text" id="position_other" name="position_other" class="input" maxlength="100"
                   value="<?= e($__po) ?>" placeholder="Type your position" autocomplete="organization-title"
                   aria-label="Your position (Others)" style="margin-top:8px;"<?= $__pc === 'Others' ? '' : ' hidden' ?>>
            <div class="field-hint" id="posHint">Search your position — not on the list? Choose &ldquo;Others&rdquo; at the end and type it.</div>
            <div id="posStatus" class="sr-only" role="status" aria-live="polite"></div>
          </div>
          <script>
          /* Position: searchable like Department, with "Others" always last.
             "Others" shows a box to type a position that is not on the list. */
          (function () {
            var POS = <?= json_encode(reg_positions(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            var OTHERS = 'Others';
            var wrap = document.getElementById('posCombo'), input = document.getElementById('position_search');
            var toggle = document.getElementById('posToggle'), list = document.getElementById('posList');
            var hidden = document.getElementById('position_choice'), other = document.getElementById('position_other');
            var hint = document.getElementById('posHint'), status = document.getElementById('posStatus');
            var HINT = hint.innerHTML;
            var open = false, matches = [], activeIx = -1;

            function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
            function mark(text, term) {
              if (!term) return esc(text);
              var i = text.toLowerCase().indexOf(term.toLowerCase());
              if (i < 0) return esc(text);
              return esc(text.slice(0, i)) + '<mark>' + esc(text.slice(i, i + term.length)) + '</mark>' + esc(text.slice(i + term.length));
            }
            function startsWord(name, term) {
              var i = name.indexOf(term);
              while (i > -1) {
                if (i === 0 || !/[a-z0-9]/.test(name.charAt(i - 1))) return true;
                i = name.indexOf(term, i + 1);
              }
              return false;
            }
            function filter(term) {
              term = (term || '').trim().toLowerCase();
              if (!term) return POS.slice();
              var hits = [];
              POS.forEach(function (p, i) {
                var n = p.toLowerCase(), s = n === term ? 0 : n.indexOf(term) === 0 ? 1 : startsWord(n, term) ? 2 : n.indexOf(term) > -1 ? 3 : -1;
                if (s > -1) hits.push({ p: p, s: s, i: i });
              });
              hits.sort(function (a, b) { return a.s - b.s || a.i - b.i; });
              return hits.map(function (h) { return h.p; });
            }
            function say(m) { if (status) status.textContent = m; }

            function render(term) {
              var found = filter(term);
              matches = found.concat([OTHERS]);            // "Others" is always the last choice
              activeIx = -1; list.innerHTML = '';
              if (!found.length) {
                var li = document.createElement('li');
                li.className = 'combo-empty';
                li.innerHTML = '<strong>No position found</strong>Choose &ldquo;Others&rdquo; below to type it.';
                list.appendChild(li);
              }
              matches.forEach(function (p, ix) {
                var li = document.createElement('li');
                li.className = 'combo-opt'; li.id = 'pos-opt-' + ix;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', hidden.value === p ? 'true' : 'false');
                var typed = (term || '').trim();
                li.innerHTML = p === OTHERS
                  ? '<span class="combo-name"><strong>Others</strong>' +
                    '<span class="combo-group">' + (typed && !found.length ? 'Type &ldquo;' + esc(typed) + '&rdquo; as your position' : 'My position is not on the list') + '</span></span>'
                  : '<span class="combo-name">' + mark(p, term) + '</span>';
                li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(p, typed); });
                li.addEventListener('mouseenter', function () { setActive(ix, false); });
                list.appendChild(li);
              });
              say(found.length + (found.length === 1 ? ' position found.' : ' positions found.') + ' Others is last.');
            }
            function setActive(ix, scroll) {
              var rows = list.querySelectorAll('.combo-opt');
              rows.forEach(function (r) { r.classList.remove('is-active'); });
              activeIx = ix;
              if (ix < 0 || ix >= rows.length) { input.removeAttribute('aria-activedescendant'); return; }
              rows[ix].classList.add('is-active');
              input.setAttribute('aria-activedescendant', rows[ix].id);
              if (scroll !== false) rows[ix].scrollIntoView({ block: 'nearest' });
            }
            function openList(term) { render(term !== undefined ? term : ''); open = true; wrap.classList.add('is-open'); input.setAttribute('aria-expanded', 'true'); }
            function closeList() { open = false; wrap.classList.remove('is-open'); input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); activeIx = -1; }
            function resetHint() { hint.innerHTML = HINT; hint.style.color = ''; wrap.classList.remove('combo-invalid'); }

            function choose(p, typed) {
              hidden.value = p; input.value = p;
              wrap.classList.add('is-chosen'); resetHint(); closeList();
              if (p === OTHERS) {
                other.hidden = false;
                // What was typed in the search (and matched nothing) goes into the box
                if (typed && !filter(typed).length && !other.value) other.value = typed;
                other.focus();
                say('Others selected. Type your position.');
              } else {
                other.hidden = true; other.value = '';
                say(p + ' selected.');
              }
            }
            function clearChoice() {
              if (!hidden.value) return;
              hidden.value = ''; wrap.classList.remove('is-chosen');
              other.hidden = true;
            }

            input.addEventListener('input', function () { clearChoice(); openList(input.value); });
            input.addEventListener('focus', function () { openList(hidden.value ? '' : input.value); if (hidden.value) input.select(); });
            toggle.addEventListener('click', function () { if (open) { closeList(); return; } input.focus(); openList(hidden.value ? '' : input.value); });
            input.addEventListener('keydown', function (ev) {
              switch (ev.key) {
                case 'ArrowDown': ev.preventDefault();
                  if (!open) { openList(hidden.value ? '' : input.value); setActive(0); }
                  else setActive(activeIx + 1 >= matches.length ? 0 : activeIx + 1); break;
                case 'ArrowUp': ev.preventDefault();
                  if (!open) { openList(hidden.value ? '' : input.value); setActive(matches.length - 1); }
                  else setActive(activeIx - 1 < 0 ? matches.length - 1 : activeIx - 1); break;
                case 'Home': if (open) { ev.preventDefault(); setActive(0); } break;
                case 'End':  if (open) { ev.preventDefault(); setActive(matches.length - 1); } break;
                case 'Enter':
                  if (open && activeIx > -1 && matches[activeIx]) { ev.preventDefault(); choose(matches[activeIx], input.value.trim()); }
                  else if (open && matches.length === 2) { ev.preventDefault(); choose(matches[0], input.value.trim()); }
                  break;
                case 'Escape': if (open) { ev.preventDefault(); closeList(); } break;
                case 'Tab': closeList(); break;
              }
            });
            /* Leaving the search: an exact or single match is taken; text that
               matches nothing becomes "Others" with that text, so nothing typed is lost */
            input.addEventListener('blur', function () {
              setTimeout(function () {
                if (document.activeElement === other || document.activeElement === input) return;   // moved to the box, or came straight back
                closeList();
                if (hidden.value) { input.value = hidden.value; return; }
                var typed = input.value.trim();
                if (!typed) return;
                var exact = POS.filter(function (p) { return p.toLowerCase() === typed.toLowerCase(); })[0];
                var m = filter(typed);
                if (exact) choose(exact);
                else if (m.length === 1) choose(m[0]);
                else if (!m.length) { choose(OTHERS, typed); }
                else input.value = '';
              }, 120);
            });
            document.addEventListener('click', function (ev) { if (!wrap.contains(ev.target)) closeList(); });
            other.addEventListener('input', resetHint);

            var form = input.closest('form');
            if (form) form.addEventListener('submit', function (ev) {
              if (!hidden.value) {   // v104: Position is required
                ev.preventDefault(); ev.stopImmediatePropagation();
                hint.textContent = 'Please choose your position — or “Others” at the end, and type it.';
                hint.style.color = 'var(--orange-500)'; wrap.classList.add('combo-invalid');
                input.focus();
                return;
              }
              if (hidden.value === OTHERS && !other.value.trim()) {
                ev.preventDefault(); ev.stopImmediatePropagation();
                hint.textContent = 'You chose “Others” — please type your position.';
                hint.style.color = 'var(--orange-500)'; wrap.classList.add('combo-invalid');
                other.focus();
              }
            }, true);
            if (hidden.value) wrap.classList.add('is-chosen');
          })();
          </script>

          <?php
            // Label shown in the box when a department is already chosen
            // (e.g. the form came back with a validation error).
            $__sel      = $deptByCode[strtolower($form['dept_code'])] ?? null;
            $__selLabel = $__sel ? $__sel['name'] . ' (' . $__sel['code'] . ')' : '';   // v81: "School of Education (SOED)"
            $__isOther  = ($form['dept_code'] ?? '') === DEPT_OTHER;                     // v99
            if ($__isOther) $__selLabel = 'Others (not on the list)';
          ?>
          <div class="field">
            <label for="dept_search">Department <span class="req">*</span></label>
            <div class="combo" id="deptCombo">
              <input type="text" id="dept_search" class="input combo-input"
                     placeholder="Type to search your department…"
                     value="<?= e($__selLabel) ?>"
                     role="combobox" aria-expanded="false" aria-controls="deptList"
                     aria-autocomplete="list" aria-haspopup="listbox"
                     autocomplete="off" autocapitalize="off" spellcheck="false">
              <button type="button" class="combo-toggle" id="deptToggle" tabindex="-1"
                      aria-label="Show all departments">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <polyline points="6 9 12 15 18 9"/>
                </svg>
              </button>
              <ul class="combo-list" id="deptList" role="listbox" aria-label="Departments"></ul>
            </div>
            <!-- The real submitted value. Keeps the id "dept_code" so the
                 Program / Major field below keeps working unchanged. -->
            <input type="hidden" id="dept_code" name="dept_code" value="<?= e($__isOther ? DEPT_OTHER : ($__sel['code'] ?? '')) ?>">
            <div class="field-hint" id="deptHint">Search by code or name — e.g. &ldquo;ICT&rdquo;, &ldquo;Engineering&rdquo;. Not on the list? Choose &ldquo;Others&rdquo;.</div>
            <div id="deptStatus" class="sr-only" role="status" aria-live="polite"></div>
          </div>

          <?php /* v99: "Others" — the department typed */ ?>
          <div class="field full" id="deptOtherField"<?= $__isOther ? '' : ' style="display: none;"' ?>>
            <label for="dept_other">Your department <span class="req">*</span></label>
            <input type="text" id="dept_other" name="dept_other" class="input" maxlength="150"
                   value="<?= e($form['dept_other'] ?? '') ?>" placeholder="Type the name of your department or office"<?= $__isOther ? ' required' : '' ?>>
            <div class="field-hint">The Procurement Unit puts your account in this department when it approves it.</div>
          </div>

          <div class="field">
            <label for="contact">Contact number <span class="req">*</span></label>
            <input type="text" id="contact" name="contact" class="input" inputmode="numeric" pattern="09[0-9]{9}" maxlength="16" required
                   value="<?= e($form['contact']) ?>" placeholder="09171234567"
                   data-only="mobile" title="Philippine mobile number, for example 09171234567">
            <div class="field-hint">Must start with 09 (11 digits). This number is used to verify your account and reset your password.</div>
          </div>

          <div class="field full" id="subUnitField" style="display: none;">
            <label for="sub_unit">Bachelor / Master</label>
            <select id="sub_unit" name="sub_unit" class="select">
              <option value="">Whole school (all programs)</option>
            </select>
          </div>

          <script>
            const DEPT_UNITS = <?= json_encode($deptUnits) ?>;
            const PRESELECT_SUB = <?= json_encode($form['sub_unit'] ?? '') ?>;
            function updateSubUnits() {
              const dept = document.getElementById('dept_code').value;
              const field = document.getElementById('subUnitField');
              const sel = document.getElementById('sub_unit');
              const units = DEPT_UNITS[dept] || [];
              if (units.length === 0) {
                field.style.display = 'none';
                sel.innerHTML = '<option value=""></option>';
                sel.required = false;
                return;
              }
              field.style.display = '';
              sel.required = false;
              sel.innerHTML = '<option value="">Whole school (all programs)</option>';
              units.forEach(function (u) {
                const o = document.createElement('option');
                o.value = u[0]; o.textContent = u[1]; if (u[0] === PRESELECT_SUB) o.selected = true;
                sel.appendChild(o);
              });
            }
            document.addEventListener('DOMContentLoaded', updateSubUnits);
          </script>

          <script>
          /* ─────────────────────────────────────────────────────────────
             Searchable department picker.
             The person types part of a code or a name and picks from the
             filtered results. Nothing is submitted unless a real department
             is chosen — the hidden #dept_code field holds the value, and
             register.php re-checks it against the active list on the server.
             ───────────────────────────────────────────────────────────── */
          (function () {
            var DEPTS = <?= json_encode($depts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            // v99: "Others" — always last in the list; the department is then typed below
            var OTHER = { code: '<?= DEPT_OTHER ?>', name: 'Others — my department is not on the list', dept_group: '' };
            var otherField = document.getElementById('deptOtherField'), otherInput = document.getElementById('dept_other');
            function showOther(on) {
              if (!otherField) return;
              otherField.style.display = on ? '' : 'none';
              if (otherInput) { otherInput.required = !!on; if (!on) otherInput.value = ''; }
            }

            var wrap   = document.getElementById('deptCombo');
            var input  = document.getElementById('dept_search');
            var hidden = document.getElementById('dept_code');
            var list   = document.getElementById('deptList');
            var toggle = document.getElementById('deptToggle');
            var status = document.getElementById('deptStatus');
            if (!wrap || !input || !hidden || !list) return;

            var matches = [];      // departments currently shown
            var activeIx = -1;     // keyboard-highlighted row
            var open = false;

            function esc(s) {
              return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
              });
            }

            /* Wrap the typed term inside the label so the match is visible. */
            function mark(text, term) {
              if (!term) return esc(text);
              var i = text.toLowerCase().indexOf(term.toLowerCase());
              if (i < 0) return esc(text);
              return esc(text.slice(0, i)) + '<mark>' + esc(text.slice(i, i + term.length)) +
                     '</mark>' + esc(text.slice(i + term.length));
            }

            function labelOf(d) { return d.code === OTHER.code ? 'Others (not on the list)' : d.name + ' (' + d.code + ')'; }   // v81: "School of Education (SOED)"

            /* True when the term starts a word inside the name, e.g. "eng"
               matches "School of Engineering". No RegExp is ever built from
               typed text, so nothing the person types is read as a pattern. */
            function startsWord(name, term) {
              var i = name.indexOf(term);
              while (i > -1) {
                if (i === 0 || !/[a-z0-9]/.test(name.charAt(i - 1))) return true;
                i = name.indexOf(term, i + 1);
              }
              return false;
            }

            /* Best matches first: code, then start of a word in the name,
               then anywhere in the name. */
            function score(d, term) {
              var code = d.code.toLowerCase(), name = d.name.toLowerCase();
              if (code === term) return 0;
              if (code.indexOf(term) === 0) return 1;
              if (name.indexOf(term) === 0) return 2;
              if (startsWord(name, term)) return 3;
              if (name.indexOf(term) > -1) return 4;
              if (code.indexOf(term) > -1) return 5;
              return -1;
            }

            function filter(term, noOther) {
              var all = filterDepts(term);
              return noOther ? all : all.concat([OTHER]);
            }
            function filterDepts(term) {
              term = (term || '').trim().toLowerCase();
              if (!term) return DEPTS.slice();
              var hits = [];
              DEPTS.forEach(function (d, i) {
                var s = score(d, term);
                if (s > -1) hits.push({ d: d, s: s, i: i });
              });
              hits.sort(function (a, b) { return a.s - b.s || a.i - b.i; });
              return hits.map(function (h) { return h.d; });
            }

            function render(term) {
              matches = filter(term);
              activeIx = -1;
              list.innerHTML = '';

              if (!matches.length) {
                var li = document.createElement('li');
                li.className = 'combo-empty';
                li.innerHTML = '<strong>No department found</strong>' +
                  'Check the spelling, or ask the Procurement Unit to add your unit to the list.';
                list.appendChild(li);
                say('No department found.');
                return;
              }

              matches.forEach(function (d, ix) {
                var li = document.createElement('li');
                li.className = 'combo-opt';
                li.id = 'dept-opt-' + ix;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', hidden.value === d.code ? 'true' : 'false');
                li.dataset.code = d.code;
                if (d.code === OTHER.code) {
                  li.className = 'combo-opt combo-other';
                  li.innerHTML = '<span class="combo-code">Others</span><span class="combo-name">' +
                    (matches.length === 1 ? 'No department found — type yours instead' : 'My department is not on the list') + '</span>';
                  li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(d); });
                  li.addEventListener('mouseenter', function () { setActive(ix, false); });
                  list.appendChild(li);
                  return;
                }
                li.innerHTML =
                  '<span class="combo-code">' + mark(d.code, term) + '</span>' +
                  '<span class="combo-name">' + mark(d.name, term) +
                    (d.dept_group ? '<span class="combo-group">' + esc(d.dept_group) + '</span>' : '') +
                  '</span>';
                li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(d); });
                li.addEventListener('mouseenter', function () { setActive(ix, false); });
                list.appendChild(li);
              });

              say(matches.length + (matches.length === 1 ? ' department found.' : ' departments found.'));
            }

            function say(msg) { if (status) status.textContent = msg; }

            function setActive(ix, scroll) {
              var rows = list.querySelectorAll('.combo-opt');
              rows.forEach(function (r) { r.classList.remove('is-active'); });
              activeIx = ix;
              if (ix < 0 || ix >= rows.length) { input.removeAttribute('aria-activedescendant'); return; }
              rows[ix].classList.add('is-active');
              input.setAttribute('aria-activedescendant', rows[ix].id);
              if (scroll !== false) rows[ix].scrollIntoView({ block: 'nearest' });
            }

            function openList(term) {
              render(term !== undefined ? term : '');
              open = true;
              wrap.classList.add('is-open');
              input.setAttribute('aria-expanded', 'true');
            }

            function closeList() {
              open = false;
              wrap.classList.remove('is-open');
              input.setAttribute('aria-expanded', 'false');
              input.removeAttribute('aria-activedescendant');
              activeIx = -1;
            }

            function choose(d) {
              hidden.value = d.code;
              input.value = labelOf(d);
              wrap.classList.add('is-chosen');
              wrap.classList.remove('combo-invalid');
              closeList();
              say(d.code === OTHER.code ? 'Others selected — type your department below.' : d.name + ' selected.');
              showOther(d.code === OTHER.code);
              if (d.code === OTHER.code && otherInput) setTimeout(function () { otherInput.focus(); }, 0);
              // Show the Program / Major list for this department
              if (typeof updateSubUnits === 'function') updateSubUnits();
            }

            function clearChoice() {
              if (!hidden.value) return;
              hidden.value = '';
              wrap.classList.remove('is-chosen');
              showOther(false);
              if (typeof updateSubUnits === 'function') updateSubUnits();
            }

            /* ── Typing ── */
            input.addEventListener('input', function () {
              clearChoice();                 // typing means the old pick no longer applies
              openList(input.value);
            });

            input.addEventListener('focus', function () {
              // Clicking back into a chosen field shows the whole list again
              openList(hidden.value ? '' : input.value);
              if (hidden.value) input.select();
            });

            toggle.addEventListener('click', function () {
              if (open) { closeList(); return; }
              input.focus();
              openList(hidden.value ? '' : input.value);
            });

            /* ── Keyboard ── */
            input.addEventListener('keydown', function (ev) {
              switch (ev.key) {
                case 'ArrowDown':
                  ev.preventDefault();
                  if (!open) { openList(hidden.value ? '' : input.value); setActive(0); }
                  else setActive(activeIx + 1 >= matches.length ? 0 : activeIx + 1);
                  break;
                case 'ArrowUp':
                  ev.preventDefault();
                  if (!open) { openList(hidden.value ? '' : input.value); setActive(matches.length - 1); }
                  else setActive(activeIx - 1 < 0 ? matches.length - 1 : activeIx - 1);
                  break;
                case 'Home':
                  if (open && matches.length) { ev.preventDefault(); setActive(0); }
                  break;
                case 'End':
                  if (open && matches.length) { ev.preventDefault(); setActive(matches.length - 1); }
                  break;
                case 'Enter':
                  if (open && activeIx > -1 && matches[activeIx]) { ev.preventDefault(); choose(matches[activeIx]); }
                  else if (open && filter(input.value, true).length === 1) { ev.preventDefault(); choose(filter(input.value, true)[0]); }   // one department found ("Others" aside)
                  break;
                case 'Escape':
                  if (open) { ev.preventDefault(); closeList(); }
                  break;
                case 'Tab':
                  // Leaving the field with exactly one match takes that match
                  if (open && filter(input.value, true).length === 1 && !hidden.value) choose(filter(input.value, true)[0]);
                  else closeList();
                  break;
              }
            });

            /* ── Leaving the field: never keep text that isn't a real pick ── */
            input.addEventListener('blur', function () {
              setTimeout(function () {
                closeList();
                if (hidden.value) {
                  var picked = DEPTS.concat([OTHER]).filter(function (d) { return d.code === hidden.value; })[0];
                  if (picked) input.value = labelOf(picked);
                } else if (input.value.trim()) {
                  // One unambiguous match is accepted; anything else is cleared
                  var m = filter(input.value, true);   // "Others" is chosen only by picking it
                  var typed = input.value.trim().toLowerCase();
                  var exact = DEPTS.filter(function (d) {
                    return d.code.toLowerCase() === typed || d.name.toLowerCase() === typed ||
                           labelOf(d).toLowerCase() === typed;
                  })[0];
                  if (exact) { choose(exact); return; }
                  if (m.length === 1) { choose(m[0]); return; }
                  input.value = '';
                }
              }, 120);
            });

            document.addEventListener('click', function (ev) {
              if (!wrap.contains(ev.target)) closeList();
            });

            /* ── Block submit until a department is actually chosen ── */
            var form = input.closest('form');
            if (form) {
              form.addEventListener('submit', function (ev) {
                if (!hidden.value) {
                  ev.preventDefault();
                  wrap.classList.add('combo-invalid');
                  var hint = document.getElementById('deptHint');
                  hint.textContent = 'Please pick your department from the search results.';
                  hint.style.color = 'var(--orange-500)';
                  input.focus();
                  openList(input.value);
                }
              });
            }

            if (hidden.value) wrap.classList.add('is-chosen');
          })();
          </script>

          <div class="field full">
            <label for="email">Email address <span class="req">*</span></label>
            <input type="email" id="email" name="email" class="input"
                   value="<?= e($form['email']) ?>" placeholder="you@example.com" required
                   <?= $googleSignup ? 'readonly style="background:var(--navy-50);"' : '' ?>>
            <div class="field-hint">
              <?= $googleSignup
                    ? '✅ Verified with Google — no code needed.'
                    : (sms_gateway_enabled()
                        ? 'A 6-digit verification code will be sent to this address. If it cannot be sent here, it goes to your contact number by text instead.'
                        : 'A 6-digit verification code will be sent to this address.') ?>
            </div>
          </div>

          <div class="field">
            <label for="password">Password <span class="req">*</span></label>
            <div class="pw-wrap">
              <input type="password" id="password" name="password" class="input"
                     placeholder="At least <?= password_min_length() ?> characters" required minlength="<?= password_min_length() ?>"
                     autocomplete="new-password" oninput="checkStrength()">
              <button type="button" class="pw-eye" data-for="password" aria-label="Show password" aria-pressed="false" title="Show password">
                <svg class="eye-on" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <div class="field-hint" id="pwHint"></div>
          </div>

          <div class="field">
            <label for="confirm_password">Confirm password <span class="req">*</span></label>
            <div class="pw-wrap">
              <input type="password" id="confirm_password" name="confirm_password" class="input"
                     placeholder="Retype your password" required minlength="<?= password_min_length() ?>"
                     autocomplete="new-password" oninput="checkMatch()">
              <button type="button" class="pw-eye" data-for="confirm_password" aria-label="Show confirm password" aria-pressed="false" title="Show confirm password">
                <svg class="eye-on" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <script>
            /* The eye beside Password and Confirm password shows what was typed; clicking again hides it */
            document.querySelectorAll('.pw-eye').forEach(function (b) {
              b.addEventListener('click', function () {
                var i = document.getElementById(b.dataset.for); if (!i) return;
                var show = i.type === 'password';
                i.type = show ? 'text' : 'password';
                var what = b.dataset.for === 'password' ? 'password' : 'confirm password';
                b.setAttribute('aria-pressed', show ? 'true' : 'false');
                b.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + what);
                b.title = (show ? 'Hide ' : 'Show ') + what;
                i.focus();
                try { var n = i.value.length; i.setSelectionRange(n, n); } catch (e) {}
              });
            });
            </script>
            <div class="field-hint" id="pwMatch"></div>
          </div>
        </div>

        <div class="terms-agree">
          <label class="terms-check">
            <input type="checkbox" id="agree_terms" name="agree_terms" value="1" required
                   <?= !empty($form['agree_terms']) ? 'checked' : '' ?>>
            <span>
              I have read and agree to the
              <a href="#" onclick="openTerms(event)">Terms and Conditions</a>
              and to the processing of my information under the Data Privacy Act of 2012.
            </span>
          </label>
        </div>

        <button type="submit" class="btn-signin"><?= $googleSignup ? 'Create account' : 'Send verification code' ?></button>
      </form>

      <div class="login-links">
        <span class="ll-lead">Already have an account?</span>
        <a href="email-signin.php">Sign in with email</a>
        <span class="ll-dot" aria-hidden="true">·</span>
        <a href="index.php">Log in</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ─── Terms and Conditions ─── -->
<div id="termsOverlay" class="terms-overlay" onclick="if(event.target===this) closeTerms()">
  <div class="terms-modal">
    <div class="terms-head">
      <div>
        <div class="terms-title">Terms and Conditions</div>
        <div class="terms-sub">ProcureEase — Aurora State College of Technology</div>
      </div>
      <button type="button" class="terms-x" onclick="closeTerms()" aria-label="Close">&times;</button>
    </div>

    <div class="terms-body">
      <p class="terms-eff">Effective upon account registration · Governed by Republic Act No. 12009 (New Government Procurement Act) and its 2025 IRR</p>

      <h4>1. About this system</h4>
      <p>ProcureEase is the internal procurement planning system of Aurora State College of Technology (ASCOT). It is used to prepare, submit, review, and consolidate the Project Procurement Management Plan (PPMP), the Annual Procurement Plan for Common-Use Supplies and Equipment (APP-CSE), the List of Non-PS Supplies and Materials, and the Annex forms (A, B, and C) of each department, unit, and office.</p>

      <h4>2. Who may register</h4>
      <p>Accounts are limited to ASCOT personnel who are officially designated to prepare or review procurement documents for their department, unit, or office. You must register using your own identity, your actual position, and the department or unit you genuinely belong to. Registering on behalf of another person, or creating an account for a department you are not assigned to, is not allowed.</p>

      <h4>3. Account approval</h4>
      <p>Registration does not automatically grant access. After you verify your email address, your account stays pending until the Procurement Unit reviews and approves it. The Procurement Unit may decline a registration that cannot be verified, and may deactivate an account when a person leaves their post or their designation changes.</p>

      <h4>4. Your responsibility over your account</h4>
      <p>You are responsible for your username and password, and for everything done through your account. Do not share your credentials with anyone, including colleagues within your own unit. If you believe someone else has gained access to your account, change your password immediately and inform the Procurement Unit.</p>

      <h4>5. Accuracy of what you submit</h4>
      <p>Everything you enter — item descriptions, quantities, unit costs, fund sources, modes of procurement, project details, and supporting figures — must be accurate and made in good faith. These entries are consolidated into the college's Annual Procurement Plan and form part of the official procurement record. Submitting figures you know to be inaccurate, padded, or unsupported may be dealt with under existing civil service and procurement rules.</p>

      <h4>6. Submission and review</h4>
      <p>A document that you submit moves out of your control and into review. The Budget Unit and the Procurement Unit may return it to you for revision, and the Procurement Unit may edit or add items during consolidation where this is necessary to comply with procurement rules or the approved budget. Once a document is approved, it is locked and can no longer be edited by the submitting unit.</p>

      <h4>7. Fiscal year and deadlines</h4>
      <p>The active fiscal year is set by the Procurement Unit. You may only prepare and submit documents for the active fiscal year, and you are expected to observe the submission deadlines posted in the system. Late submissions may not be included in the consolidated plan for that year.</p>

      <h4>8. Your personal information</h4>
      <p>The system collects your name, position, department or unit, email address, contact number, and — if you choose to upload one — your profile photo. This information is used only to operate the system: to identify who prepared and submitted each document, to route documents for review, to send verification codes and notifications, and to maintain the activity log. Your information is not sold, and is not shared outside ASCOT except where disclosure is required by law, by the Commission on Audit, or by a lawful order.</p>

      <h4>9. Data Privacy Act of 2012</h4>
      <p>Your information is processed in line with Republic Act No. 10173 (Data Privacy Act of 2012). You have the right to be informed about how your data is used, to access and correct your information, to object to processing, and to request the deletion of your account through the system's account deletion request. Records that form part of the official procurement or audit trail may be retained even after an account is deleted, as required by law and by retention rules.</p>

      <h4>10. Activity logging</h4>
      <p>For accountability and audit purposes, the system records actions taken in it — including sign-ins, submissions, approvals, revisions, edits, archiving, and deletions — together with the account that performed them and the date and time. These logs are reviewed by the Procurement Unit and may be provided to auditors.</p>

      <h4>11. Acceptable use</h4>
      <p>Use the system only for its intended procurement purpose. Do not attempt to access documents belonging to another department, to bypass approval steps, to alter records you are not authorized to change, or to interfere with the operation of the system. Do not upload anything unlawful, offensive, or unrelated to procurement.</p>

      <h4>12. Availability and accuracy of the system</h4>
      <p>ASCOT will keep the system running as reliably as it reasonably can, but it may be unavailable at times due to maintenance, updates, power interruption, or circumstances beyond its control. Figures shown in the system are working figures for planning; the signed and approved documents remain the official record.</p>

      <h4>13. Changes to these terms</h4>
      <p>These Terms and Conditions may be updated as procurement rules or system features change. Continued use of the system after an update means you accept the revised terms.</p>

      <h4>14. Getting help</h4>
      <p>For questions about your account, a submission, or these terms, contact the ASCOT Procurement Unit, or use the Report a problem option inside the system once you are signed in.</p>

      <p class="terms-close-note">By ticking the agreement box and creating an account, you confirm that you have read and understood these Terms and Conditions.</p>
    </div>

    <div class="terms-foot">
      <button type="button" class="btn-terms-ghost" onclick="closeTerms()">Close</button>
      <button type="button" class="btn-terms-primary" onclick="acceptTerms()">I agree</button>
    </div>
  </div>
</div>

<script>
  function openTerms(e){ if (e) e.preventDefault(); document.getElementById('termsOverlay').style.display = 'flex'; }
  function closeTerms(){ document.getElementById('termsOverlay').style.display = 'none'; }
  function acceptTerms(){
    var box = document.getElementById('agree_terms');
    if (box) box.checked = true;
    closeTerms();
  }
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeTerms(); });
</script>
<script>
  // ─── Password strength ───
  const PW_RULES = {
    len:    function(v){ return v.length >= <?= password_min_length() ?>; },
    upper:  function(v){ return /[A-Z]/.test(v); },
    lower:  function(v){ return /[a-z]/.test(v); },
    digit:  function(v){ return /[0-9]/.test(v); },
    symbol: function(v){ return /[^A-Za-z0-9]/.test(v); }
  };

  function pwScore(v){
    let met = 0;
    for (const k in PW_RULES) if (PW_RULES[k](v)) met++;
    // A long password earns a little extra credit
    if (v.length >= 12 && met === 5) return 4;
    if (met === 5) return 3;
    if (met === 4) return 2;
    if (met >= 2)  return 1;
    return v.length ? 1 : 0;
  }

  function checkStrength(){
    const v = document.getElementById('password').value;

    // Build list of missing requirements in order
    const missing = [];
    if (!PW_RULES.len(v)) missing.push('at least <?= password_min_length() ?> characters');
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
    const pw = document.getElementById('password').value;
    const cf = document.getElementById('confirm_password');
    const out = document.getElementById('pwMatch');
    if (!cf || !out) return true;
    if (!cf.value){ out.textContent = ''; return false; }
    const same = (pw === cf.value);
    out.textContent = same ? '✓ Passwords match' : '✕ Passwords do not match';
    out.style.color = same ? '#16a34a' : '#dc2626';
    return same;
  }

  // Block the submit unless the password is genuinely strong
  (function(){
    const form = document.querySelector('form[method="POST"]');
    if (!form) return;
    form.addEventListener('submit', function(e){
      const pwField = document.getElementById('password');
      if (!pwField) return;                     // not on the form step
      const v = pwField.value;
      const missing = [];
      if (!PW_RULES.len(v)) missing.push('8+ characters');
      if (!PW_RULES.upper(v)) missing.push('uppercase letter');
      if (!PW_RULES.lower(v)) missing.push('lowercase letter');
      if (!PW_RULES.digit(v)) missing.push('number');
      if (!PW_RULES.symbol(v)) missing.push('symbol');
      if (missing.length > 0){
        e.preventDefault();
        alert('Please choose a stronger password.\n\nMissing: ' + missing.join(', '));
        pwField.focus();
        return;
      }
      if (!checkMatch()){
        e.preventDefault();
        alert('The two passwords do not match.');
        document.getElementById('confirm_password').focus();
      }
    });
  })();
</script>
<script src="assets/name-case.js"></script>
<script>
  // ─── What each box will accept, as it is typed ───
  //
  // The server checks all of this again; this is so a digit never lands
  // in a name box in the first place, rather than being complained about
  // after the form is sent. The caret is put back where it was, so
  // correcting the middle of a name does not send it to the end.
  (function () {
    var KEEP = {
      // Letters, and the marks that appear inside real names: the space
      // in "Dela Cruz", the hyphen in "Ana-Marie", the apostrophe in
      // "D'Souza", the full stop in "Jr.". \p{L} covers ñ and accented
      // letters; the fallback runs where a browser lacks it.
      // v104: and written as a name is — "JHADE" / "jhade" → "Jhade" (window.peNameCase)
      name:    function (v) { return window.peNameCase(strip(v, /[^\p{L} .'\-]/gu, /[^A-Za-zÀ-ÖØ-öø-ÿÑñ .'\-]/g)); },
      suffix:  function (v) { return v.replace(/[^A-Za-z.]/g, '').slice(0, 4); },
      initial: function (v) { return strip(v, /[^\p{L}]/gu, /[^A-Za-zÀ-ÖØ-öø-ÿÑñ]/g).toUpperCase().slice(0, 2); },
      digits:  function (v) { return v.replace(/\D/g, '').slice(0, 11); },
      mobile:  function (v) {
        var d = v.replace(/\D/g, '');
        if (d.length === 13 && d.indexOf('630') === 0) d = d.slice(2);
        if (d.length >= 12 && d.indexOf('63') === 0) d = '0' + d.slice(2);
        if (d.length === 10 && d.charAt(0) === '9') d = '0' + d;
        return d.slice(0, 11);
      }
    };

    function strip(v, unicodeRe, plainRe) {
      try { return v.replace(unicodeRe, ''); }
      catch (e) { return v.replace(plainRe, ''); }
    }

    document.querySelectorAll('[data-only]').forEach(function (el) {
      var clean = KEEP[el.getAttribute('data-only')];
      if (!clean) return;
      el.addEventListener('input', function () {
        var before = el.value;
        var after  = clean(before);
        if (after === before) return;
        var at = el.selectionStart;
        // however many characters were dropped ahead of the caret
        var dropped = before.slice(0, at).length - clean(before.slice(0, at)).length;
        el.value = after;
        try { el.setSelectionRange(at - dropped, at - dropped); } catch (e) {}
      });
      // Pasting goes through the same rule
      el.addEventListener('paste', function () {
        setTimeout(function () { el.value = clean(el.value); }, 0);
      });
    });

    // One last look before the form is sent, so the message names the
    // field instead of the browser refusing with nothing to read. Bound
    // to the contact box's own form — the verify step has a form of its
    // own and must not be caught by this.
    var contactBox = document.getElementById('contact');
    var form = contactBox ? contactBox.form : null;
    if (!form) return;
    form.addEventListener('submit', function (e) {
      var contact = document.getElementById('contact');
      if (contact && !/^09\d{9}$/.test(contact.value)) {
        e.preventDefault();
        alert('The contact number must be a Philippine mobile number, for example 09171234567.');
        contact.focus();
      }
    });
  })();
</script>
</body>
</html>
