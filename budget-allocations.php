<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_budget();
ensure_admin_tables();
$u = current_user();
$activeNav = 'allocations';
$pdo = db();
require_once __DIR__ . '/config/funds.php';
require_once __DIR__ . '/config/academic-units.php';

/** v92: where the allotment comes from (config/funds.php) — a column added once. */
function ba_fund_column(): void {
    try {
        if (db()->inTransaction()) return;
        $have = db()->query('SHOW COLUMNS FROM dept_budgets')->fetchAll(PDO::FETCH_COLUMN);
        if ($have && !in_array('fund_source', $have, true)) db()->exec("ALTER TABLE dept_budgets ADD COLUMN fund_source VARCHAR(60) NULL");
    } catch (Throwable $t) {}
}
ba_fund_column();

// Which FY are we allocating?
$fy = active_fy();

// Remove an allotment for the selected department and fiscal year.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $dept_code = trim((string)($_POST['dept_code'] ?? ''));
    $fyPost = active_fy();
    if ($dept_code === '' || !au_is_active($dept_code)) {
        flash('error', 'Choose an active department to remove its allocation.');
    } else {
        try {
            $del = $pdo->prepare('DELETE FROM dept_budgets WHERE dept_code = ? AND fy = ?');
            $del->execute([$dept_code, $fyPost]);
            if ($del->rowCount()) {
                log_activity('Removed budget allocation', 'budget', "$dept_code · FY $fyPost");
                flash('success', "Budget allocation for $dept_code FY $fyPost removed.");
            } else {
                flash('error', 'There is no allocation to remove for that department and fiscal year.');
            }
        } catch (Throwable $t) {
            error_log('remove budget allocation failed: ' . $t->getMessage());
            flash('error', 'Could not remove the budget allocation.');
        }
    }
    header('Location: budget-allocations.php?fy=' . $fyPost);
    exit;
}

// Handle POST — save an alloted budget from GAA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set') {
    $dept_code = trim($_POST['dept_code'] ?? '');
    // v81: the allotment belongs to the school, never to one of its programs / majors
    require_once __DIR__ . '/config/academic-units.php';
    if (au_is_unit($dept_code) || os_is_set($dept_code)) $dept_code = au_school_of($dept_code);   // (v83: a set → its office)
    $travel = (float)str_replace(',', '', $_POST['travel_budget'] ?? '0');
    $training = (float)str_replace(',', '', $_POST['training_budget'] ?? '0');
    $supply = (float)str_replace(',', '', $_POST['supply_budget'] ?? '0');
    $equipment = (float)str_replace(',', '', $_POST['equipment_budget'] ?? '0');
    // Total Allocation is automatically computed from the four category budgets.
    $alloted = round($travel + $training + $supply + $equipment, 2);
    $fyPost    = active_fy();
    $fund      = trim((string)($_POST['fund_source'] ?? ''));

    if ($dept_code === '' || $fyPost <= 0) {
        flash('error', 'Invalid department or fiscal year.');
    } elseif (!au_is_active($dept_code)) {
        flash('error', "$dept_code is deactivated. Reactivate it on Departments first.");
    } elseif (!is_fund($fund)) {
        flash('error', 'Choose the source of fund.');
    } elseif (min($alloted, $travel, $training, $supply, $equipment) < 0) {
        flash('error', 'Total Allocation and category budgets cannot be negative.');
    } else {
        try {
            $pdo->prepare('INSERT INTO dept_budgets
                (dept_code, fy, alloted_budget, travel_budget, training_budget, supply_budget, equipment_budget, fund_source, set_by, set_role)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    alloted_budget = VALUES(alloted_budget),
                    travel_budget = VALUES(travel_budget),
                    training_budget = VALUES(training_budget),
                    supply_budget = VALUES(supply_budget),
                    equipment_budget = VALUES(equipment_budget),
                    fund_source = VALUES(fund_source),
                    set_by = VALUES(set_by),
                    set_role = VALUES(set_role)')
                ->execute([$dept_code, $fyPost, $alloted, $travel, $training, $supply, $equipment, $fund, $u['full_name'], 'budget']);
            log_activity('Set alloted budget', 'budget', "$dept_code · FY $fyPost",
                'Alloted ₱' . number_format($alloted, 2) . ' · ' . fund_label($fund));
            flash('success', "Alloted budget for $dept_code FY $fyPost saved.");
        } catch (Throwable $t) {
            error_log('set-budget page failed: ' . $t->getMessage());
            flash('error', 'Could not save the alloted budget.');
        }
    }
    header('Location: budget-allocations.php?fy=' . $fyPost);
    exit;
}

// Load all departments (excluding the Budget Unit itself)
// v81: one allotment per school — its programs and majors (config/academic-units.php) share it
$departments = array_values(array_filter($pdo->query("SELECT * FROM departments
                             WHERE 1=1
                          ORDER BY dept_group, display_order, name")->fetchAll(), fn($d) => empty($d['parent_code'])));
$departments = au_active_rows($departments);   // v92: a deactivated department is not shown until it is reactivated
/** What a school's allotment covers: "BEED · BPED · BSED (5 majors) · BTLED (2 majors)" */
function ba_covers(string $school): string {
    $parts = [];
    foreach (au_programs_of($school) as $pc => $pn) {
        $maj = count(array_filter(au_units_of_program($school, (string)$pc), fn($r) => !empty($r['major_name'])));
        $parts[] = $pc . ($maj ? " ($maj majors)" : '');
    }
    return implode(' · ', $parts);
}

// Load current alloted budgets for the FY
$budgets = [];
try {
    $bstmt = $pdo->prepare('SELECT * FROM dept_budgets WHERE fy = ?');
    $bstmt->execute([$fy]);
    foreach ($bstmt->fetchAll() as $row) {
        if (!au_is_active((string)$row['dept_code'])) continue;   // v92
        $budgets[$row['dept_code']] = $row;
    }
} catch (Throwable $t) {}

// Group departments by Academic / Administrative
$academicSupport = []; // Only Schools & Colleges belong to Academic
$grouped = ['Academic' => [], 'Administrative' => []];
foreach ($departments as $d) {
    if ($d['dept_group'] === 'Schools & Colleges' && !in_array(strtoupper((string)$d['code']), ['LIB','REG','NSTP','SCA','IEAS','LAB'], true)) {
        $grouped['Academic'][] = $d;
    } else {
        $grouped['Administrative'][] = $d;
    }
}

// Totals
$totalAlloted   = array_sum(array_map(fn($b) => (float)$b['alloted_budget'], $budgets));
$setCount       = count($budgets);
$totalDepts     = count($departments);

// FY dropdown range
$currentYear = active_fy();
$fyOptions   = range($currentYear - 1, $currentYear + 3);
$pageTitle = 'Budget Allocations · ProcureEase';
require __DIR__ . '/includes/head.php';
?>
<div class="app">
  <?php require __DIR__ . '/includes/sidebar-budget.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Budget Allocations · FY <?= $currentYear ?></h1></div>
      <div style="display: inline-flex; gap: 8px; align-items: center;">
        <span class="form-label" style="margin: 0;">Fiscal year</span>
        <span style="padding: 8px 14px; background: var(--navy-50); border-radius: 8px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; color: var(--navy-800);"> FY <?= $fy ?></span></div></div>
    <?php render_flashes(); ?>

    <!-- Summary stats -->
    <div class="stats" style="margin-bottom: 24px;">
      <div class="stat">
        <div class="stat-label">Total Alloted</div>
        <div class="stat-value" style="color: var(--navy-700);">₱<?= number_format($totalAlloted, 0) ?></div>
        <div class="stat-meta">Across all departments</div></div>
      <div class="stat">
        <div class="stat-label">Allocations set</div>
        <div class="stat-value" style="color: <?= $setCount === $totalDepts ? '#16a34a' : 'var(--muted)' ?>;"><?= $setCount ?>/<?= $totalDepts ?></div>
        <div class="stat-meta">Departments with an allotment</div></div>
      <div class="stat">
        <div class="stat-label">Fiscal year</div>
        <div class="stat-value">FY <?= $fy ?></div>
        <div class="stat-meta">Active allocation cycle</div></div></div>

    <?php
      // Build data for pie chart — only depts with an allocation > 0
      $pieData = [];
      foreach ($budgets as $code => $b) {
        $amt = (float)$b['alloted_budget'];
        if ($amt > 0) $pieData[] = ['code' => $code, 'amount' => $amt];
      }
      usort($pieData, function($a, $b) { return $b['amount'] <=> $a['amount']; });
      $pieTotal = array_sum(array_column($pieData, 'amount'));
      $palette = ['#0d1a3d', '#121358', '#1c2c52', '#232f72', '#2f3f8a', '#3a4a9e',
                  '#4a5ab0', '#3368a0', '#4a8fb3', '#5a7ab3', '#6f93c9', '#95bdd7'];
    ?>
    <?php if ($pieData && $pieTotal > 0): ?>
      <div class="card" style="margin-bottom: 20px; padding: 22px 26px;">
        <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; color: var(--navy-800); margin-bottom: 14px;">Allocation by department (FY <?= $fy ?>)</div>
        <div style="display: grid; grid-template-columns: 260px 1fr; gap: 32px; align-items: center;">
          <?php
            $cx = 130; $cy = 130; $r = 110;
            $accum = 0;
            $slices = [];
            foreach ($pieData as $i => $s) {
              $frac = $s['amount'] / $pieTotal;
              $startAngle = $accum * 2 * M_PI;
              $endAngle   = ($accum + $frac) * 2 * M_PI;
              $x1 = $cx + $r * cos($startAngle - M_PI / 2);
              $y1 = $cy + $r * sin($startAngle - M_PI / 2);
              $x2 = $cx + $r * cos($endAngle - M_PI / 2);
              $y2 = $cy + $r * sin($endAngle - M_PI / 2);
              $largeArc = $frac > 0.5 ? 1 : 0;
              $slices[] = [
                'path'  => "M {$cx} {$cy} L {$x1} {$y1} A {$r} {$r} 0 {$largeArc} 1 {$x2} {$y2} Z",
                'color' => $palette[$i % count($palette)],
                'pct'   => $frac * 100,
                'code'  => $s['code'],
                'amount'=> $s['amount'],
              ];
              $accum += $frac;
            }
          ?>
          <svg viewBox="0 0 260 260" style="width: 100%; max-width: 260px; height: auto;">
            <?php foreach ($slices as $sl): ?>
              <path d="<?= $sl['path'] ?>" fill="<?= $sl['color'] ?>" stroke="white" stroke-width="2"/>
            <?php endforeach; ?></svg>
          <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px 20px; font-size: 12px;">
            <?php foreach ($slices as $sl): ?>
              <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                <div style="width: 12px; height: 12px; background: <?= $sl['color'] ?>; border-radius: 3px; flex-shrink: 0;"></div>
                <div style="min-width: 0; flex: 1;">
                  <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; color: var(--navy-800); font-size: 12.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= e($sl['code']) ?></div>
                  <div style="color: var(--muted); font-size: 11px;">₱<?= number_format($sl['amount'], 0) ?> · <?= number_format($sl['pct'], 1) ?>%</div></div></div>
            <?php endforeach; ?></div></div></div>
    <?php endif; ?>

    <!-- v92: search -->
    <div class="filter-row" role="search" style="align-items: center; margin: 8px 0 0;">
      <?= search_field(['id' => 'baSearch', 'placeholder' => 'Search department code or name…', 'label' => 'Search departments']) ?></div>
    <div id="baNone" class="card" style="display: none; text-align: center; padding: 40px 24px; color: var(--muted); margin-top: 16px;">No department matches your search.</div>

    <?php foreach (['Academic', 'Administrative'] as $groupName):
      $groupDepts = $grouped[$groupName];
      if (!$groupDepts) continue;
      $groupIcon  = $groupName === 'Academic' ? '' : '';
      $groupColor = $groupName === 'Academic' ? 'var(--navy-700)' : 'var(--orange-600)';
    ?>
      <div class="ba-group">
      <div style="display: flex; align-items: center; gap: 10px; margin: 24px 0 12px;">
        <span style="font-size: 20px;"><?= $groupIcon ?></span>
        <h3 style="font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; color: <?= $groupColor ?>; margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
          <?= $groupName ?></h3>
        <span style="color: var(--muted); font-size: 12px;">(<?= count($groupDepts) ?>)</span>
        <div style="flex: 1; height: 1px; background: var(--border);"></div></div>

      <!-- One <form> per department in this group, referenced by inputs via form="" attribute -->
      <?php foreach ($groupDepts as $d): ?>
        <form method="POST" id="frm-<?= e($d['code']) ?>" style="display: none;">
          <input type="hidden" name="action" value="set">
          <input type="hidden" name="dept_code" value="<?= e($d['code']) ?>">
          <input type="hidden" name="fy" value="<?= $fy ?>"></form>
      <?php endforeach; ?>

      <div class="card" style="padding: 0; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="background: var(--navy-50); border-bottom: 1.5px solid var(--border);">
              <th style="text-align: left; padding: 12px 16px; font-size: 10.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em;">Department</th>
              <th style="text-align: left; padding: 12px 14px; font-size: 10.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em;">Total Allocation (₱)</th>
              <th style="text-align: left; padding: 12px 14px; font-size: 10.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em;">Source of Fund</th>
              <th style="text-align: right; padding: 12px 16px; font-size: 10.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em;"></th></tr></thead>
          <tbody>
            <?php foreach ($groupDepts as $d):
              $current = $budgets[$d['code']] ?? null;
              $curAmt  = $current ? (float)$current['alloted_budget'] : 0;
              $cats = ['travel'=>'Travel','training'=>'Training','supply'=>'Supply','equipment'=>'Equipment'];
              $frmId   = 'frm-' . $d['code'];
            ?>
              <tr id="<?= e($d['code']) ?>" class="ba-row" data-q="<?= e(mb_strtolower($d['code'] . ' ' . $d['name'])) ?>" style="border-bottom: 1px solid var(--border);">
                <td style="padding: 14px 16px; vertical-align: middle;">
                  <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; color: var(--ink); font-size: 13.5px;"><?= e($d['code']) ?></div>
                  <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;"><?= e($d['name']) ?></div>
                  <?php if (au_has_units((string)$d['code'])): ?>
                    <div class="ba-covers" style="font-size: 11px; color: var(--ink); margin-top: 4px; line-height: 1.45;"><b>Covers:</b> <?= e(ba_covers((string)$d['code'])) ?></div>
                  <?php elseif (os_has_sets((string)$d['code'])): /* v83 */ ?>
                    <div class="ba-covers" style="font-size: 11px; color: var(--ink); margin-top: 4px; line-height: 1.45;"><b>Covers:</b> <?= e(implode(' · ', array_map('au_label', os_family((string)$d['code'])))) ?> (shared)</div>
                  <?php endif; ?></td>
                <td style="padding: 14px 14px; vertical-align: middle;">
                  <div style="display:grid;grid-template-columns:repeat(2,minmax(110px,1fr));gap:6px;min-width:245px">
                    <div style="grid-column:1/-1;font-size:10px;color:var(--muted);font-weight:700;margin-top:3px">Category budgets (guide/reference only)</div>
                    <?php foreach ($cats as $__key=>$__label): ?><label style="font-size:10px;color:var(--muted);font-weight:700"><?= e($__label) ?>
                    <input type="text" inputmode="decimal" name="<?= e($__key) ?>_budget" form="<?= e($frmId) ?>" value="<?= number_format((float)($current[$__key.'_budget'] ?? 0),2,'.','') ?>" class="form-input money ba-category-budget" data-dept="<?= e($d['code']) ?>" placeholder="0.00" style="width:100%;padding:6px 8px;font-weight:700"></label><?php endforeach; ?>
                    <label style="grid-column:1/-1;font-size:10px;color:var(--muted);font-weight:800;margin-top:4px">Total Allocation
                      <input type="text" value="<?= number_format((float)($current['travel_budget'] ?? 0) + (float)($current['training_budget'] ?? 0) + (float)($current['supply_budget'] ?? 0) + (float)($current['equipment_budget'] ?? 0),2,'.','') ?>" class="form-input money ba-total-allocation" data-dept="<?= e($d['code']) ?>" readonly placeholder="0.00" style="width:100%;padding:7px 8px;font-weight:800;background:var(--surface-2, #f3f4f6)" aria-label="Total Allocation for <?= e($d['code']) ?>"></label>
                  </div></td>
                <td style="padding: 14px 14px; vertical-align: middle;">
                  <?php $__fund = trim((string)($current['fund_source'] ?? '')); if ($__fund === '') $__fund = 'GAA'; ?>
                  <select name="fund_source" form="<?= e($frmId) ?>" class="filter-select ba-fund" aria-label="Source of fund for <?= e($d['code']) ?>" style="min-width: 190px;">
                    <?php foreach (FUND_SOURCES as $__fc => $__fm): ?>
                      <option value="<?= e($__fc) ?>" <?= $__fund === $__fc ? 'selected' : '' ?>><?= e($__fm['label']) ?></option>
                    <?php endforeach; ?>
                    <?php if (!is_fund($__fund)): ?><option value="<?= e($__fund) ?>" selected><?= e($__fund) ?></option><?php endif; ?></select></td>
                <td style="padding: 14px 16px; vertical-align: middle; text-align: right; white-space:nowrap;">
                  <button type="submit" form="<?= e($frmId) ?>" class="btn btn-primary" style="padding: 7px 14px; font-size: 12px;">
                    <?= $curAmt > 0 ? 'Update' : 'Save' ?></button>
                  <form method="POST" style="display:inline" data-confirm="Remove this department's FY <?= (int)$fy ?> budget allocation?" data-confirm-title="Remove allocation" data-confirm-label="Remove" data-confirm-tone="danger">
                    <input type="hidden" name="action" value="remove"><input type="hidden" name="dept_code" value="<?= e($d['code']) ?>">
                    <button type="submit" class="btn btn-ghost" style="padding:7px 10px;font-size:12px;color:#b91c1c;margin-left:4px">Remove</button>
                  </form></td></tr>
              <?php if ($current && !empty($current['set_by'])): ?>
                <tr class="ba-sub" data-for="<?= e($d['code']) ?>" style="border-bottom: 1px solid var(--border);">
                  <td colspan="4" style="padding: 4px 16px 10px; font-size: 11px; color: var(--muted);"> Last set by <strong><?= e($current['set_by']) ?></strong> · <?= reltime_span($current['updated_at']) ?></td></tr>
              <?php endif; ?>
            <?php endforeach; ?></tbody></table></div></div>
    <?php endforeach; ?>
    <script>
    (function () {
      var box = document.getElementById('baSearch');
      if (!box) return;
      function run() {
        var q = box.value.trim().toLowerCase(), any = false;
        document.querySelectorAll('.ba-group').forEach(function (g) {
          var shown = 0;
          g.querySelectorAll('tr.ba-row').forEach(function (r) {
            var hit = q === '' || r.getAttribute('data-q').indexOf(q) !== -1;
            r.style.display = hit ? '' : 'none';
            var sub = g.querySelector('tr.ba-sub[data-for="' + r.id + '"]');
            if (sub) sub.style.display = hit ? '' : 'none';
            if (hit) shown++;
          });
          g.style.display = shown ? '' : 'none';
          if (shown) any = true;
        });
        document.getElementById('baNone').style.display = any ? 'none' : '';
      }
      box.addEventListener('input', run);
      box.addEventListener('search', run);
    })();
    </script>    <script>
    (function () {
      function numericValue(input) {
        return parseFloat(String(input.value || '0').replace(/,/g, '')) || 0;
      }
      function updateTotal(dept) {
        var total = 0;
        document.querySelectorAll('.ba-category-budget[data-dept="' + dept + '"]').forEach(function (input) {
          total += numericValue(input);
        });
        var output = document.querySelector('.ba-total-allocation[data-dept="' + dept + '"]');
        if (output) output.value = total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      }
      document.querySelectorAll('.ba-category-budget').forEach(function (input) {
        input.addEventListener('input', function () { updateTotal(input.getAttribute('data-dept')); });
        input.addEventListener('change', function () { updateTotal(input.getAttribute('data-dept')); });
      });
    })();
    </script>
</main></div></body></html>
