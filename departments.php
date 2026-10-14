<?php
/* ═══════════════════════════════════════════════════════════════
   Departments — Procurement Unit control of the department list.

   What the Procurement Unit can do here:
     • Add a department      → it appears on Register Account immediately
     • Edit a department     → rename, re-group
     • Deactivate            → it stops appearing on Register Account
     • Reactivate            → it is offered again

   RECORD SAFETY
   Deactivating NEVER deletes anything. The department row stays in the
   database, so every PPMP, APP, Non-PS list, Annex, Market Scoping document,
   budget allocation, user account and audit-log entry that points at the code
   keeps pointing at it and keeps rendering exactly as before.
   A permanent delete is offered only for a department that has never been
   used anywhere — that is checked table by table before the button appears.
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_admin();
ensure_admin_tables();

$activeNav = 'departments';
$pageTitle = 'Departments';
$pdo = db();
$u   = current_user();

/* ─── Every table that stores a department code ────────────────
   Used to count what a department holds, and to prove a department is
   genuinely unused before a permanent delete is allowed. */
const DEPT_LINKED_TABLES = [
    'users'                     => 'user accounts',
    'ppmps'                     => 'PPMPs',
    'archived_ppmps'            => 'archived PPMPs',
    'deleted_ppmps'             => 'PPMPs in trash',
    'edit_requests'             => 'edit requests',
    'revision_requests'         => 'revision requests',
    'dept_budgets'              => 'budget allocations',
    'nonps_submissions'         => 'Non-PS submissions',
    'nonps_admin_items'         => 'consolidated Non-PS items',
    'annex_forms'               => 'Annex forms',
    'market_scoping'            => 'Market Scoping documents',
    'dept_units'                => 'programs / majors',
    'user_reports'              => 'user reports',
    'submission_reminders'      => 'submission reminders',
    'account_deletion_requests' => 'account deletion requests',
];

/** Rows held per department, per table. Missing tables are skipped safely. */
function dept_usage(PDO $pdo): array {
    $usage = [];
    foreach (DEPT_LINKED_TABLES as $table => $label) {
        try {
            $rows = $pdo->query("SELECT dept_code, COUNT(*) AS n FROM `$table`
                                  WHERE dept_code IS NOT NULL AND dept_code <> ''
                               GROUP BY dept_code")->fetchAll();
            foreach ($rows as $r) {
                $usage[$r['dept_code']][$label] = (int)$r['n'];
            }
        } catch (Throwable $t) { /* table not present in this install */ }
    }
    return $usage;
}

/** Total rows a single department holds across every linked table. */
function dept_record_count(PDO $pdo, string $code): int {
    $total = 0;
    foreach (array_keys(DEPT_LINKED_TABLES) as $table) {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE dept_code = ?");
            $s->execute([$code]);
            $total += (int)$s->fetchColumn();
        } catch (Throwable $t) { /* table not present */ }
    }
    return $total;
}

/* Groups already in use — a new department has to join one of them so the
   admin PPMP folders keep grouping correctly. */
$groups = [];
try {
    foreach ($pdo->query("SELECT DISTINCT dept_group FROM departments
                           WHERE dept_group IS NOT NULL AND dept_group <> ''
                        ORDER BY dept_group")->fetchAll() as $g) {
        $groups[] = $g['dept_group'];
    }
} catch (Throwable $t) {}
if (!$groups) $groups = ['Executive Offices', 'Schools & Colleges', 'Support Units', 'Special Programs'];

/* ─── POST actions ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add a department ──────────────────────────────────────
    if ($action === 'add') {
        $code  = trim($_POST['code'] ?? '');
        $name  = preg_replace('/\s+/', ' ', trim($_POST['name'] ?? ''));
        $group = trim($_POST['dept_group'] ?? '');
        $order = (int)($_POST['display_order'] ?? 0);

        if ($code === '' || $name === '') {
            flash('error', 'Enter both a department code and a department name.');
        } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,19}$/', $code)) {
            flash('error', 'The code must be 2–20 characters: letters, digits, dot, dash or underscore, starting with a letter or digit.');
        } elseif (mb_strlen($name) > 200) {
            flash('error', 'The department name cannot be longer than 200 characters.');
        } elseif (!in_array($group, $groups, true)) {
            flash('error', 'Choose one of the existing department groups.');
        } else {
            $dupe = $pdo->prepare('SELECT code, name, status FROM departments WHERE code = ?');
            $dupe->execute([$code]);
            $existing = $dupe->fetch();

            if ($existing) {
                // Same code already on file. Never overwrite it — that row may
                // own years of records. Point the admin at it instead.
                flash('error', ($existing['status'] ?? 'active') === 'inactive'
                    ? "The code \"{$existing['code']}\" already belongs to \"{$existing['name']}\", which is currently deactivated. Reactivate it instead of adding a duplicate."
                    : "The code \"{$existing['code']}\" is already used by \"{$existing['name']}\".");
            } else {
                $nameDupe = $pdo->prepare('SELECT code FROM departments WHERE name = ?');
                $nameDupe->execute([$name]);
                if ($nameDupe->fetch()) {
                    flash('error', "A department named \"$name\" already exists.");
                } else {
                    if ($order <= 0) {
                        // Slot it at the end of its group
                        $mx = $pdo->prepare('SELECT COALESCE(MAX(display_order), 0) FROM departments WHERE dept_group = ?');
                        $mx->execute([$group]);
                        $order = (int)$mx->fetchColumn() + 10;
                    }
                    try {
                        $pdo->prepare("INSERT INTO departments (code, name, dept_group, display_order, status, created_at, created_by)
                                            VALUES (?, ?, ?, ?, 'active', NOW(), ?)")
                            ->execute([$code, $name, $group, $order, $u['full_name']]);
                        log_activity('Added department', 'department', $code, "Added \"$name\" ($group)");
                        flash('success', "Added $code — $name. It is now available on Register Account.");
                    } catch (Throwable $t) {
                        flash('error', 'Could not add that department. Please try again.');
                    }
                }
            }
        }
        header('Location: departments.php'); exit;
    }

    // ── Edit a department ─────────────────────────────────────
    if ($action === 'edit') {
        $code    = trim($_POST['code'] ?? '');
        $newCode = trim($_POST['new_code'] ?? '');
        $name    = preg_replace('/\s+/', ' ', trim($_POST['name'] ?? ''));
        $group   = trim($_POST['dept_group'] ?? '');
        $order   = (int)($_POST['display_order'] ?? 0);

        $s = $pdo->prepare('SELECT * FROM departments WHERE code = ?');
        $s->execute([$code]);
        $dept = $s->fetch();

        if (!$dept) {
            flash('error', 'That department no longer exists.');
        } elseif ($name === '') {
            flash('error', 'The department name cannot be empty.');
        } elseif (mb_strlen($name) > 200) {
            flash('error', 'The department name cannot be longer than 200 characters.');
        } elseif (!in_array($group, $groups, true)) {
            flash('error', 'Choose one of the existing department groups.');
        } else {
            $nameDupe = $pdo->prepare('SELECT code FROM departments WHERE name = ? AND code <> ?');
            $nameDupe->execute([$name, $code]);
            if ($nameDupe->fetch()) {
                flash('error', "Another department is already named \"$name\".");
                header('Location: departments.php'); exit;
            }

            // The code is the key every record points at. It may only change
            // while the department still holds no records at all.
            $codeChanged = ($newCode !== '' && $newCode !== $code);
            // v81: a school's programs and majors are filed under its code
            require_once __DIR__ . '/config/academic-units.php';
            if ($codeChanged && (au_has_units($code) || os_has_sets($code))) {   // (v83: or its sets)
                flash('error', "The code \"$code\" cannot be changed — its programs, majors or sets are filed under it. The name and group were not saved either; remove the code change and save again.");
                header('Location: departments.php'); exit;
            }
            if ($codeChanged) {
                $held = dept_record_count($pdo, $code);
                if ($held > 0) {
                    flash('error', "The code \"$code\" cannot be changed — $held record(s) are filed under it. The name and group were not saved either; remove the code change and save again.");
                    header('Location: departments.php'); exit;
                }
                if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,19}$/', $newCode)) {
                    flash('error', 'The new code must be 2–20 characters: letters, digits, dot, dash or underscore.');
                    header('Location: departments.php'); exit;
                }
                $codeDupe = $pdo->prepare('SELECT code FROM departments WHERE code = ?');
                $codeDupe->execute([$newCode]);
                if ($codeDupe->fetch()) {
                    flash('error', "The code \"$newCode\" is already taken.");
                    header('Location: departments.php'); exit;
                }
            }

            try {
                $changes = [];
                if ($dept['name'] !== $name)                          $changes[] = "name \"{$dept['name']}\" → \"$name\"";
                if ($dept['dept_group'] !== $group)                   $changes[] = "group \"{$dept['dept_group']}\" → \"$group\"";
                if ((int)$dept['display_order'] !== $order && $order) $changes[] = "order {$dept['display_order']} → $order";
                if ($codeChanged)                                     $changes[] = "code \"$code\" → \"$newCode\"";

                $pdo->prepare('UPDATE departments SET code = ?, name = ?, dept_group = ?, display_order = ? WHERE code = ?')
                    ->execute([
                        $codeChanged ? $newCode : $code,
                        $name, $group,
                        $order > 0 ? $order : (int)$dept['display_order'],
                        $code,
                    ]);

                if ($changes) {
                    log_activity('Edited department', 'department', $codeChanged ? $newCode : $code, implode(' · ', $changes));
                    flash('success', 'Updated ' . ($codeChanged ? $newCode : $code) . ' — ' . $name . '.');
                } else {
                    flash('success', 'No changes to save for ' . $code . '.');
                }
            } catch (Throwable $t) {
                flash('error', 'Could not save that department.');
            }
        }
        header('Location: departments.php'); exit;
    }

    // ── Deactivate / Reactivate ───────────────────────────────
    if ($action === 'deactivate' || $action === 'reactivate') {
        $code = trim($_POST['code'] ?? '');
        $s = $pdo->prepare('SELECT * FROM departments WHERE code = ?');
        $s->execute([$code]);
        $dept = $s->fetch();

        if (!$dept) {
            flash('error', 'That department no longer exists.');
            header('Location: departments.php'); exit;
        }

        if ($action === 'deactivate') {
            // Guard: a department that is home to the Procurement Unit or the Budget
            // account cannot be switched off — those roles would lose their unit.
            $sys = $pdo->prepare("SELECT COUNT(*) FROM users
                                   WHERE dept_code = ? AND role IN ('admin','budget')
                                     AND COALESCE(status,'active') <> 'rejected'");
            $sys->execute([$code]);
            if ((int)$sys->fetchColumn() > 0) {
                flash('error', "$code cannot be deactivated — it is the home department of a Procurement Unit or Budget account.");
                header('Location: departments.php'); exit;
            }

            $pdo->prepare("UPDATE departments SET status = 'inactive', deactivated_at = NOW(), deactivated_by = ? WHERE code = ?")
                ->execute([$u['full_name'], $code]);
            $held = dept_record_count($pdo, $code);
            log_activity('Deactivated department', 'department', $code,
                "Deactivated \"{$dept['name']}\" · $held linked record(s) kept");
            flash('success', "$code — {$dept['name']} is deactivated and no longer appears on Register Account. Its $held record(s) were kept.");
        } else {
            $pdo->prepare("UPDATE departments SET status = 'active', deactivated_at = NULL, deactivated_by = NULL WHERE code = ?")
                ->execute([$code]);
            log_activity('Reactivated department', 'department', $code, "Reactivated \"{$dept['name']}\"");
            flash('success', "$code — {$dept['name']} is active again and appears on Register Account.");
        }
        header('Location: departments.php'); exit;
    }

    // ── Permanent delete (only a department that holds nothing) ──
    if ($action === 'delete') {
        $code = trim($_POST['code'] ?? '');
        $s = $pdo->prepare('SELECT * FROM departments WHERE code = ?');
        $s->execute([$code]);
        $dept = $s->fetch();

        if (!$dept) {
            flash('error', 'That department no longer exists.');
        } else {
            $held = dept_record_count($pdo, $code);
            require_once __DIR__ . '/config/academic-units.php';   // v81
            if (au_has_units($code) || os_has_sets($code)) {
                flash('error', "$code has programs, majors or sets under it, so it cannot be deleted. Deactivate it instead — that hides it from Register Account and keeps every record.");
            } elseif ($held > 0) {
                flash('error', "$code holds $held record(s), so it cannot be deleted. Deactivate it instead — that hides it from Register Account and keeps every record.");
            } else {
                try {
                    $pdo->prepare('DELETE FROM departments WHERE code = ?')->execute([$code]);
                    log_activity('Deleted empty department', 'department', $code,
                        "Permanently removed \"{$dept['name']}\" · held no records");
                    flash('success', "Removed $code — {$dept['name']}. It held no records.");
                } catch (Throwable $t) {
                    flash('error', "$code could not be removed because other data still references it. Deactivate it instead.");
                }
            }
        }
        header('Location: departments.php'); exit;
    }

    header('Location: departments.php'); exit;
}

/* ─── Load the list ────────────────────────────────────────── */
try {
    $rows = $pdo->query("SELECT code, name, dept_group, display_order,
                                COALESCE(status, 'active') AS status,
                                deactivated_at, deactivated_by
                           FROM departments
                       ORDER BY display_order, name")->fetchAll();
} catch (Throwable $t) {
    $rows = $pdo->query("SELECT code, name, dept_group, display_order
                           FROM departments ORDER BY display_order, name")->fetchAll();
    foreach ($rows as &$__r) { $__r['status'] = 'active'; }
    unset($__r);
}

// v81: the programs and majors of a school (config/academic-units.php) are not departments of their
// own here — they come from ASCOT's list of academic programs — so each school says what it covers
require_once __DIR__ . '/config/academic-units.php';
$rows = array_values(array_filter($rows, fn($r) => !au_is_unit((string)$r['code']) && !os_is_set((string)$r['code'])));   // (v83: nor an office's sets)
$__covers = function (string $school): string {
    $parts = [];
    foreach (au_programs_of($school) as $pc => $pn) {
        $maj = count(array_filter(au_units_of_program($school, (string)$pc), fn($r) => !empty($r['major_name'])));
        $parts[] = $pc . ($maj ? " ($maj majors)" : '');
    }
    if (os_has_sets($school)) return implode(' · ', array_map('au_label', os_family($school)));   // v83
    return au_has_units($school) ? implode(' · ', $parts) : '';
};

$usage = dept_usage($pdo);
// …and a school holds what its programs and majors hold
foreach ($rows as $__r) {
    foreach (array_merge(array_keys(au_units_of((string)$__r['code'])), array_keys(os_sets_of((string)$__r['code']))) as $__uc) {
        foreach ($usage[$__uc] ?? [] as $__l => $__n) $usage[$__r['code']][$__l] = ($usage[$__r['code']][$__l] ?? 0) + $__n;
    }
}

// Departments that are the home of a Procurement Unit / Budget account are protected
$protected = [];
try {
    foreach ($pdo->query("SELECT DISTINCT dept_code FROM users
                           WHERE role IN ('admin','budget') AND dept_code IS NOT NULL")->fetchAll() as $p) {
        $protected[$p['dept_code']] = true;
    }
} catch (Throwable $t) {}

$countActive   = 0;
$countInactive = 0;
$countHolding  = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'inactive') $countInactive++; else $countActive++;
    if (!empty($usage[$r['code']])) $countHolding++;
}

// The order is no longer typed in. A new department is slotted at the
// end of its group by the add handler, and editing one keeps the order
// it already had, so the stored value still decides how these are
// listed — it is simply not something anyone has to fill in.

require __DIR__ . '/includes/head.php';
?>
<style>
  .dept-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .dept-table thead th {
    text-align: left; padding: 11px 14px;
    font-size: 10.5px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--muted); background: var(--navy-50);
    border-bottom: 1px solid var(--border); white-space: nowrap;
  }
  .dept-table tbody td { padding: 13px 14px; border-bottom: 1px solid var(--border); vertical-align: top; }
  .dept-table tbody tr:last-child td { border-bottom: none; }
  .dept-table tbody tr:hover { background: var(--navy-50); }
  .dept-table tbody tr.is-inactive { opacity: 0.72; }
  .dept-code {
    font-family: 'Space Grotesk', sans-serif; font-weight: 700;
    font-size: 13.5px; color: var(--navy-900);
  }
  .dept-name { color: var(--ink); line-height: 1.4; }
  .dept-note { font-size: 11px; color: var(--muted); margin-top: 3px; }
  .pill {
    display: inline-block; padding: 3px 9px; border-radius: 999px;
    font-size: 10.5px; font-weight: 700; letter-spacing: 0.03em; white-space: nowrap;
  }
  .pill-active   { background: var(--green-50); color: #14532d; border: 1px solid #86efac; }
  .pill-inactive { background: var(--orange-50); color: var(--orange-600); border: 1px solid var(--orange-100); }
  .pill-group    { background: var(--navy-50); color: var(--navy-800); border: 1px solid var(--navy-100); }
  .pill-locked   { background: var(--navy-50); color: var(--muted); border: 1px solid var(--border); }
  .dept-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
  /* Edit, Deactivate and Remove belong on one line. They need 250px and
     the column was sizing itself to 202px, so Remove dropped underneath.
     Only from 1320px up: narrower than that the table has no room, and
     holding the width would push the whole page sideways instead. */
  @media (min-width: 1320px) {
    .dept-actions { min-width: 260px; flex-wrap: nowrap; }
    .dept-actions .btn-xs, .dept-actions .pill { white-space: nowrap; }
  }
  .btn-xs { padding: 6px 13px; font-size: 12px; border-radius: 8px; }
  .records-link {
    background: none; border: none; padding: 0; cursor: pointer;
    font: inherit; font-size: 12.5px; color: var(--navy-700);
    text-decoration: underline; text-underline-offset: 2px;
  }
  .records-link:hover { color: var(--navy-900); }
  .records-none { font-size: 12.5px; color: var(--muted); }
  .dept-edit-row td { background: var(--navy-50); padding: 18px 16px; }
  .dept-edit-grid {
    display: grid; grid-template-columns: 170px 1fr 200px auto;
    gap: 12px; align-items: end;
  }
  .dept-edit-grid label {
    display: block; font-size: 10.5px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.05em; color: var(--muted); margin-bottom: 5px;
  }
  .mini-input, .mini-select {
    width: 100%; padding: 8px 11px; font-family: inherit; font-size: 13px;
    border: 1.5px solid var(--border); border-radius: 8px;
    background: var(--white); color: var(--ink);
  }
  .mini-input:focus, .mini-select:focus {
    outline: none; border-color: var(--navy-500); box-shadow: 0 0 0 3px rgba(74, 143, 179, 0.18);
  }
  .mini-input[readonly] { background: var(--navy-50); color: var(--muted); cursor: not-allowed; }
  .rec-list { margin: 0; padding: 0; list-style: none; font-size: 13px; }
  .rec-list li {
    display: flex; justify-content: space-between; gap: 18px;
    padding: 9px 2px; border-bottom: 1px dashed var(--border);
  }
  .rec-list li:last-child { border-bottom: none; }
  .rec-list b { font-family: 'Space Grotesk', sans-serif; color: var(--navy-800); }
  @media (max-width: 1080px) {
    .dept-edit-grid { grid-template-columns: 1fr 1fr; }
  }
</style>

<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Departments</h1>
        <p class="page-sub">The master list of units. Active departments are the ones offered on Register Account.</p>
      </div>
      <button type="button" class="btn btn-primary" onclick="toggleAdd()">+ Add department</button>
    </div>
    <?php render_flashes(); ?>

    <div class="stats" style="margin-bottom: 18px;">
      <div class="stat">
        <div class="stat-label">Total departments</div>
        <div class="stat-value"><?= count($rows) ?></div>
        <div class="stat-meta">On the master list</div>
      </div>
      <div class="stat">
        <div class="stat-label">Active</div>
        <div class="stat-value" style="color: #16a34a;"><?= $countActive ?></div>
        <div class="stat-meta">Shown on Register Account</div>
      </div>
      <div class="stat">
        <div class="stat-label">Deactivated</div>
        <div class="stat-value" style="color: var(--orange-600);"><?= $countInactive ?></div>
        <div class="stat-meta">Hidden &middot; records kept</div>
      </div>
      <div class="stat">
        <div class="stat-label">Holding records</div>
        <div class="stat-value" style="color: var(--navy-700);"><?= $countHolding ?></div>
        <div class="stat-meta">Have data on file</div>
      </div>
    </div>

    <!-- ─── Add department ─── -->
    <div class="card" id="addCard" style="display: none; margin-bottom: 18px;">
      <div class="card-title">Add a department</div>
      <div class="card-sub" style="margin-bottom: 16px;">
        Once saved it appears on Register Account straight away — no further setup needed.
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="dept-edit-grid">
          <div>
            <label for="add_code">Code <span style="color: var(--orange-600);">*</span></label>
            <input type="text" id="add_code" name="code" class="mini-input" required
                   maxlength="20" pattern="[A-Za-z0-9][A-Za-z0-9._\-]{1,19}"
                   placeholder="e.g. SOCJE" title="2–20 characters: letters, digits, dot, dash or underscore"
                   autocomplete="off">
          </div>
          <div>
            <label for="add_name">Department name <span style="color: var(--orange-600);">*</span></label>
            <input type="text" id="add_name" name="name" class="mini-input" required
                   maxlength="200" placeholder="e.g. School of Criminal Justice Education" autocomplete="off">
          </div>
          <div>
            <label for="add_group">Group <span style="color: var(--orange-600);">*</span></label>
            <select id="add_group" name="dept_group" class="mini-select" required>
              <?php foreach ($groups as $g): ?>
                <option value="<?= e($g) ?>"><?= e($g) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary btn-xs">Add department</button>
            <button type="button" class="btn btn-ghost btn-xs" onclick="toggleAdd()">Cancel</button>
          </div>
        </div>
        <div style="font-size: 11.5px; color: var(--muted); margin-top: 10px;">
          The code cannot be changed once records are filed under it.
        </div>
      </form>
    </div>

    <!-- ─── Search + filter ─── -->
    <div class="filter-row" style="margin-bottom: 14px;">
      <?= search_field(['id' => 'deptFilter', 'placeholder' => 'Search by code, name or group…', 'label' => 'Search departments']) ?>
      <div class="chips">
        <button type="button" class="chip active" data-status="all"      onclick="setStatusFilter(this)">All</button>
        <button type="button" class="chip"        data-status="active"   onclick="setStatusFilter(this)">Active</button>
        <button type="button" class="chip"        data-status="inactive" onclick="setStatusFilter(this)">Deactivated</button>
      </div>
    </div>

    <div class="card" style="padding: 0; overflow: hidden;">
      <table class="dept-table">
        <thead>
          <tr>
            <th style="width: 120px;">Code</th>
            <th>Department</th>
            <th style="width: 180px;">Group</th>
            <th style="width: 120px;">Status</th>
            <th style="width: 150px;">Records held</th>
            <th style="width: 230px; text-align: right;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $code     = $r['code'];
          $inactive = $r['status'] === 'inactive';
          $held     = $usage[$code] ?? [];
          $heldTot  = array_sum($held);
          $isProt   = !empty($protected[$code]);
          $rowId    = 'd' . preg_replace('/[^A-Za-z0-9]/', '_', $code);
        ?>
          <tr class="dept-row <?= $inactive ? 'is-inactive' : '' ?>"
              data-status="<?= $inactive ? 'inactive' : 'active' ?>"
              data-search="<?= e(strtolower($code . ' ' . $r['name'] . ' ' . $r['dept_group'])) ?>">
            <td><span class="dept-code"><?= e($code) ?></span></td>
            <td>
              <div class="dept-name"><?= e($r['name']) ?></div>
              <?php if (($__cv = $__covers((string)$code)) !== ''): ?>
                <div class="dept-note dept-covers"><b><?= os_has_sets((string)$code) ? 'Sets:' : 'Programs:' ?></b> <?= e($__cv) ?></div>
              <?php endif; ?>
              <?php if ($inactive && !empty($r['deactivated_at'])): ?>
                <div class="dept-note">
                  Deactivated <?= e(date('j M Y', strtotime($r['deactivated_at']))) ?><?= !empty($r['deactivated_by']) ? ' by ' . e($r['deactivated_by']) : '' ?>
                </div>
              <?php elseif ($isProt): ?>
                <div class="dept-note">Home of a Procurement Unit or Budget account</div>
              <?php endif; ?>
            </td>
            <td><span class="pill pill-group"><?= e($r['dept_group']) ?></span></td>
            <td>
              <span class="pill <?= $inactive ? 'pill-inactive' : 'pill-active' ?>">
                <?= $inactive ? 'Deactivated' : 'Active' ?>
              </span>
            </td>
            <td>
              <?php if ($heldTot > 0): ?>
                <button type="button" class="records-link"
                        onclick="showRecords('<?= e($code) ?>')"><?= $heldTot ?> record<?= $heldTot === 1 ? '' : 's' ?></button>
              <?php else: ?>
                <span class="records-none">None yet</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="dept-actions">
                <button type="button" class="btn btn-ghost btn-xs" onclick="toggleEdit('<?= e($rowId) ?>')">Edit</button>

                <?php if ($inactive): ?>
                  <form method="POST" style="margin:0;"
                        data-confirm="<?= e($r['name']) ?> will appear on Register Account again."
                        data-confirm-title="Reactivate department?" data-confirm-label="Reactivate" data-confirm-tone="success">
                    <input type="hidden" name="action" value="reactivate">
                    <input type="hidden" name="code" value="<?= e($code) ?>">
                    <button type="submit" class="btn btn-xs" style="background:#15803d; border-color:#15803d; color:#fff;">Reactivate</button>
                  </form>
                <?php elseif ($isProt): ?>
                  <span class="pill pill-locked" title="This unit is the home of a Procurement Unit or Budget account">Protected</span>
                <?php else: ?>
                  <form method="POST" style="margin:0;"
                        data-confirm="<?= e($r['name']) ?> will stop appearing on Register Account. Its <?= $heldTot ?> record(s) — PPMPs, APPs and every past submission — are kept and stay visible everywhere else."
                        data-confirm-title="Deactivate department?" data-confirm-label="Deactivate" data-confirm-tone="danger">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="code" value="<?= e($code) ?>">
                    <button type="submit" class="btn btn-ghost btn-xs" style="color: var(--orange-600);">Deactivate</button>
                  </form>
                <?php endif; ?>

                <?php if ($heldTot === 0 && !$isProt && !au_has_units((string)$code) && !os_has_sets((string)$code)): ?>
                  <form method="POST" style="margin:0;"
                        data-confirm="<?= e($code) ?> holds no records anywhere, so removing it deletes nothing else. This cannot be undone."
                        data-confirm-title="Remove empty department?" data-confirm-label="Remove" data-confirm-tone="danger">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="code" value="<?= e($code) ?>">
                    <button type="submit" class="btn btn-ghost btn-xs" style="color: var(--muted);"
                            title="Only possible because this department holds no records">Remove</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>

          <!-- Inline edit -->
          <tr class="dept-edit-row" id="edit-<?= e($rowId) ?>" style="display: none;">
            <td colspan="6">
              <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="code" value="<?= e($code) ?>">
                <div class="dept-edit-grid">
                  <div>
                    <label for="c-<?= e($rowId) ?>">Code</label>
                    <input type="text" id="c-<?= e($rowId) ?>" name="new_code" class="mini-input"
                           value="<?= e($code) ?>" maxlength="20"
                           pattern="[A-Za-z0-9][A-Za-z0-9._\-]{1,19}"
                           <?= $heldTot > 0 ? 'readonly title="Locked — records are filed under this code"' : ((au_has_units((string)$code) || os_has_sets((string)$code)) ? 'readonly title="Locked — its programs, majors or sets are filed under this code"' : '') ?>>
                    <?php if ($heldTot > 0): ?>
                      <div style="font-size: 10.5px; color: var(--muted); margin-top: 4px;">Locked — <?= $heldTot ?> record(s) filed</div>
                    <?php elseif (au_has_units((string)$code) || os_has_sets((string)$code)): ?>
                      <div style="font-size: 10.5px; color: var(--muted); margin-top: 4px;">Locked — its programs, majors or sets are filed under it</div>
                    <?php endif; ?>
                  </div>
                  <div>
                    <label for="n-<?= e($rowId) ?>">Department name</label>
                    <input type="text" id="n-<?= e($rowId) ?>" name="name" class="mini-input"
                           value="<?= e($r['name']) ?>" maxlength="200" required>
                  </div>
                  <div>
                    <label for="g-<?= e($rowId) ?>">Group</label>
                    <select id="g-<?= e($rowId) ?>" name="dept_group" class="mini-select" required>
                      <?php foreach ($groups as $g): ?>
                        <option value="<?= e($g) ?>" <?= $r['dept_group'] === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn btn-primary btn-xs">Save changes</button>
                    <button type="button" class="btn btn-ghost btn-xs" onclick="toggleEdit('<?= e($rowId) ?>')">Cancel</button>
                  </div>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div id="deptEmpty" style="display: none; padding: 48px 24px; text-align: center; color: var(--muted);">
        <div style="font-size: 15px; font-weight: 600; color: var(--ink); margin-bottom: 5px;">No departments match</div>
        <div style="font-size: 13px;">Try a different code, name or group.</div>
      </div>
    </div>
  </main>
</div>

<!-- ─── Records breakdown ─── -->
<div class="modal-overlay" id="recModal" role="dialog" aria-modal="true" aria-labelledby="recTitle">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="recTitle">Records held</div>
        <div class="modal-sub" id="recSub">These stay on file whatever the department's status is.</div>
      </div>
      <button type="button" class="modal-close" onclick="closeRecords()" aria-label="Close">&times;</button>
    </div>
    <ul class="rec-list" id="recList"></ul>
    <div class="modal-actions">
      <button type="button" class="btn btn-primary" onclick="closeRecords()">Close</button>
    </div>
  </div>
</div>

<script>
  var DEPT_USAGE = <?= json_encode($usage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var DEPT_NAMES = <?= json_encode(array_column($rows, 'name', 'code'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function toggleAdd() {
    var c = document.getElementById('addCard');
    var show = c.style.display === 'none';
    c.style.display = show ? '' : 'none';
    if (show) document.getElementById('add_code').focus();
  }

  function toggleEdit(id) {
    var row = document.getElementById('edit-' + id);
    if (!row) return;
    var show = row.style.display === 'none';
    // Only one editor open at a time
    document.querySelectorAll('.dept-edit-row').forEach(function (r) { r.style.display = 'none'; });
    row.style.display = show ? '' : 'none';
    if (show) { var f = row.querySelector('input[name="name"]'); if (f) f.focus(); }
  }

  /* ─── Search + status filter ─── */
  var statusFilter = 'all';

  function applyFilters() {
    var term = (document.getElementById('deptFilter').value || '').trim().toLowerCase();
    var shown = 0;
    document.querySelectorAll('.dept-row').forEach(function (row) {
      var okText   = !term || row.dataset.search.indexOf(term) > -1;
      var okStatus = statusFilter === 'all' || row.dataset.status === statusFilter;
      var visible  = okText && okStatus;
      row.style.display = visible ? '' : 'none';
      // Keep an open editor with its row
      var ed = row.nextElementSibling;
      if (ed && ed.classList.contains('dept-edit-row') && !visible) ed.style.display = 'none';
      if (visible) shown++;
    });
    document.getElementById('deptEmpty').style.display = shown ? 'none' : '';
  }

  function setStatusFilter(btn) {
    statusFilter = btn.dataset.status;
    document.querySelectorAll('.chip').forEach(function (c) { c.classList.remove('active'); });
    btn.classList.add('active');
    applyFilters();
  }

  document.getElementById('deptFilter').addEventListener('input', applyFilters);

  /* ─── Records breakdown ─── */
  function showRecords(code) {
    var held = DEPT_USAGE[code] || {};
    var list = document.getElementById('recList');
    var total = 0;
    list.innerHTML = '';
    Object.keys(held).forEach(function (label) {
      total += held[label];
      var li = document.createElement('li');
      var a = document.createElement('span'); a.textContent = label;
      var b = document.createElement('b');    b.textContent = held[label];
      li.appendChild(a); li.appendChild(b);
      list.appendChild(li);
    });
    document.getElementById('recTitle').textContent = code + ' — records held';
    document.getElementById('recSub').textContent =
      total + ' record(s) on file for ' + (DEPT_NAMES[code] || code) +
      '. These are kept whether the department is active or deactivated.';
    document.getElementById('recModal').classList.add('active');
  }

  function closeRecords() {
    document.getElementById('recModal').classList.remove('active');
  }

  document.getElementById('recModal').addEventListener('click', function (e) {
    if (e.target === this) closeRecords();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeRecords();
  });
</script>
</body>
</html>
