<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/phone.php';
require_login();
$u = current_user();
$__profileDepartments = [];
if (($u['role'] ?? '') === 'admin') {
    try { $__profileDepartments = db()->query("SELECT code, name FROM departments WHERE COALESCE(status, 'active') = 'active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $t) { $__profileDepartments = []; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Superadmin may directly change their own department, role and username.
    // Other accounts must continue to use the existing request/approval flows.
    if (isset($_POST['action']) && $_POST['action'] === 'update_admin_assignment') {
        if (($u['role'] ?? '') !== 'admin') {
            flash('error', 'Only the Superadmin can directly change these account fields.');
            header('Location: profile.php'); exit;
        }
        $newDept = strtoupper(trim((string)($_POST['dept_code'] ?? '')));
        $newRole = trim((string)($_POST['role'] ?? ''));
        $newUsername = trim((string)($_POST['username'] ?? ''));
        $allowedRoles = ['admin', 'dept_admin', 'budget', 'user'];
        $err = '';
        if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $newUsername)) {
            $err = 'Username must be 3–32 characters using letters, numbers, dot, dash or underscore.';
        } elseif (!in_array($newRole, $allowedRoles, true)) {
            $err = 'Please select a valid role.';
        } else {
            $d = db()->prepare("SELECT code FROM departments WHERE code = ? AND COALESCE(status, 'active') = 'active' LIMIT 1");
            $d->execute([$newDept]);
            if (!$d->fetchColumn()) $err = 'Please select an active department.';
        }
        if ($err === '') {
            $q = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
            $q->execute([$newUsername, (int)$u['id']]);
            if ($q->fetchColumn()) $err = 'That username is already in use.';
        }
        if ($err !== '') {
            flash('error', $err);
        } else {
            db()->prepare('UPDATE users SET dept_code = ?, role = ?, username = ? WHERE id = ?')
                ->execute([$newDept, $newRole, $newUsername, (int)$u['id']]);
            log_activity('Updated own admin assignment', 'user', $newUsername,
                'Changed own department to ' . $newDept . ', role to ' . $newRole . ', and username.');
            flash('success', 'Your department, role, and username have been updated.');
        }
        header('Location: profile.php'); exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'upload_avatar') {
        if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $type = mime_content_type($_FILES['avatar']['tmp_name']);
            if (!isset($allowed[$type])) {
                flash('error', 'Please upload a JPG, PNG, WEBP, or GIF image.');
            } elseif ($_FILES['avatar']['size'] > 3 * 1024 * 1024) {
                flash('error', 'Image must be under 3 MB.');
            } else {
                $dir = __DIR__ . '/uploads/avatars';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $fname = 'user' . (int)$u['id'] . '_' . time() . '.' . $allowed[$type];
                $dest  = $dir . '/' . $fname;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                    // Remove old avatar file if any
                    if (!empty($u['avatar_path'])) {
                        $old = __DIR__ . '/' . $u['avatar_path'];
                        if (is_file($old)) @unlink($old);
                    }
                    $rel = 'uploads/avatars/' . $fname;
                    $pos = trim($_POST['avatar_pos'] ?? '50% 50%');
                    if (!preg_match('/^\d{1,3}%\s+\d{1,3}%$/', $pos)) $pos = '50% 50%';
                    db()->prepare('UPDATE users SET avatar_path = ?, avatar_pos = ? WHERE id = ?')->execute([$rel, $pos, $u['id']]);
                    flash('success', 'Profile picture updated.');
                } else {
                    flash('error', 'Could not save the image. Please try again.');
                }
            }
        } else {
            flash('error', 'Please choose an image to upload.');
        }
        header('Location: profile.php'); exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'remove_avatar') {
        if (!empty($u['avatar_path'])) {
            $old = __DIR__ . '/' . $u['avatar_path'];
            if (is_file($old)) @unlink($old);
        }
        db()->prepare('UPDATE users SET avatar_path = NULL WHERE id = ?')->execute([$u['id']]);
        flash('success', 'Profile picture removed.');
        header('Location: profile.php'); exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'update_info') {
        /* ─────────────────────────────────────────────────────────────
           Self-service account update — available to every signed-in
           account (user, Department Admin, Budget and Procurement Unit alike).

           RESTRICTED FIELDS ARE NOT TOUCHED.
           dept_code, sub_unit's department, role, username, status and
           password_hash are never read from $_POST here and are absent
           from the UPDATE below. A tampered or replayed form therefore
           cannot move anyone between departments or change a role —
           those stay with the Procurement Unit.
           ───────────────────────────────────────────────────────────── */
        $first    = mb_substr(trim($_POST['first_name']     ?? ''), 0, 20);
        $mi       = mb_substr(trim($_POST['middle_initial'] ?? ''), 0, 2);
        $last     = mb_substr(trim($_POST['last_name']      ?? ''), 0, 20);
        // v104: Suffix (optional) — "jr" → "Jr.", "iii" → "III"; null: not a suffix
        require_once __DIR__ . '/config/name-suffix.php';
        $suffix   = name_suffix_norm(mb_substr(trim($_POST['suffix'] ?? ($u['suffix'] ?? '')), 0, 5));
        $sex      = trim($_POST['sex']      ?? '');
        $bday     = trim($_POST['birthday'] ?? '');
        $position = mb_substr(trim($_POST['position'] ?? ''), 0, 100);
        $email    = trim($_POST['email']    ?? '');
        $subUnit  = trim($_POST['sub_unit'] ?? '');

        // Contact: keep the digits, then accept the usual Philippine forms —
        // 0917…, +63 917…, or a bare 917… — and settle on the 11-digit format
        // registration uses, so older records tidy themselves up on first save.
        $contactRaw = trim((string)($_POST['contact'] ?? ''));
        $contact = $contactRaw === '' ? '' : ph_mobile_local($contactRaw);

        // v103: the name as it is written (each part capitalised); the middle initial in capitals
        $first = name_case($first); $last = name_case($last);
        $mi = mb_strtoupper($mi);
        // Same display-name rule registration uses, so the two agree
        if ($mi !== '' && substr($mi, -1) !== '.') $mi .= '.';
        $fullName = trim(preg_replace('/\s+/', ' ', $first . ' ' . $mi . ' ' . $last . ' ' . ($suffix ?? '')));

        // Programs belonging to THIS person's own department — never another's
        $myUnits = [];
        try {
            $q = db()->prepare('SELECT name FROM dept_units WHERE dept_code = ? ORDER BY display_order, name');
            $q->execute([$u['school_code'] ?? $u['dept_code']]);
            $myUnits = $q->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $t) { $myUnits = []; }
        // v81: in a school with programs, the account's Bachelor / Master decides which documents it
        // prepares, so it is set by the Procurement Unit (User Management), not here: it is kept as it is
        require_once __DIR__ . '/config/academic-units.php';
        if (au_has_units((string)($u['school_code'] ?? ''))) { $subUnit = trim((string)($u['sub_unit'] ?? '')); $myUnits = [$subUnit]; }

        $err = '';
        // Letters, and the marks inside real names (Dela Cruz, Ana-Marie,
        // D'Souza, Jr.) — the same rule as Register. No numbers.
        $__nameOk = fn(string $v) => preg_match("/^\p{L}[\p{L} .'\-]*$/u", $v) === 1;
        if ($first === '' || $last === '') {
            $err = 'First name and last name are required.';
        } elseif (!$__nameOk($first)) {
            $err = 'The first name can only contain letters. Numbers are not allowed.';
        } elseif (!$__nameOk($last)) {
            $err = 'The last name can only contain letters. Numbers are not allowed.';
        } elseif ($suffix === null) {
            $err = NAME_SUFFIX_MSG;
        } elseif ($email === '') {
            $err = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please enter a valid email address.';
        } elseif ($sex !== '' && !in_array($sex, ['Male', 'Female'], true)) {
            $err = 'Please select a valid sex.';
        } elseif ($bday !== '' && !strtotime($bday)) {
            $err = 'Please enter a valid birthday.';
        } elseif ($bday !== '' && strtotime($bday) > strtotime('2005-12-31')) {
            $err = 'Please enter a valid birthday.';
        } elseif ($position === '') {   // v123: required, as on Create account
            $err = 'Please choose your position from the list, or choose "Others" and type it.';
        } elseif ($contactRaw === '') {   // v123: required, as on Create account
            $err = 'Please enter your contact number (11 digits, for example 09171234567).';
        } elseif ($contactRaw !== '' && $contact === '') {
            $err = 'Contact number must be a Philippine mobile number, e.g. 09171234567.';
        } elseif ($subUnit !== '' && !in_array($subUnit, $myUnits, true)) {
            $err = 'Please choose a program / major offered by your department.';
        }

        // One address per account, so sign-in and password reset stay unambiguous
        if ($err === '') {
            $dupe = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $dupe->execute([$email, $u['id']]);
            if ($dupe->fetch()) $err = 'That email address is already used by another account.';
        }
        if ($err === '' && $contact !== '') {
            try {
                $allC = db()->prepare("SELECT id, contact FROM users WHERE contact IS NOT NULL AND contact <> '' AND id <> ?");
                $allC->execute([$u['id']]);
                foreach ($allC->fetchAll() as $__r) {
                    if (ph_mobile_local((string)$__r['contact']) === $contact) {
                        $err = 'That contact number is already used by another account.';
                        break;
                    }
                }
            } catch (Throwable $t) {}
        }

        if ($err !== '') {
            flash('error', $err);
        } else {
            // Personal fields only. No dept_code, no role, no username.
            db()->prepare('UPDATE users
                              SET first_name = ?, middle_initial = ?, last_name = ?,
                                  full_name = ?, sex = ?, birthday = ?,
                                  position = ?, email = ?, contact = ?, sub_unit = ?
                            WHERE id = ?')
                ->execute([
                    $first, $mi, $last,
                    $fullName, $sex, ($bday !== '' ? $bday : null),
                    $position, $email, $contact, ($subUnit !== '' ? $subUnit : null),
                    $u['id'],
                ]);
            // v104: the suffix (its own column, added when first needed)
            name_suffix_column();
            try { db()->prepare('UPDATE users SET suffix = ? WHERE id = ?')->execute([$suffix !== '' ? $suffix : null, $u['id']]); } catch (Throwable $t) {}

            $changed = [];
            if (($u['full_name'] ?? '') !== $fullName) $changed[] = 'name';
            if (($u['email']     ?? '') !== $email)    $changed[] = 'email';
            if (($u['contact']   ?? '') !== $contact)  $changed[] = 'contact';
            if (($u['position']  ?? '') !== $position) $changed[] = 'position';
            log_activity('Updated own account', 'user', $u['username'],
                $changed ? 'Changed: ' . implode(', ', $changed) : 'Saved account information');

            flash('success', 'Your account information has been updated.');
        }
        header('Location: profile.php'); exit;
    }
}

// The person's most recent username request, if any
$__unameReq = null;
try {
    $__q = db()->prepare('SELECT * FROM username_change_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $__q->execute([(int)$u['id']]);
    $__unameReq = $__q->fetch() ?: null;
} catch (Throwable $t) {}

$activeNav = 'profile';
$pageTitle = 'Profile';
require __DIR__ . '/includes/head.php';
?>
<div class="app">
<?php
  if ($u['role'] === 'admin')       require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($u['role'] === 'budget')  require __DIR__ . '/includes/sidebar-budget.php';
  else                              require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Profile</h1>
        <p class="page-sub">Manage your account information and password.</p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <!-- Profile header card -->
    <?php $avatarPos = $u['avatar_pos'] ?? '50% 50%'; ?>
    <div class="card" style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
      <?php if (!empty($u['avatar_path'])): ?>
        <img src="<?= e($u['avatar_path']) ?>?v=<?= time() ?>" alt="Profile picture"
             style="width: 72px; height: 72px; border-radius: 50%; object-fit: cover; object-position: <?= e($avatarPos) ?>; border: 2px solid var(--border);">
      <?php else: ?>
        <div style="width: 72px; height: 72px; border-radius: 50%; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 26px;">
          <?= e(initials($u['full_name'])) ?>
        </div>
      <?php endif; ?>
      <div style="flex: 1; min-width: 200px;">
        <div style="font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 600;"><?= e($u['full_name']) ?></div>
        <div style="font-size: 14px; color: var(--muted); margin-top: 4px;"><?= e($u['position']) ?> · <?= e($u['dept_name']) ?></div>
        <div style="font-size: 12px; color: var(--muted); margin-top: 6px;">
          Role: <?= e(role_label($u['role'])) ?> · Username: <?= e($u['username']) ?>
        </div>
      </div>
      <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <form method="POST" enctype="multipart/form-data" id="avatarUploadForm" style="margin: 0; display: flex; gap: 8px; align-items: center;">
          <input type="hidden" name="action" value="upload_avatar">
          <input type="hidden" name="avatar_pos" id="avatarPosInput" value="50% 50%">
          <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display: none;" onchange="onAvatarPicked(event)">
          <button type="button" onclick="document.getElementById('avatarInput').click()" class="btn btn-ghost" style="padding: 8px 16px; font-size: 13px;">
            📷 <?= !empty($u['avatar_path']) ? 'Change photo' : 'Upload photo' ?>
          </button>
        </form>
        <?php if (!empty($u['avatar_path'])): ?>
          <form method="POST" style="margin: 0;" data-confirm="Remove your profile picture?" data-confirm-title="Remove photo?" data-confirm-label="Remove" data-confirm-tone="danger">
            <input type="hidden" name="action" value="remove_avatar">
            <button type="submit" class="btn btn-ghost" style="padding: 8px 12px; font-size: 13px; color: var(--orange-600);">Remove</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Position-while-uploading modal -->
    <div id="avatarAdjustModal" style="display:none; position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center;">
      <div style="position:absolute; inset:0; background:rgba(19,52,88,0.45); backdrop-filter:blur(2px);" onclick="cancelAvatarPick()"></div>
      <div style="position:relative; background:var(--white); border-radius:16px; max-width:360px; width:calc(100% - 40px); padding:24px; box-shadow:0 20px 60px rgba(19,52,88,0.28); text-align:center;">
        <div style="font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:17px; color:var(--navy-800); margin-bottom:6px;">Position your photo</div>
        <div style="font-size:13px; color:var(--muted); margin-bottom:18px;">Drag the photo to center your face in the circle, then save.</div>
        <div id="avatarFrame" style="width:200px; height:200px; border-radius:50%; overflow:hidden; margin:0 auto 20px; border:3px solid var(--border); cursor:grab; position:relative; background:#000;">
          <img id="avatarAdjustImg" src="" alt="" style="width:100%; height:100%; object-fit:cover; object-position:50% 50%; pointer-events:none; user-select:none;">
        </div>
        <div style="display:flex; gap:10px; justify-content:center;">
          <button type="button" onclick="cancelAvatarPick()" class="btn btn-ghost" style="padding:9px 18px; font-size:13px;">Cancel</button>
          <button type="button" onclick="saveAvatarPick()" class="btn btn-primary" style="padding:9px 20px; font-size:13px;">Save photo</button>
        </div>
      </div>
    </div>
    <script>
      var posX = 50, posY = 50;
      var dragging = false, startX = 0, startY = 0, startPosX = 50, startPosY = 50;

      function onAvatarPicked(e) {
        var file = e.target.files && e.target.files[0];
        if (!file) return;
        if (file.size > 3 * 1024 * 1024) { alert('Image must be under 3 MB.'); e.target.value = ''; return; }
        var url = URL.createObjectURL(file);
        var img = document.getElementById('avatarAdjustImg');
        img.src = url;
        posX = 50; posY = 50;
        img.style.objectPosition = '50% 50%';
        document.getElementById('avatarPosInput').value = '50% 50%';
        document.getElementById('avatarAdjustModal').style.display = 'flex';
      }
      function cancelAvatarPick() {
        document.getElementById('avatarAdjustModal').style.display = 'none';
        document.getElementById('avatarInput').value = '';
      }
      function saveAvatarPick() {
        document.getElementById('avatarPosInput').value = Math.round(posX) + '% ' + Math.round(posY) + '%';
        document.getElementById('avatarUploadForm').submit();
      }

      (function() {
        var frame = document.getElementById('avatarFrame');
        var img   = document.getElementById('avatarAdjustImg');
        function apply() {
          posX = Math.max(0, Math.min(100, posX));
          posY = Math.max(0, Math.min(100, posY));
          img.style.objectPosition = posX + '% ' + posY + '%';
        }
        function down(e) {
          dragging = true; frame.style.cursor = 'grabbing';
          var p = e.touches ? e.touches[0] : e;
          startX = p.clientX; startY = p.clientY; startPosX = posX; startPosY = posY;
          e.preventDefault();
        }
        function move(e) {
          if (!dragging) return;
          var p = e.touches ? e.touches[0] : e;
          posX = startPosX - (p.clientX - startX) * 0.4;
          posY = startPosY - (p.clientY - startY) * 0.4;
          apply();
        }
        function up() { dragging = false; if (frame) frame.style.cursor = 'grab'; }
        if (frame) {
          frame.addEventListener('mousedown', down);
          frame.addEventListener('touchstart', down, {passive:false});
          document.addEventListener('mousemove', move);
          document.addEventListener('touchmove', move, {passive:false});
          document.addEventListener('mouseup', up);
          document.addEventListener('touchend', up);
        }
      })();
    </script>

    <?php
      /* Older accounts were created with a single display name and no separate
         parts. Split it for display so saving can never blank the name out. */
      $__first = trim($u['first_name']     ?? '');
      $__mi    = trim($u['middle_initial'] ?? '');
      $__last  = trim($u['last_name']      ?? '');
      if ($__first === '' && $__last === '' && trim($u['full_name'] ?? '') !== '') {
          $__parts = preg_split('/\s+/', trim($u['full_name']));
          $__first = array_shift($__parts) ?: '';
          $__last  = $__parts ? array_pop($__parts) : '';
          $__mi    = $__parts ? implode(' ', $__parts) : '';
      }
      // Contact shown in the 11-digit form the field accepts
      $__contact = ph_mobile_local((string)($u['contact'] ?? ''));

      // Programs offered by this person's own department
      $__myUnits = [];
      try {
          $__q = db()->prepare('SELECT name FROM dept_units WHERE dept_code = ? ORDER BY display_order, name');
          $__q->execute([$u['school_code'] ?? $u['dept_code']]);
          $__myUnits = $__q->fetchAll(PDO::FETCH_COLUMN);
      } catch (Throwable $t) { $__myUnits = []; }
      // v81: a school with programs: the program is shown under "Assignment" (set by the Procurement Unit)
      require_once __DIR__ . '/config/academic-units.php';
      $__auSchool = au_has_units((string)($u['school_code'] ?? ''));
      if ($__auSchool) $__myUnits = [];
    ?>
    <div class="card">
      <div class="card-title">Account information</div>
      <div class="card-sub">Edit your personal details and save — the changes take effect immediately.</div>
      <form method="POST">
        <input type="hidden" name="action" value="update_info">
        <div class="form-grid">
          <div>
            <label class="form-label" for="pf_first">First name *</label>
            <input type="text" id="pf_first" name="first_name" class="form-input"
                   value="<?= e($__first) ?>" maxlength="20" required data-only="name" autocapitalize="words"
                   title="Letters only — no numbers">
          </div>
          <div>
            <label class="form-label" for="pf_mi">Middle initial</label>
            <input type="text" id="pf_mi" name="middle_initial" class="form-input"
                   value="<?= e($__mi) ?>" maxlength="2" placeholder="e.g. D">
          </div>
          <div>
            <label class="form-label" for="pf_last">Last name *</label>
            <input type="text" id="pf_last" name="last_name" class="form-input"
                   value="<?= e($__last) ?>" maxlength="20" required data-only="name" autocapitalize="words"
                   title="Letters only — no numbers">
          </div>
          <div>
            <label class="form-label" for="pf_suffix">Suffix</label>
            <input type="text" id="pf_suffix" name="suffix" class="form-input"
                   value="<?= e($u['suffix'] ?? '') ?>" maxlength="4" placeholder="e.g. Jr."
                   title="Optional — Jr., Sr., II, III, IV">
          </div>
          <div>
            <label class="form-label" for="pf_sex">Sex</label>
            <select id="pf_sex" name="sex" class="form-select">
              <option value="">Select…</option>
              <option value="Male"   <?= ($u['sex'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
              <option value="Female" <?= ($u['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
            </select>
          </div>
          <div>
            <label class="form-label" for="pf_bday">Birthday</label>
            <input type="date" id="pf_bday" name="birthday" class="form-input"
                   value="<?= e($u['birthday'] ?? '') ?>" max="2005-12-31">
          </div>
          <div>
            <label class="form-label" for="pf_position">Position *</label>
            <?php /* v123: every account picks it the way Create account does — required */ ?>
            <?php $pcValue = (string)($u['position'] ?? ''); $pcRequired = true; require __DIR__ . '/includes/position-combo.php'; ?>
          </div>
          <div>
            <label class="form-label" for="pf_email">Email *</label>
            <input type="email" id="pf_email" name="email" class="form-input"
                   value="<?= e($u['email']) ?>" required>
          </div>
          <div>
            <label class="form-label" for="pf_contact">Contact number *</label>
            <input type="text" id="pf_contact" name="contact" class="form-input"
                   value="<?= e($__contact) ?>" inputmode="numeric" pattern="09[0-9]{9}" maxlength="16"
                   placeholder="09171234567" title="Philippine mobile number (e.g. 09171234567)" required><?php /* v123: required, as on Create account */ ?>
          </div>
          <?php if ($__myUnits): ?>
            <div class="form-full">
              <label class="form-label" for="pf_subunit">Program / Major</label>
              <select id="pf_subunit" name="sub_unit" class="form-select">
                <option value="">Select program…</option>
                <?php foreach ($__myUnits as $__un): ?>
                  <option value="<?= e($__un) ?>" <?= ($u['sub_unit'] ?? '') === $__un ? 'selected' : '' ?>><?= e($__un) ?></option>
                <?php endforeach; ?>
              </select>
              <div style="font-size: 11.5px; color: var(--muted); margin-top: 4px;">
                Programs offered by <?= e($u['dept_name']) ?>. Choosing one does not change your department.
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
          <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
      </form>
    </div>

    <!-- Assignment & sign-in: direct edits for Superadmin; request flow for other accounts -->
    <div class="card" style="margin-top: 16px;">
      <div class="card-title">Assignment &amp; sign-in</div>
      <?php if (($u['role'] ?? '') === 'admin'): ?>
        <div class="card-sub">As Superadmin, you can change your own department, role, and username directly.</div>
        <form method="POST">
          <input type="hidden" name="action" value="update_admin_assignment">
          <div class="form-grid">
            <div>
              <label class="form-label" for="pf_admin_dept">Department</label>
              <select class="form-select" id="pf_admin_dept" name="dept_code" required>
                <?php foreach ($__profileDepartments as $__pd): ?>
                  <option value="<?= e($__pd['code']) ?>" <?= (string)($u['school_code'] ?? $u['dept_code'] ?? '') === (string)$__pd['code'] || (string)($u['dept_code'] ?? '') === (string)$__pd['code'] ? 'selected' : '' ?>><?= e($__pd['name']) ?> (<?= e($__pd['code']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label" for="pf_admin_role">Role</label>
              <select class="form-select" id="pf_admin_role" name="role" required>
                <?php foreach (['admin' => 'Superadmin / Procurement Unit', 'dept_admin' => 'Department Admin', 'budget' => 'Budget Unit', 'user' => 'User'] as $__roleValue => $__roleText): ?>
                  <option value="<?= e($__roleValue) ?>" <?= ($u['role'] ?? '') === $__roleValue ? 'selected' : '' ?>><?= e($__roleText) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label" for="pf_admin_username">Username</label>
              <input type="text" class="form-input" id="pf_admin_username" name="username" value="<?= e($u['username']) ?>" pattern="[a-zA-Z0-9._-]{3,32}" minlength="3" maxlength="32" required>
            </div>
            <div>
              <label class="form-label">Account status</label>
              <input type="text" class="form-input" value="<?= e(ucfirst($u['status'] ?? 'active')) ?>" readonly>
            </div>
          </div>
          <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
            <button type="submit" class="btn btn-primary">Save assignment changes</button>
          </div>
        </form>
      <?php else: ?>
        <div class="card-sub">Department, role, and username changes must be requested and approved by the Procurement Unit.</div>
        <div class="form-grid">
          <div>
            <label class="form-label">Department</label>
            <input type="text" class="form-input" value="<?= e($__auSchool ? $u['school_name'] . ' (' . $u['school_code'] . ')' : ($u['dept_name'] ?: $u['dept_code'])) ?>" readonly>
          </div>
          <?php if ($__auSchool): ?>
          <div>
            <label class="form-label">Bachelor / Master</label>
            <input type="text" class="form-input" id="pfProgram" value="<?= e(trim((string)($u['sub_unit'] ?? '')) !== '' ? au_program_label((string)$u['school_code'], (string)$u['sub_unit']) : 'Whole school (all programs)') ?>" readonly>
          </div>
          <?php endif; ?>
          <div>
            <label class="form-label">Role</label>
            <input type="text" class="form-input" value="<?= e(role_label($u['role'])) ?>" readonly>
          </div>
          <div>
            <label class="form-label">Username</label>
            <input type="text" class="form-input" value="<?= e($u['username']) ?>" readonly>
            <?php if ($__unameReq && $__unameReq['status'] === 'pending'): ?>
              <div style="font-size: 11.5px; color: var(--orange-600); margin-top: 5px;">Waiting on the Procurement Unit — you asked for “<?= e($__unameReq['requested_username']) ?>”.</div>
            <?php else: ?>
              <button type="button" onclick="document.getElementById('unameModal').classList.add('active')" style="background:none;border:none;padding:0;margin-top:5px;cursor:pointer;font:inherit;font-size:11.5px;color:var(--navy-700);font-weight:600;text-decoration:underline;text-underline-offset:2px;">Request a different username</button>
            <?php endif; ?>
          </div>
          <div>
            <label class="form-label">Account status</label>
            <input type="text" class="form-input" value="<?= e(ucfirst($u['status'] ?? 'active')) ?>" readonly>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Ask the Procurement Unit for a different username -->
<div class="modal-overlay" id="unameModal" onclick="if(event.target===this)this.classList.remove('active')">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Request a different username</div>
        <div class="modal-sub">The Procurement Unit assigns usernames, so this goes to them to decide.</div>
      </div>
      <button type="button" class="modal-close" onclick="document.getElementById('unameModal').classList.remove('active')">&times;</button>
    </div>
    <form method="POST" action="api/request-username-change.php">
      <label class="form-label" for="req_uname">Username you would like</label>
      <input type="text" class="form-input" id="req_uname" name="requested_username"
             pattern="[a-zA-Z0-9._-]{3,32}" maxlength="32" required
             placeholder="e.g. <?= e(strtolower((string)($u['dept_code'] ?? 'dept'))) ?>-01-002">
      <div style="font-size:11.5px; color:var(--muted); margin:5px 0 12px;">
        3&ndash;32 characters: letters, digits, dot, dash or underscore. The Procurement Unit may adjust it
        to keep your department's numbering.
      </div>
      <label class="form-label" for="req_reason">Why you need the change</label>
      <textarea class="form-textarea" id="req_reason" name="reason" required
                placeholder="e.g. my surname changed, or the current one has a typo"></textarea>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('unameModal').classList.remove('active')">Cancel</button>
        <button type="submit" class="btn btn-primary">Send request</button>
      </div>
    </form>
  </div>
</div>
<script src="assets/name-case.js"></script>
<script>
  // First and last name: letters only (the same rule as Register). A number
  // typed or pasted is taken out at once; the server checks again.
  // v104: and written as a name is ("JHADE" → "Jhade"), as it is typed.
  (function () {
    function clean(v) {
      try { return window.peNameCase(v.replace(/[^\p{L} .'\-]/gu, '')); }
      catch (e) { return window.peNameCase(v.replace(/[^A-Za-zÀ-ÖØ-öø-ÿÑñ .'\-]/g, '')); }
    }
    document.querySelectorAll('[data-only="name"]').forEach(function (el) {
      el.addEventListener('input', function () {
        var before = el.value, after = clean(before);
        if (after === before) return;
        var at = el.selectionStart, dropped = before.slice(0, at).length - clean(before.slice(0, at)).length;
        el.value = after;
        try { el.setSelectionRange(at - dropped, at - dropped); } catch (e) {}
      });
      el.addEventListener('paste', function () { setTimeout(function () { el.value = clean(el.value); }, 0); });
    });
  })();
</script>
</body></html>
