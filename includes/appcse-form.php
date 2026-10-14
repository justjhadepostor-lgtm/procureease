<?php
/* ═══════════════════════════════════════════════════════════════
   The APP-CSE form itself — one copy, used by three pages.

   appcse-print.php         a department's own APP-CSE, for printing
   appcse-print.php?dept=__ALL__   the consolidated one, for printing
   appcse-consolidated.php  the consolidated one, on screen

   It used to exist twice: the real form in appcse-print.php, and a
   plain summary table in appcse-consolidated.php that shared none of
   its headings, none of its quarter columns and none of its totals.
   The Procurement Unit's consolidated copy is an APP-CSE like any
   other, so it is now the same document, filled with the approved
   departments' quantities added together.

   Expects, from the page that includes it:
     $fy       int     the fiscal year this document belongs to
     $months   array   APPCSE_MONTHS
     $grouped  array   [part][category] => rows, from
                       appcse_get_grouped() or appcse_consolidated_grouped()
     $hdr      array   the agency block
   Optional:
     $acConsolidated  bool   true for the Procurement Unit's roll-up
     $acDeptCount     int    how many departments are counted in it

   Every wording on it comes from the Document Format page for $fy, so
   the consolidated copy and a department's copy always read alike.
   =============================================================== */

if (!function_exists('ac_nz')) {
    /** Quantities print as whole numbers when they are whole. */
    function ac_nz($n) { return $n == 0 ? '0' : rtrim(rtrim(number_format($n, 2), '0'), '.'); }
}

$acConsolidated = $acConsolidated ?? false;
$acDeptCount    = $acDeptCount ?? 0;
$acPartTotals   = ['I' => 0.0, 'II' => 0.0];
?>
<style>
  /* Scoped to .appcse-doc so the form looks identical whether it sits on
     a printed page or inside the application shell. */
  .appcse-doc { font-family: Arial, sans-serif; color:#000; font-size:8px; background:#fff; }
  .appcse-doc .form-title { text-align:center; font-size:11px; font-weight:bold; margin:8px 0 6px; }
  .appcse-doc .intro { font-size:7.5px; text-align:center; line-height:1.4; margin:0 auto 8px; max-width:80%; }
  .appcse-doc .intro .lbl { font-weight:bold; }
  .appcse-doc .reminders { font-size:7px; line-height:1.4; margin:10px 0 8px; }
  .appcse-doc .reminders .rem-head { font-style:italic; margin-bottom:3px; }
  .appcse-doc .reminders .rem { display:grid; grid-template-columns:24px 1fr; column-gap:4px; margin-bottom:2px; font-weight:bold; }
  .appcse-doc .reminders .rem a { color:#1155cc; }
  .appcse-doc .note { font-size:7.5px; font-style:italic; margin:8px 0; }
  .appcse-doc .agency-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:3px 20px; font-size:8px; margin-bottom:8px; }
  .appcse-doc .agency-grid > div { display:grid; grid-template-columns:auto 1fr; align-items:center; column-gap:4px; row-gap:2px; }
  .appcse-doc .agency-grid .fld { display:contents; }
  @media screen and (max-width: 900px) { .appcse-doc .agency-grid { grid-template-columns: 1fr; } }   /* v96: a phone */
  .appcse-doc .agency-grid .fld .lb { text-align:right; white-space:nowrap; }
  .appcse-doc .agency-grid .fld .bx { border:1px solid #999; background:#f0f0f0; -webkit-print-color-adjust:exact; print-color-adjust:exact; min-height:11px; padding:0 4px; }
  .appcse-doc table.ac-items { border-collapse:collapse; width:100%; }
  .appcse-doc table.ac-items th, .appcse-doc table.ac-items td { border:1px solid #000; padding:2px 3px; text-align:center; color:#000; }
  .appcse-doc table.ac-items th { background:#e8e8e8; -webkit-print-color-adjust:exact; print-color-adjust:exact; font-size:7px; }
  .appcse-doc table.ac-items td.d { text-align:left; white-space:nowrap; }
  .appcse-doc table.ac-items td.c { text-align:left; font-family:monospace; font-size:6.5px; }
  .appcse-doc table.ac-items tr.part td { background:#133458; color:#fff; -webkit-print-color-adjust:exact; print-color-adjust:exact; text-align:left; font-weight:bold; font-size:9px; }
  .appcse-doc table.ac-items tr.cat td { background:#dce7f0; -webkit-print-color-adjust:exact; print-color-adjust:exact; text-align:left; font-weight:bold; }
  .appcse-doc .ac-scroll { overflow-x:auto; }
  .appcse-doc .ac-sum-doc { font-size:9px; margin-top:12px; }
</style>

<div class="appcse-doc">

  <div class="form-title"><?= doc_nl('appcse', 'form_title', $fy) ?></div>

  <div class="intro">
    <span class="lbl"><?= e(doc_fmt('appcse', 'intro_label')) ?></span> <?= doc_nl('appcse', 'intro_body') ?>
  </div>

  <div class="reminders">
    <div class="rem-head"><?= e(doc_fmt('appcse', 'reminders_label')) ?></div>
    <?php /* v94: the reminders from the Document Format page, numbered as on the form */
      foreach (preg_split('/\r?\n/', str_replace(['{FY-1}', '{FY}'], [(string)($fy - 1), (string)$fy], doc_fmt('appcse', 'reminders_body', (int)$fy))) as $__rl):
        $__rl = trim($__rl); if ($__rl === '') continue;
        $__rn = preg_match('/^(\d+(?:\.\d+)?)\.?\s+(.*)$/s', $__rl, $__rm) ? [$__rm[1], $__rm[2]] : ['', $__rl]; ?>
    <div class="rem"><span><?= e($__rn[0]) ?></span><span><?= e($__rn[1]) ?></span></div>
    <?php endforeach; ?>
  </div>

  <div class="note"><?= doc_nl('appcse', 'note_text', $fy) ?></div>

  <div class="agency-grid">
    <div>
      <div class="fld"><span class="lb">Department/Bureau/Office:</span><span class="bx"><?= e($hdr['department']) ?: '&nbsp;' ?></span></div>
      <div class="fld"><span class="lb">Region:</span><span class="bx"><?= e($hdr['region']) ?: '&nbsp;' ?></span></div>
      <div class="fld"><span class="lb">Address:</span><span class="bx"><?= e($hdr['address']) ?: '&nbsp;' ?></span></div>
    </div>
    <div>
      <div class="fld"><span class="lb">Agency Code/UACS:</span><span class="bx"><?= e($hdr['agency_code']) ?: '&nbsp;' ?></span></div>
      <div class="fld"><span class="lb">Organization Type:</span><span class="bx"><?= e($hdr['org_type']) ?: '&nbsp;' ?></span></div>
    </div>
    <div>
      <div class="fld"><span class="lb">Contact Person:</span><span class="bx"><?= e($hdr['contact_person']) ?: '&nbsp;' ?></span></div>
      <div class="fld"><span class="lb">Position:</span><span class="bx"><?= e($hdr['position']) ?: '&nbsp;' ?></span></div>
      <div class="fld"><span class="lb">E-mail :</span><span class="bx"><?= e($hdr['email']) ?: '&nbsp;' ?></span></div>
      <div class="fld"><span class="lb">Telephone/Mobile Nos:</span><span class="bx"><?= e($hdr['telephone']) ?: '&nbsp;' ?></span></div>
    </div>
  </div>

  <div class="ac-scroll">
  <table class="ac-items">
    <thead>
      <tr>
        <th rowspan="2"><?= doc_nl('appcse', 'col_code') ?></th><th rowspan="2"><?= doc_nl('appcse', 'col_item') ?></th><th rowspan="2"><?= doc_nl('appcse', 'col_uom') ?></th>
        <th colspan="20"><?= doc_nl('appcse', 'col_months') ?></th>
        <th rowspan="2"><?= doc_nl('appcse', 'col_totqty') ?></th><th rowspan="2"><?= doc_nl('appcse', 'col_price') ?></th><th rowspan="2"><?= doc_nl('appcse', 'col_amount') ?></th>
      </tr>
      <tr>
        <th><?= e(doc_fmt('appcse', 'mon_1')) ?></th><th><?= e(doc_fmt('appcse', 'mon_2')) ?></th><th><?= e(doc_fmt('appcse', 'mon_3')) ?></th><th><?= e(doc_fmt('appcse', 'qtr_1')) ?></th><th><?= doc_nl('appcse', 'qamt_1') ?></th>
        <th><?= e(doc_fmt('appcse', 'mon_4')) ?></th><th><?= e(doc_fmt('appcse', 'mon_5')) ?></th><th><?= e(doc_fmt('appcse', 'mon_6')) ?></th><th><?= e(doc_fmt('appcse', 'qtr_2')) ?></th><th><?= doc_nl('appcse', 'qamt_2') ?></th>
        <th><?= e(doc_fmt('appcse', 'mon_7')) ?></th><th><?= e(doc_fmt('appcse', 'mon_8')) ?></th><th><?= e(doc_fmt('appcse', 'mon_9')) ?></th><th><?= e(doc_fmt('appcse', 'qtr_3')) ?></th><th><?= doc_nl('appcse', 'qamt_3') ?></th>
        <th><?= e(doc_fmt('appcse', 'mon_10')) ?></th><th><?= e(doc_fmt('appcse', 'mon_11')) ?></th><th><?= e(doc_fmt('appcse', 'mon_12')) ?></th><th><?= e(doc_fmt('appcse', 'qtr_4')) ?></th><th><?= doc_nl('appcse', 'qamt_4') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach (['I'  => 'PART I. AVAILABLE AT PS-DBM (MAIN WAREHOUSE AND DEPOTS)',
                    'II' => 'PART II. OTHER ITEMS NOT AVAILABLE AT PS-DBM BUT ARE REGULARLY PURCHASED FROM OTHER SOURCES'] as $pk => $pl): ?>
      <tr class="part"><td colspan="26"><?= e($pl) ?></td></tr>
      <?php foreach (($grouped[$pk] ?? []) as $cat => $rows): ?>
        <tr class="cat"><td colspan="26"><?= e($cat) ?></td></tr>
        <?php foreach ($rows as $r):
          $price = (float)$r['unit_price'];
          $qsum = []; $tot = 0;
          for ($q = 0; $q < 4; $q++) {
              $s3 = 0;
              for ($k = 0; $k < 3; $k++) $s3 += (float)($r[$months[$q * 3 + $k]] ?? 0);
              $qsum[$q] = $s3; $tot += $s3;
          }
          $amt = $tot * $price;
          $acPartTotals[$pk] += $amt;
        ?>
          <tr>
            <td class="c"><?= e($r['code']) ?></td>
            <td class="d"><?= e($r['description']) ?></td>
            <td><?= e($r['unit']) ?></td>
            <?php for ($q = 0; $q < 4; $q++): for ($k = 0; $k < 3; $k++): $mv = (float)($r[$months[$q * 3 + $k]] ?? 0); ?>
              <td><?= $mv == 0 ? '0' : ac_nz($mv) ?></td>
            <?php endfor; ?>
              <td><?= ac_nz($qsum[$q]) ?></td>
              <td><?= number_format($qsum[$q] * $price, 2) ?></td>
            <?php endfor; ?>
            <td><?= ac_nz($tot) ?></td>
            <td><?= number_format($price, 2) ?></td>
            <td><?= number_format($amt, 2) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php
    /* Under the table: A to E of each Part, the statement and the three
       signatures — the same block the form on screen shows
       (includes/appcse-summary.php). C. is the department's own; on the
       consolidated copy it is the counted departments' C. added together. */
    require_once __DIR__ . '/../config/price-provision.php';
    $acSum = appcse_summary($acPartTotals, appcse_transport((int)$fy, $acConsolidated ? '__ALL__' : (string)($deptCode ?? '')), (int)$fy);
    $acSumScreen = false; $acSumEditC = false;
    require __DIR__ . '/appcse-summary.php';
  ?>
</div>
