<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/positions.php';
require_once __DIR__ . '/config/login-guard.php';   // v79: Sign-in lockouts
require_once __DIR__ . '/config/academic-units.php';  // v81: programs of a school
require_once __DIR__ . '/config/department-change.php';
require_admin();
ensure_admin_tables();
ensure_department_change_requests();

$activeNav = 'pending-users';
$pageTitle = 'Pending Users';
$pdo = db();
$u   = current_user();

// ─── Handle POST approve/reject and Superadmin user edits ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_user_info') {
        $editId = (int)($_POST['edit_user_id'] ?? 0);
        $find = $pdo->prepare("SELECT * FROM users WHERE id=? AND status IN ('active','blocked') AND role IN ('user','dept_admin')");
        $find->execute([$editId]);
        $targetUser = $find->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            flash('error', 'User not found or cannot be edited from this page.');
            header('Location: pending-users.php'); exit;
        }
        $first = trim((string)($_POST['first_name'] ?? ''));
        $middle = trim((string)($_POST['middle_initial'] ?? ''));
        $last = trim((string)($_POST['last_name'] ?? ''));
        $suffix = trim((string)($_POST['suffix'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $contact = trim((string)($_POST['contact'] ?? ''));
        $position = trim((string)($_POST['position'] ?? ''));
        $sex = trim((string)($_POST['sex'] ?? ''));
        $birthday = trim((string)($_POST['birthday'] ?? ''));
        $dept = trim((string)($_POST['dept_code'] ?? ''));
        $role = trim((string)($_POST['role'] ?? 'user'));
        $username = trim((string)($_POST['username'] ?? ''));
        if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
            flash('error', 'Please provide first and last name, a valid email, and a username of 3–32 letters, digits, dots, dashes, or underscores.');
            header('Location: pending-users.php'); exit;
        }
        if (!in_array($role, ['user','dept_admin'], true)) $role = 'user';
        $validDept = $pdo->prepare("SELECT code FROM departments WHERE code=? AND COALESCE(status,'active')='active'");
        $validDept->execute([$dept]);
        if (!$validDept->fetchColumn()) {
            flash('error', 'Choose an active department.');
            header('Location: pending-users.php'); exit;
        }
        $dupe = $pdo->prepare('SELECT id FROM users WHERE username=? AND id<>?');
        $dupe->execute([$username, $editId]);
        if ($dupe->fetch()) {
            flash('error', 'That username is already in use.');
            header('Location: pending-users.php'); exit;
        }
        $dupeEmail = $pdo->prepare('SELECT id FROM users WHERE email=? AND id<>?');
        $dupeEmail->execute([$email, $editId]);
        if ($dupeEmail->fetch()) {
            flash('error', 'That email address is already in use.');
            header('Location: pending-users.php'); exit;
        }
        $fullName = trim($first . ' ' . ($middle !== '' ? $middle . ' ' : '') . $last . ($suffix !== '' ? ' ' . $suffix : ''));
        $birthdayValue = '';
        if ($birthday !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $birthday);
            if (!$dt || $dt->format('Y-m-d') !== $birthday) {
                flash('error', 'Enter a valid birthday.'); header('Location: pending-users.php'); exit;
            }
            $birthdayValue = $birthday;
        }
        $pdo->prepare('UPDATE users SET first_name=?, middle_initial=?, last_name=?, suffix=?, full_name=?, email=?, contact=?, position=?, sex=?, birthday=?, dept_code=?, sub_unit=NULL, role=?, username=? WHERE id=?')
            ->execute([$first,$middle,$last,$suffix,$fullName,$email,$contact,$position,$sex,$birthdayValue ?: null,$dept,$role,$username,$editId]);
        log_activity('Updated user information', 'user', $username, 'Updated profile, department, role, and sign-in details for ' . $fullName);
        flash('success', 'User information updated successfully.');
        header('Location: pending-users.php'); exit;
    }
    if (in_array($action, ['approve_department_request','decline_department_request'], true)) {
        $rid = (int)($_POST['request_id'] ?? 0);
        $rq = $pdo->prepare("SELECT r.*,u.full_name,u.username FROM department_change_requests r JOIN users u ON u.id=r.user_id WHERE r.id=? AND r.status='pending'");
        $rq->execute([$rid]); $request = $rq->fetch();
        if (!$request) flash('error','That department-change request is no longer pending.');
        elseif ($action === 'approve_department_request') {
            $valid = $pdo->prepare("SELECT code FROM departments WHERE code=? AND COALESCE(status,'active')='active'"); $valid->execute([$request['requested_dept']]);
            if (!$valid->fetch()) flash('error','The requested department is no longer active.');
            else {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET dept_code=?,sub_unit=NULL WHERE id=?')->execute([$request['requested_dept'],(int)$request['user_id']]);
                $pdo->prepare("UPDATE department_change_requests SET status='approved',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([$u['full_name'] ?? 'Superadmin',$rid]);
                $pdo->commit();
                log_activity('Approved department change','user',$request['username'],$request['current_dept'].' → '.$request['requested_dept']);
                notify_user((int)$request['user_id'],'approval','Department change approved','Your department was changed to '.$request['requested_dept'].'.','user-settings.php');
                flash('success','Approved department change for '.$request['full_name'].'.');
            }
        } else {
            $pdo->prepare("UPDATE department_change_requests SET status='declined',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([$u['full_name'] ?? 'Superadmin',$rid]);
            notify_user((int)$request['user_id'],'revision','Department change declined','Your department-change request was declined. Please contact the Superadmin if you need more information.','user-settings.php');
            flash('success','Declined department-change request.');
        }
        header('Location: pending-users.php'); exit;
    }
    $target_id = (int)($_POST['user_id'] ?? 0);

    if ($target_id > 0) {
        $s = $pdo->prepare('SELECT id, username, full_name, email, dept_code FROM users WHERE id = ? AND status = "pending"');
        $s->execute([$target_id]);
        $target = $s->fetch();

        if ($target) {
            if ($action === 'approve') {
                $newUsername = strtolower(trim($_POST['assigned_username'] ?? ''));

                if ($newUsername === '' || !preg_match('/^[a-z0-9._-]{3,32}$/', $newUsername)) {
                    flash('error', 'Enter a username of 3–32 characters (letters, digits, dot, dash, underscore) before approving.');
                    header('Location: pending-users.php'); exit;
                }

                // Make sure nobody else already has it
                $dupe = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
                $dupe->execute([$newUsername, $target_id]);
                if ($dupe->fetch()) {
                    flash('error', "The username \"$newUsername\" is already taken. Please choose another one.");
                    header('Location: pending-users.php'); exit;
                }

                // v99: registered under "Others" (no department yet) — the Procurement Unit puts it in one on record
                if (trim((string)($target['dept_code'] ?? '')) === '') {
                    require_once __DIR__ . '/config/dept-other.php';
                    $__assign = (string)($_POST['assign_dept'] ?? '');
                    $__ok = '';
                    foreach (dept_other_choices() as $__d) if (strcasecmp((string)$__d['code'], $__assign) === 0) { $__ok = (string)$__d['code']; break; }
                    if ($__ok === '') {
                        flash('error', 'Choose the department to put ' . $target['full_name'] . ' in before approving. If it is not on the list, add it on Departments first.');
                        header('Location: pending-users.php'); exit;
                    }
                    $pdo->prepare('UPDATE users SET dept_code = ? WHERE id = ?')->execute([$__ok, $target_id]);
                    $target['dept_code'] = $__ok;
                }

                $pdo->prepare('UPDATE users SET status = "active", username = ? WHERE id = ?')
                    ->execute([$newUsername, $target_id]);
                log_activity('Approved user account', 'user', $newUsername,
                    "Approved {$target['full_name']} · Dept: {$target['dept_code']} · Username: $newUsername");

                // In-app notification
                notify_user((int)$target['id'], 'approval',
                    'Your ProcureEase account is approved',
                    "Hi {$target['full_name']}, your account has been approved. Your username is \"$newUsername\".",
                    'user-dashboard.php');

                // Email them their username
                require_once __DIR__ . '/config/smtp.php';
                $subject = 'Your ProcureEase account is ready';
                $body    = "Hi {$target['full_name']},\n\n"
                         . "Your ProcureEase account has been approved by the Procurement Unit.\n\n"
                         . "    Username: $newUsername\n"
                         . "    Password: the one you set when you registered\n\n"
                         . "You can now sign in and start preparing your unit's procurement documents.\n\n"
                         . "If you did not register for this account, please contact the Procurement Unit.\n\n"
                         . "— ProcureEase\n"
                         . "Aurora State College of Technology";
                $emailed = false;
                try { $emailed = smtp_send($target['email'], $subject, $body); } catch (Throwable $t) { $emailed = false; }

                flash('success', $emailed
                    ? "Approved {$target['full_name']}. Their username \"$newUsername\" was emailed to {$target['email']}."
                    : "Approved {$target['full_name']} with username \"$newUsername\". The email could not be sent — please pass the username on manually.");
            } elseif ($action === 'reject') {
                $pdo->prepare('UPDATE users SET status = "rejected" WHERE id = ?')->execute([$target_id]);
                log_activity('Rejected user account', 'user', $target['username'],
                    "Rejected {$target['full_name']}");

                notify_user((int)$target['id'], 'revision',
                    'Your ProcureEase account was not approved',
                    "Hi {$target['full_name']}, your account was not approved. Please contact the Procurement Unit if you believe this is a mistake.",
                    'help.php');

                flash('success', "Rejected {$target['full_name']}'s account.");
            }
        }
    }
    header('Location: pending-users.php');
    exit;
}

// ─── Load pending, approved-recent, and rejected users ────
$pending  = $pdo->query("SELECT u.*, d.name AS dept_name
                           FROM users u
                      LEFT JOIN departments d ON d.code = u.dept_code
                          WHERE u.status = 'pending'
                       ORDER BY u.id DESC")->fetchAll();

$rejected = $pdo->query("SELECT u.*, d.name AS dept_name
                           FROM users u
                      LEFT JOIN departments d ON d.code = u.dept_code
                          WHERE u.status = 'rejected'
                       ORDER BY u.id DESC LIMIT 20")->fetchAll();

// Accurate total rejected count (LIMIT above is only for the display list)
$rejectedTotal = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'rejected'")->fetchColumn();

// Pending account-deletion requests
$deletionReqs = [];
try {
    $deletionReqs = $pdo->query("SELECT * FROM account_deletion_requests WHERE status = 'pending' ORDER BY requested_at DESC")->fetchAll();
} catch (Throwable $t) {}

// Pending username change requests
$unameReqs = [];
try {
    $unameReqs = $pdo->query("SELECT * FROM username_change_requests WHERE status = 'pending' ORDER BY requested_at DESC")->fetchAll();
} catch (Throwable $t) {}

$departmentChangeRequests = [];
try { $departmentChangeRequests = $pdo->query("SELECT r.*,u.full_name,u.username,dc.name AS current_name,dn.name AS requested_name FROM department_change_requests r JOIN users u ON u.id=r.user_id LEFT JOIN departments dc ON dc.code=r.current_dept LEFT JOIN departments dn ON dn.code=r.requested_dept WHERE r.status='pending' ORDER BY r.requested_at DESC")->fetchAll(); } catch (Throwable $t) {}

// Active department users (for role management) — excludes admin + budget
$academicSupportCodes = [];
function pu_dept_category(?string $deptGroup, string $code, array $academicSupport): string {
    if (in_array(strtoupper(trim($code)), ['LIB', 'REG', 'NSTP', 'SCA', 'IEAS', 'LAB'], true)) return 'Administrative';
    if ($deptGroup === 'Schools & Colleges') return 'Academic';
    if (in_array($code, $academicSupport, true)) return 'Academic';
    return 'Administrative';
}
$activeUsers = [];
try {
    // v104: u.* — the View window shows what each user filled in on their Profile (photo included)
    $activeUsers = $pdo->query("SELECT u.*,
                                       d.name AS dept_name, d.dept_group AS dept_group
                                  FROM users u
                             LEFT JOIN departments d ON d.code = u.dept_code
                                 WHERE u.status IN ('active','blocked') AND u.role IN ('user','dept_admin')
                              ORDER BY u.dept_code, u.role DESC, u.full_name")->fetchAll();
} catch (Throwable $t) {}

// Categorize + counts
foreach ($activeUsers as &$__au) {
    $__au['category'] = pu_dept_category($__au['dept_group'] ?? '', $__au['dept_code'] ?? '', $academicSupportCodes);
}
unset($__au);
$acadUsers  = array_filter($activeUsers, fn($x) => $x['category'] === 'Academic');
$adminUsers = array_filter($activeUsers, fn($x) => $x['category'] === 'Administrative');
$totalActiveUsers = count($activeUsers);

$editDepartments = [];
try { $editDepartments = $pdo->query("SELECT code,name FROM departments WHERE COALESCE(status,'active')='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $t) {}

// Filter selection
$userCat = $_GET['cat'] ?? 'all';
if (!in_array($userCat, ['all','Academic','Administrative'], true)) $userCat = 'all';
$shownUsers = $userCat === 'Academic' ? $acadUsers : ($userCat === 'Administrative' ? $adminUsers : $activeUsers);
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<div class="app">
  <?php require_once __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Pending user accounts</h1>
        <p class="page-sub">Review new user registrations. Approving lets them sign in; rejecting locks them out.</p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <div class="card" style="margin-bottom:20px;">
      <div class="card-title">Department-change requests <span style="font-size:12px;color:var(--muted);font-weight:500;">(<?= count($departmentChangeRequests) ?> pending)</span></div>
      <?php if (!$departmentChangeRequests): ?><p style="color:var(--muted);font-size:13px;margin:8px 0 0;">No pending department-change requests.</p>
      <?php else: foreach ($departmentChangeRequests as $dr): ?>
        <div style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:12px 0;border-top:1px solid var(--border);">
          <div style="min-width:220px;flex:1;"><strong><?= e($dr['full_name']) ?></strong> <span style="color:var(--muted);font-size:12px;">@<?= e($dr['username']) ?></span><br>
            <span style="font-size:13px;"> <?= e($dr['current_name'] ?: $dr['current_dept']) ?> → <strong><?= e($dr['requested_name'] ?: $dr['requested_dept']) ?></strong></span>
            <?php if (!empty($dr['note'])): ?><div style="font-size:12px;color:var(--muted);margin-top:3px;">Reason: <?= e($dr['note']) ?></div><?php endif; ?>
          </div>
          <div style="display:flex;gap:7px;flex-wrap:wrap;">
            <form method="POST"><input type="hidden" name="action" value="approve_department_request"><input type="hidden" name="request_id" value="<?= (int)$dr['id'] ?>"><button class="btn btn-primary" type="submit">Approve</button></form>
            <form method="POST" data-confirm="Decline this department-change request?"><input type="hidden" name="action" value="decline_department_request"><input type="hidden" name="request_id" value="<?= (int)$dr['id'] ?>"><button class="btn btn-ghost" type="submit">Decline</button></form>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="stats">
      <div class="stat">
        <div class="stat-label">Awaiting approval</div>
        <div class="stat-value" style="color: var(--orange-600);"><?= count($pending) ?></div>
        <div class="stat-meta">New registrations</div>
      </div>
      <div class="stat">
        <div class="stat-label">Rejected</div>
        <div class="stat-value" style="color: var(--muted);"><?= $rejectedTotal ?></div>
        <div class="stat-meta">Not approved</div>
      </div>
      <div class="stat">
        <div class="stat-label">Deletion requests</div>
        <div class="stat-value" style="color: var(--orange-600);"><?= count($deletionReqs) ?></div>
        <div class="stat-meta">Awaiting your decision</div>
      </div>
    </div>

    <!-- ─── Sign-in lockouts: wrong passwords (v79, config/login-guard.php) ─── -->
    <?php $__lockouts = login_guard_list(); $__nLocked = 0; foreach ($__lockouts as $__l) if ($__l['lock']['locked']) $__nLocked++; ?>
    <style>
      .lo-table { width: 100%; border-collapse: collapse; font-size: 13px; }
      .lo-table th { text-align: left; padding: 10px 14px; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .06em;
        background: var(--navy-50); border-bottom: 1px solid var(--border); }
      .lo-table td { padding: 12px 14px; border-bottom: 1px solid var(--border); vertical-align: top; color: var(--ink); }
      .lo-table tr:last-child td { border-bottom: none; }
      .lo-name { font-weight: 700; } .lo-sub { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
      .lo-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
      .lo-pill.locked { background: var(--orange-50); color: var(--orange-600); border: 1.5px solid var(--orange-100); }
      .lo-pill.open { background: var(--navy-50); color: var(--ink); border: 1.5px solid var(--border); }
      .lo-empty { padding: 18px 22px; color: var(--muted); font-size: 13px; }
      @media (max-width: 760px) { .lo-table thead { display: none; } .lo-table tr { display: block; border-bottom: 1px solid var(--border); }
        .lo-table td { display: block; border: none; padding: 6px 16px; } .lo-table td:first-child { padding-top: 14px; } .lo-table td:last-child { padding-bottom: 14px; } }
    </style>
    <div class="section-head" id="lockouts">
      <div>
        <h2 class="section-title">Sign-in lockouts<?php if ($__nLocked): ?> <span style="font-size: 13px; color: var(--orange-600);">· <?= $__nLocked ?> locked</span><?php endif; ?></h2>
      </div>
    </div>
    <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 24px;" id="lockoutList">
      <?php if (!$__lockouts): ?>
        <div class="lo-empty">No wrong passwords in the last 24 hours.</div>
      <?php else: ?>
      <table class="lo-table">
        <thead><tr><th>Account</th><th>Wrong passwords</th><th>Last wrong password</th><th>Status</th><th style="text-align:right;">Action</th></tr></thead>
        <tbody>
        <?php foreach ($__lockouts as $__l): $__locked = $__l['lock']['locked']; ?>
          <tr data-user="<?= e($__l['username']) ?>">
            <td><div class="lo-name"><?= e($__l['full_name']) ?></div>
              <div class="lo-sub">@<?= e($__l['username']) ?> · <?= e($__l['dept_code'] ?: role_label($__l['role'])) ?></div></td>
            <td><b><?= (int)$__l['failed_logins'] ?></b> of <?= LOGIN_MAX_FAILS ?></td>
            <td><?= !empty($__l['last_failed_at']) ? e(date('M j, Y g:i A', strtotime((string)$__l['last_failed_at']))) : '—' ?>
              <?php if (!empty($__l['last_failed_ip'])): ?><div class="lo-sub">from <?= e($__l['last_failed_ip']) ?></div><?php endif; ?></td>
            <td>
              <?php if ($__locked): ?>
                <span class="lo-pill locked">🔒 Locked until <?= e(login_time_label((int)$__l['lock']['until'])) ?></span>
              <?php else: ?>
                <span class="lo-pill open">Not locked · <?= max(0, LOGIN_MAX_FAILS - (int)$__l['failed_logins']) ?> tries left</span>
              <?php endif; ?></td>
            <td style="text-align:right;">
              <form method="POST" action="api/unlock-account.php" style="margin: 0; display: inline;"
                    data-confirm="<?= $__locked ? 'Unlock ' . e($__l['full_name']) . ' (@' . e($__l['username']) . ')? They can sign in again right away.' : 'Clear the wrong passwords of ' . e($__l['full_name']) . '? The count starts again from 0.' ?>"
                    data-confirm-title="<?= $__locked ? 'Unlock this account?' : 'Clear the count?' ?>" data-confirm-label="<?= $__locked ? 'Unlock' : 'Clear' ?>" data-confirm-tone="info">
                <input type="hidden" name="user_id" value="<?= (int)$__l['id'] ?>">
                <button type="submit" class="btn <?= $__locked ? 'btn-primary' : 'btn-ghost' ?>" style="padding: 6px 14px; font-size: 12px;">
                  <?= $__locked ? '🔓 Unlock now' : 'Clear count' ?></button>
              </form></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </div>

    <?php if ($deletionReqs): ?>
    <div class="section-head">
      <div>
        <h2 class="section-title" style="color: var(--orange-600);">⚠ Account deletion requests</h2>
      </div>
    </div>
    <?php foreach ($deletionReqs as $dr): ?>
      <div class="card" style="margin-bottom: 12px; padding: 18px 22px; border-left: 4px solid var(--orange-500);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
          <div style="flex: 1; min-width: 240px;">
            <div style="font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; color: var(--ink);">
              <?= e($dr['full_name']) ?>
              <span style="font-weight: 500; color: var(--muted); font-size: 13px;">@<?= e($dr['username']) ?> · <?= e($dr['dept_code']) ?></span>
            </div>
            <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">
              Requested <?= reltime_span($dr['requested_at']) ?>
            </div>
            <?php if (!empty($dr['reason'])): ?>
              <div style="margin-top: 8px; padding: 8px 12px; background: var(--orange-50); border-radius: 8px; font-size: 12.5px; color: var(--ink);">
                <strong style="color: var(--orange-600);">Reason:</strong> <?= e($dr['reason']) ?>
              </div>
            <?php endif; ?>
          </div>
          <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <form method="POST" action="api/decide-account-deletion.php" style="margin: 0;" data-confirm="This permanently deletes <?= e($dr['full_name']) ?>'s account. This cannot be undone." data-confirm-title="Approve deletion?" data-confirm-label="Delete account" data-confirm-tone="danger">
              <input type="hidden" name="request_id" value="<?= (int)$dr['id'] ?>">
              <input type="hidden" name="decision" value="approve">
              <button type="submit" class="btn" style="padding: 8px 16px; font-size: 12.5px; background: var(--orange-600); color: white; border-color: var(--orange-600);">Approve deletion</button>
            </form>
            <form method="POST" action="api/decide-account-deletion.php" style="margin: 0;" data-confirm="Deny this request? The account will stay active and the user will be notified." data-confirm-title="Deny request?" data-confirm-label="Deny" data-confirm-tone="info">
              <input type="hidden" name="request_id" value="<?= (int)$dr['id'] ?>">
              <input type="hidden" name="decision" value="deny">
              <button type="submit" class="btn btn-ghost" style="padding: 8px 16px; font-size: 12.5px;">Deny</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($unameReqs): ?>
    <div class="section-head">
      <div>
        <h2 class="section-title">Username change requests</h2>
        <p class="section-sub">Approving sets the username straight away. Earlier activity stays recorded under the old one.</p>
      </div>
    </div>
    <?php foreach ($unameReqs as $ur): ?>
      <div class="card" style="margin-bottom: 12px; padding: 18px 22px; border-left: 4px solid var(--navy-500);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
          <div style="flex:1; min-width:260px;">
            <div style="font-family:'Space Grotesk',sans-serif; font-size:15px; font-weight:700; color:var(--ink);">
              <?= e($ur['full_name']) ?>
              <span style="font-weight:500; color:var(--muted); font-size:13px;"><?= e($ur['dept_code']) ?></span>
            </div>
            <div style="font-size:13px; color:var(--ink); margin-top:6px;">
              <code style="background:var(--navy-50); padding:2px 7px; border-radius:5px;"><?= e($ur['current_username']) ?></code>
              <span style="color:var(--muted); margin:0 6px;">&rarr;</span>
              <code style="background:var(--green-50); padding:2px 7px; border-radius:5px;"><?= e($ur['requested_username']) ?></code>
            </div>
            <div style="font-size:12px; color:var(--muted); margin-top:5px;">
              Requested <?= reltime_span($ur['requested_at']) ?>
            </div>
            <?php if (!empty($ur['reason'])): ?>
              <div style="margin-top:8px; padding:8px 12px; background:var(--navy-50); border-radius:8px; font-size:12.5px; color:var(--ink);">
                <strong>Reason:</strong> <?= e($ur['reason']) ?>
              </div>
            <?php endif; ?>
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
            <form method="POST" action="api/decide-username-change.php" style="margin:0; display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;"
                  data-confirm="<?= e($ur['full_name']) ?> will sign in with the new username from now on."
                  data-confirm-title="Approve username change?" data-confirm-label="Approve" data-confirm-tone="success">
              <input type="hidden" name="request_id" value="<?= (int)$ur['id'] ?>">
              <input type="hidden" name="decision" value="approve">
              <div>
                <label style="display:block; font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px;">Final username</label>
                <input type="text" name="approved_username" value="<?= e($ur['requested_username']) ?>"
                       pattern="[a-zA-Z0-9._-]{3,32}" required
                       style="width:170px; padding:7px 10px; border:1.5px solid var(--border); border-radius:8px; font-family:inherit; font-size:12.5px;">
              </div>
              <button type="submit" class="btn btn-primary" style="padding:8px 18px; font-size:13px; background:#15803d; border-color:#15803d;">Approve</button>
            </form>
            <form method="POST" action="api/decide-username-change.php" style="margin:0;"
                  data-confirm="The username stays as it is and <?= e($ur['full_name']) ?> is notified."
                  data-confirm-title="Decline this request?" data-confirm-label="Decline" data-confirm-tone="danger">
              <input type="hidden" name="request_id" value="<?= (int)$ur['id'] ?>">
              <input type="hidden" name="decision" value="deny">
              <button type="submit" class="btn btn-ghost" style="padding:8px 18px; font-size:13px; color:var(--orange-600);">Decline</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="section-head">
      <div>
        <h2 class="section-title">Awaiting approval</h2>
        <p class="section-sub">These users have verified their email but can't sign in yet.</p>
      </div>
    </div>

    <?php if (!$pending): ?>
      <div class="card" style="text-align: center; padding: 60px 24px; color: var(--muted);">
        <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px; display: block; color: var(--border);"><polyline points="20 6 9 17 4 12"/></svg>
        <div style="font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px;">No pending accounts</div>
        <div style="font-size: 13.5px;">New registrations will appear here for your review.</div>
      </div>
    <?php else: ?>
      <div class="folder-grid" style="grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 14px;">
        <?php foreach ($pending as $p): ?>
          <div class="folder" style="cursor: default; padding: 20px 22px;">
            <span class="folder-badge" style="background: var(--orange-50); color: var(--orange-600); border: 1px solid var(--orange-100);">Pending</span>
            <div class="folder-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div class="folder-info" style="flex: 1; min-width: 0;">
              <div class="folder-code" style="white-space: normal; padding-right: 80px;"><?= e($p['full_name']) ?></div>
              <?php $__isOther = trim((string)($p['dept_code'] ?? '')) === '';   /* v99: registered under "Others" */ ?>
              <div class="folder-full" style="white-space: normal; padding-right: 80px;"><?= e($p['position'] ?: '—') ?> · <?php if ($__isOther): ?><span class="dept-other" title="Not on the list — typed at registration">Others: <?= e((string)($p['dept_other'] ?? '') ?: '—') ?></span><?php else: ?><?= e($p['dept_code']) ?><?= !empty($p['sub_unit']) ? ' · ' . e(au_program_label((string)$p['dept_code'], (string)$p['sub_unit'])) : (au_has_units((string)$p['dept_code']) ? ' · Whole school' : '') ?><?php endif; ?></div>
              <div class="folder-meta">
                <span style="max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: bottom;"><?= e($p['email']) ?></span>
              </div>
              <?php if ($p['contact']): ?>
                <div class="folder-meta" style="margin-top: 4px;">
                  <span><?= e($p['contact']) ?></span>
                </div>
              <?php endif; ?>

              <?php
                // Suggest the next username in this department's series, e.g. ictu-01-003
                $suggest = '';
                try {
                    $q = $pdo->prepare("SELECT username FROM users
                                         WHERE dept_code = ? AND username REGEXP '^[a-z]+-[0-9]+-[0-9]+$'
                                      ORDER BY username DESC LIMIT 1");
                    $q->execute([$p['dept_code']]);
                    $last = (string)$q->fetchColumn();
                    if ($last !== '' && preg_match('/^(.*-)(\d+)$/', $last, $m)) {
                        $suggest = $m[1] . str_pad((string)((int)$m[2] + 1), strlen($m[2]), '0', STR_PAD_LEFT);
                    }
                } catch (Throwable $t) {}
                if ($suggest === '' && !$__isOther) $suggest = strtolower($p['dept_code']) . '-01-001';
              ?>

              <div style="display: flex; gap: 8px; margin-top: 14px; align-items: flex-end; flex-wrap: wrap;">
                <form method="POST" style="margin: 0; display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap;"
                      data-confirm="This approves <?= e($p['full_name']) ?> and emails them their username."
                      data-confirm-title="Approve account?" data-confirm-label="Approve" data-confirm-tone="success">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                  <?php if ($__isOther): require_once __DIR__ . '/config/dept-other.php'; ?>
                  <div>
                    <label for="assignDept<?= (int)$p['id'] ?>" style="display:block; font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px;">
                      Put in department</label>
                    <select name="assign_dept" id="assignDept<?= (int)$p['id'] ?>" class="form-select" required
                            style="width: 200px; padding: 7px 10px; font-size: 12.5px;">
                      <option value="">— Choose —</option>
                      <?php foreach (dept_other_choices() as $__d): ?>
                        <option value="<?= e($__d['code']) ?>"><?= e($__d['name']) ?> (<?= e($__d['code']) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php endif; ?>
                  <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px;">
                      Assign username
                    </label>
                    <input type="text" name="assigned_username" value="<?= e($suggest) ?>"<?= $__isOther ? ' placeholder="e.g. hrmu-01-001"' : '' ?>
                           pattern="[a-zA-Z0-9._-]{3,32}" required
                           style="width: 165px; padding: 7px 10px; border: 1.5px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 12.5px;">
                  </div>
                  <button type="submit" class="btn btn-primary" style="padding: 8px 18px; font-size: 13px; background: #15803d; border-color: #15803d;">
                    Approve
                  </button>
                </form>
                <form method="POST" style="margin: 0;" data-confirm="<?= e($p['full_name']) ?> will not be able to sign in." data-confirm-title="Reject account?" data-confirm-label="Reject" data-confirm-tone="danger">
                  <input type="hidden" name="action" value="reject">
                  <input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="btn btn-ghost" style="padding: 8px 18px; font-size: 13px; color: var(--orange-600);">
                    Reject
                  </button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($rejected): ?>
      <div class="section-head" style="margin-top: 32px;">
        <div>
          <h2 class="section-title">Recently rejected</h2>
          <p class="section-sub">Accounts that were not approved. Click any row to reconsider.</p>
        </div>
      </div>
      <div class="card" style="padding: 0; overflow: hidden;">
        <table style="width:100%; border-collapse: collapse; font-size: 13px;">
          <thead>
            <tr style="background: var(--navy-50); border-bottom: 1px solid var(--border);">
              <th style="text-align:left; padding: 10px 14px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Name</th>
              <th style="text-align:left; padding: 10px 14px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Username</th>
              <th style="text-align:left; padding: 10px 14px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Dept</th>
              <th style="text-align:left; padding: 10px 14px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Email</th>
              <th style="text-align:right; padding: 10px 14px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rejected as $r): ?>
              <tr style="border-bottom: 1px solid var(--border);">
                <td style="padding: 12px 14px; font-weight: 600;"><?= e($r['full_name']) ?></td>
                <td style="padding: 12px 14px; color: var(--muted);">@<?= e($r['username']) ?></td>
                <td style="padding: 12px 14px; color: var(--muted);"><?= trim((string)($r['dept_code'] ?? '')) === '' ? 'Others: ' . e((string)($r['dept_other'] ?? '') ?: '—') : e($r['dept_code']) ?></td>
                <td style="padding: 12px 14px; color: var(--muted);"><?= e($r['email']) ?></td>
                <td style="padding: 12px 14px; text-align: right;">
                  <form method="POST" style="margin: 0; display: inline;">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn btn-ghost" style="padding: 6px 12px; font-size: 12px; color: #15803d;">Reconsider &amp; Approve</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <!-- ─── Manage department roles ─── -->
    <?php if ($activeUsers): ?>
    <div class="section-head" style="margin-top: 32px;">
      <div>
        <h2 class="section-title">Department roles</h2>
      </div>
    </div>

    <!-- Total + Academic/Administrative filter -->
    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap;">
      <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 14px; color: var(--navy-800);">
        Total users: <?= $totalActiveUsers ?>
      </div>
      <div style="display: inline-flex; border: 1.5px solid var(--border); border-radius: 999px; overflow: hidden;">
        <a href="?cat=all" style="padding: 6px 16px; font-size: 12.5px; font-weight: 700; text-decoration: none; <?= $userCat === 'all' ? 'background: var(--accent); color: var(--accent-ink);' : 'background: var(--white); color: var(--ink);' ?>">All (<?= $totalActiveUsers ?>)</a>
        <a href="?cat=Academic" style="padding: 6px 16px; font-size: 12.5px; font-weight: 700; text-decoration: none; border-left: 1.5px solid var(--border); <?= $userCat === 'Academic' ? 'background: var(--accent); color: var(--accent-ink);' : 'background: var(--white); color: var(--ink);' ?>">🎓 Academic (<?= count($acadUsers) ?>)</a>
        <a href="?cat=Administrative" style="padding: 6px 16px; font-size: 12.5px; font-weight: 700; text-decoration: none; border-left: 1.5px solid var(--border); <?= $userCat === 'Administrative' ? 'background: var(--accent); color: var(--accent-ink);' : 'background: var(--white); color: var(--ink);' ?>">🏛️ Administrative (<?= count($adminUsers) ?>)</a>
      </div>
    </div>

    <div class="card" style="padding: 0; overflow: hidden;">
      <table style="width:100%; border-collapse: collapse; font-size: 13px;">
        <thead>
          <tr style="background: var(--navy-50); border-bottom: 1px solid var(--border);">
            <th style="text-align:left; padding: 11px 16px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Name</th>
            <th style="text-align:left; padding: 11px 14px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Department</th>
            <th style="text-align:left; padding: 11px 14px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Current role</th>
            <th style="text-align:right; padding: 11px 16px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($shownUsers as $au):
            $isDeptAdmin = ($au['role'] === 'dept_admin');
          ?>
            <tr style="border-bottom: 1px solid var(--border);">
              <td style="padding: 13px 16px;">
                <?php /* v104: the user's profile picture (or initials), and View — what they filled in on their Profile */ ?>
                <div class="uv-who">
                  <?php if (!empty($au['avatar_path'])): ?>
                    <img src="<?= e($au['avatar_path']) ?>" alt="" class="uv-av" style="object-position: <?= e($au['avatar_pos'] ?? '50% 50%') ?>;">
                  <?php else: ?>
                    <div class="uv-av uv-ini" aria-hidden="true"><?= e(initials((string)$au['full_name'])) ?></div>
                  <?php endif; ?>
                  <div style="min-width: 0;">
                    <div style="font-weight: 700; color: var(--ink);"><?= e($au['full_name']) ?></div>
                    <div style="font-size: 11.5px; color: var(--muted); margin-top: 3px;">@<?= e($au['username']) ?>
                      <button type="button" class="btn btn-ghost uv-btn" data-uv="<?= (int)$au['id'] ?>" aria-label="View the profile of <?= e($au['full_name']) ?>">👁 View</button></div>
                  </div>
                </div>
              </td>
              <td style="padding: 13px 14px; color: var(--muted);">
                <strong style="color: var(--ink);"><?= e($au['dept_code']) ?></strong>
                <form method="POST" action="api/set-user-department.php" style="margin:5px 0 0;display:flex;gap:5px;align-items:center;flex-wrap:wrap;">
                  <input type="hidden" name="user_id" value="<?= (int)$au['id'] ?>">
                  <select name="dept_code" class="form-select" aria-label="Change department for <?= e($au['full_name']) ?>" style="padding:4px 7px;font-size:11px;max-width:200px;">
                    <?php foreach ($pdo->query("SELECT code,name FROM departments WHERE COALESCE(status,'active')='active' ORDER BY name")->fetchAll() as $__deptOpt): ?>
                      <option value="<?= e($__deptOpt['code']) ?>" <?= $__deptOpt['code'] === $au['dept_code'] ? 'selected' : '' ?>><?= e($__deptOpt['name']) ?></option>
                    <?php endforeach; ?>
                  </select><button class="btn btn-ghost" type="submit" style="padding:3px 8px;font-size:11px;">Change</button>
                </form>
                <?php if (au_has_units((string)$au['dept_code'])): /* v81: the account's Bachelor / Master, set here */ ?>
                  <form method="POST" action="api/set-user-program.php" class="prog-form" style="margin: 4px 0 0; display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                    <input type="hidden" name="user_id" value="<?= (int)$au['id'] ?>">
                    <select name="sub_unit" class="form-select" aria-label="Bachelor / Master of <?= e($au['full_name']) ?>" style="padding: 4px 8px; font-size: 11.5px; max-width: 220px;"<?= $au['role'] === 'dept_admin' ? ' disabled title="The Department Admin works on the whole school"' : '' ?>>
                      <option value="">Whole school</option>
                      <?php foreach (au_programs_of((string)$au['dept_code']) as $__pc => $__pn): ?>
                        <option value="<?= e($__pc) ?>" <?= strcasecmp((string)($au['sub_unit'] ?? ''), (string)$__pc) === 0 ? 'selected' : '' ?>><?= e($__pc) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($au['role'] !== 'dept_admin'): ?><button type="submit" class="btn btn-ghost" style="padding: 3px 10px; font-size: 11px;">Save</button><?php endif; ?>
                  </form>
                <?php elseif (!empty($au['sub_unit'])): ?>
                  <div style="font-size: 11px; color: var(--muted); margin-top: 2px;"><?= e($au['sub_unit']) ?></div>
                <?php endif; ?>
              </td>
              <td style="padding: 13px 14px;">
                <?php if ($isDeptAdmin): ?>
                  <span style="background: var(--navy-100); color: var(--ink); border: 1.5px solid var(--navy-500); padding: 3px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;">🛡 Department Admin</span>
                <?php else: ?>
                  <span style="background: var(--navy-50); color: var(--muted); border: 1.5px solid var(--border); padding: 3px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;">User</span>
                <?php endif; ?>
                <?php if (($au['status'] ?? 'active') === 'blocked'): ?>
                  <span style="background: var(--orange-50); color: var(--orange-600); border: 1.5px solid var(--orange-100); padding: 3px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-left: 4px;">🚫 Blocked</span>
                <?php endif; ?>
              </td>
              <td style="padding: 13px 16px; text-align: right;">
                <div class="user-actions" style="display: inline-flex; gap: 8px; align-items: center; justify-content: flex-end; flex-wrap: wrap;">
                <?php if ($isDeptAdmin): ?>
                  <form method="POST" action="api/set-user-role.php" style="margin: 0; display: inline;"
                        data-confirm="Set <?= e($au['full_name']) ?> back to a normal user? They will lose department-wide tracking."
                        data-confirm-title="Make normal user?" data-confirm-label="Make user" data-confirm-tone="danger">
                    <input type="hidden" name="user_id" value="<?= (int)$au['id'] ?>">
                    <input type="hidden" name="new_role" value="user">
                    <button type="submit" class="btn btn-ghost" style="padding: 6px 14px; font-size: 12px;">Set to normal user</button>
                  </form>
                <?php else: ?>
                  <form method="POST" action="api/set-user-role.php" style="margin: 0; display: inline;"
                        data-confirm="Make <?= e($au['full_name']) ?> the department admin for <?= e($au['dept_code']) ?>? They'll be able to track their whole department's PPMPs. Only one dept admin is allowed per department."
                        data-confirm-title="Make department admin?" data-confirm-label="Make Department Admin" data-confirm-tone="info">
                    <input type="hidden" name="user_id" value="<?= (int)$au['id'] ?>">
                    <input type="hidden" name="new_role" value="dept_admin">
                    <button type="submit" class="btn btn-ghost" style="padding: 6px 14px; font-size: 12px; color: var(--ink);">🛡 Make Department Admin</button>
                  </form>
                <?php endif; ?>
                  <?php $isBlocked = (($au['status'] ?? 'active') === 'blocked'); ?>
                  <?php if ($isBlocked): ?>
                    <form method="POST" action="api/block-user.php" style="margin: 0; display: inline;"
                          data-confirm="Unblock <?= e($au['full_name']) ?>? They will be able to sign in again."
                          data-confirm-title="Unblock this account?" data-confirm-label="Unblock" data-confirm-tone="info">
                      <input type="hidden" name="user_id" value="<?= (int)$au['id'] ?>">
                      <input type="hidden" name="do" value="unblock">
                      <button type="submit" class="btn btn-ghost" style="padding: 6px 12px; font-size: 12px; color: #16a34a;" title="Unblock account">✓ Unblock</button>
                    </form>
                  <?php else: ?>
                    <form method="POST" action="api/block-user.php" style="margin: 0; display: inline;"
                          data-confirm="Block <?= e($au['full_name']) ?> (@<?= e($au['username']) ?>)? They will be signed out and can no longer sign in until you unblock them. Their records are kept."
                          data-confirm-title="Block this account?" data-confirm-label="Block" data-confirm-tone="danger">
                      <input type="hidden" name="user_id" value="<?= (int)$au['id'] ?>">
                      <input type="hidden" name="do" value="block">
                      <button type="submit" class="btn btn-ghost" style="padding: 6px 12px; font-size: 12px; color: var(--orange-600);" title="Block account">🚫 Block</button>
                    </form>
                  <?php endif; ?>
                  <form method="POST" action="api/delete-user.php" style="margin: 0; display: inline;"
                        data-confirm="Permanently delete <?= e($au['full_name']) ?>'s account (@<?= e($au['username']) ?>)? This cannot be undone. Their department's PPMPs are kept."
                        data-confirm-title="Delete this account?" data-confirm-label="Delete account" data-confirm-tone="danger">
                    <input type="hidden" name="user_id" value="<?= (int)$au['id'] ?>">
                    <button type="submit" class="btn btn-ghost" style="padding: 6px 12px; font-size: 12px; color: var(--orange-600);" title="Delete account">🗑 Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- ─── Positions offered on the registration form (v78) ─── -->
    <?php $__positions = reg_positions(); ?>
    <style>
      .pos-admin { padding: 18px 22px; }
      .pos-add { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
      .pos-add .form-input { flex: 1 1 280px; min-width: 0; }
      .pos-chips { display: flex; flex-wrap: wrap; gap: 8px; }
      .pos-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 6px 5px 12px; border-radius: 999px;
        background: var(--navy-50); border: 1.5px solid var(--border); font-size: 12.5px; font-weight: 600; color: var(--ink); }
      .pos-chip form { margin: 0; display: inline; }
      .pos-chip button { display: grid; place-items: center; width: 22px; height: 22px; padding: 0; border: none; border-radius: 50%;
        background: transparent; color: var(--orange-600); font-size: 13px; font-weight: 700; cursor: pointer; }
      .pos-chip button:hover, .pos-chip button:focus-visible { background: var(--orange-50); }
      .pos-chip.others { background: transparent; border-style: dashed; color: var(--muted); padding-right: 12px; }
      .pos-note { font-size: 12px; color: var(--muted); margin-top: 12px; }
    </style>
    <div class="section-head" style="margin-top: 32px;" id="positions">
      <div>
        <h2 class="section-title">Positions</h2>
      </div>
    </div>
    <div class="card pos-admin">
      <form method="POST" action="api/positions.php" class="pos-add">
        <input type="hidden" name="do" value="add">
        <input type="text" name="position" id="posNew" class="form-input" maxlength="<?= REG_POSITION_MAX ?>" required
               placeholder="New position, e.g. Budget Officer" aria-label="New position">
        <button type="submit" class="btn btn-primary" id="posAddBtn" style="padding: 8px 18px; font-size: 13px;">＋ Add position</button>
      </form>
      <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: var(--ink); margin-bottom: 10px;">
        On the list: <span id="posCount"><?= count($__positions) ?></span></div>
      <div class="pos-chips" id="posList">
        <?php foreach ($__positions as $__p): ?>
          <span class="pos-chip"><?= e($__p) ?>
            <form method="POST" action="api/positions.php"
                  data-confirm="Take &ldquo;<?= e($__p) ?>&rdquo; off the list? New users will no longer see it when registering. Users who already have this position keep it."
                  data-confirm-title="Remove this position?" data-confirm-label="Remove" data-confirm-tone="danger">
              <input type="hidden" name="do" value="remove">
              <input type="hidden" name="position" value="<?= e($__p) ?>">
              <button type="submit" title="Remove <?= e($__p) ?>" aria-label="Remove <?= e($__p) ?>">✕</button>
            </form></span>
        <?php endforeach; ?>
        <span class="pos-chip others" title="Always last on the list">Others</span>
      </div>
      <div class="pos-note">Removing a position does not change the position of anyone who already has it.</div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/includes/user-view.php';   /* v104: View — a user's Profile, read only */ ?>
</body>
</html>
