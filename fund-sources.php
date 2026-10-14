<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_budget();
ensure_admin_tables();

$u = current_user();
$activeNav = 'fund-sources';
$pageTitle = 'Fund Sources';
$pdo = db();

// Active FY (from settings) — the FY dropdown default
$activeFY = active_fy();
$fy = $activeFY;

// Handle POST — save a pool
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_pool') {
    $source = trim($_POST['fund_source'] ?? '');
    $amount = (float)str_replace(',', '', $_POST['total_amount'] ?? '0');
    $postFy = active_fy();
    if (is_fund($source) && $postFy > 0) {
        $stmt = $pdo->prepare("INSERT INTO fund_pools (fy, fund_source, total_amount, set_by)
                                    VALUES (?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE total_amount = VALUES(total_amount),
                                                       set_by = VALUES(set_by)");
        $stmt->execute([$postFy, $source, $amount, $u['full_name']]);
        log_activity('Set fund pool', 'fund', "$source · FY $postFy", "Set to ₱" . number_format($amount, 2));
        flash('success', "$source pool for FY $postFy saved.");
    } else {
        flash('error', 'Invalid fund source or fiscal year.');
    }
    header('Location: fund-sources.php?fy=' . $postFy); exit;
}

// Load current pools for this FY
$pools = [];
$stmt = $pdo->prepare("SELECT * FROM fund_pools WHERE fy = ?");
$stmt->execute([$fy]);
foreach ($stmt->fetchAll() as $r) $pools[$r['fund_source']] = $r;

// Compute committed amounts per fund from budget-approved and approved PPMPs
$committed = fund_zeroes();
try {
    $c = $pdo->prepare("SELECT e.fund, COALESCE(SUM(e.amount), 0) AS total
                         FROM ppmp_entries e JOIN ppmps p ON p.id = e.ppmp_id
                        WHERE p.fy = ?
                          AND p.status IN ('budget_approved','approved')
                     GROUP BY e.fund");
    $c->execute([$fy]);
    foreach ($c->fetchAll() as $r) {
        if (isset($committed[$r['fund']])) $committed[$r['fund']] = (float)$r['total'];
    }
} catch (Throwable $t) {}

$fundSources = FUND_SOURCES;

// Totals
$totalPool = 0; $totalCommitted = 0;
foreach ($fundSources as $s => $_meta) {
    $totalPool      += (float)($pools[$s]['total_amount'] ?? 0);
    $totalCommitted += $committed[$s];
}
$totalRemaining = $totalPool - $totalCommitted;

$curY = active_fy();
$fyOptions = range($curY - 1, $curY + 3);

require __DIR__ . '/includes/head.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar-budget.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Fund Sources · FY <?= $curY ?></h1></div>
      <div style="display: inline-flex; gap: 8px; align-items: center;">
        <span class="form-label" style="margin: 0;">FY</span>
        <span style="padding: 8px 14px; background: var(--navy-50); border-radius: 8px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; color: var(--navy-800);"> FY <?= $fy ?></span></div></div>
    <?php render_flashes(); ?>

    <!-- Summary stats -->
    <div class="stats" style="margin-bottom: 18px;">
      <div class="stat">
        <div class="stat-label">Total Pool</div>
        <div class="stat-value" style="color: var(--navy-700);">₱<?= number_format($totalPool, 0) ?></div>
        <div class="stat-meta">Across all funds · FY <?= $fy ?></div></div>
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
        <div class="stat-value">FY <?= $fy ?></div>
        <div class="stat-meta">Active cycle</div></div></div>

    <?php
      // Compute chart max (rounded up nicely for axis)
      $chartMax = 0;
      foreach ($fundSources as $k => $_m) $chartMax = max($chartMax, (float)($pools[$k]['total_amount'] ?? 0), (float)$committed[$k]);
      if ($chartMax <= 0) $chartMax = 1;
      // Round the axis to a nicer number
      $magnitude = pow(10, floor(log10($chartMax)));
      $chartMax = ceil($chartMax / $magnitude) * $magnitude;
    ?>
    <?php if ($totalPool > 0 || $totalCommitted > 0): ?>
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
          $chartH = 180;
          $chartW = 900;
          $padL = 55; $padR = 12; $padT = 12; $padB = 32;
          $plotW = $chartW - $padL - $padR;
          $plotH = $chartH - $padT - $padB;
          $groupCount = count($fundSources);
          $groupW = $plotW / $groupCount;
          $barW = 16;
          $gap = 5;
          $trioW = $barW * 3 + $gap * 2;
        ?>
        <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" preserveAspectRatio="xMidYMid meet" style="width: 100%; height: auto; max-height: 220px; font-family: 'Space Grotesk', sans-serif;">
          <!-- Y-axis gridlines + labels -->
          <?php for ($i = 0; $i <= 4; $i++):
            $y = $padT + $plotH - ($plotH * $i / 4);
            $val = $chartMax * $i / 4;
          ?>
            <line x1="<?= $padL ?>" y1="<?= $y ?>" x2="<?= $chartW - $padR ?>" y2="<?= $y ?>" stroke="var(--navy-50)" stroke-width="1"/>
            <text x="<?= $padL - 6 ?>" y="<?= $y + 3 ?>" text-anchor="end" fill="#3b7597" font-size="9">₱<?= number_format($val / 1000, 0) ?>k</text>
          <?php endfor; ?>

          <!-- Bars per fund -->
          <?php $gi = 0; foreach ($fundSources as $key => $meta):
            $current = $pools[$key] ?? null;
            $total   = $current ? (float)$current['total_amount'] : 0;
            $spent   = $committed[$key];
            $rem     = max(0, $total - $spent);
            $groupCenterX = $padL + $groupW * $gi + $groupW / 2;
            $trioStartX = $groupCenterX - $trioW / 2;

            $bars = [
              ['x' => $trioStartX,                       'val' => $total, 'color' => '#232f72'],
              ['x' => $trioStartX + $barW + $gap,        'val' => $spent, 'color' => 'var(--orange-50)'],
              ['x' => $trioStartX + ($barW + $gap) * 2,  'val' => $rem,   'color' => '#16a34a'],
            ];
            foreach ($bars as $b):
              $h = $chartMax > 0 ? ($b['val'] / $chartMax) * $plotH : 0;
              $y = $padT + $plotH - $h;
          ?>
            <rect x="<?= $b['x'] ?>" y="<?= $y ?>" width="<?= $barW ?>" height="<?= $h ?>" fill="<?= $b['color'] ?>" rx="2"/>
            <?php if ($b['val'] > 0 && $h > 22): ?>
              <text x="<?= $b['x'] + $barW / 2 ?>" y="<?= $y - 3 ?>" text-anchor="middle" fill="<?= $b['color'] ?>" font-weight="700" font-size="8">
                ₱<?= number_format($b['val'] / 1000, 0) ?>k
              </text>
            <?php endif; ?>
          <?php endforeach; ?>
            <!-- Fund label -->
            <text x="<?= $groupCenterX ?>" y="<?= $padT + $plotH + 16 ?>" text-anchor="middle" fill="#121358" font-weight="700" font-size="10"><?= e($meta['label']) ?></text>
            <text x="<?= $groupCenterX ?>" y="<?= $padT + $plotH + 26 ?>" text-anchor="middle" fill="#3b7597" font-size="8">
              <?= $total <= 0 ? 'not set' : ($spent > $total ? 'OVER' : number_format(($spent / $total) * 100, 0) . '% used') ?></text>
          <?php $gi++; endforeach; ?>

          <!-- Y-axis line -->
          <line x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $padL ?>" y2="<?= $padT + $plotH ?>" stroke="var(--border)" stroke-width="1"/></svg></div>
    <?php endif; ?>

    <!-- Per-fund pools -->
    <?php foreach ($fundSources as $key => $meta):
      $current = $pools[$key] ?? null;
      $total   = $current ? (float)$current['total_amount'] : 0;
      $spent   = $committed[$key];
      $rem     = $total - $spent;
      $pct     = $total > 0 ? min(100, ($spent / $total) * 100) : 0;
      $over    = ($total > 0 && $spent > $total);
      $barClr  = $over ? 'var(--orange-500)' : ($pct >= 85 ? '#f59e0b' : '#16a34a');
      $frmId   = 'frm-' . str_replace(' ', '-', $key);
    ?>
      <div class="card" style="margin-bottom: 12px;">
        <div class="fund-row" style="display: grid; grid-template-columns: 220px 1fr auto; gap: 24px; align-items: center;">
          <!-- Fund identity -->
          <div>
            <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 18px; color: var(--navy-800);"><?= e($meta['label']) ?></div>
            <div style="font-size: 12px; color: var(--muted); margin-top: 2px;"><?= e($meta['sub']) ?></div>
            <?php if ($current && !empty($current['set_by'])): ?>
              <div style="font-size: 10.5px; color: var(--muted); margin-top: 8px;">Set by <?= e($current['set_by']) ?></div>
            <?php endif; ?></div>

          <!-- Original / Committed / Remaining side-by-side -->
          <div>
            <div class="pool-3" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 10px;">
              <div style="padding: 10px 12px; background: var(--navy-50); border-radius: 8px; border: 1px solid var(--navy-100);">
                <div style="font-size: 10px; color: var(--navy-700); text-transform: uppercase; font-weight: 700; letter-spacing: 0.06em;">Original (Pool)</div>
                <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; color: var(--navy-800); margin-top: 3px;">₱<?= number_format($total, 2) ?></div>
                <div style="font-size: 10.5px; color: var(--muted); margin-top: 2px;">Never changes</div></div>
              <div style="padding: 10px 12px; background: var(--orange-50); border-radius: 8px; border: 1px solid var(--orange-100);">
                <div style="font-size: 10px; color: var(--orange-600); text-transform: uppercase; font-weight: 700; letter-spacing: 0.06em;">Committed</div>
                <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; color: var(--orange-600); margin-top: 3px;">− ₱<?= number_format($spent, 2) ?></div>
                <div style="font-size: 10.5px; color: var(--muted); margin-top: 2px;">From approved PPMPs</div></div>
              <div style="padding: 10px 12px; background: <?= $over ? 'var(--orange-50)' : ($total > 0 ? '#f0fdf4' : 'var(--navy-50)') ?>; border-radius: 8px; border: 1px solid <?= $over ? 'var(--orange-100)' : ($total > 0 ? '#86efac' : 'var(--border)') ?>;">
                <div style="font-size: 10px; color: <?= $over ? 'var(--orange-600)' : ($total > 0 ? '#14532d' : 'var(--muted)') ?>; text-transform: uppercase; font-weight: 700; letter-spacing: 0.06em;">Remaining</div>
                <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; color: <?= $over ? 'var(--orange-600)' : ($total > 0 ? '#16a34a' : 'var(--muted)') ?>; margin-top: 3px;">
                  <?= $over ? '−' : '' ?>₱<?= number_format(abs($rem), 2) ?></div>
                <div style="font-size: 10.5px; color: var(--muted); margin-top: 2px;"><?= $over ? 'Overspent' : ($total > 0 ? 'Available' : 'Awaiting pool') ?></div></div></div>
            <div style="height: 8px; background: var(--navy-50); border-radius: 4px; overflow: hidden;">
              <div style="height: 100%; width: <?= $pct ?>%; background: <?= $barClr ?>; transition: width 0.3s;"></div></div>
            <?php if ($total <= 0): ?>
              <div style="margin-top: 8px; font-size: 11.5px; color: var(--muted); font-style: italic;"> No pool amount set yet. Enter the total available amount for this fund below.
              </div>
            <?php elseif ($over): ?>
              <div style="margin-top: 8px; font-size: 11.5px; color: var(--orange-600); font-weight: 700;"> Committed exceeds the pool by ₱<?= number_format(abs($rem), 2) ?>. Consider increasing the pool.
              </div>
            <?php endif; ?></div>

          <!-- Inline edit form -->
          <form method="POST" style="display: flex; gap: 8px; align-items: center;" id="<?= e($frmId) ?>">
            <input type="hidden" name="action" value="set_pool">
            <input type="hidden" name="fund_source" value="<?= e($key) ?>">
            <input type="hidden" name="fy" value="<?= $fy ?>">
            <div style="display: flex; align-items: center; gap: 4px;">
              <span style="color: var(--muted); font-weight: 700;">₱</span>
              <input type="text" inputmode="decimal" name="total_amount"
                     value="<?= $total > 0 ? number_format($total, 2, '.', '') : '' ?>"
                     class="form-input money" placeholder="0.00" required
                     style="width: 170px; padding: 8px 10px; font-family: 'Space Grotesk', sans-serif; font-weight: 700;"></div>
            <button type="submit" class="btn btn-primary" style="padding: 8px 16px; font-size: 12.5px;">
              <?= $total > 0 ? 'Update' : 'Save' ?></button></form></div></div>
    <?php endforeach; ?></main></div></body></html>
