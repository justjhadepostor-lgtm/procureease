<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/backup-lib.php';   // backup_last_at(), backup_schedule_days()
require_admin();
$activeNav = 'settings';
$pageTitle = 'Settings';

// Auto-create the activity_logs / settings / backups tables if they don't exist yet
ensure_admin_tables();

$pdo = db();

// ─── Handle POST save ───────────────────────────────────────
// Every value is checked before it is kept; each one takes effect across the
// system (config/app-settings.php). The fiscal year is changed separately,
// through the same step as the Set Year page, so the year before is archived.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $in  = $_POST['settings'] ?? [];
    $upd = $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $put = function (string $k, string $v) use ($pdo, $upd) {
        // delete + insert as well, so it works on any database
        $pdo->prepare('DELETE FROM settings WHERE key_name = ?')->execute([$k]);
        $upd->execute([$k, $v]);
    };
    $errors = [];
    $clamp = function (string $k, int $lo, int $hi, string $label) use ($in, &$errors, $put) {
        $raw = trim((string)($in[$k] ?? ''));
        if ($raw === '' || !ctype_digit($raw)) { $errors[] = "$label must be a whole number."; return; }
        $v = (int)$raw;
        if ($v < $lo || $v > $hi) { $errors[] = "$label must be from $lo to $hi."; return; }
        $put($k, (string)$v);
    };

    // Institution
    $name = trim((string)($in['institution_name'] ?? ''));
    if ($name === '') $errors[] = 'Institution name cannot be empty.';
    else $put('institution_name', mb_substr($name, 0, 150));
    $put('institution_address', mb_substr(trim((string)($in['institution_address'] ?? '')), 0, 255));
    $put('governing_law', mb_substr(trim((string)($in['governing_law'] ?? '')), 0, 150));

    // Notifications (an unticked box is not sent, so both states are written)
    foreach (['notify_on_submission', 'notify_on_revision'] as $flag) $put($flag, isset($in[$flag]) ? '1' : '0');

    // Backups
    $sched = (string)($in['backup_schedule'] ?? '');
    if (!in_array($sched, ['daily', 'weekly', 'monthly', 'manual'], true)) $errors[] = 'Choose how often backups run.';
    else $put('backup_schedule', $sched);

    // Help & documentation
    foreach (['support_ict_email' => 'Technical support email', 'support_procurement_email' => 'Procurement questions email'] as $k => $label) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[] = "$label is not a valid email address.";
        else $put($k, $v);
    }

    app_settings_all(true);
    log_activity('Updated settings', 'settings', 'system', $errors ? 'Saved, with ' . count($errors) . ' value(s) not accepted.' : 'Saved all settings.');
    if ($errors) {
        flash('error', 'Saved, except: ' . implode(' ', $errors));
    } else {
        flash('success', 'Settings saved. They are in effect across the system now.');
    }
    header('Location: settings.php');
    exit;
}

// ─── Change password ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $u = current_user();
    $cur = $_POST['current_password'] ?? '';
    $new = $_POST['new_password']     ?? '';
    $con = $_POST['confirm_password'] ?? '';
    if (!password_verify($cur, $u['password_hash'])) {
        flash('error', 'Current password is incorrect.');
    } elseif (password_problem($new) !== '') {
        flash('error', password_problem($new));
    } elseif ($new !== $con) {
        flash('error', 'New passwords do not match.');
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        log_activity('Changed password', 'security', 'self', 'Admin changed their own password.');
        flash('success', 'Password changed successfully.');
    }
    header('Location: settings.php#security');
    exit;
}

// ─── Save preference (high contrast) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pref') {
    $u   = current_user();
    $key = $_POST['key']   ?? '';
    $val = $_POST['value'] ?? '';
    if (in_array($key, ['high_contrast'], true)) {
        $pdo->prepare('INSERT INTO user_prefs (user_id, key_name, value) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute([$u['id'], $key, $val]);
    }
    header('Location: settings.php#appearance');
    exit;
}

// ─── Load settings ──────────────────────────────────────────
$rows = $pdo->query('SELECT key_name, value FROM settings')->fetchAll();
$settings = [];
foreach ($rows as $r) $settings[$r['key_name']] = $r['value'];

// Load current user's preferences
$u = current_user();
$prefs = [];
$stmt = $pdo->prepare('SELECT key_name, value FROM user_prefs WHERE user_id = ?');
$stmt->execute([$u['id']]);
foreach ($stmt->fetchAll() as $r) $prefs[$r['key_name']] = $r['value'];

function s(array $settings, string $key, string $default = ''): string {
    return $settings[$key] ?? $default;
}
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<style>
  .pw-match { font-size: 12.5px; margin-top: 6px; font-weight: 600; }
  .card-sub a { color: var(--navy-700); font-weight: 600; }
  body[data-theme="dark"] .card-sub a { color: #9fd0ff; }
</style>
<div class="app">
  <?php require_once __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-sub">System-wide configuration for ProcureEase.</p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <?php
      $__backLast  = backup_last_at();
      $__backDays  = backup_schedule_days();
      $__fyNow     = active_fy();
    ?>
    <!-- The fiscal year is changed through the Set Year step, which archives the year before -->
    <form method="POST" action="api/set-fiscal-year.php" id="fyForm"
          data-confirm="Every PPMP, APP-CSE, Non-PS list, Annex and Market Scoping of the years before the new one is archived for everyone. New documents start in the new year."
          data-confirm-title="Change the fiscal year?" data-confirm-label="Change year" data-confirm-tone="danger">
      <input type="hidden" name="back" value="settings.php">
    </form>

    <form method="POST" id="settingsForm">
      <input type="hidden" name="action" value="save">
      <div class="card">
        <div class="card-title">Institution</div>
        <div class="form-grid">
          <div class="form-full">
            <label class="form-label" for="st_name">Institution name</label>
            <input type="text" class="form-input" id="st_name" name="settings[institution_name]" maxlength="150"
                   value="<?= e(app_setting('institution_name')) ?>" required>
          </div>
          <div class="form-full">
            <label class="form-label" for="st_addr">Institution address</label>
            <input type="text" class="form-input" id="st_addr" name="settings[institution_address]" maxlength="255"
                   value="<?= e(app_setting('institution_address')) ?>">
          </div>
          <div class="form-full">
            <label class="form-label" for="st_law">Governing law</label>
            <input type="text" class="form-input" id="st_law" name="settings[governing_law]" maxlength="150"
                   value="<?= e(app_setting('governing_law')) ?>">
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-title">Procurement cycle</div>
        <div class="card-sub">The fiscal year every new document belongs to.</div>
        <div class="form-grid">
          <div>
            <label class="form-label" for="st_fy">Active fiscal year</label>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
              <select class="form-select" id="st_fy" name="fy" form="fyForm" style="flex:1 1 140px;">
                <?php for ($y = $__fyNow - 2; $y <= $__fyNow + 3; $y++): ?>
                  <option value="<?= $y ?>" <?= $y === $__fyNow ? 'selected' : '' ?>>FY <?= $y ?></option>
                <?php endfor; ?>
              </select>
              <button type="submit" form="fyForm" class="btn btn-ghost" id="fyChange" style="padding:8px 14px; font-size:13px;">Change year</button>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-title">Notifications</div>
        <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 8px;">
          <label style="display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer;">
            <input type="checkbox" name="settings[notify_on_submission]" value="1" id="st_nsub"
                   <?= app_setting('notify_on_submission') === '1' ? 'checked' : '' ?>
                   style="width: 16px; height: 16px; accent-color: var(--orange-500);">
            <span><strong>Email on a new submission</strong></span>
          </label>
          <label style="display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer;">
            <input type="checkbox" name="settings[notify_on_revision]" value="1" id="st_nrev"
                   <?= app_setting('notify_on_revision') === '1' ? 'checked' : '' ?>
                   style="width: 16px; height: 16px; accent-color: var(--orange-500);">
            <span><strong>Email on a revision sent back</strong></span>
          </label>
        </div>
      </div>

      <div class="card">
        <div class="card-title">Backup schedule</div>
        <div class="card-sub">Automatic encrypted backups of the whole database, kept on <a href="backup.php">Backup &amp; Restore</a>.</div>
        <div style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
          <div>
            <label class="form-label" for="st_backup">Frequency</label>
            <select class="form-select" id="st_backup" name="settings[backup_schedule]" style="max-width: 260px;">
              <?php foreach (['daily'=>'Daily', 'weekly'=>'Weekly', 'monthly'=>'Monthly (every 30 days)', 'manual'=>'Manual only'] as $val=>$label): ?>
                <option value="<?= $val ?>" <?= app_setting('backup_schedule') === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <a href="backup.php" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">Back up now</a>
        </div>
      </div>

      <div class="card" id="help-settings">
        <div class="card-title">Help &amp; documentation</div>
        <div class="card-sub">The user guide for every role, and who to contact. The addresses below are shown on the Help page.</div>
        <div class="form-grid">
          <div>
            <label class="form-label" for="st_ict">Technical support email</label>
            <input type="email" class="form-input" id="st_ict" name="settings[support_ict_email]" value="<?= e(app_setting('support_ict_email')) ?>">
          </div>
          <div>
            <label class="form-label" for="st_pro">Procurement questions email</label>
            <input type="email" class="form-input" id="st_pro" name="settings[support_procurement_email]" value="<?= e(app_setting('support_procurement_email')) ?>">
          </div>
        </div>
        <a href="help.php" class="btn btn-primary" style="margin-top: 14px;">Open the Help page</a>
      </div>

      <div style="display: flex; gap: 10px; margin-bottom: 30px; justify-content: center;">
        <button type="submit" class="btn btn-primary" id="saveSettings">Save all settings</button>
        <a href="settings.php" class="btn btn-ghost">Discard changes</a>
      </div>
    </form>

    <!-- ─── Change password ─── -->
    <div class="card" id="security">
      <div class="card-title">Change password</div>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <input type="text" name="username" value="<?= e($u['username'] ?? '') ?>"
               autocomplete="username" readonly hidden aria-hidden="true">
        <div class="form-grid">
          <div>
            <label class="form-label" for="cur_pw">Current password</label>
            <input type="password" id="cur_pw" name="current_password" class="form-input" required autocomplete="current-password">
          </div>
          <div></div>
          <div>
            <label class="form-label" for="new_pw">New password</label>
            <input type="password" id="new_pw" name="new_password" class="form-input" required minlength="<?= password_min_length() ?>" autocomplete="new-password" placeholder="At least <?= password_min_length() ?> characters" oninput="checkStrength()">
            <div class="field-hint" id="pwHint"></div>
          </div>
          <div>
            <label class="form-label" for="con_pw">Confirm new password</label>
            <input type="password" id="con_pw" name="confirm_password" class="form-input" required minlength="<?= password_min_length() ?>" autocomplete="new-password" oninput="checkMatch()">
            <div class="field-hint" id="pwMatch"></div>
          </div>
        </div>
        <div style="margin-top: 14px; display: flex; justify-content: center;">
          <button type="submit" class="btn btn-primary">Change password</button>
        </div>
      </form>
    </div>

    <!-- ─── Appearance (High contrast) ─── -->
    <div class="card" id="appearance">
      <div class="card-title">Appearance</div>
      <div class="card-sub">Personal preferences for the interface. Applied to your account only.</div>

      <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 0;">
        <div>
          <div style="font-weight: 600; color: var(--ink); font-size: 14px;">High contrast</div>
          <div style="font-size: 12.5px; color: var(--muted); margin-top: 2px;">Increase text and border contrast for better readability.</div>
        </div>
        <form method="POST" style="margin: 0;">
          <input type="hidden" name="action" value="save_pref">
          <input type="hidden" name="key" value="high_contrast">
          <input type="hidden" name="value" value="<?= ($prefs['high_contrast'] ?? '0') === '1' ? '0' : '1' ?>">
          <button type="submit" class="btn <?= ($prefs['high_contrast'] ?? '0') === '1' ? 'btn-primary' : 'btn-ghost' ?>" style="padding: 8px 18px; font-size: 13px;">
            <?= ($prefs['high_contrast'] ?? '0') === '1' ? 'On' : 'Off' ?>
          </button>
        </form>
      </div>
    </div>

  </main>
</div>

<script>
  // ─── Password strength (same as register.php) ───
  const PW_RULES = {
    len:    function(v){ return v.length >= <?= password_min_length() ?>; },
    upper:  function(v){ return /[A-Z]/.test(v); },
    lower:  function(v){ return /[a-z]/.test(v); },
    digit:  function(v){ return /[0-9]/.test(v); },
    symbol: function(v){ return /[^A-Za-z0-9]/.test(v); }
  };

  function checkStrength(){
    const v = document.getElementById('new_pw').value;

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
    const pw = document.getElementById('new_pw').value;
    const cf = document.getElementById('con_pw');
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
    const form = document.querySelector('form[action="settings.php"]');
    if (!form) return;
    form.addEventListener('submit', function(e){
      const pwField = document.getElementById('new_pw');
      if (!pwField) return;
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
        alert('The two new passwords do not match.');
        document.getElementById('con_pw').focus();
      }
    });
  })();

</script>
</body>
</html>
