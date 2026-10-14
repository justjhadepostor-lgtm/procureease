<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/ppmp-expense.php';
require_login();
ensure_admin_tables();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT p.*, d.name AS dept_name
                         FROM ppmps p
                    LEFT JOIN departments d ON d.code = p.dept_code
                        WHERE p.id = ?');
$stmt->execute([$id]);
$ppmp = $stmt->fetch();
if (!$ppmp) { header('Location: user-dashboard.php'); exit; }
// v90: the Budget Unit sees a PPMP once it is submitted; the Procurement Unit once the Budget Unit approved it
$__pu = current_user();
if (in_array((string)($__pu['role'] ?? ''), ['admin', 'budget'], true) && !ppmp_visible_to($ppmp, $__pu)) {
    flash('error', ($__pu['role'] ?? '') === 'admin' ? 'This PPMP is not with the Procurement Unit yet. It opens here once the Budget Unit approves it.'
                                                     : 'This PPMP is still being prepared by the department. It opens here once it is submitted.');
    header('Location: ' . (($__pu['role'] ?? '') === 'admin' ? 'admin-dashboard.php' : 'budget-dashboard.php')); exit;
}

$stmt = db()->prepare('SELECT * FROM ppmp_entries WHERE ppmp_id = ? ORDER BY id');
$stmt->execute([$id]);
$entries = $stmt->fetchAll();

$total = 0;
foreach ($entries as $e) $total += (float)$e['amount'];

// Helpers
function shortMonth(string $m): string {
    $m = trim($m);
    $map = ['January'=>'Jan','February'=>'Feb','March'=>'Mar','April'=>'Apr','May'=>'May','June'=>'Jun',
            'July'=>'Jul','August'=>'Aug','September'=>'Sep','October'=>'Oct','November'=>'Nov','December'=>'Dec'];
    return $map[$m] ?? mb_substr($m, 0, 3);
}
function ppmpYearShort(int $fy): string { return substr((string)$fy, -2); }
// This document renders with the format of its own fiscal year,
// not today's, so a filed document keeps the form it was filed under.
doc_fmt_use_year((int)($ppmp['fy'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PPMP <?= e($ppmp['dept_code']) ?> · FY <?= (int)$ppmp['fy'] ?> — Print</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Arial&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #eee; color: #000; font-family: Arial, 'Inter', sans-serif; font-size: 10px; }

  /* Screen preview: a4 landscape page */
  .page {
    width: 279mm; min-height: 216mm;
    margin: 20px auto; padding: 12mm 10mm;
    background: white;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    position: relative;
  }

  /* Toolbar (hidden when printing) */
  .toolbar {
    position: sticky; top: 0; z-index: 100;
    background: #4A7AAD; color: white;
    padding: 10px 20px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  }
  .toolbar h1 { margin: 0; font-size: 14px; font-family: 'Inter', sans-serif; font-weight: 600; }
  .btn-print {
    background: #CFE4F0; color: #4A7AAD; padding: 8px 20px;
    border: none; border-radius: 6px; font-weight: 600; font-size: 13px;
    cursor: pointer; font-family: 'Inter', sans-serif;
  }
  .btn-print:hover { background: #B8D8E5; }
  .btn-back {
    background: transparent; color: white; padding: 8px 14px;
    text-decoration: none; font-size: 13px; font-family: 'Inter', sans-serif;
    border: 1px solid rgba(255,255,255,0.4); border-radius: 6px;
  }

  /* Header (letterhead) */
  .letterhead {
    display: grid;
    grid-template-columns: 90px 1fr 90px;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
  }
  .seal { width: 90px; height: 90px; display: flex; align-items: center; justify-content: center; }
  .seal img { width: 82px; height: 82px; object-fit: contain; }
  .letterhead-center { text-align: center; }
  .rep-line { font-size: 11px; }
  .school-name { font-size: 18px; font-weight: 700; letter-spacing: 0.02em; margin: 1px 0; }
  .school-addr { font-size: 10px; font-style: italic; margin-bottom: 8px; }
  .doc-title { font-size: 13px; font-weight: 700; letter-spacing: 0.03em; margin-top: 4px; }
  .doc-title .ppmp-no { border-bottom: 1px solid #000; display: inline-block; min-width: 40px; padding: 0 8px; margin-left: 4px; }

  .flag {
    width: 90px; height: 60px;
    background: url('<?= e(doc_logo('ppmp', 'right')) ?>') center/contain no-repeat;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }

  /* Indicative / Final checkboxes */
  .status-boxes {
    display: flex; justify-content: center; gap: 30px;
    margin: 8px 0 12px;
    font-size: 11px; font-weight: 600;
  }
  .status-boxes label { display: inline-flex; align-items: center; gap: 6px; }
  .checkbox {
    display: inline-block; width: 12px; height: 12px;
    border: 1.5px solid #000; position: relative;
  }
  .checkbox.checked::after {
    content: '✓'; position: absolute; top: -4px; left: 0;
    font-size: 14px; font-weight: bold; line-height: 1;
  }

  /* Meta */
  .meta-block {
    font-size: 11px; margin-bottom: 8px;
    display: grid; grid-template-columns: max-content 1fr;
    gap: 4px 18px; align-items: baseline;
  }
  .meta-label { font-weight: 700; }
  .meta-block .meta-value { border-bottom: 1px solid #000; padding: 0 4px; }

  /* Main table */
  table.ppmp-table {
    width: 100%; border-collapse: collapse;
    font-size: 8.5px;
    margin-bottom: 14px;
  }
  .ppmp-table th, .ppmp-table td {
    border: 1px solid #000;
    padding: 4px 3px;
    vertical-align: middle;
    text-align: center;
  }
  .ppmp-table thead th {
    background: white;
    font-weight: 700;
    font-size: 9px;
  }
  .ppmp-table thead .group-header {
    background: white;
    font-size: 10px;
    font-weight: 700;
    padding: 6px 4px;
  }
  .ppmp-table thead .col-label {
    font-weight: 700; font-size: 9px;
    padding: 6px 3px;
    height: 90px; /* enough for wrapping headers */
  }
  .ppmp-table thead .col-num {
    background: white;
    font-weight: 700; font-style: italic;
    font-size: 9px;
    padding: 3px;
  }
  .ppmp-table tbody td {
    text-align: left;
    padding: 6px 4px;
    font-size: 9px;
    line-height: 1.35;
    vertical-align: top;
  }
  .ppmp-table tbody td.num,
  .ppmp-table tbody td.center { text-align: center; }
  .ppmp-table tbody td.right { text-align: right; font-family: 'Arial', monospace; }
  .ppmp-table tfoot td {
    font-weight: 700; font-size: 10px;
    padding: 8px 4px;
    text-align: right;
  }
  .ppmp-table tfoot .total-label { text-align: right; padding-right: 10px; }

  /* Column width tuning */
  .c1 { width: 13%; } /* Description */
  .c2 { width: 8%; }  /* Type */
  .c3 { width: 8%; }  /* Quantity */
  .c4 { width: 8%; }  /* Mode */
  .c5 { width: 6%; }  /* Pre-proc */
  .c6 { width: 5%; }  /* Start */
  .c7 { width: 5%; }  /* End */
  .c8 { width: 6%; }  /* Delivery */
  .c9 { width: 6%; }  /* Fund */
  .c10 { width: 8%; } /* Amount */
  .c11 { width: 12%; } /* Docs */
  .c12 { width: 15%; } /* Remarks */

  /* Signature block */
  .sig-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    margin-top: 24px;
    page-break-inside: avoid;
  }
  .sig-block {
    text-align: center;
    font-size: 10px;
  }
  .sig-label {
    text-align: left;
    font-weight: 600;
    margin-bottom: 34px;
  }
  .sig-line {
    border-bottom: 1px solid #000;
    height: 1px;
    margin: 0 auto 2px;
    width: 70%;
  }
  .sig-name {
    font-weight: 700;
    font-size: 11px;
    margin-top: 4px;
    text-transform: uppercase;
  }
  .sig-caption {
    font-size: 9px;
    font-style: italic;
    margin-bottom: 2px;
  }
  .sig-position {
    font-size: 10px;
    margin-top: 2px;
  }
  .sig-bracket {
    font-size: 8.5px;
    font-style: italic;
    color: #333;
  }
  .sig-date {
    text-align: left;
    margin-top: 20px;
    font-size: 10px;
  }
  .sig-date .datefield {
    border-bottom: 1px solid #000;
    display: inline-block;
    min-width: 120px;
    padding: 0 4px;
    margin-left: 4px;
  }

  /* Budget breakdown under the table */
  .xb-table { page-break-inside: avoid; break-inside: avoid; }

  /* Print rules */
  @page {
    size: A4 landscape;
    margin: 10mm 8mm;
  }
  @media print {
    html, body { background: white; }
    .toolbar { display: none !important; }
    .page {
      width: auto; min-height: 0; margin: 0; padding: 0;
      box-shadow: none;
    }
  }
</style>
</head>
<body>

<div class="toolbar">
  <a href="ppmp-file-view.php?id=<?= (int)$ppmp['id'] ?>" class="btn-back">← Back to PPMP</a>
  <h1>PPMP Print View — <?= e($ppmp['dept_code']) ?> · FY <?= (int)$ppmp['fy'] ?></h1>
  <div style="display: flex; gap: 8px; align-items: center;">
    <a href="ppmp-excel.php?id=<?= (int)$ppmp['id'] ?>" class="btn-print" style="background: #CFE4F0; color: #4A7AAD; text-decoration: none;">
      📊 Download Excel
    </a>
    <button class="btn-print" onclick="window.print()">🖨️ Print</button>
  </div>
</div>

<div class="page">
  <!-- ─── Letterhead ─────────────────────────────────── -->
  <div class="letterhead">
    <div class="seal">
      <img src="<?= e(doc_logo('ppmp', 'left')) ?>" alt="ASCOT Seal">
    </div>
    <div class="letterhead-center">
      <div class="rep-line"><?= e(doc_fmt('ppmp', 'rep_line')) ?></div>
      <div class="school-name"><?= e(doc_fmt('ppmp', 'org_name')) ?></div>
      <div class="school-addr"><?= e(doc_fmt('ppmp', 'address')) ?></div>
      <div class="doc-title">
        <?= e(doc_fmt('ppmp', 'doc_title')) ?>
        <span class="ppmp-no"><?= e(os_ppmp_no($ppmp['ppmp_no'] ?? '', $ppmp['dept_code'] ?? '')) ?></span>
      </div>
    </div>
    <div class="flag" title="Bagong Pilipinas"></div>
  </div>

  <!-- Indicative / Final -->
  <div class="status-boxes">
    <label><span class="checkbox <?= !$ppmp['is_final'] ? 'checked' : '' ?>"></span> INDICATIVE</label>
    <label><span class="checkbox <?=  $ppmp['is_final'] ? 'checked' : '' ?>"></span> FINAL</label>
  </div>

  <!-- Fiscal year + End-User -->
  <div class="meta-block">
    <div class="meta-label">Fiscal Year:</div>
    <div class="meta-value"><?= (int)$ppmp['fy'] ?></div>
    <div class="meta-label">End-User or Implementing Unit:</div>
    <div class="meta-value"><?php require_once __DIR__ . '/config/ppmp-unit.php'; ?><?= e(ppmp_unit_label($ppmp, (string)($ppmp['dept_code'] ?? ''), (string)$ppmp['dept_name'])) /* v137 */ ?></div>
  </div>

  <!-- Main table -->
  <?php /* v94: the columns of this PPMP's year (Document Format) */
    require_once __DIR__ . '/config/ppmp-columns.php';
    $__cols = ppmp_cols((int)$ppmp['fy']); $__hdr = ppmp_cols_header($__cols, (int)$ppmp['fy']);
    $__cls  = ['desc' => 'c1', 'type' => 'c2', 'qty' => 'c3', 'mode' => 'c4', 'pre' => 'c5', 'start' => 'c6', 'end' => 'c7',
               'deliv' => 'c8', 'fund' => 'c9', 'amount' => 'c10', 'docs' => 'c11', 'remarks' => 'c12']; ?>
  <table class="ppmp-table">
    <thead>
      <tr>
        <?php foreach ($__hdr['top'] as $__t): ?>
          <?php if (isset($__t['col'])): ?><th rowspan="2" class="col-label <?= $__cls[$__t['col']['id']] ?? '' ?>"><?= e($__t['col']['label']) ?></th>
          <?php else: ?><th colspan="<?= (int)$__t['span'] ?>" class="group-header"><?= e($__t['label']) ?></th><?php endif; ?>
        <?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($__hdr['second'] as $__c): ?><th class="col-label <?= $__cls[$__c['id']] ?? '' ?>"><?= e($__c['label']) ?></th><?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($__cols as $__i => $__c): ?><th class="col-num"><?= e(doc_fmt('ppmp', 'col_num_prefix')) ?> <?= $__i + 1 ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php $yr = ppmpYearShort((int)$ppmp['fy']); if (!$entries): ?>
        <tr><?php foreach ($__cols as $__c): ?><td>&nbsp;</td><?php endforeach; ?></tr>
        <tr><?php foreach ($__cols as $__c): ?><td>&nbsp;</td><?php endforeach; ?></tr>
      <?php else: foreach ($entries as $r):
        $__x = ppmp_entry_extra($r);
        $__cell = [
          'desc'    => '<td class="c1">' . e($r['description']) . '</td>',
          'type'    => '<td class="c2 center">' . e($r['proj_type']) . '</td>',
          'qty'     => '<td class="c3 center">' . e($r['quantity'] ?: 'as per schedule') . '</td>',
          'mode'    => '<td class="c4 center">' . e($r['mode']) . '</td>',
          'pre'     => '<td class="c5 center">' . e($r['pre_conference'] ?: 'No') . '</td>',
          'start'   => '<td class="c6 center">' . (trim((string)$r['activity_start']) !== '' ? e(shortMonth($r['activity_start'])) . '-' . e($yr) : 'N/A') . '</td>',
          'end'     => '<td class="c7 center">' . (trim((string)$r['activity_end'])   !== '' ? e(shortMonth($r['activity_end']))   . '-' . e($yr) : 'N/A') . '</td>',
          'deliv'   => '<td class="c8 center">' . (trim((string)$r['delivery'])       !== '' ? e(shortMonth($r['delivery']))       . '-' . e($yr) : 'N/A') . '</td>',
          'fund'    => '<td class="c9 center">' . e($r['fund']) . '</td>',
          'amount'  => '<td class="c10 right">₱' . number_format((float)$r['amount'], 2) . '</td>',
          'docs'    => '<td class="c11 center">' . e($r['supporting_docs'] ?: 'N/A') . '</td>',
          'remarks' => '<td class="c12">' . e($r['remarks']) . '</td>',
        ]; ?>
        <tr>
          <?php foreach ($__cols as $__c) echo $__c['builtin'] ? $__cell[$__c['id']] : '<td class="center">' . e($__x[$__c['id']] ?? '') . '</td>'; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <?php [$__b, $__a] = ppmp_cols_around_amount($__cols); ?>
        <td colspan="<?= $__b ?>" class="total-label">TOTAL BUDGET:</td>
        <td class="right">₱<?= number_format($total, 2) ?></td>
        <?php if ($__a > 0): ?><td colspan="<?= $__a ?>">&nbsp;</td><?php endif; ?>
      </tr>
    </tfoot>
  </table>


  <!-- Signature block -->
  <div class="sig-row">
    <div class="sig-block">
      <div class="sig-label"><?= e(doc_fmt('ppmp', 'sig_prepared') ?: 'Prepared by:') ?></div>
      <div class="sig-line"></div>
      <?php $__ph = department_head($ppmp['dept_code'] ?? null); ?>
      <div class="sig-name"><?= e($ppmp['sig_name'] ?: ($__ph['name'] ?: $ppmp['preparer'])) ?></div>
      <div class="sig-caption">Signature over Printed Name</div>
      <div class="sig-position"><?= e($ppmp['sig_position']) ?></div>
      <div class="sig-bracket">[Head of the End-User or Implementing Unit]</div>
      <div class="sig-date">Date: <span class="datefield"><?= e($ppmp['date_prepared'] ?? '') ?></span></div>
    </div>
    <div class="sig-block">
      <div class="sig-label"><?= e(doc_fmt('ppmp', 'sig_approved') ?: 'Approved by:') ?></div>
      <div class="sig-line"></div>
<?php
        /* What is saved on the PPMP wins; a blank one is the department's
           own head, the same person the editor fills in. */
        $__ab = ppmp_approved_by((array)$ppmp);   // the VP, unless typed on purpose
        $__hd = $__ab['vp'];
        $__hdName = $__ab['name'];
        $__hdPos  = $__ab['position'];
      ?>
      <div class="sig-name"><?= e($__hdName) ?></div>
      <div class="sig-caption">Signature over Printed Name</div>
      <div class="sig-position"><?= e($__hdPos) ?></div>
      <div class="sig-date">Date: <span class="datefield"><?= e($ppmp['date_submitted_head'] ?? '') ?></span></div>
    </div>
  </div>

  <div class="print-footer" style="text-align: center; margin-top: 24px; font-size: 9pt; color: #666; font-family: Arial, sans-serif; border-top: 1px solid #ccc; padding-top: 8px;">
    Printed on <span id="printedOn"><?= date('F j, Y g:i A') ?></span>
  </div>
</div>

<script>
  // Refresh the printed date + time whenever the user actually invokes print
  window.addEventListener('beforeprint', function() {
    var now = new Date();
    var opts = { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
    document.getElementById('printedOn').textContent = now.toLocaleString('en-US', opts);
  });
</script>

<?php $__pu = current_user(); if (!empty($__pu['id'])): /* Back stays in your section (assets/back-nav.js) */ ?>
<script>window.PE_UID = <?= (int)$__pu['id'] ?>;</script><script src="assets/back-nav.js"></script>
<?php endif; ?>
</body>
</html>
