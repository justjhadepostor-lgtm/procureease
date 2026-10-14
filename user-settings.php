<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/department-change.php';
require_login();
ensure_admin_tables();
ensure_department_change_requests();

$u = current_user();
$activeNav = 'settings';
$pageTitle = 'Settings';
$pdo = db();

// ─── Handle POST actions ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request_department_change') {
        $requested = trim((string)($_POST['requested_dept'] ?? ''));
        $note = trim((string)($_POST['department_change_note'] ?? ''));
        $check = $pdo->prepare("SELECT code FROM departments WHERE code = ? AND COALESCE(status,'active') = 'active'");
        $check->execute([$requested]);
        if ($requested === '' || !$check->fetch()) {
            flash('error', 'Choose an active department.');
        } elseif ($requested === (string)($u['dept_code'] ?? '')) {
            flash('error', 'That is already your current department.');
        } else {
            $pending = $pdo->prepare("SELECT id FROM department_change_requests WHERE user_id = ? AND status = 'pending' LIMIT 1");
            $pending->execute([(int)$u['id']]);
            if ($pending->fetch()) { flash('error', 'You already have a pending department-change request.'); }
            else {
                $pdo->prepare("INSERT INTO department_change_requests (user_id,current_dept,requested_dept,note) VALUES (?,?,?,?)")
                    ->execute([(int)$u['id'], (string)($u['dept_code'] ?? ''), $requested, mb_substr($note,0,1000)]);
                log_activity('Requested department change', 'user', (string)$u['username'], 'Requested ' . $requested);
                flash('success', 'Your department-change request was sent to the Superadmin.');
            }
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $newpw   = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (password_problem($newpw) !== '') {
            flash('error', password_problem($newpw));
        } elseif ($newpw !== $confirm) {
            flash('error', 'The new passwords do not match.');
        } else {
            $hash = password_hash($newpw, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $u['id']]);
            log_activity('Changed password', 'account', $u['username']);
            flash('success', 'Password changed successfully.');
        }
    } elseif ($action === 'save_pref') {
        $key = in_array($_POST['key'] ?? '', ['high_contrast'], true) ? $_POST['key'] : '';
        $val = ($_POST['value'] ?? '0') === '1' ? '1' : '0';
        if ($key) {
            $pdo->prepare('INSERT INTO user_prefs (user_id, key_name, value) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE value = VALUES(value)')
                ->execute([$u['id'], $key, $val]);
            flash('success', 'Preference saved.');
        }
    }
    header('Location: user-settings.php');
    exit;
}

// Load department choices and this user's pending request
$departmentChoices = []; $myDeptRequest = null;
try { $departmentChoices = $pdo->query("SELECT code,name FROM departments WHERE COALESCE(status,'active')='active' ORDER BY name")->fetchAll(); } catch (Throwable $t) {}
try { $rq = $pdo->prepare("SELECT * FROM department_change_requests WHERE user_id=? AND status='pending' ORDER BY id DESC LIMIT 1"); $rq->execute([(int)$u['id']]); $myDeptRequest = $rq->fetch() ?: null; } catch (Throwable $t) {}

// Load current prefs
$prefs = ['dark_mode' => '0', 'high_contrast' => '0'];
try {
    $s = $pdo->prepare('SELECT key_name, value FROM user_prefs WHERE user_id = ?');
    $s->execute([$u['id']]);
    foreach ($s->fetchAll() as $r) $prefs[$r['key_name']] = $r['value'];
} catch (Throwable $t) { /* user_prefs may not exist yet */ }
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<style>
  .pw-match { font-size: 12.5px; margin-top: 6px; font-weight: 600; }
</style>
<div class="app">
<?php
  if ($u['role'] === 'admin')      require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($u['role'] === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
  else                             require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-sub">Manage your password and personal display preferences.</p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <!-- Department change request -->
    <div class="card" style="margin-bottom:18px;">
      <div class="card-title">Request department change</div>
      <div class="card-sub">Send a request to the Superadmin. Your department will not change until it is approved.</div>
      <?php if ($myDeptRequest): ?>
        <p style="margin:10px 0 0;color:var(--muted);font-size:13px;">Pending request: <strong><?= e($myDeptRequest['current_dept']) ?> → <?= e($myDeptRequest['requested_dept']) ?></strong></p>
      <?php else: ?>
        <form method="POST" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:14px;">
          <input type="hidden" name="action" value="request_department_change">
          <div style="min-width:220px;flex:1;"><label class="form-label" for="requested_dept">New department</label>
            <select class="form-select" id="requested_dept" name="requested_dept" required>
              <option value="">Choose department…</option>
              <?php foreach ($departmentChoices as $dc): if ($dc['code'] === ($u['dept_code'] ?? '')) continue; ?>
                <option value="<?= e($dc['code']) ?>"><?= e($dc['name']) ?> (<?= e($dc['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="min-width:220px;flex:2;"><label class="form-label" for="department_change_note">Reason (optional)</label><input class="form-input" id="department_change_note" name="department_change_note" maxlength="1000" placeholder="Reason for the request"></div>
          <button class="btn btn-primary" type="submit">Send request</button>
        </form>
      <?php endif; ?>
    </div>

    <!-- ─── Change password ─── -->
    <div class="card" id="security">
      <div class="card-title">Change password</div>
      <div class="card-sub">Update your sign-in password. You'll stay signed in on this device.</div>
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

    <!-- ─── Help ─── -->
    <div class="card" style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
      <div>
        <div class="card-title" style="margin: 0;">Help &amp; documentation</div>
        <div class="card-sub" style="margin: 2px 0 0;">User guide, FAQ, and support contacts.</div>
      </div>
      <a href="help.php" class="btn btn-primary" style="white-space: nowrap;">Open help page</a>
    </div>

    <?php
      // Check for an existing pending deletion request
      $pendingDeletion = false;
      try {
          $chk = db()->prepare("SELECT COUNT(*) FROM account_deletion_requests WHERE user_id = ? AND status = 'pending'");
          $chk->execute([(int)$u['id']]);
          $pendingDeletion = (int)$chk->fetchColumn() > 0;
      } catch (Throwable $t) {}
    ?>
    <?php if (($u['role'] ?? '') !== 'admin'): ?>
    <!-- ─── Danger zone: delete account ─── -->
    <div class="card" style="border: 1.5px solid var(--orange-100); background: var(--orange-50);">
      <div class="card-title" style="color: var(--orange-600);">⚠ Delete account</div>
      <div class="card-sub" style="color: var(--ink);">
        An administrator must approve it. Your department's PPMPs are kept.
      </div>
      <?php if ($pendingDeletion): ?>
        <div style="margin-top: 12px; padding: 12px 16px; background: white; border: 1px solid var(--orange-100); border-radius: 10px; font-size: 13px; color: var(--orange-600); font-weight: 600;">
          ⏳ Your deletion request is pending admin approval.
        </div>
      <?php else: ?>
        <form method="POST" action="api/request-account-deletion.php" style="margin-top: 12px;"
              data-confirm="Your account-deletion request will be sent to the administrator for approval. You'll keep access until they approve it."
              data-confirm-title="Request account deletion?" data-confirm-label="Send request" data-confirm-tone="danger">
          <label class="form-label" for="del_reason">Reason (optional)</label>
          <textarea id="del_reason" name="reason" class="form-textarea" rows="2"
                    placeholder="Let the admin know why you're leaving…" style="margin-bottom: 12px;"></textarea>
          <button type="submit" class="btn" style="background: var(--accent); color: var(--accent-ink); border-color: var(--accent);">
            Request account deletion
          </button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
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
    const form = document.querySelector('form[action="user-settings.php"]');
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
