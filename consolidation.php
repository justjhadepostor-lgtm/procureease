<?php
/* ═══════════════════════════════════════════════════════════════
   Non-PS (Consolidated) — the Procurement Unit's copy.

   Laid out like APP-CSE (Consolidated): the departments (counted, with you,
   still with the department) and, below them, one List of Non-PS Supplies
   adding up every Non-PS list the Procurement Unit has approved for the
   active fiscal year (config/nonps-consolidation.php).

   (Until v67 this page was "Consolidation": the APP budget, By procurement
   category, Special Projects and the Non-PS itemized list.)
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/nonps-consolidation.php';
require_once __DIR__ . '/config/ui.php';
require_once __DIR__ . '/config/price-provision.php';   // v80: each counted department's GRAND TOTAL on its chip
require_admin();
ensure_admin_tables();

$fy = (int)($_GET['fy'] ?? active_fy());
if ($fy < 2000 || $fy > 2100) $fy = active_fy();

$groups  = nonps_consol_departments($fy);
$counted = $groups['counted'];
$waiting = $groups['waiting'];
$rest    = $groups['rest'];
$lines   = nonps_consol_items($fy);
$total   = array_sum(array_map(fn($l) => (float)$l['total_cost'], $lines));
$allDepts = count($counted) + count($waiting) + count($rest);

$activeNav = 'consolidation';
$pageTitle = 'Non-PS (Consolidated)';
doc_fmt_use_year($fy);
require __DIR__ . '/includes/head.php';
?>
<style>
  .dept-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 11px; border-radius: 999px; font-size: 11.5px; font-weight: 600;
    border: 1px solid var(--border); background: var(--white); margin: 0 6px 6px 0;
    text-decoration: none; color: var(--ink);
  }
  .dept-chip.in  { background: var(--green-50); border-color: #86efac; color: #14532d; }
  .dept-chip.in:hover { border-color: #15803d; }
  .dept-chip.out { color: var(--muted); }
  .dept-chip.wait { background: var(--navy-50); border-color: var(--navy-500); color: var(--ink); border-style: dashed; }
  .dept-chip.wait:hover { border-style: solid; }
  .dept-chip .amt { font-family: 'Space Grotesk', sans-serif; color: var(--navy-800); }
  .ac-tools { display:flex; align-items:center; gap:10px 14px; flex-wrap:wrap; margin-bottom:10px; }
  .ac-tools .search-field { flex: 0 1 280px; min-width: 0; }
  .ac-group { border-top:1px solid var(--border); padding:10px 0 4px; }
  .ac-tools + .ac-group { border-top:none; }
  .ac-group-head { display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; margin-bottom:8px;
    font-family:'Space Grotesk',sans-serif; font-size:12.5px; font-weight:700; color:var(--ink); }
  .ac-group-head .n { font-size:11px; padding:1px 8px; border-radius:999px; background:var(--navy-100); color:var(--ink); }
  .ac-group-hint { font-family:'Inter',sans-serif; font-size:11.5px; font-weight:500; color:var(--muted); }
  details.ac-group > summary { cursor:pointer; list-style:none; }
  details.ac-group > summary::-webkit-details-marker { display:none; }
  details.ac-group > summary::before { content:'▸'; color:var(--muted); font-size:11px; }
  details.ac-group[open] > summary::before { content:'▾'; }
  details.ac-group[open] .ac-group-hint { visibility:hidden; }
  .dept-chip.is-hidden { display:none; }
  .ac-nomatch { font-size:12.5px; color:var(--muted); padding:6px 0; }
  /* the form, as the departments' own List of Non-PS Supplies */
  .nc-sheet { padding: 32px 40px; background: white; overflow-x: auto; }
  .nc-sheet .lh { display:flex; flex-direction:column; align-items:center; gap:4px; margin-bottom:4px; text-align:center; font-family: Arial, sans-serif; }
  .nc-sheet .lh img { width:72px; height:72px; object-fit:contain; display:block; margin-bottom:4px; }
  .nc-sheet .t { text-align:center; font-family: Arial, sans-serif; }
  .nc-table { width:100%; min-width: 560px; border-collapse:collapse; font-family: Arial, sans-serif; font-size:12px; table-layout: fixed; }
  .nc-table th, .nc-table td { border:1px solid #000; padding:5px 6px; vertical-align: middle; }
  .nc-table th { font-weight:700; text-align:center; background:white; }
  .nc-table td.c { text-align:center; }
  .nc-table td.r { text-align:right; white-space:nowrap; }
  .nc-table .avg { font-size:10px; font-style:italic; }
  .nc-table tfoot td { font-weight:700; }
</style>

<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Non-PS (Consolidated) · FY <?= $fy ?></h1>
        <p class="page-sub">The approved departments' Non-PS Supplies lists, on one form.</p>
      </div>
      <?php if ($counted): ?>
        <a class="btn btn-primary" style="text-decoration:none; white-space:nowrap;" id="printBtn"
           href="nonps-print.php?fy=<?= $fy ?>&dept=__ALL__" target="_blank">Print consolidated</a>
      <?php endif; ?>
    </div>
    <?php render_flashes(); ?>

    <div class="card" style="margin-bottom: 16px;">
      <div class="card-title">Departments</div>
      <?php if (!$allDepts): ?>
        <div style="color: var(--muted); font-size: 13px;">No department has a Non-PS list for FY <?= $fy ?> yet.</div>
      <?php else: ?>
        <div class="ac-tools">
          <?= sort_bar('.chip-set') ?>
          <?php if ($allDepts >= 20): ?>
            <?= search_field(['id' => 'ncDeptSearch', 'placeholder' => 'Find a department…', 'label' => 'Find a department', 'attrs' => SEARCH_NO_DIGITS]) ?>
          <?php endif; ?></div>

        <?php if ($counted): ?>
        <div class="ac-group" id="ncGroupCounted">
          <div class="ac-group-head">Counted <span class="n"><?= count($counted) ?></span>
            <span class="ac-group-hint">approved · on the form below</span></div>
          <span class="chip-set" id="ncCounted">
          <?php foreach ($counted as $d): ?>
            <a class="dept-chip in" target="_blank" data-ts="<?= sort_ts($d['submitted_at']) ?>" href="nonps-print.php?id=<?= (int)$d['id'] ?>">
              <?= e($d['name']) ?> <span class="amt" title="<?= e(doc_fmt('nonps', 'lbl_grand')) ?> — with VAT, logistics and inflation">&#8369;<?= number_format(nonps_provision((float)$d['total'])['grand'], 2) ?></span></a>
          <?php endforeach; ?>
          </span></div>
        <?php endif; ?>

        <?php if ($waiting): ?>
        <div class="ac-group" id="ncGroupWaiting">
          <div class="ac-group-head">With you, waiting for your approval <span class="n"><?= count($waiting) ?></span>
            <span class="ac-group-hint">submitted · not counted yet</span></div>
          <span class="chip-set" id="ncWaiting">
          <?php foreach ($waiting as $d): ?>
            <a class="dept-chip wait" target="_blank" data-ts="<?= sort_ts($d['submitted_at']) ?>" title="Submitted — not counted until you approve it"
               href="nonps-print.php?id=<?= (int)$d['id'] ?>"><?= e($d['name']) ?></a>
          <?php endforeach; ?>
          </span></div>
        <?php endif; ?>

        <?php if ($rest): ?>
        <details class="ac-group" id="ncGroupRest">
          <summary class="ac-group-head">Still with the department <span class="n"><?= count($rest) ?></span>
            <span class="ac-group-hint">draft or sent back · click to show</span></summary>
          <span class="ac-out">
          <?php foreach ($rest as $d): ?>
            <span class="dept-chip out"><?= e($d['name']) ?> · <?= $d['status'] === 'revision' ? 'sent back' : e($d['status']) ?></span>
          <?php endforeach; ?>
          </span></details>
        <?php endif; ?>
        <div class="ac-nomatch" id="ncNoMatch" hidden>No department matches.</div>
        <?php if ($allDepts >= 20): ?>
        <script>
          (function(){
            const box = document.getElementById('ncDeptSearch'); if (!box) return;
            box.addEventListener('input', function(){
              const q = box.value.trim().toLowerCase(); let shown = 0;
              document.querySelectorAll('.ac-group .dept-chip').forEach(function(c){
                const hit = q === '' || c.textContent.toLowerCase().includes(q);
                c.classList.toggle('is-hidden', !hit); if (hit) shown++;
              });
              const r = document.getElementById('ncGroupRest');
              if (r && q !== '' && r.querySelector('.dept-chip:not(.is-hidden)')) r.open = true;
              document.getElementById('ncNoMatch').hidden = shown > 0;
            });
          })();
        </script>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (!$counted): ?>
      <div class="card" style="text-align:center; padding: 56px 24px; color: var(--muted);" id="ncEmpty">
        <div style="font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px;">Nothing to consolidate yet</div>
        <div style="font-size: 13.5px;">A department's items appear on the form here once you approve its Non-PS list.</div>
      </div>
    <?php else: ?>
      <div class="card doc-paper nc-sheet" id="ncSheet">
        <div class="lh">
          <?= doc_logo_pair('nonps', '') ?>
          <div style="font-size: 12px;"><?= e(doc_fmt('nonps', 'rep_line')) ?></div>
          <div style="font-size: 16px; font-weight: 700; margin: 2px 0;"><?= e(doc_fmt('nonps', 'org_name')) ?></div>
          <div style="font-size: 12px;"><?= e(doc_fmt('nonps', 'address')) ?></div></div>
        <div class="t" style="font-weight: 700; font-size: 13px; padding-top: 8px;"><?= e(doc_fmt('nonps', 'unit_line')) ?></div>
        <div class="t" style="font-weight: 700; font-size: 13px; padding-top: 8px;"><?= e(doc_fmt('nonps', 'doc_title')) ?> (Consolidated)</div>
        <div class="t" style="font-size: 12px; padding: 2px 0 18px;">FY <?= $fy ?></div>

        <table class="nc-table" id="ncTable">
          <colgroup><col style="width: 44px;"><col><col style="width: 70px;"><col style="width: 80px;"><col style="width: 110px;"><col style="width: 120px;"></colgroup>
          <thead><tr>
            <th><?= e(doc_fmt('nonps', 'col_no')) ?></th><th><?= e(doc_fmt('nonps', 'col_part')) ?></th><th><?= e(doc_fmt('nonps', 'col_unit')) ?></th>
            <th><?= e(doc_fmt('nonps', 'col_qty')) ?></th><th><?= e(doc_fmt('nonps', 'col_cost')) ?></th><th><?= e(doc_fmt('nonps', 'col_total')) ?></th></tr></thead>
          <tbody>
            <?php $n = 1; foreach ($lines as $l): ?>
              <tr>
                <td class="c"><?= $n++ ?></td>
                <td><?= e($l['particulars']) ?></td>
                <td class="c"><?= e($l['unit']) ?></td>
                <td class="c"><?= nonps_consol_qty((float)$l['quantity']) ?></td>
                <td class="r"><?= number_format((float)$l['unit_cost'], 2) ?><?php if ($l['mixed_price']): ?><div class="avg">avg.</div><?php endif; ?></td>
                <td class="r"><?= number_format((float)$l['total_cost'], 2) ?></td></tr>
            <?php endforeach; ?></tbody>
          <?php require_once __DIR__ . '/config/price-provision.php'; $__np = nonps_provision((float)$total);   // + VAT, logistics, inflation ?>
          <tfoot><tr>
            <td colspan="5" class="c">TOTAL</td>
            <td class="r" id="ncTotal" style="text-decoration: underline;">&#8369;<?= number_format($total, 2) ?></td></tr>
            <?= nonps_charge_rows($__np, 'colspan="5" class="c"', 'class="r"', true) ?>
            <tr><td colspan="5" class="c"><?= e(doc_fmt('nonps', 'lbl_grand')) ?></td>
            <td class="r" id="npGrand" style="text-decoration: underline double;">&#8369;<?= number_format($__np['grand'], 2) ?></td></tr></tfoot>
        </table>
        <div style="font-size: 11.5px; color: var(--muted); margin-top: 10px; font-family: 'Inter', sans-serif;">
          <em>avg.</em> = average unit cost
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>
</body></html>
