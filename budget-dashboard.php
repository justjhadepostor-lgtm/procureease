<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/appcse-review.php';
require_budget();
ensure_admin_tables();

$u = current_user();
$view = $_GET['view'] ?? 'pending';   // 'pending' | 'approved'
if (!in_array($view, ['pending', 'approved'], true)) $view = 'pending';   // v90: a department's draft is its own
$activeNav = 'budget-queue';
$pageTitle = 'PPMP Folders';

$pdo = db();

// The Budget Unit reviews OTHER departments' PPMPs here (the "PPMP Folders").
// Their own department's PPMP is prepared under "New PPMP" (editable template)
// and must never appear in this review queue — so it is excluded here.
$me = $u['dept_code'];

if ($view === 'approved') {
    $stmt = $pdo->prepare("SELECT p.*, d.name AS dept_name, d.dept_group
                         FROM ppmps p JOIN departments d ON p.dept_code = d.code
                         WHERE p.status = 'approved' AND p.dept_code <> ? AND COALESCE(d.status,'active') = 'active'
                           AND NOT EXISTS (SELECT 1 FROM archived_ppmps ap WHERE ap.ppmp_id = p.id)
                           AND p.fy >= " . (int)active_fy() . "
                         ORDER BY p.admin_reviewed_at DESC");
} else {
    $stmt = $pdo->prepare("SELECT p.*, d.name AS dept_name, d.dept_group
                         FROM ppmps p JOIN departments d ON p.dept_code = d.code
                         WHERE p.status = 'submitted' AND p.dept_code <> ? AND COALESCE(d.status,'active') = 'active'
                           AND NOT EXISTS (SELECT 1 FROM archived_ppmps ap WHERE ap.ppmp_id = p.id)
                           AND p.fy >= " . (int)active_fy() . "
                         ORDER BY p.submitted_at DESC");
}
$stmt->execute([$me]);
$items = $stmt->fetchAll();

// Fetch entry counts for the folder cards
$entryCounts = [];
if ($items) {
    $ids = array_column($items, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT ppmp_id, COUNT(*) AS n FROM ppmp_entries WHERE ppmp_id IN ($ph) GROUP BY ppmp_id");
    $s->execute($ids);
    foreach ($s->fetchAll() as $r) $entryCounts[$r['ppmp_id']] = (int)$r['n'];
}

// (Past years are left out below: a restored PPMP of a past year is for viewing only.)
// Top-of-page stat counters — these represent the review workload, so they
// also exclude the Budget Unit's own department.
$sc = function (string $extra) use ($pdo, $me): int {
    // (a past year's PPMP, restored from the Archive, is for viewing only — not workload)
    $q = $pdo->prepare("SELECT COUNT(*) FROM ppmps WHERE dept_code <> ? AND fy >= " . (int)active_fy() . $extra);
    $q->execute([$me]);
    return (int)$q->fetchColumn();
};
$stats = [
    'pending'         => $sc(" AND status = 'submitted'"),
    'budget_approved' => $sc(" AND status = 'budget_approved'"),
    'admin_approved'  => $sc(" AND status = 'approved'"),
    'total'           => $sc(" AND status <> 'draft'"),
];

function relTime(string $t): string {
    $secs = time() - strtotime($t);
    if ($secs < 60)     return $secs . 's ago';
    if ($secs < 3600)   return floor($secs / 60)    . 'm ago';
    if ($secs < 86400)  return floor($secs / 3600)  . 'h ago';
    if ($secs < 604800) return floor($secs / 86400) . 'd ago';
    return date('M j, Y', strtotime($t));
}

// ─── Department grouping (mirrors the admin dashboard) ──────
$academicSupportCodes = [];
function budget_dept_category(string $deptGroup, string $code, array $academicSupport): string {
    if (in_array(strtoupper(trim($code)), ['LIB', 'REG', 'NSTP', 'SCA', 'IEAS', 'LAB'], true)) return 'Administrative';
    if ($deptGroup === 'Schools & Colleges') return 'Academic';
    if (in_array(strtoupper(trim($code)), $academicSupport, true)) return 'Academic';
    return 'Administrative';
}

// Group the PPMP items by Academic / Administrative
$grouped = ['Academic' => [], 'Administrative' => []];
foreach ($items as $p) {
    $cat = budget_dept_category($p['dept_group'] ?? '', $p['dept_code'], $academicSupportCodes);
    $grouped[$cat][] = $p;
}
$groupMeta = [
    'Academic'       => ['icon' => '', 'color' => 'var(--navy-700)', 'bg' => 'var(--navy-50)',   'border' => 'var(--navy-100)'],
    'Administrative' => ['icon' => '', 'color' => 'var(--navy-800)', 'bg' => 'var(--navy-50)',   'border' => 'var(--navy-100)'],
];

// ─── Overview of alloted budgets set by the Budget Unit (for the active FY) ─
$overviewFY = active_fy();

$allocations = [];
$totalAlloted = 0.0;
try {
    $q = $pdo->prepare("SELECT d.code AS dept_code, d.name AS dept_name, d.dept_group,
                               COALESCE(b.alloted_budget, 0) AS alloted_budget,
                               b.updated_at
                          FROM departments d
                     LEFT JOIN dept_budgets b ON b.dept_code = d.code AND b.fy = :fy
                      ORDER BY d.display_order, d.code");
    $q->execute([':fy' => $overviewFY]);
    $allocations = $q->fetchAll();
    // v81: the programs and majors of a school share the school's allotment — only the school is listed
    require_once __DIR__ . '/config/academic-units.php';
    $allocations = array_values(array_filter($allocations, fn($a) => !au_is_unit((string)$a['dept_code']) && !os_is_set((string)$a['dept_code'])));   // (v83: nor an office's sets)
    $allocations = au_active_rows($allocations, 'dept_code');   // v92: a deactivated department is not shown
    foreach ($allocations as $a) $totalAlloted += (float)$a['alloted_budget'];
} catch (Throwable $t) {}
$totalPlanned = 0.0;
try { $q = $pdo->prepare('SELECT COALESCE(SUM(e.amount),0) FROM ppmp_entries e JOIN ppmps p ON p.id=e.ppmp_id WHERE p.fy=?'); $q->execute([$overviewFY]); $totalPlanned = (float)$q->fetchColumn(); } catch (Throwable $t) {}
$totalRemaining = $totalAlloted - $totalPlanned;
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<div class="app">
  <?php require_once __DIR__ . '/includes/sidebar-budget.php'; ?>
  <style>
    .bq-item { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .bq-item > .folder { flex: 1 1 auto; }
    .bq-appcse { align-self: flex-start; max-width: 100%; font-size: 12px; font-weight: 600; text-decoration: none;
      color: var(--ink); background: var(--white); border: 1px dashed var(--navy-500);
      border-radius: 8px; padding: 6px 11px; }
    .bq-appcse:hover { background: var(--navy-50); }
  </style>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">PPMP Folders · FY <?= active_fy() ?></h1>
        <p class="page-sub" style="margin: 4px 0 0;">Each department's submitted PPMP is filed in its folder here for your review and approval</p></div></div>

    <?php render_flashes(); ?>

<?php
$__budgetCats = ['travel'=>'Travel','training'=>'Training','supply'=>'Supply','equipment'=>'Equipment'];
$__budgetColors = ['travel'=>'#0ea5e9','training'=>'#8b5cf6','supply'=>'#f59e0b','equipment'=>'#10b981'];
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
  <div><div style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase">Total Allocation · FY <?= (int)$overviewFY ?></div><div style="font-size:22px;font-weight:800">₱<?= number_format($totalAlloted,2) ?></div></div>
  <div><div style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase">Planned PPMP Total</div><div style="font-size:22px;font-weight:800">₱<?= number_format($totalPlanned,2) ?></div></div>
  <div><div style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase">Remaining Allocation</div><div style="font-size:22px;font-weight:800;color:<?= $totalRemaining < 0 ? '#dc2626' : '#15803d' ?>">₱<?= number_format($totalRemaining,2) ?></div></div>
</div>
<div class="card" style="padding:16px;margin:0 0 18px;display:flex;gap:18px;align-items:center;flex-wrap:wrap">
  <div style="font-weight:800;min-width:140px">FY <?= (int)active_fy() ?> Category Guide</div>
  <div style="width:96px;height:96px;border-radius:50%;background:<?= e($__donut) ?>;display:grid;place-items:center;flex:0 0 96px"><div style="width:58px;height:58px;border-radius:50%;background:var(--card,#fff);display:grid;place-items:center;text-align:center;font-size:10px;font-weight:800">₱<?= number_format($__sumBudget,0) ?><span style="font-size:8px">Guide</span></div></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;flex:1;min-width:220px">
  <?php foreach ($__budgetCats as $__k=>$__label): ?><div style="border-left:3px solid <?= $__budgetColors[$__k] ?>;padding:3px 8px;font-size:11px"><strong><?= e($__label) ?></strong><div>Allocated ₱<?= number_format($__budgetTotals[$__k],2) ?></div><div style="color:var(--muted)">Planned ₱<?= number_format($__spentTotals[$__k],2) ?></div><div style="font-weight:700">Remaining ₱<?= number_format($__budgetTotals[$__k]-$__spentTotals[$__k],2) ?></div></div><?php endforeach; ?>
  </div>
</div>


    <?php
      // v88: PPMPs of past fiscal years restored from the Archive — view only, by year
      require_once __DIR__ . '/config/past-years.php';
      $__pastByYear = [];
      foreach (past_year_docs('ppmp', 'budget') as $__d => $__byFy) foreach ($__byFy as $__fy => $__docs) foreach ($__docs as $__doc) {
          $__doc['title'] = $__doc['dept_code'] . ' · ' . $__doc['title'];
          $__pastByYear[(int)$__fy][] = $__doc;
      }
      krsort($__pastByYear);
      $__yearOpen = (int)($_GET['year'] ?? 0);
      if (!empty($_GET['restored'])):   // v111: PPMP Folders › Restored — the restored PPMPs, in a folder of their own
        $__pastAll = []; foreach ($__pastByYear as $__docs) foreach ($__docs as $__d1) $__pastAll[] = $__d1;
        echo past_year_restored_page_html([['PPMP Folders', 'budget-dashboard.php'], ['Restored']], 'Restored PPMPs', 'budget-dashboard.php', $__pastAll);
    ?>
  </main>
</div>
</body></html>
    <?php return; elseif ($__yearOpen > 0):
    ?>
    <div class="breadcrumb"><a href="budget-dashboard.php">PPMP Folders</a><span>›</span><span>FY <?= $__yearOpen ?></span></div>
    <div class="section-head" id="pastYearPage"><div>
      <h2 class="section-title">FY <?= $__yearOpen ?></h2>
      <p class="section-sub">Restored from the Archive · view only</p></div>
      <a href="budget-dashboard.php" class="btn btn-ghost">← Back</a></div>
    <?= !empty($__pastByYear[$__yearOpen]) ? past_year_files_html($__pastByYear[$__yearOpen])
          : '<div style="background: var(--white); border: 1px dashed var(--border); border-radius: 14px; padding: 40px 20px; text-align: center; color: var(--muted); font-size: 14px;">No restored FY ' . $__yearOpen . ' PPMPs.</div>' ?>
  </main>
</div>
</body></html>
    <?php return; endif; ?>

    <?php
      // Group filter — same layout/behaviour as the Admin PPMP Folders
      $groupFilter = $_GET['group'] ?? 'all';
      if (!in_array($groupFilter, ['all', 'Academic', 'Administrative'], true)) $groupFilter = 'all';
      $allCount   = count($items);
      $acadCount  = count($grouped['Academic']);
      $adminCount = count($grouped['Administrative']);
      $shown = ($groupFilter === 'all') ? $items : $grouped[$groupFilter];
      $qg = fn($g) => '?' . http_build_query(array_filter([
          'view'  => $view !== 'pending' ? $view : null,
          'group' => $g !== 'all' ? $g : null,
      ]));
    ?>

    <div class="section-head">
      <div>
        <h2 class="section-title">
          <?php
            if ($view === 'approved')    echo 'Fully approved PPMPs';
            else                         echo 'PPMPs awaiting my review';
          ?></h2></div></div>

    <!-- Group filter buttons (same layout as the Admin PPMP Folders) -->
    <div style="display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap;">
      <a href="<?= $qg('all') ?>" style="text-decoration: none; padding: 10px 18px; border-radius: 10px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; border: 1.5px solid var(--border); background: <?= $groupFilter === 'all' ? 'var(--accent)' : 'var(--white)' ?>; color: <?= $groupFilter === 'all' ? 'var(--accent-ink)' : 'var(--ink)' ?>; display: flex; align-items: center; gap: 8px;">All departments
        <span style="font-weight: 600; opacity: 0.7; font-size: 12px;"><?= $allCount ?></span></a>
      <a href="<?= $qg('Academic') ?>" style="text-decoration: none; padding: 10px 18px; border-radius: 10px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; border: 1.5px solid <?= $groupFilter === 'Academic' ? 'var(--navy-700)' : 'var(--navy-100)' ?>; background: <?= $groupFilter === 'Academic' ? 'var(--accent)' : 'var(--white)' ?>; color: <?= $groupFilter === 'Academic' ? 'var(--accent-ink)' : 'var(--ink)' ?>; display: flex; align-items: center; gap: 8px;">🎓 Academic
        <span style="font-weight: 600; opacity: 0.7; font-size: 12px;"><?= $acadCount ?></span></a>
      <a href="<?= $qg('Administrative') ?>" style="text-decoration: none; padding: 10px 18px; border-radius: 10px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; border: 1.5px solid <?= $groupFilter === 'Administrative' ? 'var(--orange-600)' : 'var(--orange-100)' ?>; background: <?= $groupFilter === 'Administrative' ? 'var(--accent)' : 'var(--white)' ?>; color: <?= $groupFilter === 'Administrative' ? 'var(--accent-ink)' : 'var(--ink)' ?>; display: flex; align-items: center; gap: 8px;">🏢 Administrative
        <span style="font-weight: 600; opacity: 0.7; font-size: 12px;"><?= $adminCount ?></span></a></div>

    <?php if (!$shown): ?>
      <div class="card" style="text-align: center; padding: 60px 24px; color: var(--muted);">
        <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px; display: block; color: var(--border);">
          <?php if ($view === 'approved'): ?>
            <polyline points="20 6 9 17 4 12"/>
          <?php else: ?>
            <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
          <?php endif; ?></svg>
        <div style="font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px;">
          <?= $view === 'approved' ? 'Nothing has been fully approved yet' : 'No PPMPs waiting for review' ?></div>
        <div style="font-size: 13.5px;">
          <?= $view === 'approved'
              ? 'When the Procurement Unit gives final approval, PPMPs will appear here.'
              : 'When a department submits a PPMP, it will land in this queue for review.' ?></div></div>
    <?php else: ?>
      <?= sort_bar('budgetQueue') ?>
      <div class="folder-grid" id="budgetQueue">
        <?php foreach ($shown as $p):
          $isApproved = ($view === 'approved');
          $when       = $isApproved ? $p['admin_reviewed_at'] : $p['submitted_at'];
          $who        = $isApproved ? $p['admin_reviewed_by'] : $p['submitted_by'];
          $entries    = $entryCounts[$p['id']] ?? 0;
          $catClass   = $isApproved ? 'cat-navy' : 'cat-orange';
        ?>
          <div class="bq-item" data-ts="<?= sort_ts($when) ?>">
          <a href="ppmp-file-view.php?id=<?= (int)$p['id'] ?>" class="folder <?= $catClass ?>">
            <?php if (!$isApproved): ?>
              <span class="folder-badge">Review</span>
            <?php else: ?>
              <span class="folder-badge" style="background: var(--green-50); color: #14532d; border: 1px solid #86efac;">Approved</span>
            <?php endif; ?>
            <div class="folder-icon">
              <?php if ($isApproved): ?>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              <?php else: ?>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
              <?php endif; ?></div>
            <div class="folder-info">
              <div class="folder-code"><?= strtoupper((string)$p['dept_code']) === 'BUDGET-OFC' ? 'Budget' : e($p['dept_code']) ?> · FY <?= (int)$p['fy'] ?></div>
              <?php if (strtoupper((string)$p['dept_code']) !== 'BUDGET-OFC'): ?><div class="folder-full"><?= e($p['dept_name']) ?></div><?php endif; ?>
              <div class="folder-meta">
                <span><?= $entries ?> entrie<?= $entries === 1 ? '' : 's' ?></span>
                <span class="dot"></span>
                <span<?= $when ? ' title="' . e(date('M j, Y g:i A', strtotime($when))) . '"' : '' ?>><?= $when ? e(relTime($when)) : '—' ?></span>
                <?php if ($who): ?>
                  <span class="dot"></span>
                  <span style="max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: bottom;"><?= e($who) ?></span>
                <?php endif; ?></div></div></a>
            <?php /* v77: the APP-CSE no longer comes here — it goes straight to the Procurement Unit */ ?>
          </div>
        <?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($__pastByYear): /* v111: not listed under the PPMPs — in a folder of their own, "Restored" */
      $__pastAll = []; foreach ($__pastByYear as $__docs) foreach ($__docs as $__d1) $__pastAll[] = $__d1;
      echo past_year_restored_folder_html('budget-dashboard.php?restored=1', $__pastAll);
    endif; ?>
  </main>
</div>
</body></html>
