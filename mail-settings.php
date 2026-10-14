<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/mail-config.php';
require_once __DIR__ . '/config/smtp.php';
require_admin();
ensure_admin_tables();

$u   = current_user();
$pdo = db();

function mail_setting_get(string $key, string $default = ''): string {
    try {
        $s = db()->prepare('SELECT value FROM settings WHERE key_name = ?');
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $t) { return $default; }
}
function mail_setting_put(string $key, string $value): void {
    try {
        db()->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute([$key, $value]);
    } catch (Throwable $t) {}
}

$fileConfigured = function_exists('mail_is_configured') && mail_is_configured();

// ─── Save / test ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'save') {
        $addr = trim($_POST['smtp_user'] ?? '');
        $pass = preg_replace('/\s+/', '', $_POST['smtp_pass'] ?? '');   // Gmail shows the key in groups of four
        $name = trim($_POST['smtp_from_name'] ?? 'ProcureEase — ASCOT');

        if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid sender email address.');
        } else {
            mail_setting_put('smtp_user', $addr);
            mail_setting_put('smtp_from', $addr);
            mail_setting_put('smtp_from_name', $name);
            mail_setting_put('smtp_host', 'smtp.gmail.com');
            mail_setting_put('smtp_port', '587');
            if ($pass !== '') mail_setting_put('smtp_pass', $pass);   // blank means "keep the saved one"
            log_activity('Updated email settings', 'settings', 'smtp', $addr);
            flash('success', 'Email settings saved.');
        }
        header('Location: mail-settings.php'); exit;
    }

    if ($do === 'google') {
        $cid = trim($_POST['google_client_id'] ?? '');
        $sec = trim($_POST['google_client_secret'] ?? '');
        mail_setting_put('google_client_id', $cid);
        if ($sec !== '') mail_setting_put('google_client_secret', $sec);
        log_activity('Updated Google Sign-In settings', 'settings', 'google', $cid !== '' ? 'configured' : 'cleared');
        flash('success', 'Google Sign-In settings saved.');
        header('Location: mail-settings.php'); exit;
    }

    if ($do === 'test') {
        $to = trim($_POST['test_to'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid address to send the test to.');
        } else {
            $ok = false;
            try {
                $ok = smtp_send($to, 'ProcureEase test email',
                    "This is a test message from ProcureEase.\n\n" .
                    "If you are reading this, the email connection is working and the system can now send " .
                    "verification codes, password resets, and notifications.\n\n— ProcureEase, ASCOT");
            } catch (Throwable $t) { $ok = false; }
            if ($ok) flash('success', "Test email sent to $to. Check the inbox (and the spam folder).");
            else     flash('error', 'Could not send the test email. Double-check the address and the app password.');
        }
        header('Location: mail-settings.php'); exit;
    }
}

$curUser = mail_setting_get('smtp_user', $fileConfigured ? (string)MAIL_USERNAME : '');
$curName = mail_setting_get('smtp_from_name', 'ProcureEase — ASCOT');
$hasPass = mail_setting_get('smtp_pass', '') !== '' || $fileConfigured;
$isReady = ($curUser !== '' && $hasPass);

$activeNav = 'mail';
$pageTitle = 'Email Settings';
require __DIR__ . '/includes/head.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Email Settings</h1>
        <p class="page-sub">The Gmail account ProcureEase sends from — verification codes, password resets, and notifications.</p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <!-- Status -->
    <div class="card" style="border-left:4px solid <?= $isReady ? 'var(--green-500)' : 'var(--orange-600)' ?>; margin-bottom:18px;">
      <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <span style="font-size:22px;"><?= $isReady ? '✅' : '⚠️' ?></span>
        <div>
          <div style="font-weight:700; color:var(--ink); font-size:14px;">
            <?= $isReady ? 'Email is connected' : 'Email is not connected yet' ?>
          </div>
          <div style="font-size:12.5px; color:var(--muted); margin-top:2px;">
            <?php if ($isReady): ?>
              Sending as <strong><?= e($curUser) ?></strong><?= $fileConfigured ? ' (set in config/mail-config.php)' : '' ?>.
            <?php else: ?>
              Until this is set up, registration shows the verification code on screen instead of emailing it.
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(min(340px,100%),1fr)); gap:18px; align-items:start;">

      <!-- Sender account -->
      <div class="card">
        <div class="card-title" style="margin-bottom:4px;">Sender account</div>
        <div class="card-sub" style="margin-bottom:16px;">Use a Gmail account with 2-Step Verification turned on, and an app password.</div>

        <form method="post">
          <input type="hidden" name="do" value="save">

          <div class="field" style="margin-bottom:14px;">
            <label class="form-label" for="smtp_user">Gmail address</label>
            <input type="email" id="smtp_user" name="smtp_user" class="form-input"
                   value="<?= e($curUser) ?>" placeholder="procureease@ascot.edu.com" required>
          </div>

          <div class="field" style="margin-bottom:14px;">
            <label class="form-label" for="smtp_pass">App password</label>
            <input type="password" id="smtp_pass" name="smtp_pass" class="form-input"
                   placeholder="<?= $hasPass ? '•••••••••••• (leave blank to keep)' : 'abcd efgh ijkl mnop' ?>"
                   autocomplete="new-password">
            <div style="font-size:11.5px; color:var(--muted); margin-top:5px;">
              16 characters from Google. Spaces are fine — they're removed automatically.
            </div>
          </div>

          <div class="field" style="margin-bottom:18px;">
            <label class="form-label" for="smtp_from_name">Sender name</label>
            <input type="text" id="smtp_from_name" name="smtp_from_name" class="form-input"
                   value="<?= e($curName) ?>" placeholder="ProcureEase — ASCOT">
          </div>

          <button type="submit" class="btn btn-primary" style="padding:9px 22px; font-size:13px;">💾 Save</button>
        </form>
      </div>

      <!-- How to + test -->
      <div class="card">
        <div class="card-title" style="margin-bottom:4px;">Getting an app password</div>
        <div class="card-sub" style="margin-bottom:14px;">One-time setup, about two minutes.</div>

        <ol style="font-size:13px; color:var(--ink); line-height:1.75; padding-left:20px; margin:0 0 20px;">
          <li>Sign in to the Gmail account you want to send from.</li>
          <li>Open <strong>myaccount.google.com/security</strong> and turn on <strong>2-Step Verification</strong>.</li>
          <li>Open <strong>myaccount.google.com/apppasswords</strong>.</li>
          <li>Name it <em>ProcureEase</em> and click <strong>Create</strong>.</li>
          <li>Copy the 16-character password and paste it here.</li>
        </ol>

        <div style="border-top:1px solid var(--border); padding-top:16px;">
          <div class="card-title" style="font-size:14px; margin-bottom:4px;">Send a test email</div>
          <div class="card-sub" style="margin-bottom:12px;">Confirm it works before anyone registers.</div>
          <form method="post" style="display:flex; gap:8px; flex-wrap:wrap;">
            <input type="hidden" name="do" value="test">
            <input type="email" name="test_to" class="form-input" style="flex:1; min-width:200px;"
                   value="<?= e($u['email'] ?? '') ?>" placeholder="you@ascot.edu.com" required>
            <button type="submit" class="btn btn-secondary" style="padding:9px 18px; font-size:13px;">📧 Send test</button>
          </form>
        </div>
      </div>
    </div>

    <!-- ─── Google Sign-In ─── -->
    <?php
      require_once __DIR__ . '/config/google-oauth.php';
      $gid      = mail_setting_get('google_client_id', '');
      $gHasSec  = mail_setting_get('google_client_secret', '') !== '';
      $gReady   = google_is_configured();
      $callback = google_redirect_uri();
    ?>
    <div class="card" style="margin-top:18px; border-left:4px solid <?= $gReady ? 'var(--green-500)' : 'var(--orange-600)' ?>;">
      <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px;">
        <svg viewBox="0 0 48 48" width="20" height="20" aria-hidden="true">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24s.92 7.54 2.56 10.78l7.97-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        <div class="card-title" style="margin:0;">Google Sign-In</div>
        <span style="margin-left:auto; font-size:11.5px; font-weight:700; color:<?= $gReady ? '#14532d' : 'var(--orange-600)' ?>;">
          <?= $gReady ? '✅ Connected' : '⚠️ Not set up' ?>
        </span>
      </div>

      <?php $gSource = google_config_source(); ?>
      <?php if ($gSource === 'file'): ?>
        <div style="background:var(--navy-50); border-left:4px solid #2f578a; padding:11px 14px; border-radius:8px; margin-bottom:16px; font-size:12.5px; color:var(--ink);">
          Currently using the credentials set in <strong>config/google-config.php</strong>.
          Saving here will override them with database values.
        </div>
      <?php elseif ($gSource === 'none'): ?>
        <div style="background:var(--orange-50); border-left:4px solid var(--orange-500); padding:11px 14px; border-radius:8px; margin-bottom:16px; font-size:12.5px; color:var(--orange-600);">
          <strong>No credentials found.</strong> The Google button will not appear on the sign-in page until you add them.
          To keep them through a database rebuild, you can also put them in <strong>config/google-config.php</strong>.
        </div>
      <?php endif; ?>

      <div style="background:var(--navy-50); border:1px solid var(--border); border-radius:9px; padding:12px 14px; margin-bottom:16px;">
        <div style="font-size:11.5px; font-weight:700; color:var(--ink); margin-bottom:4px;">Authorised redirect URI</div>
        <div style="font-size:12.5px; font-family:monospace; color:var(--ink); word-break:break-all;"><?= e($callback) ?></div>
        <div style="font-size:11.5px; color:var(--muted); margin-top:5px;">Paste this exactly into your Google OAuth client.</div>
      </div>

      <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr)); gap:18px;">
        <form method="post">
          <input type="hidden" name="do" value="google">
          <div class="field" style="margin-bottom:14px;">
            <label class="form-label" for="google_client_id">Client ID</label>
            <input type="text" id="google_client_id" name="google_client_id" class="form-input"
                   value="<?= e($gid) ?>" placeholder="1234567890-abc123.apps.googleusercontent.com">
          </div>
          <div class="field" style="margin-bottom:18px;">
            <label class="form-label" for="google_client_secret">Client secret</label>
            <input type="password" id="google_client_secret" name="google_client_secret" class="form-input"
                   placeholder="<?= $gHasSec ? '•••••••••••• (leave blank to keep)' : 'GOCSPX-…' ?>"
                   autocomplete="new-password">
          </div>
          <button type="submit" class="btn btn-primary" style="padding:9px 22px; font-size:13px;">💾 Save</button>
        </form>

        <div>
          <div style="font-size:13px; font-weight:700; color:var(--ink); margin-bottom:8px;">Where to get these</div>
          <ol style="font-size:12.5px; color:var(--ink); line-height:1.75; padding-left:20px; margin:0;">
            <li>Open <strong>console.cloud.google.com</strong> and create a project.</li>
            <li>Go to <strong>APIs &amp; Services → OAuth consent screen</strong>, choose <em>External</em>, fill in the app name and support email, then save.</li>
            <li>Go to <strong>APIs &amp; Services → Credentials → Create credentials → OAuth client ID</strong>.</li>
            <li>Application type: <strong>Web application</strong>.</li>
            <li>Under <strong>Authorised redirect URIs</strong>, paste the URI shown above.</li>
            <li>Copy the <strong>Client ID</strong> and <strong>Client secret</strong> here.</li>
          </ol>
        </div>
      </div>
    </div>
  </main>
</div>
</body>
</html>
