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
$search        = preg_replace('/[0-9]/', '', $search); // search is characters only — no numbers

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

// Load all departments (excluding the Budget Unit itself — it's a home dept for
// the budget account, not a real PPMP-submitting unit; see budget-allocations.php)
$stmt = db()->query("SELECT * FROM departments ORDER BY display_order");
$departments = $stmt->fetchAll();
require_once __DIR__ . '/config/academic-units.php'; $departments = pe_folder_listable($departments);   // v104: a school is one folder — its programs and majors are inside it (was v81: the units, not the school)
if (($__od = trim((string)($_GET['dept'] ?? ''))) !== '' && au_is_unit($__od)) { header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?dept=' . urlencode(au_school_of($__od))); exit; }   // v104: a program / major opens its school's folder

$departments = au_active_rows($departments);   // v92: a deactivated department is not shown
$departments = os_without_sets($departments);   // v90: an office's sets are inside the office's one folder

// Load all PPMPs
$stmt = db()->query('SELECT id, dept_code, fy, updated_at, submitted_at, submitted_by, status,
                                   budget_reviewed_at, budget_reviewed_by
                              FROM ppmps ORDER BY dept_code, fy DESC');
$allPPMPs = $stmt->fetchAll();

// Load entry counts and totals per PPMP
$stmt = db()->query('SELECT ppmp_id, COUNT(*) AS cnt, SUM(amount) AS total FROM ppmp_entries GROUP BY ppmp_id');
$stats = [];
foreach ($stmt->fetchAll() as $r) $stats[$r['ppmp_id']] = $r;

// Load pending revision requests (only unresolved) → { dept_code => [fy => request] }
$stmt = db()->query('SELECT * FROM revision_requests WHERE resolved = 0');
$revByDept = [];
foreach ($stmt->fetchAll() as $r) { $revByDept[$r['dept_code']][$r['fy']] = $r; }

// Which PPMPs are already in the archive — these must NOT appear in the folders
$archivedIds = [];
try {
    foreach (db()->query('SELECT ppmp_id, id FROM archived_ppmps')->fetchAll() as $a) {
        $archivedIds[(int)$a['ppmp_id']] = (int)$a['id'];
    }
} catch (Throwable $t) {}

// Assemble department index (archived PPMPs are skipped — they live in the Archive)
$deptIndex = [];
foreach ($departments as $d) {
    // lastSubmitted / submittedCount drive the "submitted units first" ordering
    // of the folder grid further down this page.
    $deptIndex[$d['code']] = $d + [
        'files'          => [],
        'hasRevision'    => false,
        'lastSubmitted'  => null,
        'submittedCount' => 0,
        'withPuSince'    => null,   // newest PPMP of theirs now waiting on the PU
        'approvedCount'  => 0,      // counted: given final approval by the Super Admin
    ];
}
foreach ($allPPMPs as $p) {
    $__k = pe_folder_of((string)$p['dept_code']);   // v90: a set's PPMP is in its office's folder — v104: a program's / major's in its school's
    if (!isset($deptIndex[$__k])) continue;
    if (isset($archivedIds[(int)$p['id']])) continue;   // archived → hidden here
    // A past year's PPMP restored from the Archive: in its own year folder, view only (config/past-years.php)
    if (fy_is_past((int)$p['fy'])) continue;
    $revNote = $revByDept[$p['dept_code']][$p['fy']] ?? null;
    // Drafts belonging to the Budget Unit must never appear in the Procurement Unit folders.
    if (($p['dept_code'] ?? '') === 'BUDGET-OFC' && ($p['status'] ?? '') === 'draft') continue;
    // A department's draft is not in the folders — only once it is sent (or sent back for changes)
    if (($p['status'] ?? '') === 'draft' && !$revNote) continue;
    // v90: and only once it is with the Procurement Unit — not while the department or the Budget Unit has it
    if (!ppmp_visible_to($p, $u)) continue;
    if ($revNote) $deptIndex[$__k]['hasRevision'] = true;
    // A PPMP counts as "passed" once the department has sent it in — that is
    // true for submitted, budget-approved and fully approved PPMPs alike,
    // since all of them carry a submitted_at stamp.
    if (!empty($p['submitted_at'])) {
        $deptIndex[$__k]['submittedCount']++;
        $prev = $deptIndex[$__k]['lastSubmitted'];
        if ($prev === null || strtotime($p['submitted_at']) > strtotime($prev)) {
            $deptIndex[$__k]['lastSubmitted'] = $p['submitted_at'];
        }
    }
    $__pu = ppmp_with_pu_since($p);
    if ($__pu !== null) {
        $__prevPu = $deptIndex[$__k]['withPuSince'];
        if ($__prevPu === null || strcmp($__pu, $__prevPu) > 0) $deptIndex[$__k]['withPuSince'] = $__pu;
    }
    $deptIndex[$__k]['files'][] = [
        'status'     => $p['status'],
        'with_pu'    => $__pu,                        // when it reached the PU, or null
        'budget_by'  => $p['budget_reviewed_by'] ?? '',
        'id'         => $p['id'],
        'fy'         => $p['fy'],
        'submitted'  => $p['submitted_at'],
        'submitted_by' => $p['submitted_by'],
        'entries'    => (int)($stats[$p['id']]['cnt'] ?? 0),
        'amount'     => (float)($stats[$p['id']]['total'] ?? 0),
        'revision'   => $revNote,
        'dept_code'  => (string)$p['dept_code'],                 // v90: the set's own code (PU-S2), for its links
        'set'        => pe_folder_label((string)$p['dept_code']),   // "Set 2" / v104: "BSED – Major in English" — '' otherwise
    ];
    if (($p['status'] ?? '') === 'approved') $deptIndex[$__k]['approvedCount']++;
}

// Restored PPMPs of past years, by department and year
require_once __DIR__ . '/config/past-years.php';
$pastByDept = past_year_docs('ppmp');
$yearOpen = (int)($_GET['year'] ?? 0);
$restoredOpen = !empty($_GET['restored']);   // v111: the "Restored" folder inside a department's PPMP folder

// Totals for stats strip
$totalFiles = count($allPPMPs);
$totalDepts = count($departments);
$totalRevisions = 0;
foreach ($revByDept as $byFY) $totalRevisions += count($byFY);

// Approved (active) user accounts
$approvedUsers = 0;
try {
    $approvedUsers = (int)db()->query("SELECT COUNT(*) FROM users WHERE status = 'active' AND role IN ('user','dept_admin','budget')")->fetchColumn();
} catch (Throwable $t) {}

// Consolidated total for the active FY
$consolFy = (int)$activeFY;
$stmt = db()->prepare('SELECT COALESCE(SUM(e.amount), 0) AS total
                         FROM ppmp_entries e
                         JOIN ppmps p ON p.id = e.ppmp_id
                        WHERE p.fy = ?
                          AND p.status IN (\'submitted\',\'budget_approved\',\'approved\')');
$stmt->execute([$consolFy]);
$consolidated = (float)$stmt->fetch()['total'];
$stmt = db()->prepare('SELECT COUNT(DISTINCT dept_code) AS n FROM ppmps WHERE fy = ?');
$stmt->execute([$consolFy]);
$submittedCurrentFy = (int)$stmt->fetch()['n'];

$activeNav = 'ppmps';
$pageTitle = 'PPMP Folders';
require __DIR__ . '/includes/head.php';
?>

<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>

  <main class="main">

<?php
  /* PPMP Folders opens on two folders, Administrative and Academic. A search,
     the revision filter, or a group opens the department folders. */
  $__landing = ($deptOpen === null && $groupFilter === 'all' && $search === '' && $statusFilter === 'all');
?>
<style>
  .group-folders { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-top: 6px; }
  @media (max-width: 820px) { .group-folders { grid-template-columns: minmax(0, 1fr); } }
  .group-folder { display: flex; gap: 18px; align-items: flex-start; min-width: 0; padding: 24px 24px 22px;
    background: var(--white); border: 1px solid var(--border); border-left: 5px solid var(--navy-500);
    border-radius: 14px; text-decoration: none; color: var(--ink); transition: border-color .15s, box-shadow .15s; }
  .group-folder:hover { border-color: var(--navy-500); box-shadow: 0 8px 22px rgba(19, 52, 88, .10); }
  .group-folder.g-admin { border-left-color: var(--orange-500); }
  .group-folder .gf-icon { width: 58px; height: 58px; flex-shrink: 0; border-radius: 14px; display: grid; place-items: center;
    background: var(--navy-50); color: var(--ink); }
  .group-folder .gf-body { min-width: 0; flex: 1 1 auto; }
  .group-folder .gf-name { font-family: 'Space Grotesk', sans-serif; font-size: 19px; font-weight: 700; color: var(--ink); }
  .group-folder .gf-sub { font-size: 12.5px; color: var(--muted); margin-top: 3px; }
  .group-folder .gf-stats { font-size: 12.5px; color: var(--ink); margin-top: 12px; display: flex; flex-wrap: wrap; gap: 4px 12px; }
  .group-folder .gf-wait { display: inline-block; margin-top: 12px; padding: 5px 11px; border-radius: 999px; font-size: 12px; font-weight: 700;
    background: var(--navy-100); color: var(--ink); border: 1px solid var(--navy-500); }
  .group-folder .gf-latest { font-size: 12px; color: var(--muted); margin-top: 6px; overflow-wrap: anywhere; }

  .pu-queue { background: var(--white); border: 1px solid var(--navy-500); border-radius: 14px; padding: 14px 16px 6px; margin: 4px 0 18px; }
  .pu-queue-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-family: 'Space Grotesk', sans-serif;
    font-size: 13.5px; font-weight: 700; color: var(--ink); margin-bottom: 8px; }
  .pu-queue-head .n { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--navy-100); color: var(--ink); }
  .pu-q-row { display: flex; align-items: center; gap: 10px 14px; flex-wrap: wrap; padding: 10px 0; border-top: 1px solid var(--border); }
  .pu-q-info { flex: 1 1 280px; min-width: 0; }
  .pu-q-title { font-weight: 700; font-size: 13.5px; color: var(--ink); overflow-wrap: anywhere; }
  .pu-q-meta { font-size: 12px; color: var(--muted); margin-top: 2px; overflow-wrap: anywhere; }
  .pu-q-actions, .file-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .pu-q-actions .btn, .file-actions .btn { padding: 7px 13px; font-size: 12.5px; text-decoration: none; }
  .file-amount { color: var(--navy-700); }
  body:not([data-theme="dark"]) .file-amount { color: #2f578a; }   /* 3.86:1 -> 7.0:1 on this page's palette */
</style>

<?php if ($__landing):
  // Departments, files on hand, and what is waiting on the PU, per group
  $__g = [];
  foreach (['Administrative', 'Academic'] as $__gn) $__g[$__gn] = ['depts' => 0, 'onFile' => 0, 'waiting' => 0, 'latest' => null];
  foreach ($departments as $__d) {
      $__gn = dept_group_folder($__d['code'], $__d['dept_group'] ?? null);
      $__x  = $deptIndex[$__d['code']];
      $__g[$__gn]['depts']++;
      if ($__x['approvedCount'] > 0) $__g[$__gn]['onFile']++;
      foreach ($__x['files'] as $__f) {
          if ($__f['with_pu'] === null) continue;
          $__g[$__gn]['waiting']++;
          if ($__g[$__gn]['latest'] === null || strcmp($__f['with_pu'], $__g[$__gn]['latest']['at']) > 0) {
              $__g[$__gn]['latest'] = ['at' => $__f['with_pu'], 'code' => $__d['code']];
          }
      }
  }
  $__gm = [
      'Administrative' => ['cls' => 'g-admin', 'icon' => '🏢', 'sub' => 'Executive, finance, operations, and special projects'],
      'Academic'       => ['cls' => 'g-acad',  'icon' => '🎓', 'sub' => 'Teaching, learning, research, and student affairs'],
  ];
?>
    <div class="page-head">
      <div>
        <h1 class="page-title">PPMP Folders · FY <?= $consolFy ?></h1>
        <p class="page-sub">Two folders — Administrative and Academic. Open one to see its departments.</p></div></div>

    <?php render_flashes(); ?>

<?php
$__budgetCats = ['travel'=>'Travel','training'=>'Training','supply'=>'Supply','equipment'=>'Equipment'];
$__budgetColors = ['travel'=>'#0ea5e9','training'=>'#8b5cf6','supply'=>'#f59e0b','equipment'=>'#10b981'];
$__overallAllocation = 0.0; $__overallPlanned = 0.0;
try {
  $__q = db()->prepare('SELECT dept_code, alloted_budget FROM dept_budgets WHERE fy=?');
  $__q->execute([active_fy()]);
  foreach ($__q->fetchAll() as $__allocRow) {
    if (!au_is_unit((string)$__allocRow['dept_code']) && !os_is_set((string)$__allocRow['dept_code'])) $__overallAllocation += (float)$__allocRow['alloted_budget'];
  }
  $__q = db()->prepare('SELECT COALESCE(SUM(e.amount),0) FROM ppmp_entries e JOIN ppmps p ON p.id=e.ppmp_id WHERE p.fy=?');
  $__q->execute([active_fy()]); $__overallPlanned = (float)$__q->fetchColumn();
} catch (Throwable $__t) {}
$__overallRemaining = $__overallAllocation - $__overallPlanned;
$__budgetTotals = array_fill_keys(array_keys($__budgetCats), 0.0);
$__spentTotals = array_fill_keys(array_keys($__budgetCats), 0.0);
try {
  $__fy = active_fy();
  $__q = db()->prepare('SELECT SUM(travel_budget) travel, SUM(training_budget) training, SUM(supply_budget) supply, SUM(equipment_budget) equipment FROM dept_budgets WHERE fy=?');
  $__q->execute([$__fy]); $__rr = $__q->fetch() ?: [];
  foreach ($__budgetCats as $__k=>$__v) $__budgetTotals[$__k] = (float)($__rr[$__k] ?? 0);
  $__q = db()->prepare("SELECT e.budget_category, SUM(e.amount) spent FROM ppmp_entries e JOIN ppmps p ON p.id=e.ppmp_id WHERE p.fy=? GROUP BY e.budget_category");
  $__q->execute([$__fy]); foreach ($__q->fetchAll() as $__rr) if(isset($__spentTotals[$__rr['budget_category']])) $__spentTotals[$__rr['budget_category']] = (float)$__rr['spent'];
} catch (Throwable $__t) {}
$__sumBudget = array_sum($__budgetTotals); $__segments=[]; $__offset=0;
foreach ($__budgetTotals as $__k=>$__v) { $__share=$__sumBudget>0 ? $__v/$__sumBudget*100 : 0; if($__share>0){$__segments[]=$__budgetColors[$__k].' '.$__offset.'% '.($__offset+$__share).'%';$__offset += $__share;} }
$__donut = $__segments ? 'conic-gradient('.implode(',', $__segments).')' : 'conic-gradient(#e5e7eb 0% 100%)';
?>
<div class="card" style="padding:14px 18px;margin:0 0 12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px">
  <div><div style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase">Total Allocation · FY <?= (int)active_fy() ?></div><div style="font-size:22px;font-weight:800">₱<?= number_format($__overallAllocation,2) ?></div></div>
  <div><div style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase">Planned PPMP Total</div><div style="font-size:22px;font-weight:800">₱<?= number_format($__overallPlanned,2) ?></div></div>
  <div><div style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase">Remaining Allocation</div><div style="font-size:22px;font-weight:800;color:<?= $__overallRemaining < 0 ? '#dc2626' : '#15803d' ?>">₱<?= number_format($__overallRemaining,2) ?></div></div>
</div>
<div class="card" style="padding:16px;margin:0 0 18px;display:flex;gap:18px;align-items:center;flex-wrap:wrap">
  <div style="font-weight:800;min-width:140px">FY <?= (int)active_fy() ?> Category Guide</div>
  <div style="width:96px;height:96px;border-radius:50%;background:<?= e($__donut) ?>;display:grid;place-items:center;flex:0 0 96px"><div style="width:58px;height:58px;border-radius:50%;background:var(--card,#fff);display:grid;place-items:center;text-align:center;font-size:10px;font-weight:800">₱<?= number_format($__sumBudget,0) ?><span style="font-size:8px">Guide</span></div></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;flex:1;min-width:220px">
  <?php foreach ($__budgetCats as $__k=>$__label): ?><div style="border-left:3px solid <?= $__budgetColors[$__k] ?>;padding:3px 8px;font-size:11px"><strong><?= e($__label) ?></strong><div>Allocated ₱<?= number_format($__budgetTotals[$__k],2) ?></div><div style="color:var(--muted)">Planned ₱<?= number_format($__spentTotals[$__k],2) ?></div><div style="font-weight:700">Remaining ₱<?= number_format($__budgetTotals[$__k]-$__spentTotals[$__k],2) ?></div></div><?php endforeach; ?>
  </div>
</div>


    <form method="GET" class="filter-row" role="search" style="align-items: center;">
      <?= search_field(['name' => 'q', 'value' => '', 'placeholder' => 'Search all departments…',
                        'label' => 'Search all departments', 'attrs' => SEARCH_NO_DIGITS]) ?>
    </form>

    <div class="group-folders">
      <?php foreach ($__g as $__gn => $__s): $__m = $__gm[$__gn]; ?>
        <a href="?group=<?= urlencode($__gn) ?>" class="group-folder <?= $__m['cls'] ?>">
          <div class="gf-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
          <div class="gf-body">
            <div class="gf-name"><?= $__m['icon'] ?> <?= e($__gn) ?></div>
            <div class="gf-sub"><?= e($__m['sub']) ?></div>
            <div class="gf-stats">
              <span><?= $__s['depts'] ?> department<?= $__s['depts'] === 1 ? '' : 's' ?></span>
              <span><?= $__s['onFile'] ?> with an approved PPMP</span></div>
            <?php if ($__s['waiting']): ?>
              <div class="gf-wait"><?= $__s['waiting'] ?> approved by Budget · waiting for your final approval</div>
              <div class="gf-latest">Latest: <?= e($__s['latest']['code']) ?> · <?= e(admin_rel_time($__s['latest']['at'])) ?></div>
            <?php endif; ?>
            </div></a>
      <?php endforeach; ?></div>

<?php elseif ($deptOpen === null): ?>
    <!-- ── FOLDER GRID VIEW ─── -->
    <?php if ($groupFilter === 'all'): /* inside Administrative or Academic the breadcrumb and heading say where you are */ ?>
    <div class="page-head">
      <div>
        <h1 class="page-title">PPMP Folders · FY <?= $consolFy ?></h1>
        <p class="page-sub">Every department has its own folder. PPMP files submitted by end-users are stored here.</p></div></div>
    <?php endif; ?>

    <?php render_flashes(); ?>

    <div class="breadcrumb">
      <a href="admin-dashboard.php">PPMP Folders</a>
      <span>›</span>
      <span><?= $groupFilter !== 'all' ? e($groupFilter) : 'Search results' ?></span></div>

    <div class="section-head">
      <div>
        <h2 class="section-title"><?= $groupFilter !== 'all' ? e($groupFilter) . ' folders' : 'Department folders' ?></h2></div></div>

    <?php
      // Compute counts per group for the tab buttons
      $countByGroup = ['Academic' => 0, 'Administrative' => 0];
      foreach ($departments as $d) {
          $cat = ($d['code'] !== 'REG' && ($d['dept_group'] === 'Schools & Colleges' || in_array($d['code'], [], true)))
                 ? 'Academic' : 'Administrative';
          $countByGroup[$cat]++;
      }
      $totalDepts = count($departments);
      $qbase = fn($g) => '?' . http_build_query(array_filter([
          'q'      => $search ?: null,
          'fy'     => $fyFilter !== 'all' ? $fyFilter : null,
          'status' => $statusFilter !== 'all' ? $statusFilter : null,
          'group'  => $g !== 'all' ? $g : null,
      ]));
    ?>
    <div style="display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap;">
      <a href="admin-dashboard.php" style="text-decoration: none; padding: 10px 18px; border-radius: 10px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; border: 1.5px solid var(--border); background: var(--white); color: var(--ink); display: flex; align-items: center; gap: 8px;">← Back</a>
      <?php /* Inside a folder, only that folder's own button: no Academic filter
               in Administrative, no Administrative filter in Academic. */ ?>
      <?php if ($groupFilter !== 'Administrative'): ?>
      <a href="<?= $qbase('Academic') ?>" style="text-decoration: none; padding: 10px 18px; border-radius: 10px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; border: 1.5px solid <?= $groupFilter === 'Academic' ? 'var(--navy-700)' : 'var(--navy-100)' ?>; background: <?= $groupFilter === 'Academic' ? 'var(--accent)' : 'var(--white)' ?>; color: <?= $groupFilter === 'Academic' ? 'var(--accent-ink)' : 'var(--ink)' ?>; display: flex; align-items: center; gap: 8px;">🎓 Academic
        <span style="font-weight: 600; opacity: 0.7; font-size: 12px;"><?= $countByGroup['Academic'] ?></span></a>
      <?php endif; ?>
      <?php if ($groupFilter !== 'Academic'): ?>
      <a href="<?= $qbase('Administrative') ?>" style="text-decoration: none; padding: 10px 18px; border-radius: 10px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; border: 1.5px solid <?= $groupFilter === 'Administrative' ? 'var(--orange-600)' : 'var(--orange-100)' ?>; background: <?= $groupFilter === 'Administrative' ? 'var(--accent)' : 'var(--white)' ?>; color: <?= $groupFilter === 'Administrative' ? 'var(--accent-ink)' : 'var(--ink)' ?>; display: flex; align-items: center; gap: 8px;">🏢 Administrative
        <span style="font-weight: 600; opacity: 0.7; font-size: 12px;"><?= $countByGroup['Administrative'] ?></span></a>
      <?php endif; ?></div>

    <form method="GET" class="filter-row" role="search" style="align-items: center;">
      <?= search_field(['name' => 'q', 'value' => $search, 'placeholder' => 'Search department…',
                        'label' => 'Search departments', 'attrs' => SEARCH_NO_DIGITS]) ?>
      <?php if ($groupFilter !== 'all'): ?>
        <input type="hidden" name="group" value="<?= e($groupFilter) ?>">
      <?php endif; ?>
      <div class="chips">
        <a href="?<?= http_build_query(array_filter(['q' => $search ?: null, 'fy' => $fyFilter !== 'all' ? $fyFilter : null, 'group' => $groupFilter !== 'all' ? $groupFilter : null])) ?>" class="chip <?= $statusFilter === 'all' ? 'active' : '' ?>">All folders</a>
        <a href="?<?= http_build_query(array_filter(['group' => $groupFilter !== 'all' ? $groupFilter : null, 'status' => 'revision'])) ?>" class="chip revision <?= $statusFilter === 'revision' ? 'active' : '' ?>">With revisions</a></div></form>

<?php
// Map each department to a MAIN category + SUB category
// Main: Academic / Administrative
// Sub:  Schools & Colleges / Academic Support / Executive Offices / Support Units / Special Projects
$academicSupportCodes = [];

function dept_category(array $d, array $academicSupport = []): string {
    if (in_array(strtoupper((string)$d['code']), ['LIB', 'REG', 'NSTP', 'SCA', 'IEAS', 'LAB'], true)) return 'Administrative';
    if ($d['dept_group'] === 'Schools & Colleges') return 'Academic';
    if (in_array($d['code'], $academicSupport, true)) return 'Academic';
    return 'Administrative';
}

function dept_subcategory(array $d, array $academicSupport = []): string {
    if (in_array(strtoupper((string)$d['code']), ['LIB', 'REG', 'NSTP', 'SCA', 'IEAS', 'LAB'], true)) {
        if (($d['dept_group'] ?? '') === 'Special Programs') return 'Special Projects';
        if (($d['dept_group'] ?? '') === 'Executive Offices') return 'Executive Offices';
        return 'Support Units';
    }
    if ($d['dept_group'] === 'Schools & Colleges') return 'Schools & Colleges';
    if (in_array($d['code'], $academicSupport, true)) return 'Academic Support';
    if ($d['dept_group'] === 'Executive Offices')   return 'Executive Offices';
    if ($d['dept_group'] === 'Special Programs')    return 'Special Projects';
    return 'Support Units';
}

// Preserve legacy variable name for anything already referencing it
$academicCodes = $academicSupportCodes;

$groups = ['Academic', 'Administrative'];
$groupMeta = [
    'Academic'       => ['color' => 'navy',   'icon' => 'M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20', 'sub' => 'Teaching, learning, research, and student affairs'],
    'Administrative' => ['color' => 'orange', 'icon' => 'M3 21h18M5 21V7l7-4 7 4v14M9 9h1M9 12h1M9 15h1M14 9h1M14 12h1M14 15h1', 'sub' => 'Executive, finance, operations, and special projects'],
];

// Sub-category display order and icons
$subOrder = [
    'Academic'       => ['Schools & Colleges', 'Academic Support'],
    'Administrative' => ['Executive Offices', 'Support Units', 'Special Projects'],
];
$subMeta = [
    'Schools & Colleges' => ['icon' => 'M22 10v6M2 10l10-5 10 5-10 5z', 'hint' => 'Degree-granting schools and colleges'],
    'Academic Support'   => ['icon' => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20',  'hint' => 'Library, registrar, laboratories, and student services'],
    'Executive Offices'  => ['icon' => 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', 'hint' => 'Office of the President and Vice Presidents'],
    'Support Units'      => ['icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-14L4 7m8 4v10M4 7v10l8 4', 'hint' => 'Finance, HR, IT, and operations'],
    'Special Projects'   => ['icon' => 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5', 'hint' => 'Extension, R&D, gender & development, DRRM, and other special programs'],
];

$searchLower = mb_strtolower($search);
foreach ($groups as $groupName):
  // Skip if group filter is active and this isn't the selected group
  if ($groupFilter !== 'all' && $groupFilter !== $groupName) continue;
  $groupDepts = array_filter($departments, fn($d) => dept_category($d, $academicSupportCodes) === $groupName);
  $groupDepts = array_values($groupDepts);
  // Apply filters
  $groupDepts = array_filter($groupDepts, function($d) use ($deptIndex, $searchLower, $fyFilter, $statusFilter) {
      $dept = $deptIndex[$d['code']];
      if ($statusFilter === 'revision' && !$dept['hasRevision']) return false;
      if ($searchLower !== '') {
          $match = strpos(mb_strtolower($d['name']), $searchLower) !== false
                || strpos(mb_strtolower($d['code']), $searchLower) !== false;
          if (!$match) return false;
      }
      if ($fyFilter !== 'all') {
          $has = false;
          foreach ($dept['files'] as $f) if ((string)$f['fy'] === $fyFilter) { $has = true; break; }
          if (!$has && count($dept['files']) > 0) return false;
      }
      return true;
  });
  if (!$groupDepts) continue;
  $meta = $groupMeta[$groupName];
  $cls  = 'cat-' . $meta['color'];

  // Group by sub-category
  $bySub = [];
  foreach ($groupDepts as $d) {
      $sub = dept_subcategory($d, $academicSupportCodes);
      $bySub[$sub][] = $d;
  }
?>
    <div class="folder-group-header <?= $cls ?>">
      <div class="folder-group-badge">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $meta['icon'] ?>"/></svg>
        <?= e($groupName) ?></div></div>

    <?php
      /* What the Budget Unit approved last comes first: every PPMP in this
         group now waiting on the Procurement Unit, newest first. */
      $__queue = [];
      foreach ($groupDepts as $__d) {
          foreach ($deptIndex[$__d['code']]['files'] as $__f) {
              if ($__f['with_pu'] === null) continue;
              if ($fyFilter !== 'all' && (string)$__f['fy'] !== $fyFilter) continue;
              // v104: 'code' is the PPMP's own (a program's / major's, or a set's) — its Edit and APP-CSE; 'folder' is where it is
              $__queue[] = $__f + ['code' => (string)($__f['dept_code'] ?: $__d['code']), 'folder' => $__d['code'], 'name' => $__d['name']];
          }
      }
      usort($__queue, fn($a, $b) => strcmp($b['with_pu'], $a['with_pu']));
    ?>
    <?php if ($__queue): ?>
      <?= sort_bar('puQueue') ?>
      <div class="pu-queue" id="puQueue">
        <div class="pu-queue-head">Approved by the Budget Unit · waiting for your final approval
          <span class="n"><?= count($__queue) ?></span></div>
        <?php foreach ($__queue as $__q): ?>
          <div class="pu-q-row" data-ts="<?= sort_ts($__q['with_pu']) ?>">
            <div class="pu-q-info">
              <div class="pu-q-title"><?= e($__q['folder']) ?> · <?= e($__q['name']) ?></div>
              <div class="pu-q-meta">
                FY <?= (int)$__q['fy'] ?> PPMP<?= !empty($__q['set']) ? ' · ' . e($__q['set']) : '' ?> ·
                <?php if ($__q['status'] === 'budget_approved'): ?>
                  approved by the Budget Unit <?= e(admin_rel_time($__q['with_pu'])) ?><?= $__q['budget_by'] !== '' ? ' by ' . e($__q['budget_by']) : '' ?>
                <?php else: ?>
                  submitted <?= e(admin_rel_time($__q['with_pu'])) ?> · the Budget Unit's own PPMP comes straight to you
                <?php endif; ?>
                · <?= (int)$__q['entries'] ?> entr<?= (int)$__q['entries'] === 1 ? 'y' : 'ies' ?> · ₱<?= number_format($__q['amount'], 2) ?></div></div>
            <div class="pu-q-actions">
              <a href="ppmp-file-view.php?id=<?= (int)$__q['id'] ?>" class="btn btn-primary">Open</a>
              <?php require_once __DIR__ . '/config/edit-permission.php';
                    $__qe = pu_edit_state(['id' => (int)$__q['id'], 'dept_code' => $__q['code'], 'status' => $__q['status']], $u)['state']; ?>
              <?php if ($__qe === 'own' || $__qe === 'granted'): ?>
                <a href="ppmp-edit.php?id=<?= (int)$__q['id'] ?>" class="btn btn-ghost">✎ Edit</a>
              <?php elseif ($__qe === 'pending'): ?>
                <span class="btn btn-ghost" aria-disabled="true" title="Waiting for <?= e($__q['code']) ?> to answer" style="cursor:default; opacity:.8;">Edit requested</span>
              <?php else: ?>
                <a href="ppmp-file-view.php?id=<?= (int)$__q['id'] ?>&amp;request_edit=1" class="btn btn-ghost">✎ Request edit</a>
              <?php endif; ?>
              <a href="appcse-print.php?fy=<?= (int)$__q['fy'] ?>&amp;dept=<?= urlencode($__q['code']) ?>" target="_blank" class="btn btn-ghost">APP-CSE</a></div></div>
        <?php endforeach; ?></div>
    <?php endif; ?>

    <?= sort_bar('.dept-grid', 'Waiting on you first') ?>
    <?php foreach ($subOrder[$groupName] as $subName):
      if (empty($bySub[$subName])) continue;
      $subDepts = $bySub[$subName];

      /* Units that have passed a PPMP sit at the top of the folder grid, with
         the most recent submission first, so the Procurement Unit sees work
         that is waiting on them before units with nothing on file. Units that
         have not submitted keep their normal display order underneath. */
      usort($subDepts, function ($a, $b) use ($deptIndex) {
          $A = $deptIndex[$a['code']];
          $B = $deptIndex[$b['code']];
          // First: a PPMP the Budget Unit has approved, newest first
          $aPu = $A['withPuSince'] !== null;
          $bPu = $B['withPuSince'] !== null;
          if ($aPu !== $bPu) return $aPu ? -1 : 1;
          if ($aPu && $bPu) {
              $byPu = strcmp($B['withPuSince'], $A['withPuSince']);
              if ($byPu !== 0) return $byPu;
          }
          $aHas = $A['lastSubmitted'] !== null;
          $bHas = $B['lastSubmitted'] !== null;
          if ($aHas !== $bHas) return $aHas ? -1 : 1;
          if ($aHas && $bHas) {
              $byDate = strtotime($B['lastSubmitted']) <=> strtotime($A['lastSubmitted']);
              if ($byDate !== 0) return $byDate;
          }
          return (int)$a['display_order'] <=> (int)$b['display_order'];
      });

      $sm = $subMeta[$subName] ?? ['icon' => '', 'hint' => ''];
    ?>
      <div class="folder-subgroup-header <?= $cls ?>">
        <div class="folder-subgroup-badge">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $sm['icon'] ?>"/></svg>
          <?= e($subName) ?>
          <span class="folder-subgroup-count"><?= count($subDepts) ?></span></div>
        <div class="folder-subgroup-hint"><?= e($sm['hint']) ?></div></div>
      <div class="folder-grid dept-grid">
      <?php foreach ($subDepts as $d):
        $dept = $deptIndex[$d['code']];
        $fileCount = $dept['approvedCount'];   // counted once approved by the Super Admin
        $__appr = array_filter($dept['files'], fn($f) => ($f['status'] ?? '') === 'approved');
        $latestFY = $__appr ? max(array_column($__appr, 'fy')) : null;
        $__nothing = !$dept['files'] && empty($pastByDept[$d['code']]);
      ?>
        <a href="?dept=<?= e($d['code']) ?>" data-ts="<?= sort_ts($dept['lastSubmitted']) ?>" class="folder <?= $cls ?><?= $dept['hasRevision'] ? ' has-revision' : '' ?>">
          <?php if ($dept['hasRevision']): ?><span class="folder-badge">Revision</span><?php endif; ?>
          <div class="folder-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
          <div class="folder-info">
            <div class="folder-code"><?= strtoupper((string)$d['code']) === 'BUDGET-OFC' ? 'Budget' : e($d['code']) ?></div>
            <?php if (strtoupper((string)$d['code']) !== 'BUDGET-OFC'): ?><div class="folder-full"><?= e($d['name']) ?></div><?php endif; ?>
            <div class="folder-meta">
              <?php if ($__nothing): ?>
                <span>Empty folder</span>
              <?php else: ?>
                <span><?= $fileCount ?> file<?= $fileCount === 1 ? '' : 's' ?></span>
                <?php if ($latestFY !== null): ?>
                <span class="dot"></span>
                <span>Latest FY <?= (int)$latestFY ?></span>
                <?php endif; ?>
                <?= past_year_tag($pastByDept[$d['code']] ?? []) ?>
              <?php endif; ?></div></div></a>
      <?php endforeach; ?></div>
    <?php endforeach; ?>
<?php endforeach; ?>

<?php elseif ($restoredOpen):
  // ── v111: the "Restored" folder inside a department's PPMP folder (view only) ──
  if (!isset($deptIndex[$deptOpen])) { header('Location: admin-dashboard.php'); exit; }
  $dept = $deptIndex[$deptOpen];
  $__g = dept_group_folder($dept['code'], $dept['dept_group'] ?? null);
  $__docs = [];
  foreach ($pastByDept[$dept['code']] ?? [] as $__list) foreach ($__list as $__d) $__docs[] = $__d;
  echo past_year_restored_page_html([['PPMP Folders', 'admin-dashboard.php'], [$__g, 'admin-dashboard.php?group=' . urlencode($__g)],
                                     [$dept['code'] . ' — ' . $dept['name'], 'admin-dashboard.php?dept=' . urlencode($dept['code'])], ['Restored']],
                                    $dept['code'] . ' · Restored', 'admin-dashboard.php?dept=' . urlencode($dept['code']), $__docs);
?>
<?php elseif ($yearOpen > 0):
  // ── One past year inside a department's folder (restored, view only) ──
  if (!isset($deptIndex[$deptOpen])) { header('Location: admin-dashboard.php'); exit; }
  $dept = $deptIndex[$deptOpen];
  echo past_year_page_html('admin-dashboard.php', 'PPMP Folders', $dept, dept_group_folder($dept['code'], $dept['dept_group'] ?? null),
                           $yearOpen, $pastByDept[$dept['code']][$yearOpen] ?? []);
else:
  // ── FILE LIST for a department ──
  if (!isset($deptIndex[$deptOpen])) { header('Location: admin-dashboard.php'); exit; }
  $dept = $deptIndex[$deptOpen];
  $__deptGroup = dept_group_folder($dept['code'], $dept['dept_group'] ?? null);
?>
    <div class="breadcrumb">
      <a href="admin-dashboard.php">PPMP Folders</a>
      <span>›</span>
      <a href="admin-dashboard.php?group=<?= urlencode($__deptGroup) ?>"><?= e($__deptGroup) ?></a>
      <span>›</span>
      <span><?= e($dept['code']) ?> — <?= e($dept['name']) ?></span></div>
    <?php render_flashes();   /* v106: "Revision request sent …" (and whether the email went) shows here, where it comes back to */ ?>
    <div class="section-head">
      <div>
        <h2 class="section-title"><?= e($dept['code']) ?> · <?= e($dept['name']) ?></h2>
        <p class="section-sub">
          <?= (int)$dept['approvedCount'] ?> file<?= (int)$dept['approvedCount'] === 1 ? '' : 's' ?>
          · Group: <?= e($dept['dept_group']) ?></p></div>
      <a href="admin-dashboard.php?group=<?= urlencode($__deptGroup) ?>" class="btn btn-ghost">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back to folders
      </a></div>

    <?php if (!$dept['files']): ?>
      <div style="background: white; border: 1px dashed var(--border); border-radius: 14px; padding: 40px 20px; text-align: center; color: var(--muted); font-size: 14px;"> No PPMP files in this folder yet.
      </div>
    <?php else: ?>
      <?php
      // Load all entries for this dept's PPMPs so we can show them inline
      $fileIds = array_map(fn($f) => $f['id'], $dept['files']);
      $ph = implode(',', array_fill(0, count($fileIds), '?'));
      $stmt = db()->prepare("SELECT * FROM ppmp_entries WHERE ppmp_id IN ($ph) ORDER BY ppmp_id, id");
      $stmt->execute($fileIds);
      $entriesByPPMP = [];
      foreach ($stmt->fetchAll() as $e) $entriesByPPMP[$e['ppmp_id']][] = $e;
      ?>
      <div style="font-size: 13px; color: var(--muted); margin-bottom: 12px;"> 💡 Click <strong>Open file</strong> to see the full PPMP.
      </div>
      <?php foreach ($dept['files'] as $f):
        $fEntries = $entriesByPPMP[$f['id']] ?? [];
      ?>
        <div class="card" style="margin-bottom: 16px; padding: 0; overflow: hidden;<?= $f['revision'] ? 'border-left: 4px solid var(--orange-500);' : '' ?>">
          <!-- File header -->
          <div style="padding: 14px 18px; display: flex; align-items: center; gap: 12px 14px; flex-wrap: wrap; background: var(--navy-50); border-bottom: 1px solid var(--border);">
            <div class="file-icon" style="background: var(--navy-100); color: var(--navy-700); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div style="flex: 1 1 240px; min-width: 0;">
              <div class="file-name" style="font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; color: var(--ink);"> FY <?= (int)$f['fy'] ?> PPMP<?= !empty($f['set']) ? ' · ' . e($f['set']) : '' ?>
                <?php if ($f['revision']): ?><span class="rev-badge" style="background: var(--accent); color: var(--accent-ink); padding: 2px 8px; border-radius: 999px; font-size: 10px; margin-left: 6px;">Revision</span><?php endif; ?>
                <span style="background: <?= $f['submitted'] ? 'var(--green-50)' : 'var(--navy-50)' ?>; color: <?= $f['submitted'] ? '#14532d' : 'var(--muted)' ?>; padding: 2px 8px; border-radius: 999px; font-size: 10px; margin-left: 6px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">
                  <?= $f['submitted'] ? 'Submitted' : 'Draft' ?></span></div>
              <div class="file-meta" style="font-size: 12px; color: var(--muted); margin-top: 3px;">
                <span><?= count($fEntries) ?> entries</span>
                <?php if ($f['submitted']): ?>
                  <span class="dot"></span>
                  <span>Submitted <?= e(date('M j, Y', strtotime($f['submitted']))) ?></span>
                  <span class="dot"></span>
                  <span>By <?= e($f['submitted_by']) ?></span>
                <?php endif; ?></div></div>
            <div class="file-actions">
            <span class="file-amount" style="font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 700;">₱<?= number_format($f['amount'], 2) ?></span>
            <a href="ppmp-file-view.php?id=<?= (int)$f['id'] ?>" class="btn btn-primary" style="padding: 8px 14px; font-size: 13px; text-decoration: none;">Open file</a>
            <?php if ($f['with_pu'] !== null):
              require_once __DIR__ . '/config/edit-permission.php';
              $__fe = pu_edit_state(['id' => (int)$f['id'], 'dept_code' => $f['dept_code'] ?? $dept['code'], 'status' => $f['status'] ?? ''], $u)['state']; ?>
              <?php if ($__fe === 'own' || $__fe === 'granted'): ?>
                <a href="ppmp-edit.php?id=<?= (int)$f['id'] ?>" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; text-decoration: none;">✎ Edit</a>
              <?php elseif ($__fe === 'pending'): ?>
                <span class="btn btn-ghost" aria-disabled="true" title="Waiting for <?= e($dept['code']) ?> to answer" style="padding: 8px 14px; font-size: 13px; cursor:default; opacity:.8;">Edit requested</span>
              <?php else: ?>
                <a href="ppmp-file-view.php?id=<?= (int)$f['id'] ?>&amp;request_edit=1" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; text-decoration: none;">✎ Request edit</a>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (($f['status'] ?? 'draft') !== 'draft'): ?>
              <a href="appcse-print.php?fy=<?= (int)$f['fy'] ?>&amp;dept=<?= urlencode($f['dept_code'] ?? $dept['code']) ?>" target="_blank" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; text-decoration: none;">APP-CSE</a>
            <?php endif; ?>
            <form method="post" action="archive.php" style="display:inline; margin:0;"
                  data-confirm="This moves <?= e($f['dept_code'] ?? $dept['code']) ?> FY <?= (int)$f['fy'] ?> to the Archive. It will no longer appear in the PPMP folders."
                  data-confirm-title="Archive this PPMP?"
                  data-confirm-label="Archive"
                  data-confirm-tone="info">
              <input type="hidden" name="action" value="archive">
              <input type="hidden" name="ppmp_id" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="reason" value="Archived from PPMP folders">
              <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; color: var(--ink);" title="Move to Archive">🗄️ Archive</button></form></div></div></div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php /* v111: restored PPMPs are in their own folder inside this one ("Restored"), not listed under the PPMP */
      $__docs = [];
      foreach ($pastByDept[$dept['code']] ?? [] as $__list) foreach ($__list as $__d) $__docs[] = $__d;
      echo past_year_restored_folder_html('admin-dashboard.php?dept=' . urlencode($dept['code']) . '&restored=1', $__docs); ?>
<?php endif; ?></main></div></body></html>
