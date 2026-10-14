<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_login();
$u = current_user();
$pdo = db();

// ?fy=…&dept=__ALL__ — Non-PS (Consolidated), the Procurement Unit's own copy
$consol = (($_GET['dept'] ?? '') === '__ALL__');
if ($consol) {
    if (($u['role'] ?? '') !== 'admin') { header('Location: user-dashboard.php'); exit; }
    require_once __DIR__ . '/config/nonps-consolidation.php';
    $__fy = (int)($_GET['fy'] ?? active_fy());
    if ($__fy < 2000 || $__fy > 2100) $__fy = active_fy();
    $sub = ['id' => 0, 'fy' => $__fy, 'dept_code' => (string)($u['dept_code'] ?? ''), 'prepared_by' => (string)($u['full_name'] ?? ''),
            'submitted_by' => '', 'prepared_designation' => (string)($u['position'] ?? '')];
    $deptName = 'Procurement Unit';
    try {
        $__dn = $pdo->prepare('SELECT name FROM departments WHERE code = ?');
        $__dn->execute([(string)($u['dept_code'] ?? '')]);
        $deptName = (string)($__dn->fetchColumn() ?: $deptName);
    } catch (Throwable $t) {}
    $items = array_map(fn($l) => ['particulars' => $l['particulars'], 'unit' => $l['unit'], 'quantity' => $l['quantity'],
                                  'unit_cost' => $l['unit_cost'], 'total_cost' => $l['total_cost'], 'mixed_price' => $l['mixed_price']],
                       nonps_consol_items($__fy));
} else {
$id = (int)($_GET['id'] ?? $_GET['sub'] ?? 0);
if ($id <= 0) { header('Location: nonps-list.php'); exit; }

$q = $pdo->prepare("SELECT s.*, d.name AS dept_name FROM nonps_submissions s
                LEFT JOIN departments d ON d.code = s.dept_code WHERE s.id = ?");
$q->execute([$id]);
$sub = $q->fetch();
if (!$sub) { header('Location: nonps-list.php'); exit; }

// Access control — users can only print their own dept; admin can print any
if (($u['role'] ?? '') !== 'admin' && ($u['dept_code'] ?? '') !== $sub['dept_code']) {
    header('Location: user-dashboard.php'); exit;
}

$deptName = $sub['dept_name'] ?? $sub['dept_code'];

$q2 = $pdo->prepare("SELECT * FROM nonps_items WHERE submission_id = ? ORDER BY item_no, id");
$q2->execute([$id]);
$items = $q2->fetchAll();
}
$grandTotal = 0;
foreach ($items as $it) $grandTotal += (float)$it['total_cost'];
// This document renders with the format of its own fiscal year,
// not today's, so a filed document keeps the form it was filed under.
doc_fmt_use_year((int)($sub['fy'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>List of Non-PS Supplies<?= $consol ? ' (Consolidated)' : '' ?> · FY <?= (int)$sub['fy'] ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #e0e0e0; padding: 20px 0; }
  .paper { max-width: 8.5in; margin: 0 auto; background: white; padding: 0.5in; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
  .toolbar { margin-bottom: 12px; }
  .toolbar .btn { display: inline-block; padding: 8px 18px; border-radius: 8px; background: #1c4c86; color: white; border: none; font-weight: 600; font-size: 12.5px; cursor: pointer; font-family: Arial, sans-serif; margin-right: 6px; text-decoration: none; }
  .toolbar .btn.ghost { background: white; color: #333; border: 1.5px solid #ccc; }
  .print-tip { max-width: 8.5in; margin: 0 auto 12px; padding: 10px 14px; background: #fff8e1; border-left: 4px solid #f59e0b; font-size: 12px; color: #92400e; font-family: 'Space Grotesk', sans-serif; border-radius: 6px; }

  /* Letterhead: centered vertical stack — pantay-pantay */
  .letterhead { display: flex; flex-direction: column; align-items: center; gap: 4px; margin-bottom: 4px; text-align: center; }
  .letterhead img { width: 72px; height: 72px; object-fit: contain; display: block; margin-bottom: 4px; }
  .letterhead .rp     { font-size: 10pt; }
  .letterhead .school { font-size: 14pt; font-weight: 700; margin: 2px 0; }
  .letterhead .addr   { font-size: 10pt; }

  .lh-section { text-align: center; font-weight: 700; font-size: 11pt; padding: 6px 0 2px; }
  .lh-title { text-align: center; font-weight: 700; font-size: 11pt; padding: 8px 0 0; }
  .lh-fy { text-align: center; font-size: 10pt; padding: 2px 0 12px; }

  table.items { width: 100%; border-collapse: collapse; font-size: 10pt; margin-bottom: 20px; }
  table.items th, table.items td { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }
  table.items th { text-align: center; font-weight: 700; background: white; }
  table.items td.center { text-align: center; }
  table.items td.right  { text-align: right; }
  table.items tfoot td { font-weight: 700; }

  .signatory { margin-top: 20px; font-size: 10pt; }
  .sig-prep-label { margin-bottom: 42px; }
  .sig-name { font-weight: 700; text-transform: uppercase; letter-spacing: 0.01em; font-size: 10pt; }
  .sig-pos { font-size: 10pt; }

  .print-footer { text-align: center; margin-top: 24px; font-size: 9pt; color: #666; border-top: 1px solid #ccc; padding-top: 8px; }

  @media print {
    .no-print { display: none !important; }
    body { background: white; padding: 0; }
    .paper { box-shadow: none; padding: 0; max-width: none; }
    /* Hide browser URL/timestamp headers/footers where supported */
    @page { size: Letter portrait; margin: 0.5in; }
  }
</style>
</head>
<body>

<div class="toolbar no-print">
  <a href="<?= $consol ? 'consolidation.php' : 'nonps-edit.php' ?>" class="btn" style="background:#64748B;">← Back</a>
  <button onclick="window.print()" class="btn">🖨️ Print</button>
</div>

<div class="print-tip no-print">
  💡 <strong>To remove the URL and page timestamp at the bottom of your printout:</strong> In the print dialog, expand <strong>"More settings"</strong> and uncheck <strong>"Headers and footers"</strong>. This gives you a clean output with no browser URL.
</div>

<div class="paper">

  <div class="letterhead">
    <?= doc_logo_pair('nonps', '') ?>
    <div class="rp"><?= e(doc_fmt('nonps', 'rep_line')) ?></div>
    <div class="school"><?= e(doc_fmt('nonps', 'org_name')) ?></div>
    <div class="addr"><?= e(doc_fmt('nonps', 'address')) ?></div>
  </div>

  <div class="lh-section"><?= e(doc_fmt('nonps', 'unit_line')) ?></div>
  <div class="lh-title"><?= e(doc_fmt('nonps', 'doc_title')) ?><?= $consol ? ' (Consolidated)' : '' ?></div>
  <div class="lh-fy">FY <?= (int)$sub['fy'] ?></div>

  <table class="items">
    <colgroup>
      <col style="width: 44px;"><col><col style="width: 65px;"><col style="width: 70px;"><col style="width: 90px;"><col style="width: 100px;">
    </colgroup>
    <thead>
      <tr>
        <th><?= e(doc_fmt('nonps', 'col_no')) ?></th><th><?= e(doc_fmt('nonps', 'col_part')) ?></th><th><?= e(doc_fmt('nonps', 'col_unit')) ?></th><th><?= e(doc_fmt('nonps', 'col_qty')) ?></th><th><?= e(doc_fmt('nonps', 'col_cost')) ?></th><th><?= e(doc_fmt('nonps', 'col_total')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php $n = 1; foreach ($items as $it): ?>
        <tr>
          <td class="center"><?= $n++ ?></td>
          <td><?= e($it['particulars']) ?></td>
          <td class="center"><?= e($it['unit']) ?></td>
          <td class="center"><?= $consol ? nonps_consol_qty((float)$it['quantity']) : (int)$it['quantity'] ?></td>
          <td class="right"><?= number_format((float)$it['unit_cost'], 2) ?><?php if (!empty($it['mixed_price'])): ?><div style="font-size: 8pt; font-style: italic;">avg.</div><?php endif; ?></td>
          <td class="right"><?= number_format((float)$it['total_cost'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="5" class="center">TOTAL</td>
        <td class="right" style="text-decoration: underline;">₱<?= number_format($grandTotal, 2) ?></td>
      </tr>
      <?php /* + VAT, logistics and inflation, each a % of the TOTAL, and the total with them (config/price-provision.php) */
        require_once __DIR__ . '/config/price-provision.php';
        $__np = nonps_provision((float)$grandTotal); ?>
      <?= nonps_charge_rows($__np, 'colspan="5" class="center"', 'class="right"', true) ?>
      <tr>
        <td colspan="5" class="center"><?= e(doc_fmt('nonps', 'lbl_grand')) ?></td>
        <td class="right" id="npGrand" style="text-decoration: underline double;">₱<?= number_format($__np['grand'], 2) ?></td>
      </tr>
    </tfoot>
  </table>

  <div class="signatory">
    <div id="prepByBlock" style="position: relative;">
      <button type="button" onclick="removePrepBlock()" title="Remove this block" class="no-print"
              style="position: absolute; top: -6px; right: -6px; background: none; border: none; color: #bbb; cursor: pointer; font-size: 12px; padding: 2px 6px;">✕</button>
      <div class="sig-prep-label"><?= e(doc_fmt('nonps', 'sig_prepared')) ?></div>
      <div class="sig-name" contenteditable="true" spellcheck="false" style="outline: none; border-bottom: 1px dashed transparent;" onmouseover="this.style.borderBottomColor='#ddd'" onmouseout="this.style.borderBottomColor='transparent'"><?= e(($sub['prepared_by'] ?? '') ?: ($sub['submitted_by'] ?: ($u['full_name'] ?? ''))) ?></div>
      <?php
        $__desig = trim((string)($sub['prepared_designation'] ?? ''));
        if ($__desig === '' && ($u['dept_code'] ?? '') === $sub['dept_code']) $__desig = trim((string)($u['position'] ?? ''));
        // Once only: no department line under a designation that already is it
        $__sameAsDept = $__desig !== '' && mb_strtolower($__desig) === mb_strtolower(trim((string)$deptName));
      ?>
      <?php if ($__desig !== ''): ?><div class="sig-role"><?= e($__desig) ?></div><?php endif; ?>
      <?php if (!$__sameAsDept): ?>
      <div class="sig-pos" contenteditable="true" spellcheck="false" style="outline: none; border-bottom: 1px dashed transparent;" onmouseover="this.style.borderBottomColor='#ddd'" onmouseout="this.style.borderBottomColor='transparent'"><?= e($deptName) ?></div>
      <?php endif; ?>
    </div>

  </div>

  <div class="print-footer">Printed on <span id="printedOn"><?= date('F j, Y g:i A') ?></span></div>
</div>

<script>
  function removePrepBlock() {
    var b = document.getElementById('prepByBlock');
    if (b) b.style.display = 'none';
  }
  window.addEventListener('beforeprint', function() {
    var now = new Date();
    var opts = { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
    var el = document.getElementById('printedOn');
    if (el) el.textContent = now.toLocaleString('en-US', opts);
  });
</script>
<?php $__pu = current_user(); if (!empty($__pu['id'])): /* Back stays in your section (assets/back-nav.js) */ ?>
<script>window.PE_UID = <?= (int)$__pu['id'] ?>;</script><script src="assets/back-nav.js"></script>
<?php endif; ?>
</body>
</html>
