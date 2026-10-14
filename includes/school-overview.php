<?php
/* ═══════════════════════════════════════════════════════════════
   My Department, for a school with programs and majors (v81).

   What the school's Department Admin needs to submit the whole school:
     • which programs / majors have NOT been submitted yet, and a pie chart
       of where every one of them stands
     • each one's documents (✓ / ✗) and review — Approve, or Return with a note
     • the allotment: the school's, and the programs' shares (optional)
     • "Submit whole school", with what still stops it
   Everyone else of the school sees the same, without the buttons.

   Set before including: $u (current_user()), $fy.
   =============================================================== */
require_once __DIR__ . '/../config/school-submit.php';

$soSchool = (string)($u['school_code'] ?? '');
$soName   = (string)($u['school_name'] ?? $soSchool);
$soDean   = ($u['role'] ?? '') === 'dept_admin';
$soHead   = dept_admin_of($soSchool);
if ($soDean && $soHead !== null && (int)$soHead['id'] !== (int)$u['id']) $soDean = false;
$soCheck  = school_submit_check($soSchool, (int)$fy);
$soRows   = $soCheck['units'];

/* ─── Where each unit stands (the pie) ───────────────────────── */
$SO_STATES = [   // key => label, colour (3:1 or more against the card), what it means
    'submitted'  => ['Submitted',           'var(--so-c-sent)', 'Sent to the Budget Unit and the Procurement Unit'],
    'approved'   => ['Approved, not sent',  'var(--so-c-ok)', 'Approved — goes with the whole school'],
    'for_review' => ['For review',          'var(--so-c-rev)', 'Waiting for your review'],
    'returned'   => ['Returned',            'var(--so-c-back)', 'Sent back to them with a note'],
    'draft'      => ['Draft',               'var(--so-c-draft)', 'Still being prepared — not sent for review yet'],
];
$soCount = array_fill_keys(array_keys($SO_STATES), 0);
foreach ($soRows as $r) $soCount[$r['status']] = ($soCount[$r['status']] ?? 0) + 1;
$soTotal = count($soRows);
$soNotYet = array_filter($soRows, fn($r) => $r['status'] !== 'submitted');
// conic-gradient stops
$soStops = []; $acc = 0.0;
foreach ($SO_STATES as $k => [$lbl, $col]) {
    if (!$soCount[$k] || !$soTotal) continue;
    $from = $acc; $acc += $soCount[$k] / $soTotal * 100;
    $soStops[] = $col . ' ' . round($from, 3) . '% ' . round($acc, 3) . '%';
}
$soGradient = $soStops ? implode(', ', $soStops) : 'var(--navy-50) 0 100%';
$soPct = $soTotal ? (int)round($soCount['submitted'] / $soTotal * 100) : 0;

/* ─── The allotment and the programs' shares ─────────────────── */
$soAllot  = allot_school($soSchool, (int)$fy);
$soShares = allot_shares($soSchool, (int)$fy);
$soProgs  = au_programs_of($soSchool);
$soUse = [];   // program => [units, spend (APP-CSE + Non-PS), ppmp]
foreach ($soProgs as $pc => $pn) {
    $codes = array_keys(au_units_of_program($soSchool, $pc));
    $sp = 0; $pp = 0;
    foreach ($codes as $c) {
        $m = ppmp_budget_match_own($c, (int)$fy);
        $sp += pp_cents($m['appcse']) + pp_cents($m['nonps']);
        $pp += pp_cents((float)$m['pp']['ppmp']);
    }
    $soUse[$pc] = ['units' => count($codes), 'spend' => $sp / 100, 'ppmp' => $pp / 100];
}
$soSpendAll = array_sum(array_column($soUse, 'spend'));
$soPpmpAll  = array_sum(array_column($soUse, 'ppmp'));
$soPeso = fn(float $v) => '₱' . number_format($v, 2);
$soTok  = function_exists('csrf_field') ? csrf_field() : '';
?>
<?= ur_css() ?>
<style>
  /* the pie's colours: 3:1 or more against the card, light and dark */
  .so-card { --so-c-sent:#1e3a8a; --so-c-ok:#15803d; --so-c-rev:#b45309; --so-c-back:#b91c1c; --so-c-draft:#64748b; }
  body[data-theme="dark"] .so-card { --so-c-sent:#93c5fd; --so-c-ok:#4ade80; --so-c-rev:#fbbf24; --so-c-back:#fca5a5; --so-c-draft:#cbd5e1; }
  .so-grid { display:grid; grid-template-columns: minmax(260px, 340px) minmax(0, 1fr); gap:16px; margin-bottom:18px; }
  @media (max-width: 900px) { .so-grid { grid-template-columns: minmax(0, 1fr); } }
  .so-card { background:var(--white); border:1px solid var(--border); border-radius:14px; padding:18px; min-width:0; }
  .so-card h3 { margin:0 0 4px; font-family:'Space Grotesk',sans-serif; font-size:15px; color:var(--ink); }
  .so-card .so-sub { font-size:12px; color:var(--muted); margin-bottom:12px; line-height:1.5; }
  .so-pie-wrap { display:flex; gap:18px; align-items:center; flex-wrap:wrap; }
  .so-pie { width:150px; height:150px; border-radius:50%; position:relative; flex:0 0 auto; }
  .so-pie::after { content:''; position:absolute; inset:28px; border-radius:50%; background:var(--white); }
  .so-pie-mid { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; z-index:1; text-align:center; }
  .so-pie-mid b { font-family:'Space Grotesk',sans-serif; font-size:24px; color:var(--ink); line-height:1; }
  .so-pie-mid span { font-size:11px; color:var(--muted); margin-top:3px; }
  .so-legend { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--ink); min-width:0; }
  .so-legend li { display:flex; align-items:center; gap:8px; }
  .so-legend i { width:12px; height:12px; border-radius:3px; flex:0 0 auto; }
  .so-legend b { font-family:'Space Grotesk',sans-serif; margin-left:auto; padding-left:10px; }
  .so-notyet { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; }
  .so-notyet li { display:flex; gap:10px; align-items:center; flex-wrap:wrap; padding:8px 10px; border:1px solid var(--border); border-radius:10px; font-size:12.5px; color:var(--ink); }
  .so-notyet .so-why { flex:1 1 200px; color:var(--muted); font-size:12px; }
  .so-table-wrap { overflow-x:auto; }
  .so-table { width:100%; border-collapse:collapse; font-size:12.5px; color:var(--ink); }
  .so-table th, .so-table td { padding:9px 10px; border-bottom:1px solid var(--border); text-align:left; vertical-align:top; }
  .so-table th { font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
  .so-table td.n { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .so-table tr.so-prog td { background:var(--navy-50); font-weight:700; font-family:'Space Grotesk',sans-serif; }
  .so-docs { display:flex; gap:4px; flex-wrap:wrap; }
  .so-doc { display:inline-block; padding:1px 7px; border-radius:6px; font-size:11px; font-weight:700; border:1px solid; }
  .so-doc.ok { color:#14532d; background:#ecfdf3; border-color:#86efac; }
  .so-doc.miss { color:#7c2d12; background:#FFF4EC; border-color:#fdba74; }
  .so-acts { display:flex; gap:6px; flex-wrap:wrap; align-items:flex-start; }
  .so-acts form { margin:0; }
  .so-acts details > summary { list-style:none; cursor:pointer; }
  .so-acts details > summary::-webkit-details-marker { display:none; }
  .so-acts details[open] form { margin-top:6px; display:flex; flex-direction:column; gap:6px; min-width:220px; }
  .so-acts textarea { width:100%; min-height:60px; font:inherit; font-size:12.5px; padding:6px 8px; border:1px solid var(--border); border-radius:8px; background:var(--white); color:var(--ink); }
  .so-btn { padding:5px 11px !important; font-size:12px !important; text-decoration:none; }
  #schoolSubmitBtn:disabled { opacity:.55; cursor:not-allowed; box-shadow:none; }
  .so-problems { margin:8px 0 0; padding-left:18px; font-size:12.5px; color:#7c2d12; line-height:1.55; }
  body[data-theme="dark"] .so-problems { color:#fde2cf; }
  .so-sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
  .so-share input { width:140px; padding:5px 8px; border:1px solid var(--border); border-radius:8px; font:inherit; font-size:12.5px; text-align:right; background:var(--white); color:var(--ink); }
  body[data-theme="dark"] .so-doc.ok { background:#123222; color:#c9f2d7; border-color:#2f7a4c; }
  body[data-theme="dark"] .so-doc.miss { background:#3a2414; color:#fde2cf; border-color:#9a5a2c; }
</style>

<div class="section-head">
  <div><h2 class="section-title"><?= e($soName) ?> (<?= e($soSchool) ?>) — programs &amp; majors</h2>
</div>
</div>

<div class="so-grid">
  <!-- Where every unit stands -->
  <div class="so-card" id="soPie">
    <h3>Submitted so far</h3>
    <div class="so-sub"><?= $soCount['submitted'] ?> of <?= $soTotal ?> programs/majors submitted for FY <?= (int)$fy ?>.</div>
    <div class="so-pie-wrap">
      <div class="so-pie" style="background: conic-gradient(<?= $soGradient ?>);" role="img"
           aria-label="FY <?= (int)$fy ?>: <?php $__p = []; foreach ($SO_STATES as $k => $s) if ($soCount[$k]) $__p[] = $soCount[$k] . ' ' . strtolower($s[0]); echo e(implode(', ', $__p) ?: 'no programs'); ?>.">
        <div class="so-pie-mid"><b><?= $soPct ?>%</b><span>submitted</span></div></div>
      <ul class="so-legend">
        <?php foreach ($SO_STATES as $k => [$lbl, $col, $what]): ?>
          <li data-state="<?= e($k) ?>" title="<?= e($what) ?>"><i style="background:<?= $col ?>;"></i><?= e($lbl) ?><b><?= $soCount[$k] ?></b></li>
        <?php endforeach; ?></ul></div>
  </div>

  <!-- Not submitted yet -->
  <div class="so-card" id="soNotYet">
    <h3>Not submitted yet (<?= count($soNotYet) ?>)</h3>
    <?php if (!$soNotYet): ?>
      <div style="font-size:13px; color:#14532d; font-weight:600;">✓ Every program and major has been submitted.</div>
    <?php else: ?>
      <ul class="so-notyet">
        <?php foreach ($soNotYet as $r):
          $need = [];
          if (!$r['ppmp']) $need[] = 'PPMP';
          foreach ($r['set']['items'] as $i) if (!$i['ok']) $need[] = $i['label'];
        ?>
          <li data-unit="<?= e($r['code']) ?>"><b><?= e($r['label']) ?></b> <?= ur_chip($r['status']) ?>
            <span class="so-why"><?= $need ? 'Still needs: ' . e(implode(', ', $need)) : ($r['status'] === 'approved' ? 'Complete and approved — ready to go' : 'Complete — waiting for approval') ?></span></li>
        <?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
</div>

<!-- Each unit: its documents and its review -->
<div class="so-card" id="soUnits" style="margin-bottom:18px;">
  <h3>Programs &amp; majors · FY <?= (int)$fy ?></h3>
  <div class="so-table-wrap"><table class="so-table">
    <thead><tr><th>Program / major</th><th>Documents</th><th class="n">PPMP Total Amount</th><th>Review</th><th></th></tr></thead>
    <tbody>
    <?php $__prog = null; foreach ($soRows as $r):
      if ($r['program'] !== $__prog): $__prog = $r['program']; ?>
        <tr class="so-prog"><td colspan="5"><?= e($__prog . ' – ' . ($soProgs[$__prog] ?? '')) ?></td></tr>
      <?php endif;
      $ppTot = 0.0;
      if ($r['ppmp']) { $__t = allot_ppmp_totals([$r['code']], (int)$fy); $ppTot = (float)($__t[$r['code']] ?? 0); }
      $rv = $r['review'];
    ?>
      <tr id="unit-<?= e($r['code']) ?>" data-unit="<?= e($r['code']) ?>" data-status="<?= e($r['status']) ?>">
        <td><?= e($r['major'] !== '' ? $r['major'] : $r['program']) ?><div style="font-size:11px; color:var(--muted);"><?= e($r['code']) ?></div></td>
        <td><div class="so-docs">
          <span class="so-doc <?= $r['ppmp'] ? 'ok' : 'miss' ?>"><?= $r['ppmp'] ? '✓' : '✗' ?> PPMP</span>
          <?php foreach ($r['set']['items'] as $i): ?>
            <span class="so-doc <?= $i['ok'] ? 'ok' : 'miss' ?>" title="<?= e($i['note']) ?>"><?= $i['ok'] ? '✓' : '✗' ?> <?= e($i['label']) ?></span>
          <?php endforeach; ?></div></td>
        <td class="n"><?= $r['ppmp'] ? $soPeso($ppTot) : '—' ?></td>
        <td><?= ur_chip($r['status']) ?>
          <?php if ($r['status'] === 'for_review' && !empty($rv['requested_by'])): ?><div style="font-size:11px; color:var(--muted); margin-top:3px;">by <?= e((string)$rv['requested_by']) ?></div><?php endif; ?>
          <?php if (!empty($rv['note']) && $r['status'] !== 'submitted'): ?><div style="font-size:11.5px; color:var(--muted); margin-top:3px; max-width:260px;"><?= e((string)$rv['note']) ?></div><?php endif; ?></td>
        <td><div class="so-acts">
          <a class="btn btn-ghost so-btn" href="ppmp-edit.php?fy=<?= (int)$fy ?>&amp;unit=<?= e(rawurlencode($r['code'])) ?>">Open</a>
          <?php if ($soDean && $r['status'] !== 'submitted'): ?>
            <?php if ($r['status'] !== 'approved' && $r['complete']): /* only a complete set can be approved */ ?>
            <form method="POST" action="api/unit-review.php"><?= $soTok ?>
              <input type="hidden" name="unit" value="<?= e($r['code']) ?>"><input type="hidden" name="fy" value="<?= (int)$fy ?>">
              <input type="hidden" name="back" value="dept-tracking.php?fy=<?= (int)$fy ?>"><input type="hidden" name="do" value="approve">
              <button type="submit" class="btn btn-primary so-btn">✓ Approve</button></form>
            <?php endif; ?>
            <details><summary class="btn btn-ghost so-btn">↩ Return</summary>
              <form method="POST" action="api/unit-review.php"><?= $soTok ?>
                <input type="hidden" name="unit" value="<?= e($r['code']) ?>"><input type="hidden" name="fy" value="<?= (int)$fy ?>">
                <input type="hidden" name="back" value="dept-tracking.php?fy=<?= (int)$fy ?>">
                <label style="font-size:11.5px; color:var(--muted);" for="ret-<?= e($r['code']) ?>">What has to be changed</label>
                <textarea id="ret-<?= e($r['code']) ?>" name="note" required maxlength="1000"></textarea><input type="hidden" name="do" value="return">
                <button type="submit" class="btn btn-ghost so-btn">Return with this note</button></form></details>
          <?php endif; ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<!-- The allotment -->
<div class="so-card" id="soAllot" style="margin-bottom:18px;">
  <h3>Allotment · FY <?= (int)$fy ?></h3>
  <div class="so-sub">
    <?php if ($soAllot === null): ?>
      The Budget Unit has not set the FY <?= (int)$fy ?> allotment of <?= e($soSchool) ?> yet.
    <?php else: ?>
      Allotted to <?= e($soSchool) ?>: <b><?= $soPeso($soAllot) ?></b>
    <?php endif; ?>
  </div>
  <form method="POST" action="api/save-allot-shares.php" class="so-share"><?= $soTok ?>
    <input type="hidden" name="fy" value="<?= (int)$fy ?>">
    <div class="so-table-wrap"><table class="so-table">
      <thead><tr><th>Program</th><th class="n">Units</th><th class="n">Share</th><th class="n">APP-CSE + Non-PS</th><th class="n">PPMPs</th></tr></thead>
      <tbody>
      <?php foreach ($soProgs as $pc => $pn): $use = $soUse[$pc]; $has = array_key_exists($pc, $soShares); ?>
        <tr data-program="<?= e($pc) ?>">
          <td><b><?= e($pc) ?></b> – <?= e($pn) ?></td>
          <td class="n"><?= (int)$use['units'] ?></td>
          <td class="n"><?php if ($soDean && $soAllot !== null): ?>
              <label class="so-sr" for="share-<?= e($pc) ?>">Share of <?= e($pc) ?></label>
              <input id="share-<?= e($pc) ?>" name="share[<?= e($pc) ?>]" inputmode="decimal" placeholder="no share"
                     value="<?= $has ? number_format($soShares[$pc], 2, '.', '') : '' ?>">
            <?php else: ?><?= $has ? $soPeso($soShares[$pc]) : '—' ?><?php endif; ?></td>
          <td class="n"><?= $soPeso($use['spend']) ?></td>
          <td class="n"><?= $soPeso($use['ppmp']) ?></td>
        </tr>
      <?php endforeach; ?>
        <tr class="so-prog"><td>All of <?= e($soSchool) ?></td><td class="n"><?= $soTotal ?></td>
          <td class="n"><?= $soAllot !== null ? $soPeso($soAllot) : 'not set' ?></td>
          <td class="n"><?= $soPeso($soSpendAll) ?></td><td class="n"><?= $soPeso($soPpmpAll) ?></td></tr>
      </tbody></table></div>
    <?php if ($soDean && $soAllot !== null): ?>
      <div style="margin-top:10px;"><button type="submit" class="btn btn-ghost" style="padding:7px 14px; font-size:12.5px;">💾 Save shares</button></div>
    <?php endif; ?>
  </form>
  <?php if ($soCheck['budget']): ?>
    <ul class="so-problems" style="color:var(--ink);">
      <?php foreach ($soCheck['budget'] as $lbl => $bm): ?>
        <li><?= $bm['state'] === 'over' || !empty($bm['pool_ppmp_over']) ? '✗' : ($bm['state'] === 'equal' ? '✓' : '!') ?> <?= e($bm['note']) ?></li>
      <?php endforeach; ?></ul>
  <?php endif; ?>
</div>

<!-- Submit the whole school -->
<div class="so-card" id="schoolSubmit" style="margin-bottom:26px; border-left:4px solid var(--navy-700);">
  <h3>Submit the whole <?= e($soSchool) ?></h3>
  <?php if ($soCheck['done']): ?><div class="so-sub"><?= count($soCheck['done']) ?> already submitted.</div><?php endif; ?>
  <?php if ($soCheck['problems']): ?>
    <div style="font-size:12.5px; font-weight:700; color:var(--ink);">Not ready yet:</div>
    <ul class="so-problems" id="schoolSubmitProblems"><?php foreach ($soCheck['problems'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if ($soDean): ?>
    <form method="POST" action="api/submit-school.php" style="margin-top:12px;"
          data-confirm="All <?= count($soCheck['send']) ?> program(s)/major(s) of <?= e($soSchool) ?> will be sent at once: the PPMPs to the Budget Unit, the rest to the Procurement Unit."
          data-confirm-title="Submit the whole <?= e($soSchool) ?>?" data-confirm-label="Submit" data-confirm-tone="info"><?= $soTok ?>
      <input type="hidden" name="fy" value="<?= (int)$fy ?>">
      <button type="submit" class="btn btn-primary" id="schoolSubmitBtn" style="padding:9px 20px; font-size:13px;"<?= $soCheck['ready'] ? '' : ' disabled' ?>>📤 Submit whole school</button></form>
  <?php elseif ($soHead === null): ?>
    <div style="font-size:12.5px; color:var(--muted); margin-top:8px;"><?= e($soSchool) ?> has no Department Admin yet.</div>
  <?php else: ?>
    <div style="font-size:12.5px; color:var(--muted); margin-top:8px;"><?= e((string)$soHead['full_name']) ?>, the Department Admin, submits the whole school.</div>
  <?php endif; ?>
</div>
