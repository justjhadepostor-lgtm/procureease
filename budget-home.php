<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_budget();
ensure_admin_tables();

$u = current_user();
$activeNav = 'budget-home';
$pageTitle = 'Dashboard';

$pdo = db();
$me  = $u['dept_code'];

// Review-workload counters. The Budget Unit's own department is excluded —
// its PPMP is prepared under "New PPMP", never part of the review workload.
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

// The fiscal year this dashboard reports on. The alloted-budget query that
// used to sit here went with the table it fed — the allocations are on the
// Budget Allocations page, and only there.
$overviewFY = active_fy();
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<div class="app">
  <?php require_once __DIR__ . '/includes/sidebar-budget.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Dashboard · FY <?= $overviewFY ?></h1>
        <p class="page-sub" style="margin: 4px 0 0;">Overview of your review workload</p></div></div>

    <?php render_flashes(); ?>

    <div class="stats">
      <div class="stat">
        <div class="stat-label">Awaiting my review</div>
        <div class="stat-value" style="color: var(--orange-600);"><?= $stats['pending'] ?></div>
        <div class="stat-meta">Submitted by departments</div></div>
      <div class="stat">
        <div class="stat-label">With Procurement Unit</div>
        <div class="stat-value"><?= $stats['budget_approved'] ?></div>
        <div class="stat-meta">I approved these</div></div>
      <div class="stat">
        <div class="stat-label">Fully approved</div>
        <div class="stat-value" style="color: var(--navy-700);"><?= $stats['admin_approved'] ?></div>
        <div class="stat-meta">Approved by the Procurement Unit</div></div>
      <div class="stat">
        <div class="stat-label">All-time PPMPs</div>
        <div class="stat-value"><?= $stats['total'] ?></div>
        <div class="stat-meta">Submitted to the Budget Unit</div></div></div>

    <?php /* v79: budget per department and submissions before the deadline */
          $dcFy = (int)$overviewFY; require __DIR__ . '/includes/dashboard-charts.php'; ?>

    <!-- Quick actions -->
    <div class="section-head"><div><h2 class="section-title">Quick actions</h2></div></div>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; margin-bottom:24px;">
      <a href="budget-dashboard.php" class="card" style="text-decoration:none; padding:18px 20px; display:block;">
        <div style="font-family:'Space Grotesk',sans-serif; font-weight:700; color:var(--navy-800); font-size:15px;">PPMP Folders</div>
        <div style="font-size:12.5px; color:var(--muted); margin-top:4px;">Review and approve PPMPs submitted by departments.</div>
        <?php if ($stats['pending'] > 0): ?>
          <div style="margin-top:8px; font-size:11.5px; color:var(--orange-600); font-weight:700;"><?= $stats['pending'] ?> awaiting review</div>
        <?php endif; ?></a>
      <a href="budget-allocations.php" class="card" style="text-decoration:none; padding:18px 20px; display:block;">
        <div style="font-family:'Space Grotesk',sans-serif; font-weight:700; color:var(--navy-800); font-size:15px;">Budget Allocations</div>
        <div style="font-size:12.5px; color:var(--muted); margin-top:4px;">Set each department's alloted budget from the GAA.</div></a>
      <a href="fund-sources.php" class="card" style="text-decoration:none; padding:18px 20px; display:block;">
        <div style="font-family:'Space Grotesk',sans-serif; font-weight:700; color:var(--navy-800); font-size:15px;">Fund Sources</div>
        <div style="font-size:12.5px; color:var(--muted); margin-top:4px;">Manage the fund sources available for procurement.</div></a>
    </div>

    <?php /* The Alloted Budgets table lives on Budget Allocations, and only
             there — it was on this page as well, showing the same departments
             and the same figures. */ ?>
  </main>
</div>

</body></html>
