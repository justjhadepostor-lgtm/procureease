<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/annex-extra.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_login();
ensure_admin_tables();

$u   = current_user();
$pdo = db();
$fy  = active_fy();
$deptCode = $u['dept_code'] ?? '';



$formId = (int)($_GET['id'] ?? 0);
$form = null;
if ($formId > 0) {
    try {
        $s = $pdo->prepare('SELECT * FROM annex_forms WHERE id = ?');
        $s->execute([$formId]);
        $form = $s->fetch();
    } catch (Throwable $t) {}
    if ($form) { $fy = (int)$form['fy']; }
} else {
    try {
        $s = $pdo->prepare('SELECT * FROM annex_forms WHERE fy = ? AND dept_code = ? ORDER BY id DESC LIMIT 1');
        $s->execute([$fy, $deptCode]);
        $form = $s->fetch();
    } catch (Throwable $t) {}
}

$items = [];
if ($form) {
    try {
        $it = $pdo->prepare('SELECT * FROM annex_items WHERE form_id = ? ORDER BY sort_order, id');
        $it->execute([$form['id']]);
        $items = $it->fetchAll();
    } catch (Throwable $t) {}
}
$f = fn($k) => e((string)($form[$k] ?? ''));

// The standard wording for each annex letter, used when a form was saved
// before these fields existed (or left blank by the unit).
$ANNEX_TYPES = [
    'A' => ['subtitle' => 'Goods',                                   'note' => 'Can be used for Item A.1.'],
    'B' => ['subtitle' => 'Training, Seminars and Other Activities', 'note' => 'Can be used for Item B.1.'],
    'C' => ['subtitle' => 'Equipment and Other Capital Outlay',      'note' => 'Can be used for Item C.1.'],
    // v99: Annex D and E — no standard title; the unit types it on the form
    'D' => ['subtitle' => '', 'note' => ''],
    'E' => ['subtitle' => '', 'note' => ''],
];
$annexLetter = $form['annex'] ?? 'B';
if (!isset($ANNEX_TYPES[$annexLetter])) $annexLetter = 'B';

$subtitle = trim((string)($form['subtitle'] ?? ''));
if ($subtitle === '') $subtitle = $ANNEX_TYPES[$annexLetter]['subtitle'];

$itemNote = trim((string)($form['item_note'] ?? ''));
if ($itemNote === '') $itemNote = $ANNEX_TYPES[$annexLetter]['note'];

$total = 0;
foreach ($items as $r) $total += (float)$r['total_amount'];
// Total Estimated Budget = NEP Account #1 + #2 (config/annex-extra.php), as on the form
$estBudget = annex_estimated($form);
// This document renders with the format of its own fiscal year,
// not today's, so a filed document keeps the form it was filed under.
doc_fmt_use_year((int)($form['fy'] ?? 0));
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Annex — FY <?= $fy ?></title>
<style>
  @page { size: 8.5in 13in; margin: 12mm; }
  * { box-sizing:border-box; }
  body { font-family: Arial, sans-serif; color:#000; margin:0; padding:20px; font-size:11px; }
  .head { text-align:center; margin-bottom:12px; position:relative; padding:0 82px; }
  .head .logo { position:absolute; top:0; width:66px; height:66px; object-fit:contain; }
  .head .logo.left { left:0; } .head .logo.right { right:0; }
  .head .rep { font-size:11px; }
  .head .sch { font-size:14px; font-weight:bold; }
  .head .addr { font-size:10px; }
  .head .unit { font-size:11px; font-weight:bold; color:#c00000; margin-top:2px; }
  .head .atitle { font-size:16px; font-weight:bold; letter-spacing:4px; margin-top:10px; }
  .head .asub { font-size:12px; }
  .head .afy { font-size:11px; }
  .note-bar { background:#ffff00; -webkit-print-color-adjust:exact; print-color-adjust:exact; padding:4px 7px; font-style:italic; font-size:10.5px; line-height:1.5; }
  table.fields { width:100%; border-collapse:collapse; font-size:11px; }
  table.fields td { border:1px solid #000; padding:3px 6px; }
  table.fields td.lb { width:38%; }
  table.fields td.lb small { font-style:italic; font-size:9px; }
  table.fields td.colon { width:14px; text-align:center; }
  table.fields td.nn { width:30px; font-weight:bold; }
  table.fields tr.section-bar td { background:#808080; -webkit-print-color-adjust:exact; print-color-adjust:exact; height:15px; }
  table.fields td.gray-cell { background:#808080; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  table.fields td.val { text-align:center; }
  table.fields td.bold { font-weight:bold; font-style:italic; }
  table.items { width:100%; border-collapse:collapse; margin-top:14px; }
  table.items th, table.items td { border:1px solid #000; padding:4px 7px; }
  table.items th { text-align:center; font-weight:bold; font-size:11px; }
  table.items td.num { text-align:right; }
  table.items tr.grp td { background:#808080; -webkit-print-color-adjust:exact; print-color-adjust:exact; font-style:italic; font-weight:bold; }
  table.items tr.total td { font-weight:bold; font-style:italic; text-align:center; }
  .cellh { height:20px; }
  .instr { margin-top:16px; font-size:9.5px; line-height:1.6; }
  .instr .h { font-weight:bold; }
  .toolbar { margin-bottom:14px; }
  .toolbar a, .toolbar button { padding: 8px 18px; border: none; border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; text-decoration: none; color: #fff; font-family: Arial, sans-serif; margin-right: 6px; }
  @media print { .toolbar { display:none!important; } body{padding:0;} }
</style></head>
<body>
  <div class="toolbar">
    <a href="<?= $formId > 0 ? "annex-edit.php?id=$formId" : "annex-edit.php" ?>" style="background:#64748B;">← Back</a>
    <button onclick="window.print()" style="background:#1c4c86;">🖨️ Print</button>
  </div>

  <div class="head">
    <?php if (doc_logo('annex', 'left')): ?><img class="logo left" src="<?= e(doc_logo('annex', 'left')) ?>" alt="ASCOT logo"><?php endif; ?>
    <?php if (doc_logo('annex', 'right')): ?><img class="logo right" src="<?= e(doc_logo('annex', 'right')) ?>" alt="Bagong Pilipinas logo"><?php endif; ?>
    <div class="rep"><?= e(doc_fmt('annex', 'rep_line')) ?></div>
    <div class="sch"><?= e(doc_fmt('annex', 'org_name')) ?></div>
    <div class="addr"><?= e(doc_fmt('annex', 'address')) ?></div>
    <div class="unit"><?= e(mb_strtoupper(annex_unit_line($form, (string)($form['dept_code'] ?? $deptCode ?? '')))) ?></div>
    <div class="atitle">A N N E X&nbsp;&nbsp;<?= e($form['annex'] ?? 'B') ?></div>
    <div class="asub"><?= e($subtitle) ?></div>
    <div class="afy"><?= e(doc_fmt('annex', 'lbl_fy')) ?> <?= $fy ?></div>
  </div>

  <div class="note-bar">
    *Use another sheet for different projects/program/activity<br>
    *<?= e($itemNote) ?>
  </div>

  <table class="fields">
    <tr><td class="lb"><?= e(doc_fmt('annex', 'lbl_project')) ?></td><td class="colon">:</td><td class="val" colspan="2"><?= $form && $form['project_name']!=='' ? $f('project_name') : 'Trainings and Seminars' ?></td></tr>
    <tr><td class="lb"><?= e(doc_fmt('annex', 'lbl_general')) ?><br><small>(As categorized in the PPMP)</small></td><td class="colon">:</td><td class="val" colspan="2"><?= $form ? $f('general_description') : '' /* v102: blank when nothing was typed */ ?></td></tr>
    <tr><td colspan="2" style="text-align:center;"><?= e(doc_fmt('annex', 'lbl_item')) ?></td><td colspan="2" class="gray-cell">&nbsp;</td></tr>
    <tr><td class="lb"><?= e(doc_fmt('annex', 'lbl_nep')) ?></td><td class="nn">#1</td><td colspan="2"><?= $f('nep_account_1') ?></td></tr>
    <tr><td class="lb"><?= e(doc_fmt('annex', 'lbl_alloc')) ?></td><td class="colon">:</td><td class="num" colspan="2"><?= $form && (float)$form['allocated_budget_1']>0 ? number_format((float)$form['allocated_budget_1'],2) : '' ?></td></tr>
    <tr><td class="lb"><?= e(doc_fmt('annex', 'lbl_nep')) ?><br><small>(If NEP Account #1 allocation is not sufficient)</small></td><td class="nn">#2</td><td colspan="2"><?= $f('nep_account_2') ?></td></tr>
    <tr><td class="lb"><?= e(doc_fmt('annex', 'lbl_alloc')) ?></td><td class="colon">:</td><td class="num" colspan="2"><?= $form && (float)$form['allocated_budget_2']>0 ? number_format((float)$form['allocated_budget_2'],2) : '' ?></td></tr>
    <tr><td class="lb bold"><?= e(doc_fmt('annex', 'lbl_totbudget')) ?></td><td class="colon">:</td><td class="num" colspan="2" style="font-weight:bold; font-style:italic;">(&nbsp;<?= number_format($estBudget,2) ?>&nbsp;)</td></tr>
    <tr><td class="lb" style="font-style:italic;"><?= e(doc_fmt('annex', 'lbl_dates')) ?> <small>(tentative)</small></td><td class="colon">:</td><td class="val" colspan="2"><?= $form && $form['inclusive_dates']!=='' ? $f('inclusive_dates') : 'as per schedule' ?></td></tr>
  </table>

  <?php
    $B_GROUPS = ['Supplies and Materials','Honoraria','Venue (Proposed Location)','Meals and Snack','Others','Registration Fee'];
    $byGroup = [];
    foreach ($items as $row) { $g = $row['item_group'] !== '' ? $row['item_group'] : 'Others'; $byGroup[$g][] = $row; }
  ?>
  <table class="items">
    <thead>
      <tr><th style="width:56%;"><?= e(doc_fmt('annex', 'col_items')) ?></th><th style="width:16%;"><?= e(doc_fmt('annex', 'col_qty')) ?></th><th style="width:28%;"><?= e(doc_fmt('annex', 'col_total')) ?></th></tr>
    </thead>
    <tbody>
      <?php foreach ($B_GROUPS as $g):
        $groupRows = $byGroup[$g] ?? [];
        $blank = max(5 - count($groupRows), 1);
      ?>
        <tr class="grp"><td colspan="3"><?= e($g) ?></td></tr>
        <?php foreach ($groupRows as $r): ?>
          <tr>
            <td><?= e($r['specification']) ?></td>
            <td class="num"><?= $r['quantity']!=0 ? rtrim(rtrim(number_format((float)$r['quantity'],2),'0'),'.') : '' ?></td>
            <td class="num"><?= $r['total_amount']!=0 ? number_format((float)$r['total_amount'],2) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php for ($k=0; $k<$blank; $k++): ?>
          <tr><td class="cellh">&nbsp;</td><td></td><td></td></tr>
        <?php endfor; ?>
      <?php endforeach; ?>
      <tr class="total"><td><?= e(doc_fmt('annex', 'col_total')) ?></td><td></td><td class="num">(&nbsp;<?= number_format($total,2) ?>&nbsp;)</td></tr>
    </tbody>
  </table>

  <div class="instr">
    <div class="h"><?= e(doc_fmt('annex', 'instr_title')) ?></div>
    <?= doc_block('annex', 'instr_body', (int)($form['fy'] ?? 0)) /* v94: from the Document Format page */ ?>
  </div>

<?php $__pu = current_user(); if (!empty($__pu['id'])): /* Back stays in your section (assets/back-nav.js) */ ?>
<script>window.PE_UID = <?= (int)$__pu['id'] ?>;</script><script src="assets/back-nav.js"></script>
<?php endif; ?>
</body></html>
