<?php
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_admin();

// Auto-create the activity_logs / settings / backups tables if they don't exist yet
ensure_admin_tables();

// Send overdue reminders to any late departments (at most once per user per 2 days)
if (function_exists('check_and_send_reminders')) { check_and_send_reminders(); }

$u = current_user();
$deptOpen = $_GET['dept'] ?? null;
$fyFilter = $_GET['fy']   ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';  // 'all' | 'revision'
$groupFilter  = $_GET['group']  ?? 'all';  // 'all' | 'Academic' | 'Administrative'
$search = trim($_GET['q'] ?? '');

// Admin-tools quick stats — safely handle a fresh DB where these tables might not exist yet
$activityToday = $activityWeek = $backupCount = $totalRows = 0;
$recentEvent = $lastBackup = null;
$activeFY = active_fy(); $backupSched = 'weekly';
try {
    $activityToday = (int)db()->query('SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $activityWeek  = (int)db()->query('SELECT COUNT(*) FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
    $recentEvent   = db()->query('SELECT full_name, username, action, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 1')->fetch() ?: null;
} catch (Throwable $t) { /* activity_logs table not created yet */ }
try {
    $backupCount = (int)db()->query('SELECT COUNT(*) FROM backups')->fetchColumn();
    $lastBackup  = db()->query('SELECT created_at FROM backups ORDER BY created_at DESC LIMIT 1')->fetch() ?: null;
} catch (Throwable $t) { /* backups table not created yet */ }
try {
    $backupSched = db()->query("SELECT value FROM settings WHERE key_name = 'backup_schedule'")->fetchColumn() ?: 'weekly';
} catch (Throwable $t) { /* settings table not created yet */ }
$tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) $totalRows += (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();

// Small helper for the activity-tool preview line
function admin_rel_time(string $t): string {
    $secs = time() - strtotime($t);
    if ($secs < 60)     return $secs . 's ago';
    if ($secs < 3600)   return floor($secs / 60)    . 'm ago';
    if ($secs < 86400)  return floor($secs / 3600)  . 'h ago';
    if ($secs < 604800) return floor($secs / 86400) . 'd ago';
    return date('M j, Y', strtotime($t));
}

// Include the Budget Unit as a department folder so its own PPMP follows the same review workflow.
$stmt = db()->query("SELECT * FROM departments ORDER BY display_order");
$departments = $stmt->fetchAll();
require_once __DIR__ . '/config/academic-units.php'; $departments = au_listable($departments);   // v81: a school's programs and majors, not the school itself
$departments = au_active_rows($departments);   // v92: a deactivated department is not shown

// Load all PPMPs
$stmt = db()->query('SELECT id, dept_code, fy, updated_at, submitted_at, submitted_by, status FROM ppmps ORDER BY dept_code, fy DESC');
$allPPMPs = $stmt->fetchAll();

// Load entry counts and totals per PPMP
$stmt = db()->query('SELECT ppmp_id, COUNT(*) AS cnt, SUM(amount) AS total FROM ppmp_entries GROUP BY ppmp_id');
$stats = [];
foreach ($stmt->fetchAll() as $r) $stats[$r['ppmp_id']] = $r;

// Load pending revision requests (only unresolved) → { dept_code => [fy => request] }
$stmt = db()->query('SELECT * FROM revision_requests WHERE resolved = 0');
$revByDept = [];
foreach ($stmt->fetchAll() as $r) { $revByDept[$r['dept_code']][$r['fy']] = $r; }

// Assemble department index
$deptIndex = [];
foreach ($departments as $d) {
    $deptIndex[$d['code']] = $d + ['files' => [], 'hasRevision' => false];
}
foreach ($allPPMPs as $p) {
    if (!isset($deptIndex[$p['dept_code']])) continue;
    $revNote = $revByDept[$p['dept_code']][$p['fy']] ?? null;
    if ($revNote) $deptIndex[$p['dept_code']]['hasRevision'] = true;
    $deptIndex[$p['dept_code']]['files'][] = [
        'id'         => $p['id'],
        'fy'         => $p['fy'],
        'submitted'  => $p['submitted_at'],
        'submitted_by' => $p['submitted_by'],
        'entries'    => (int)($stats[$p['id']]['cnt'] ?? 0),
        'amount'     => (float)($stats[$p['id']]['total'] ?? 0),
        'revision'   => $revNote,
    ];
}

// Totals for stats strip — for the active fiscal year only, so both start
// again from zero when the fiscal year changes (files in the Archive left out)
$totalDepts = count($departments);
// v82: how many departments are registered — the same list as Register Account
require_once __DIR__ . '/config/admin-units.php';
$registeredDepts = ao_registered_count();
$__unitCount = count(array_filter($departments, fn($d) => au_is_unit((string)$d['code'])));
$__submitting = ao_submitting_count();
$totalFiles = 0;
try {
    $s = db()->prepare('SELECT COUNT(*) FROM ppmps p WHERE p.fy = ?
                           AND NOT EXISTS (SELECT 1 FROM archived_ppmps a WHERE a.ppmp_id = p.id)');
    $s->execute([(int)$activeFY]);
    $totalFiles = (int)$s->fetchColumn();
} catch (Throwable $t) {
    foreach ($allPPMPs as $p) if ((int)$p['fy'] === (int)$activeFY) $totalFiles++;
}
$totalRevisions = 0;
foreach ($revByDept as $byFY) if (isset($byFY[(int)$activeFY])) $totalRevisions++;

// Approved (active) user accounts
$approvedUsers = 0;
try {
    $approvedUsers = (int)db()->query("SELECT COUNT(*) FROM users WHERE status = 'active' AND role IN ('user','dept_admin','budget')")->fetchColumn();
} catch (Throwable $t) {}

// Consolidated total for the active FY
$consolFy = (int)$activeFY;
// Consolidated budget — only PPMPs the Procurement Unit has APPROVED
$stmt = db()->prepare('SELECT COALESCE(SUM(e.amount), 0) AS total
                         FROM ppmp_entries e
                         JOIN ppmps p ON p.id = e.ppmp_id
                        WHERE p.fy = ?
                          AND p.status = \'approved\'');
$stmt->execute([$consolFy]);
$consolidated = (float)$stmt->fetch()['total'];
// Units submitted — units whose PPMP has been approved for this FY
$stmt = db()->prepare('SELECT COUNT(DISTINCT dept_code) AS n FROM ppmps WHERE fy = ? AND status = \'approved\'');
$stmt->execute([$consolFy]);
$submittedCurrentFy = (int)$stmt->fetch()['n'];

$activeNav = 'home';
$pageTitle = 'Dashboard';
require __DIR__ . '/includes/head.php';
?>

<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>

  <main class="main">

<?php if ($deptOpen === null): ?>
    <!-- ── FOLDER GRID VIEW ─── -->
    <div class="page-head">
      <div>
        <h1 class="page-title">Dashboard · FY <?= $consolFy ?></h1>
        <p class="page-sub">Overview of procurement activity for FY <?= $consolFy ?>.</p></div></div>

    <?php render_flashes(); ?>

    <!-- Consolidation hero -->
    <div class="consol">
      <div class="consol-title">
        <div class="consol-eyebrow">FY <?= $consolFy ?> Consolidation · NGPA</div>
        <div class="consol-heading"> Annual Procurement Plan
          <small>Aggregated from approved unit-level PPMPs under RA 12009</small></div></div>
      <div class="consol-metric">
        <div class="consol-metric-label">Consolidated budget</div>
        <div class="consol-metric-value">₱<?= number_format($consolidated / 1000000, 2) ?>M</div></div>
      <div class="consol-metric">
        <div class="consol-metric-label">Units approved</div>
        <div class="consol-metric-value"><?= $submittedCurrentFy ?> / <?= $totalDepts ?></div></div>
      <div>
        <a href="consolidation.php" class="btn btn-ghost">Open consolidation</a></div></div>

    <!-- Stats -->
    <div class="stats">
      <div class="stat">
        <div class="stat-label">Total PPMP files</div>
        <div class="stat-value"><?= $totalFiles ?></div>
        <div class="stat-meta">FY <?= (int)$activeFY ?> · all departments</div></div>
      <div class="stat">
        <div class="stat-label">Departments</div>
        <div class="stat-value" id="statDepartments"><?= $registeredDepts ?></div>
        <div class="stat-meta">Registered</div></div>
      <div class="stat">
        <div class="stat-label">Approved users</div>
        <div class="stat-value" style="color: #16a34a;"><?= $approvedUsers ?></div>
        <div class="stat-meta">Active accounts</div></div>
      <div class="stat stat-revision">
        <div class="stat-label">Revision requested</div>
        <div class="stat-value"><?= $totalRevisions ?></div>
        <div class="stat-meta">FY <?= (int)$activeFY ?> files needing action</div></div></div>

    <?php /* v79: budget per department and submissions before the deadline */
          $dcFy = (int)$activeFY; require __DIR__ . '/includes/dashboard-charts.php'; ?>

    <?php
    // ─── Compute PPMP overview stats for the charts ────────────
    // Chart FY follows the FY filter dropdown. If "all" is selected, use the
    // most recent fiscal year that actually has files in the database.
    $availableFys = array_unique(array_map(fn($p) => (int)$p['fy'], $allPPMPs));
    sort($availableFys);
    if ($fyFilter !== 'all' && (int)$fyFilter > 0) {
        $chartFy = (int)$fyFilter;
    } elseif ($availableFys) {
        $chartFy = max($availableFys);
    } else {
        $chartFy = (int)$activeFY;
    }
    $status = ['Submitted' => 0, 'Draft' => 0, 'Revision' => 0, 'Not started' => 0];
    $catData = [
      'Academic'       => ['submitted' => 0, 'total' => 0],
      'Administrative' => ['submitted' => 0, 'total' => 0],
    ];
    $academicCodesEarly = []; // Only the 11 Schools & Colleges are Academic
    foreach ($departments as $d) {
      $cat = ($d['dept_group'] === 'Schools & Colleges' && !in_array(strtoupper((string)$d['code']), ['LIB', 'REG', 'NSTP', 'SCA', 'IEAS', 'LAB'], true))
             ? 'Academic' : 'Administrative';
      $catData[$cat]['total']++;

      $deptInfo = $deptIndex[$d['code']];
      $ppmpForFy = null;
      foreach ($deptInfo['files'] as $f) {
        if ((int)$f['fy'] === $chartFy) { $ppmpForFy = $f; break; }
      }
      if ($ppmpForFy && !empty($ppmpForFy['revision']))    { $status['Revision']++; }
      elseif ($ppmpForFy && !empty($ppmpForFy['submitted'])) {
        $status['Submitted']++;
        $catData[$cat]['submitted']++;
      }
      elseif ($ppmpForFy)                                  { $status['Draft']++; }
      else                                                 { $status['Not started']++; }
    }
    $totalDept = array_sum($status) ?: 1;

    // Doughnut chart geometry
    $r = 62; $strokeW = 24;
    $circ = 2 * M_PI * $r;
    // v136: a colour of its own for each status — seen in light and dark
    $statusColors = [
      'Submitted'   => '#15803d',   // green
      'Draft'       => '#2a78d6',   // blue
      'Revision'    => '#f59e0b',   // amber (as "Sent back" on the other chart)
      'Not started' => '#94a3b8',   // gray
    ];
    $offset = 0;

    // ─── By fund source: Pool / Committed / Remaining ─────────
    // Fund panel uses the active FY from settings (where Budget sets pools),
    // NOT the chart's FY (which follows PPMP data — could be older than pools).
    $fundFy = (int)$activeFY;

    $byFundAdmin = fund_zeroes();
    try {
        // Committed = amounts on budget_approved and approved PPMPs
        $fs = db()->prepare("SELECT e.fund, COALESCE(SUM(e.amount),0) AS total
                               FROM ppmp_entries e JOIN ppmps p ON p.id = e.ppmp_id
                              WHERE p.fy = ?
                                AND p.status IN ('budget_approved','approved')
                           GROUP BY e.fund");
        $fs->execute([$fundFy]);
        foreach ($fs->fetchAll() as $__fr) {   // v135: not $r — $r is the size of the donut chart below
            if (isset($byFundAdmin[$__fr['fund']])) {
                $byFundAdmin[$__fr['fund']] = (float)$__fr['total'];
            }
        }
    } catch (Throwable $t) {}

    // Pool amounts (set by Budget Unit) + notes + who set them
    $fundPools = fund_zeroes();
    $fundNotes = array_fill_keys(fund_codes(), '');
    $fundSetBy = array_fill_keys(fund_codes(), '');
    try {
        $ps = db()->prepare("SELECT fund_source, total_amount, notes, set_by FROM fund_pools WHERE fy = ?");
        $ps->execute([$fundFy]);
        foreach ($ps->fetchAll() as $__pr) {   // v135: not $r (the donut chart's size)
            if (isset($fundPools[$__pr['fund_source']])) {
                $fundPools[$__pr['fund_source']] = (float)$__pr['total_amount'];
                $fundNotes[$__pr['fund_source']] = $__pr['notes'] ?? '';
                $fundSetBy[$__pr['fund_source']] = $__pr['set_by'] ?? '';
            }
        }
    } catch (Throwable $t) {}

    $totalPool = array_sum($fundPools);
    $totalCommitted = array_sum($byFundAdmin);

    $fundLabelsAdmin = FUND_SOURCES;
    ?>

    <?php if ($totalPool > 0 || $totalCommitted > 0):
      $totalRemaining = $totalPool - $totalCommitted;
    ?>
      <div class="section-head" style="margin-top: 24px;">
        <div>
          <h2 class="section-title"> Fund sources — FY <?= $fundFy ?></h2></div></div>

      <!-- Summary stats (mirrors Budget's Fund Sources page) -->
      <div class="stats" style="margin-bottom: 20px;">
        <div class="stat">
          <div class="stat-label">Total Pool</div>
          <div class="stat-value" style="color: var(--ink);">₱<?= number_format($totalPool, 0) ?></div>
          <div class="stat-meta">Across all funds · FY <?= $fundFy ?></div></div>
        <div class="stat">
          <div class="stat-label">Committed</div>
          <div class="stat-value" style="color: var(--orange-600);">₱<?= number_format($totalCommitted, 0) ?></div>
          <div class="stat-meta">From approved PPMPs</div></div>
        <div class="stat">
          <div class="stat-label">Remaining</div>
          <div class="stat-value" style="color: <?= $totalRemaining < 0 ? 'var(--orange-600)' : '#16a34a' ?>;">
            <?= $totalRemaining < 0 ? '−' : '' ?>₱<?= number_format(abs($totalRemaining), 0) ?></div>
          <div class="stat-meta"><?= $totalRemaining < 0 ? 'Over budget' : 'Available' ?></div></div>
        <div class="stat">
          <div class="stat-label">Fiscal Year</div>
          <div class="stat-value">FY <?= $fundFy ?></div>
          <div class="stat-meta">Active cycle</div></div></div>

      <?php
        // Fund comparison chart max
        $adChartMax = 0;
        foreach ($fundLabelsAdmin as $c => $_m) $adChartMax = max($adChartMax, $fundPools[$c] ?? 0, $byFundAdmin[$c] ?? 0);
        if ($adChartMax <= 0) $adChartMax = 1;
        $adMag = pow(10, floor(log10($adChartMax)));
        $adChartMax = ceil($adChartMax / $adMag) * $adMag;
      ?>
      <div class="card" style="margin-bottom: 20px; padding: 20px 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 12px;">
          <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 14px; color: var(--navy-800);">Fund comparison</div>
          <div style="display: flex; gap: 14px; font-size: 11px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 5px;">
              <div style="width: 11px; height: 11px; background: #232f72; border-radius: 2px;"></div>
              <span style="color: var(--muted);">Original</span></div>
            <div style="display: flex; align-items: center; gap: 5px;">
              <div style="width: 11px; height: 11px; background: var(--orange-50); border-radius: 2px;"></div>
              <span style="color: var(--muted);">Committed</span></div>
            <div style="display: flex; align-items: center; gap: 5px;">
              <div style="width: 11px; height: 11px; background: #16a34a; border-radius: 2px;"></div>
              <span style="color: var(--muted);">Remaining</span></div></div></div>

        <?php
          // Compact landscape chart — wide but short
          $chartH = 180; $chartW = 900;
          $padL = 55; $padR = 12; $padT = 12; $padB = 32;
          $plotW = $chartW - $padL - $padR;
          $plotH = $chartH - $padT - $padB;
          $groupCount = count($fundLabelsAdmin);
          $groupW = $plotW / $groupCount;
          $barW = 16; $gap = 5;
          $trioW = $barW * 3 + $gap * 2;
        ?>
        <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" preserveAspectRatio="xMidYMid meet" style="width: 100%; height: auto; max-height: 220px; font-family: 'Space Grotesk', sans-serif;">
          <?php for ($i = 0; $i <= 4; $i++):
            $y = $padT + $plotH - ($plotH * $i / 4);
            $val = $adChartMax * $i / 4;
          ?>
            <line x1="<?= $padL ?>" y1="<?= $y ?>" x2="<?= $chartW - $padR ?>" y2="<?= $y ?>" stroke="var(--navy-50)" stroke-width="1"/>
            <text x="<?= $padL - 6 ?>" y="<?= $y + 3 ?>" text-anchor="end" fill="#3b7597" font-size="9">₱<?= number_format($val / 1000, 0) ?>k</text>
          <?php endfor; ?>

          <?php $gi = 0; foreach ($fundLabelsAdmin as $code => $meta):
            $pool  = $fundPools[$code] ?? 0;
            $spent = $byFundAdmin[$code] ?? 0;
            $rem   = max(0, $pool - $spent);
            $gCx = $padL + $groupW * $gi + $groupW / 2;
            $tsX = $gCx - $trioW / 2;
            $bars = [
              ['x' => $tsX,                       'val' => $pool,  'color' => '#232f72'],
              ['x' => $tsX + $barW + $gap,        'val' => $spent, 'color' => 'var(--orange-50)'],
              ['x' => $tsX + ($barW + $gap) * 2,  'val' => $rem,   'color' => '#16a34a'],
            ];
            foreach ($bars as $b):
              $h = $adChartMax > 0 ? ($b['val'] / $adChartMax) * $plotH : 0;
              $y = $padT + $plotH - $h;
          ?>
            <rect x="<?= $b['x'] ?>" y="<?= $y ?>" width="<?= $barW ?>" height="<?= $h ?>" fill="<?= $b['color'] ?>" rx="2"/>
            <?php if ($b['val'] > 0 && $h > 22): ?>
              <text x="<?= $b['x'] + $barW / 2 ?>" y="<?= $y - 3 ?>" text-anchor="middle" fill="<?= $b['color'] ?>" font-weight="700" font-size="8">
                ₱<?= number_format($b['val'] / 1000, 0) ?>k
              </text>
            <?php endif; ?>
          <?php endforeach; ?>
            <text x="<?= $gCx ?>" y="<?= $padT + $plotH + 16 ?>" text-anchor="middle" fill="#121358" font-weight="700" font-size="10"><?= e($meta['label']) ?></text>
            <text x="<?= $gCx ?>" y="<?= $padT + $plotH + 26 ?>" text-anchor="middle" fill="#3b7597" font-size="8">
              <?= $pool <= 0 ? 'not set' : ($spent > $pool ? 'OVER' : number_format(($spent / $pool) * 100, 0) . '% used') ?></text>
          <?php $gi++; endforeach; ?>

          <line x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $padL ?>" y2="<?= $padT + $plotH ?>" stroke="var(--border)" stroke-width="1"/></svg></div>

      <!-- Per-fund cards (mirrors Budget's card layout, minus the edit form) -->
      <?php foreach ($fundLabelsAdmin as $code => $meta):
        $pool  = $fundPools[$code] ?? 0;
        $spent = $byFundAdmin[$code] ?? 0;
        $rem   = $pool - $spent;
        $pct   = $pool > 0 ? min(100, ($spent / $pool) * 100) : 0;
        $over  = ($pool > 0 && $spent > $pool);
        $barClr = $over ? 'var(--orange-500)' : ($pct >= 85 ? '#f59e0b' : '#16a34a');
        $setBy = $fundSetBy[$code] ?? '';
      ?>
        <div class="card" style="margin-bottom: 12px;">
          <div style="display: grid; grid-template-columns: 220px 1fr; gap: 24px; align-items: center;">
            <!-- Fund identity -->
            <div>
              <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 18px; color: var(--navy-800);"><?= e($meta['label']) ?></div>
              <div style="font-size: 12px; color: var(--muted); margin-top: 2px;"><?= e($meta['sub']) ?></div>
              <?php if ($setBy): ?>
                <div style="font-size: 10.5px; color: var(--muted); margin-top: 8px;">Set by <?= e($setBy) ?></div>
              <?php endif; ?></div>

            <!-- Original / Committed / Remaining side-by-side -->
            <div>
              <div class="pool-3" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 10px;">
                <div style="padding: 10px 12px; background: var(--navy-50); border-radius: 8px; border: 1px solid var(--navy-100);">
                  <div style="font-size: 10px; color: var(--ink); text-transform: uppercase; font-weight: 700; letter-spacing: 0.06em;">Original (Pool)</div>
                  <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; color: var(--navy-800); margin-top: 3px;">
                    <?= $pool > 0 ? '₱' . number_format($pool, 2) : '<span style="font-style:italic; font-weight:500; color:var(--muted); font-size:13px;">not set</span>' ?></div>
                  <div style="font-size: 10.5px; color: var(--muted); margin-top: 2px;">Never changes</div></div>
                <div style="padding: 10px 12px; background: var(--orange-50); border-radius: 8px; border: 1px solid var(--orange-100);">
                  <div style="font-size: 10px; color: var(--orange-600); text-transform: uppercase; font-weight: 700; letter-spacing: 0.06em;">Committed</div>
                  <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; color: var(--orange-600); margin-top: 3px;">− ₱<?= number_format($spent, 2) ?></div>
                  <div style="font-size: 10.5px; color: var(--muted); margin-top: 2px;">From approved PPMPs</div></div>
                <div style="padding: 10px 12px; background: <?= $over ? 'var(--orange-50)' : ($pool > 0 ? '#f0fdf4' : 'var(--navy-50)') ?>; border-radius: 8px; border: 1px solid <?= $over ? 'var(--orange-100)' : ($pool > 0 ? '#86efac' : 'var(--border)') ?>;">
                  <div style="font-size: 10px; color: <?= $over ? 'var(--orange-600)' : ($pool > 0 ? '#14532d' : 'var(--muted)') ?>; text-transform: uppercase; font-weight: 700; letter-spacing: 0.06em;">Remaining</div>
                  <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; color: <?= $over ? 'var(--orange-600)' : ($pool > 0 ? '#16a34a' : 'var(--muted)') ?>; margin-top: 3px;">
                    <?= $pool > 0 ? ($over ? '−' : '') . '₱' . number_format(abs($rem), 2) : '—' ?></div>
                  <div style="font-size: 10.5px; color: var(--muted); margin-top: 2px;"><?= $over ? 'Overspent' : ($pool > 0 ? 'Available' : 'Awaiting pool') ?></div></div></div>
              <div style="height: 8px; background: var(--navy-50); border-radius: 4px; overflow: hidden;">
                <div style="height: 100%; width: <?= $pct ?>%; background: <?= $barClr ?>; transition: width 0.3s;"></div></div>
              <?php if ($pool <= 0): ?>
                <div style="margin-top: 8px; font-size: 11.5px; color: var(--muted); font-style: italic;"> No pool amount set yet by the Budget Unit.
                </div>
              <?php elseif ($over): ?>
                <div style="margin-top: 8px; font-size: 11.5px; color: var(--orange-600); font-weight: 700;"> Committed exceeds the pool by ₱<?= number_format(abs($rem), 2) ?>.
                </div>
              <?php endif; ?></div></div></div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="section-head">
      <div>
        <h2 class="section-title">PPMP Overview</h2></div></div>

    <div class="chart-grid">
      <div class="card chart-card">
        <div class="chart-title">Submission status</div>
        <div class="chart-donut-wrap">
          <svg viewBox="0 0 160 160" class="chart-donut">
            <circle cx="80" cy="80" r="<?= $r ?>" fill="none" stroke="var(--navy-50)" stroke-width="<?= $strokeW ?>"/>
            <?php foreach ($status as $label => $count):
              if ($count === 0) continue;
              $frac = $count / $totalDept;
              $len  = $frac * $circ;
              $dash = "$len " . ($circ - $len);
              $rot  = -90 + ($offset / $circ) * 360;
              $offset += $len;
            ?>
              <circle cx="80" cy="80" r="<?= $r ?>" fill="none"
                      stroke="<?= $statusColors[$label] ?>"
                      stroke-width="<?= $strokeW ?>"
                      stroke-dasharray="<?= $dash ?>"
                      transform="rotate(<?= $rot ?> 80 80)"/>
            <?php endforeach; ?>
            <text x="80" y="76" text-anchor="middle" class="donut-center-num"><?= $totalDept ?></text>
            <text x="80" y="94" text-anchor="middle" class="donut-center-label">departments</text></svg></div>
        <div class="chart-legend">
          <?php foreach ($status as $label => $count): ?>
            <div class="legend-item">
              <span class="legend-dot" style="background: <?= $statusColors[$label] ?>;"></span>
              <span class="legend-label"><?= e($label) ?></span>
              <span class="legend-value"><?= $count ?> · <?= $totalDept > 0 ? round($count * 100 / $totalDept) : 0 ?>%</span></div>
          <?php endforeach; ?></div></div>

      <div class="card chart-card">
        <div class="chart-title">Submitted by category</div>
        <div class="chart-bars">
          <?php foreach ($catData as $catName => $vals):
            $pct = $vals['total'] > 0 ? round($vals['submitted'] * 100 / $vals['total']) : 0;
            $barColor = $catName === 'Academic' ? 'var(--navy-700)' : 'var(--orange-500)';
          ?>
            <div class="bar-row">
              <div class="bar-label">
                <span class="folder-group-badge"
                      style="background: <?= $catName === 'Academic' ? 'var(--navy-100)' : 'var(--orange-100)' ?>;
                             color: <?= $catName === 'Academic' ? 'var(--navy-700)' : 'var(--orange-600)' ?>;">
                  <?= e($catName) ?></span>
                <span class="bar-value"><?= $vals['submitted'] ?> / <?= $vals['total'] ?></span></div>
              <div class="bar-track">
                <div class="bar-fill" style="width: <?= $pct ?>%; background: <?= $barColor ?>;"></div></div>
              <div class="bar-pct" style="color: <?= $catName === 'Academic' ? 'var(--navy-700)' : 'var(--orange-600)' ?>;">
                <?= $pct ?>%
              </div></div>
          <?php endforeach; ?></div>
        <div class="chart-note"> Percentages show departments that have submitted a PPMP for FY <?= $chartFy ?>.
        </div></div></div>

<?php else:
  // Dashboard has no per-department detail view — send to the Folders page.
  header('Location: admin-dashboard.php?dept=' . urlencode($deptOpen)); exit;
endif; ?></main></div></body></html>
