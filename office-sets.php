<?php
/* ═══════════════════════════════════════════════════════════════
   My Sets (v83) — an office's up to 3 sets of documents a year.

   Set 1 is the office itself. Its submitter (the Department Admin, or the
   Procurement Unit / Budget Unit for their own office) adds Set 2 and Set 3
   here, and sees where each set is. Each set is prepared and submitted on
   its own; all of them share the office's one allotment
   (config/office-sets.php).
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/companions.php';
require_login();
ensure_admin_tables();

$u      = current_user();
$office = (string)($u['school_code'] ?? '');
$fy     = (int)($_GET['fy'] ?? active_fy());
if (!os_eligible($office)) {
    flash('error', 'Sets are for administrative units and offices. A school\'s programs and majors are on My Department.');
    header('Location: ' . (($u['role'] ?? '') === 'admin' ? 'admin-home.php' : (($u['role'] ?? '') === 'budget' ? 'budget-home.php' : 'user-dashboard.php')));
    exit;
}
$canManage = can_submit_for_dept($u);
$oRow      = au_row($office);
$family    = os_family($office);
$next      = count($family) < OS_MAX ? os_next_no($office) : 0;
$tok       = function_exists('csrf_field') ? csrf_field() : '';

/* each set: its PPMP of the year, its documents, where it is */
$STAGE = ['draft' => ['Draft', 'os-draft'], 'submitted' => ['With Budget', 'os-sent'], 'budget_approved' => ['With Procurement', 'os-sent'],
          'approved' => ['Approved', 'os-ok'], 'revision' => ['Sent back', 'os-back']];
$rows = [];
foreach ($family as $code) {
    $p = null;
    try {
        $s = db()->prepare('SELECT p.id, p.status FROM ppmps p WHERE p.dept_code = ? AND p.fy = ?
                               AND NOT EXISTS (SELECT 1 FROM archived_ppmps a WHERE a.ppmp_id = p.id) ORDER BY p.id DESC');
        $s->execute([$code, $fy]);
        $p = $s->fetch() ?: null;
    } catch (Throwable $t) {}
    $rev = false;
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM revision_requests WHERE dept_code = ? AND fy = ? AND resolved = 0');
        $s->execute([$code, $fy]);
        $rev = (int)$s->fetchColumn() > 0;
    } catch (Throwable $t) {}
    $st  = $rev ? 'revision' : (string)($p['status'] ?? 'draft');
    $own = ppmp_budget_match_own($code, $fy);
    $rows[] = ['code' => $code, 'label' => au_label($code) === $oRow['name'] ? 'Set 1' : au_label($code), 'ppmp' => $p, 'stage' => $STAGE[$st] ?? $STAGE['draft'],
               'set' => ppmp_companions($code, $fy), 'pp' => (float)$own['pp']['ppmp'], 'spend' => (float)$own['appcse'] + (float)$own['nonps'],
               'records' => os_is_set($code) ? os_records($code) : null];
}
$bm = ppmp_budget_match($office, $fy);

$activeNav = 'office-sets';
$pageTitle = 'My Sets';
require __DIR__ . '/includes/head.php';
?>
<style>
  .os-card { background:var(--white); border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:18px; }
  .os-card h3 { margin:0 0 4px; font-family:'Space Grotesk',sans-serif; font-size:15px; color:var(--ink); }
  .os-sub { font-size:12.5px; color:var(--muted); margin-bottom:12px; line-height:1.55; }
  .os-wrap { overflow-x:auto; }
  .os-table { width:100%; border-collapse:collapse; font-size:12.5px; color:var(--ink); }
  .os-table th, .os-table td { padding:10px; border-bottom:1px solid var(--border); text-align:left; vertical-align:top; }
  .os-table th { font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
  .os-table td.n { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .os-set { font-family:'Space Grotesk',sans-serif; font-weight:700; }
  .os-pill { display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:700; border:1.5px solid; white-space:nowrap; }
  .os-draft { background:var(--navy-50); color:var(--ink); border-color:var(--navy-100); }
  .os-sent  { background:#eef2ff; color:#1e3a8a; border-color:#a5b4fc; }
  .os-ok    { background:#ecfdf3; color:#14532d; border-color:#86efac; }
  .os-back  { background:#fef2f2; color:#991b1b; border-color:#fca5a5; }
  body[data-theme="dark"] .os-sent { background:#16245a; color:#dbe4ff; border-color:#5b6fd1; }
  body[data-theme="dark"] .os-ok   { background:#123222; color:#c9f2d7; border-color:#2f7a4c; }
  body[data-theme="dark"] .os-back { background:#3a1414; color:#fecaca; border-color:#9a2c2c; }
  .os-docs { display:flex; gap:4px; flex-wrap:wrap; }
  .os-doc { display:inline-block; padding:1px 7px; border-radius:6px; font-size:11px; font-weight:700; border:1px solid; }
  .os-doc.ok { color:#14532d; background:#ecfdf3; border-color:#86efac; }
  .os-doc.miss { color:#7c2d12; background:#FFF4EC; border-color:#fdba74; }
  body[data-theme="dark"] .os-doc.ok { background:#123222; color:#c9f2d7; border-color:#2f7a4c; }
  body[data-theme="dark"] .os-doc.miss { background:#3a2414; color:#fde2cf; border-color:#9a5a2c; }
  .os-acts { display:flex; gap:6px; flex-wrap:wrap; }
  .os-acts form { margin:0; }
  .os-btn { padding:5px 11px !important; font-size:12px !important; text-decoration:none; }
  .os-budget { font-size:12.5px; color:var(--ink); line-height:1.6; margin:0; padding-left:18px; }
</style>
<div class="app">
<?php
  $__role = $u['role'] ?? '';
  if     ($__role === 'admin')  require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($__role === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
  else                          require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">My Sets · FY <?= $fy ?></h1>
        <p class="page-sub"><?= e($oRow['name']) ?> (<?= e($office) ?>)</p></div>
      <form method="GET">
        <select name="fy" class="filter-select" onchange="this.form.submit()" aria-label="Fiscal year">
          <?php foreach (range(active_fy() - 2, active_fy() + 1) as $y): ?>
            <option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>>FY <?= $y ?></option>
          <?php endforeach; ?></select></form>
    </div>
    <?php render_flashes(); ?>

    <div class="os-card" id="osSets">
      <h3>Sets of <?= e($office) ?></h3>
      <div class="os-wrap"><table class="os-table">
        <thead><tr><th>Set</th><th>Documents · FY <?= $fy ?></th><th class="n">PPMP Total Amount</th><th class="n">APP-CSE + Non-PS</th><th>PPMP</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr id="set-<?= e($r['code']) ?>" data-set="<?= e($r['code']) ?>">
            <td><span class="os-set"><?= e($r['label']) ?></span><div style="font-size:11px; color:var(--muted); white-space:nowrap;"><?= e($r['code']) ?></div></td>
            <td><div class="os-docs">
              <span class="os-doc <?= $r['ppmp'] ? 'ok' : 'miss' ?>"><?= $r['ppmp'] ? '✓' : '✗' ?> PPMP</span>
              <?php foreach ($r['set']['items'] as $i): ?>
                <span class="os-doc <?= $i['ok'] ? 'ok' : 'miss' ?>" title="<?= e($i['note']) ?>"><?= $i['ok'] ? '✓' : '✗' ?> <?= e($i['label']) ?></span>
              <?php endforeach; ?></div></td>
            <td class="n">₱<?= number_format($r['pp'], 2) ?></td>
            <td class="n">₱<?= number_format($r['spend'], 2) ?></td>
            <td><span class="os-pill <?= $r['stage'][1] ?>"><?= e($r['stage'][0]) ?></span></td>
            <td><div class="os-acts">
              <a class="btn btn-ghost os-btn" href="ppmp-edit.php?fy=<?= $fy ?>&amp;unit=<?= e(rawurlencode($r['code'])) ?>">Open</a>
              <?php if ($canManage && $r['records'] === 0): ?>
                <form method="POST" action="api/office-sets.php" data-confirm="<?= e($r['label']) ?> of <?= e($office) ?> holds nothing yet. It will be removed." data-confirm-title="Remove <?= e($r['label']) ?>?" data-confirm-label="Remove" data-confirm-tone="danger"><?= $tok ?>
                  <input type="hidden" name="do" value="remove"><input type="hidden" name="code" value="<?= e($r['code']) ?>">
                  <button type="submit" class="btn btn-ghost os-btn">Remove</button></form>
              <?php endif; ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php /* v96: everyone of the office can add a set (removing one stays with the Department Admin) */ ?>
        <?php if ($next > 0): ?>
          <form method="POST" action="api/office-sets.php" style="margin-top:12px;"><?= $tok ?>
            <input type="hidden" name="do" value="add">
            <button type="submit" class="btn btn-primary" id="addSet" style="padding:8px 16px; font-size:13px;">＋ Add Set <?= $next ?></button></form>
        <?php else: ?>
          <div style="margin-top:12px; font-size:12.5px; color:var(--muted);"><?= e($office) ?> has all <?= OS_MAX ?> sets.</div>
        <?php endif; ?>
    </div>

    <div class="os-card" id="osBudget">
      <h3>Allotment · FY <?= $fy ?></h3>
      <ul class="os-budget">
        <?php $__n = count($family); $__lead = $__n > 1 ? 'All ' . $__n . ' sets together' : 'Set 1';
              $__txt = $__lead . ': APP-CSE ₱' . number_format((float)$bm['appcse'], 2) . ' + Non-PS ₱' . number_format((float)$bm['nonps'], 2)
                     . ' = ₱' . number_format((float)$bm['sum'], 2) . ' (the APP-CSE with its ' . nonps_rate_text(appcse_rate((int)$fy)) . '%, the Non-PS with VAT, logistics and inflation)';
              if ($bm['allot'] === null)          $__txt .= '. The Budget Unit has not set the FY ' . $fy . ' allotment of ' . $office . ' yet.';
              elseif ($bm['state'] === 'equal')   $__txt .= ', exactly the allotment of ₱' . number_format((float)$bm['allot'], 2) . '.';
              elseif ($bm['state'] === 'under')   $__txt .= ', ₱' . number_format(-(float)$bm['diff'], 2) . ' below the allotment of ₱' . number_format((float)$bm['allot'], 2) . '.';
              else                                $__txt .= ', ₱' . number_format((float)$bm['diff'], 2) . ' over the allotment of ₱' . number_format((float)$bm['allot'], 2) . ' — no set can be submitted until it is within.'; ?>
        <li id="osSpend"><?= $bm['state'] === 'over' ? '✗' : ($bm['state'] === 'equal' ? '✓' : '!') ?> <?= e($__txt) ?></li>
        <?php $__all = array_sum(array_column($rows, 'pp')); ?>
        <?php if (!empty($bm['pp'])): ?><li id="osPpmps"><?= $bm['allot'] === null ? '!' : (round($__all * 100) > round((float)$bm['allot'] * 100) ? '✗' : '✓') ?>
          <?= count($family) > 1 ? 'PPMPs of all sets together' : 'PPMP Total Amount' ?>: ₱<?= number_format($__all, 2) ?><?= $bm['allot'] !== null ? ' of ₱' . number_format((float)$bm['allot'], 2) : '' ?>.</li><?php endif; ?>
      </ul>
    </div>
  </main>
</div>
</body>
</html>
