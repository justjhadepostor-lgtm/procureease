<?php
/* ═══════════════════════════════════════════════════════════════
   Under the APP-CSE table, as on the PS-DBM form: for Part I and Part II,
     A. TOTAL · B. 10% of A · C. transport and freight (if applicable)
     D. GRAND TOTAL (A + B + C) · E. approved budget, in figures and words
   then the statement and the three signatures.

   One copy for the printed form (includes/appcse-form.php) and the form
   on screen (includes/appcse-screen.php). Every wording comes from the
   Document Format page; the figures from config/price-provision.php.

   Expects:
     $fy          int
     $acSum       array   appcse_summary()
     $acSumScreen bool    on screen (live totals, id="acFoot")
     $acSumEditC  bool    C. can be typed (a department's own, open APP-CSE)
   =============================================================== */
require_once __DIR__ . '/../config/price-provision.php';
$acSumScreen = $acSumScreen ?? false;
$acSumEditC  = $acSumEditC ?? false;

/* The three signatures. The middle one is the Budget Officer, so it is
   taken from that account unless the Document Format page names someone
   for the year. The other two are offices with no account in the system
   — the Property/Supply Officer and the Head of the Agency — so they are
   set on that page and stay blank until they are. */
$__acBudget = budget_officer();
if (($__acBudget['position'] ?? '') === '') $__acBudget['position'] = 'Accountant / Budget Officer';
$__acSigs = [
    ['sig1_label', 'sig1_name', 'sig1_position', ['name' => '', 'position' => 'Property/Supply Officer'], true],
    ['sig2_label', 'sig2_name', 'sig2_position', $__acBudget, false],
    ['sig3_label', 'sig3_name', 'sig3_position', ['name' => '', 'position' => 'Head of Office/Agency'], false],
];
$__pesoCell = function (float $v, string $f): string {
    return '<td class="n" data-f="' . $f . '"><div class="nv"><span class="cur">&#8369;</span><span class="v">'
         . number_format($v, 2) . '</span></div></td>';
};
?>
<style>
  .ac-sum-doc { font-family: Arial, sans-serif; color: #000; }
  .ac-sum-doc.is-screen { font-size: 12px; }
  .ac-sum-doc.is-screen table.ac-sum col.c-lb { width: 40%; }
  .ac-sum-doc table.ac-sum { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 0 0 0.9em; }
  .ac-sum-doc table.ac-sum col.c-lb { width: 30%; }
  .ac-sum-doc table.ac-sum col.c-n { width: 22%; }
  .ac-sum-doc table.ac-sum td { border: 1px solid #000; padding: 0.4em 0.55em; color: #000; font-weight: 700; text-align: left;
                                vertical-align: middle; background: #8EA9DB; line-height: 1.3;
                                -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .ac-sum-doc table.ac-sum tr.hd td { background: #FFE699; }
  .ac-sum-doc table.ac-sum tr.hd .sub { font-weight: 400; font-size: 0.86em; margin-top: 0.35em; }
  .ac-sum-doc table.ac-sum td.n .nv { display: flex; align-items: center; justify-content: space-between; gap: 0.5em; }
  .ac-sum-doc table.ac-sum td.n .v { font-variant-numeric: tabular-nums; white-space: nowrap; }
  .ac-sum-doc table.ac-sum td.n input.ac-c { flex: 1 1 auto; min-width: 0; width: 100%; box-sizing: border-box; text-align: right;
                                font: inherit; color: #000; background: #fff; border: 1px solid #44619a; border-radius: 3px; padding: 0.15em 0.4em; }
  .ac-sum-doc table.ac-sum td.n input.ac-c:focus { outline: 2px solid #1c4c86; outline-offset: 1px; }
  .ac-sum-doc table.ac-sum td.n input.ac-c::placeholder { color: #4b5563; opacity: 1; }
  /* the form stays paper-white in dark mode, and so does this box on it */
  body[data-theme="dark"] .ac-sum-doc table.ac-sum td.n input.ac-c { background: #fff !important; color: #000 !important; border-color: #44619a !important; }
  body[data-theme="dark"] .ac-sum-doc table.ac-sum td.n input.ac-c::placeholder { color: #4b5563 !important; }
  .ac-sum-doc table.ac-sum td.ew { overflow-wrap: anywhere; }
  .ac-sum-doc .ac-warrant { font-weight: 700; margin: 0.5em 0 1.4em; line-height: 1.4; }
  .ac-sum-doc .ac-sigs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.2em 2.4em; }
  .ac-sum-doc .ac-sigs .sg-lbl { font-weight: 700; margin-bottom: 0.4em; min-height: 1.3em; }
  .ac-sum-doc .ac-sigs .sg-box { background: #f2f2f2; min-height: 3.6em; border-bottom: 1px solid #000; display: flex;
                                 align-items: flex-end; justify-content: center; padding: 0.25em 0.4em;
                                 -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .ac-sum-doc .ac-sigs .sg-nm { font-weight: 700; text-align: center; }
  .ac-sum-doc .ac-sigs input.ac-sig-edit { width:100%; box-sizing:border-box; border:1px solid #44619a; border-radius:3px; padding:3px 4px; text-align:center; font:inherit; background:#fff; color:#000; }
  .ac-sum-doc .ac-sigs input.ac-sig-edit:focus { outline:2px solid #1c4c86; outline-offset:1px; }
  .ac-sum-doc .ac-sigs .sg-pos { font-weight: 700; text-align: center; margin-top: 0.3em; }
  .ac-sum-doc .ac-sigs .sg-date { display: flex; align-items: flex-end; gap: 0.5em; font-weight: 700; margin-top: 1.3em; }
  .ac-sum-doc .ac-sigs .sg-date .ln { flex: 1 1 auto; border-bottom: 1px solid #000; min-height: 1.2em; }
  @media (max-width: 760px) { .ac-sum-doc.is-screen .ac-sigs { grid-template-columns: 1fr; } }
</style>
<div class="ac-sum-doc<?= $acSumScreen ? ' is-screen' : '' ?>"<?= $acSumScreen ? ' id="acSum"' : '' ?>>
  <?php foreach (['I' => ['sum_part1_title', ''], 'II' => ['sum_part2_title', 'sum_part2_note']] as $__pk => [$__tKey, $__nKey]):
    $__p = $acSum[$__pk];
    $__overrideKey = $__pk === 'I' ? 'override_d_1' : 'override_d_2';
    $__overrideRaw = $hdr[$__overrideKey] ?? null;
    if ($__overrideRaw !== null && $__overrideRaw !== '') { $__p['d'] = (float)$__overrideRaw; $__p['e'] = pp_figures_words($__p['d']); } ?>
  <table class="ac-sum" data-part="<?= $__pk ?>">
    <colgroup><col class="c-lb"><col class="c-sp"><col class="c-n"></colgroup>
    <tr class="hd"><td colspan="3"><div class="t"><?= doc_nl('appcse', $__tKey, $fy) ?></div><?php if ($__nKey !== ''): ?><div class="sub"><?= doc_nl('appcse', $__nKey, $fy) ?></div><?php endif; ?></td></tr>
    <tr><td class="lb"><?= doc_nl('appcse', 'sum_a', $fy) ?></td><td class="sp"></td><?= $__pesoCell($__p['a'], 'a') ?></tr>
    <tr><td class="lb"><?= doc_nl('appcse', 'sum_b', $fy) ?></td><td class="sp"></td><?= $__pesoCell($__p['b'], 'b') ?></tr>
    <tr><td class="lb"><?= doc_nl('appcse', 'sum_c', $fy) ?></td><td class="sp"></td><?php if ($acSumEditC): ?><td class="n" data-f="c"><div class="nv"><span class="cur">&#8369;</span><input class="ac-c money" data-c="<?= $__pk ?>" type="text" inputmode="decimal" maxlength="20" placeholder="0.00" aria-label="Part <?= $__pk ?>: transport and freight cost, if applicable" value="<?= $__p['c'] > 0 ? number_format($__p['c'], 2, '.', '') : '' ?>" oninput="recalcGrand()"></div></td><?php else: ?><?= str_replace('<td class="n" data-f="c"', '<td class="n" data-f="c" data-c="' . number_format($__p['c'], 2, '.', '') . '"', $__pesoCell($__p['c'], 'c')) ?><?php endif; ?></tr>
    <tr><td class="lb"><?= doc_nl('appcse', 'sum_d', $fy) ?></td><td class="sp"></td><?php if ($acSumScreen && !$acReadOnly && empty($acConsolEdit)): ?><td class="n" data-f="d"><div class="nv"><span class="cur">&#8369;</span><input class="ac-c ac-d-override money" data-d="<?= $__pk ?>" type="text" inputmode="decimal" maxlength="20" placeholder="Auto total (edit if needed)" aria-label="Part <?= $__pk ?>: editable grand total" value="<?= $__overrideRaw !== null && $__overrideRaw !== '' ? number_format((float)$__overrideRaw, 2, '.', '') : '' ?>" oninput="recalcGrand()"></div></td><?php else: ?><?= $__pesoCell($__p['d'], 'd') ?><?php endif; ?></tr>
    <tr><td class="lb"><?= doc_nl('appcse', 'sum_e', $fy) ?></td><td class="ew" colspan="2" data-f="e"><?= e(pp_figures_words($__p['d'])) ?></td></tr>
  </table>
  <?php endforeach; ?>

  <?php
    $__acItemCount = 0;
    foreach (($grouped ?? []) as $__countPart) foreach ($__countPart as $__countRows) $__acItemCount += count($__countRows);
    $__acSavedItemCount = isset($hdr['total_items']) && $hdr['total_items'] !== null && $hdr['total_items'] !== '' ? (int)$hdr['total_items'] : $__acItemCount;
  ?>
  <div style="margin:0 0 1em;font-weight:700;display:flex;gap:8px;align-items:center;">
    <span>Total number of items:</span>
    <?php if ($acSumScreen && !$acReadOnly && empty($acConsolEdit)): ?>
      <input id="h_total_items" type="number" min="0" max="99999" step="1" value="<?= $__acSavedItemCount ?>" aria-label="Total number of items" style="width:110px;border:1px solid #44619a;border-radius:3px;padding:4px 6px;font:inherit;background:#fff;color:#000;">
    <?php else: ?><span><?= $__acSavedItemCount ?></span><?php endif; ?>
  </div>
  <div class="ac-warrant"><?= doc_nl('appcse', 'sum_warrant', $fy) ?></div>

  <div class="ac-sigs">
    <?php foreach ($__acSigs as [$__lKey, $__nmKey, $__posKey, $__live, $__date]):
      $__nm  = trim((string)($hdr[$__nmKey] ?? '')) !== '' ? (string)$hdr[$__nmKey] : doc_signatory('appcse', $__nmKey, $__live, 'name');
      $__posDefault = doc_fmt('appcse', $__posKey, $fy) !== '' ? doc_fmt('appcse', $__posKey, $fy) : doc_signatory('appcse', $__posKey, $__live, 'position');
      $__pos = trim((string)($hdr[$__posKey] ?? '')) !== '' ? (string)$hdr[$__posKey] : $__posDefault;
      $__editableSig = $acSumScreen && empty($acReadOnly) && empty($acConsolEdit);
      $__inputStyle = 'class="ac-sig-edit" maxlength="180"'; ?>
      <div class="sg">
        <div class="sg-lbl"><?= e(doc_fmt('appcse', $__lKey)) ?></div>
        <div class="sg-box"><span class="sg-nm"><?php if ($__editableSig): ?><input <?= $__inputStyle ?> data-appcse-header="<?= e($__nmKey) ?>" aria-label="<?= e(doc_fmt('appcse', $__lKey) . ' name') ?>" value="<?= e($__nm) ?>" placeholder="Enter name"><?php else: ?><?= $__nm !== '' ? e($__nm) : '&nbsp;' ?><?php endif; ?></span></div>
        <div class="sg-pos"><?php if ($__editableSig): ?><input <?= $__inputStyle ?> data-appcse-header="<?= e($__posKey) ?>" aria-label="<?= e(doc_fmt('appcse', $__lKey) . ' position') ?>" value="<?= e($__pos) ?>" placeholder="Enter position"><?php else: ?><?= e($__pos) ?><?php endif; ?></div>
        <?php if ($__date): ?><div class="sg-date"><span><?= e(doc_fmt('appcse', 'sig_date_label')) ?></span><span class="ln"><?php $__dateVal = trim((string)($hdr['sig_date_value'] ?? '')) !== '' ? (string)$hdr['sig_date_value'] : doc_fmt('appcse', 'sig_date_value', $fy); ?><?php if ($__editableSig): ?><input class="ac-sig-edit" data-appcse-header="sig_date_value" maxlength="80" aria-label="Date Prepared" value="<?= e($__dateVal) ?>" placeholder="Enter date"><?php else: ?><?= e($__dateVal) ?><?php endif; ?></span></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
