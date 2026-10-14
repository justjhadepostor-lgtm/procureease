<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/ppmp-expense.php';
require_login();
ensure_admin_tables();

$u = current_user();
$id = (int)($_GET['id'] ?? 0);

// Three modes:
//   type=blank        → blank 12-column form, empty rows to fill freely
//   type=signatories  → just letterhead + signatories header, no table body
//   default (with id) → filled PPMP data
$type = $_GET['type'] ?? '';
$isBlank        = ($type === 'blank');
$isSignatories  = ($type === 'signatories');

if ($isBlank || $isSignatories) {
    $ppmp = [
        'id' => 0, 'dept_code' => $u['dept_code'] ?? '', 'fy' => active_fy(),
        'dept_name' => $u['dept_name'] ?? '', 'ppmp_no' => '', 'is_final' => 0,
        'preparer' => '', 'date_prepared' => '',
        'sig_name' => '', 'sig_position' => '',
        'head_name' => '', 'head_position' => '',
        'date_submitted_head' => '',
    ];
    // For blank: 8 truly empty rows to type into; for signatories: no rows
    $entries = [];
    if ($isBlank) {
        for ($i = 0; $i < 8; $i++) $entries[] = [
            'description'=>'','proj_type'=>'','quantity'=>'','mode'=>'','pre_conference'=>'',
            'activity_start'=>'','activity_end'=>'','delivery'=>'',
            'fund'=>'','amount'=>'','supporting_docs'=>'','remarks'=>'',
        ];
    }
    $filename = $isSignatories
        ? 'PPMP_Signatories_Blank_' . ($u['dept_code'] ?: 'ASCOT') . '.xls'
        : 'PPMP_Blank_Form_' . ($u['dept_code'] ?: 'ASCOT') . '.xls';
} else {
    $stmt = db()->prepare('SELECT p.*, d.name AS dept_name
                             FROM ppmps p LEFT JOIN departments d ON d.code = p.dept_code
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
    $filename = 'PPMP_' . $ppmp['dept_code'] . '_FY' . (int)$ppmp['fy'] . '.xls';
}

$total = 0;
foreach ($entries as $e) $total += (float)($e['amount'] ?? 0);

function shortMon(string $m): string {
    $map = ['January'=>'Jan','February'=>'Feb','March'=>'Mar','April'=>'Apr','May'=>'May','June'=>'Jun',
            'July'=>'Jul','August'=>'Aug','September'=>'Sep','October'=>'Oct','November'=>'Nov','December'=>'Dec'];
    $m = trim($m);
    return $m === '' ? '' : ($map[$m] ?? mb_substr($m, 0, 3));
}
$yr = substr((string)(int)$ppmp['fy'], -2);

// Load the ASCOT logo as base64 for embedding
// This document renders with the format of its own fiscal year,
// not today's, so a filed document keeps the form it was filed under.
doc_fmt_use_year((int)($ppmp['fy'] ?? 0));
// v94: the columns of this PPMP's year (Document Format); the sheet is as wide as they are
require_once __DIR__ . '/config/ppmp-columns.php';
$__cols = ppmp_cols((int)($ppmp['fy'] ?? 0));
$__hdr  = ppmp_cols_header($__cols, (int)($ppmp['fy'] ?? 0));
$NW = max(count($__cols), 4);            // the width of the sheet, in columns
$NH = intdiv($NW, 2);                    // the signature blocks: half each
$__xw = ['desc' => 130, 'type' => 80, 'qty' => 80, 'mode' => 80, 'pre' => 70, 'start' => 60, 'end' => 60, 'deliv' => 70, 'fund' => 60, 'amount' => 80, 'docs' => 100, 'remarks' => 120];
$logoPath = __DIR__ . '/' . doc_fmt('ppmp', 'logo_left');
$logoData = file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : '';

// Load the Bagong Pilipinas logo as base64 for embedding
$bpPath = __DIR__ . '/' . doc_fmt('ppmp', 'logo_right');
$bpData = file_exists($bpPath) ? base64_encode(file_get_contents($bpPath)) : '';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo "\xEF\xBB\xBF"; // UTF-8 BOM
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]><xml>
<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
  <x:Name>PPMP</x:Name>
  <x:WorksheetOptions>
    <x:PageSetup><x:Layout x:Orientation="Landscape"/></x:PageSetup>
    <x:Print><x:FitWidth>1</x:FitWidth><x:FitHeight>1</x:FitHeight></x:Print>
  </x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>
</xml><![endif]-->
<style>
  table { border-collapse: collapse; font-family: Arial; font-size: 10px; }
  td, th { border: 1px solid #000; padding: 4px; vertical-align: middle; }
  .no-border { border: none !important; }
  .center { text-align: center; }
  .right { text-align: right; }
  .bold { font-weight: bold; }
  .italic { font-style: italic; }
  .big { font-size: 14px; font-weight: bold; }
  .med { font-size: 11px; }
  .small { font-size: 9px; }
  .title { font-size: 12px; font-weight: bold; }
  .group-hdr { font-weight: bold; font-size: 11px; text-align: center; }
  .col-hdr { font-weight: bold; font-size: 10px; text-align: center; }
  .col-num { font-style: italic; font-weight: bold; text-align: center; font-size: 9px; }
  .amt { mso-number-format: "\#\,\#\#0\.00"; text-align: right; }
  .empty-row td { height: 32px; }
</style>
</head>
<body>

<table>
  <!-- ─── Letterhead with ASCOT seal and Bagong Pilipinas logo ─── -->
  <tr>
    <td rowspan="4" class="no-border center" style="width: 90px; padding: 4px;">
      <?php if ($logoData): ?>
        <img src="data:image/png;base64,<?= $logoData ?>" width="80" height="80" alt="ASCOT">
      <?php endif; ?>
    </td>
    <td colspan="<?= $NW - 2 ?>" class="no-border center">
      <div class="med"><?= e(doc_fmt('ppmp', 'rep_line')) ?></div>
    </td>
    <td rowspan="4" class="no-border center" style="width: 80px;">
      <?php if ($bpData): ?>
        <img src="data:image/png;base64,<?= $bpData ?>" width="80" height="80" alt="Bagong Pilipinas">
      <?php endif; ?>
    </td>
  </tr>
  <tr><td colspan="<?= $NW - 2 ?>" class="no-border center"><div class="big"><?= e(doc_fmt('ppmp', 'org_name')) ?></div></td></tr>
  <tr><td colspan="<?= $NW - 2 ?>" class="no-border center"><div class="small italic"><?= e(doc_fmt('ppmp', 'address')) ?></div></td></tr>
  <tr>
    <td colspan="<?= $NW - 2 ?>" class="no-border center">
      <div class="title" style="margin-top:6px;">
        <?= e(doc_fmt('ppmp', 'doc_title')) ?> <?= ($isBlank || $isSignatories) ? '' : e(os_ppmp_no($ppmp['ppmp_no'] ?? '', $ppmp['dept_code'] ?? '')) ?>
      </div>
    </td>
  </tr>
  <tr>
    <td colspan="<?= $NW ?>" class="no-border center bold" style="padding: 10px;">
      <?php if ($isBlank || $isSignatories): ?>
        [  ] INDICATIVE &nbsp;&nbsp;&nbsp;&nbsp; [  ] FINAL
      <?php else: ?>
        <?= $ppmp['is_final'] ? '[  ]' : '[ ✓ ]' ?> INDICATIVE
        &nbsp;&nbsp;&nbsp;&nbsp;
        <?= $ppmp['is_final'] ? '[ ✓ ]' : '[  ]' ?> FINAL
      <?php endif; ?>
    </td>
  </tr>
  <tr><td colspan="<?= $NW ?>" class="no-border" style="height:8px;"></td></tr>

  <!-- ─── Meta ─── -->
  <tr>
    <td colspan="2" class="no-border bold">Fiscal Year:</td>
    <td colspan="<?= $NW - 2 ?>" class="no-border"><?= $isBlank || $isSignatories ? '' : (int)$ppmp['fy'] ?></td>
  </tr>
  <tr>
    <td colspan="2" class="no-border bold">End-User or Implementing Unit:</td>
    <td colspan="<?= $NW - 2 ?>" class="no-border"><?php require_once __DIR__ . '/config/ppmp-unit.php'; ?><?= $isSignatories ? '' : e(ppmp_unit_label($ppmp, (string)($ppmp['dept_code'] ?? ''), (string)$ppmp['dept_name'])) /* v137 */ ?></td>
  </tr>
  <tr><td colspan="<?= $NW ?>" class="no-border" style="height:6px;"></td></tr>

<?php if (!$isSignatories): ?>
  <!-- ─── Table headers (v94: the columns of this year, from Document Format) ─── -->
  <tr>
    <?php foreach ($__hdr['top'] as $__t): ?>
      <?php if (isset($__t['col'])): ?><td rowspan="2" class="col-hdr" style="width:<?= (int)($__xw[$__t['col']['id']] ?? 100) ?>px;"><?= e($__t['col']['label']) ?></td>
      <?php else: ?><td colspan="<?= (int)$__t['span'] ?>" class="group-hdr"><?= e($__t['label']) ?></td><?php endif; ?>
    <?php endforeach; ?>
  </tr>
  <tr>
    <?php foreach ($__hdr['second'] as $__c): ?><td class="col-hdr" style="width:<?= (int)($__xw[$__c['id']] ?? 90) ?>px;"><?= e($__c['label']) ?></td><?php endforeach; ?>
  </tr>
  <tr>
    <?php foreach ($__cols as $__i => $__c): ?><td class="col-num"><?= e(doc_fmt('ppmp', 'col_num_prefix')) ?> <?= $__i + 1 ?></td><?php endforeach; ?>
  </tr>

  <!-- ─── Data rows (empty for blank template) ─── -->
  <?php foreach ($entries as $r):
    $__x = ppmp_entry_extra($r);
    $__cell = [
      'desc'    => '<td>' . e($r['description'] ?? '') . '</td>',
      'type'    => '<td class="center">' . e($r['proj_type'] ?? '') . '</td>',
      'qty'     => '<td class="center">' . e($r['quantity'] ?? '') . '</td>',
      'mode'    => '<td class="center">' . e($r['mode'] ?? '') . '</td>',
      'pre'     => '<td class="center">' . e($r['pre_conference'] ?? '') . '</td>',
      'start'   => '<td class="center">' . ((!empty($r['activity_start'])) ? e(shortMon($r['activity_start']) . '-' . $yr) : '') . '</td>',
      'end'     => '<td class="center">' . ((!empty($r['activity_end']))   ? e(shortMon($r['activity_end'])   . '-' . $yr) : '') . '</td>',
      'deliv'   => '<td class="center">' . ((!empty($r['delivery']))       ? e(shortMon($r['delivery'])       . '-' . $yr) : '') . '</td>',
      'fund'    => '<td class="center">' . e($r['fund'] ?? '') . '</td>',
      'amount'  => '<td class="amt">' . ((!empty($r['amount']) && (float)$r['amount'] > 0) ? number_format((float)$r['amount'], 2, '.', '') : '') . '</td>',
      'docs'    => '<td class="center">' . e($r['supporting_docs'] ?? '') . '</td>',
      'remarks' => '<td>' . e($r['remarks'] ?? '') . '</td>',
    ]; ?>
    <tr class="<?= $isBlank ? 'empty-row' : '' ?>">
      <?php foreach ($__cols as $__c) echo $__c['builtin'] ? $__cell[$__c['id']] : '<td class="center">' . e($__x[$__c['id']] ?? '') . '</td>'; ?>
    </tr>
  <?php endforeach; ?>

  <!-- ─── Total ─── -->
  <?php [$__b, $__a] = ppmp_cols_around_amount($__cols); ?>
  <tr>
    <td colspan="<?= $__b ?>" class="right bold">TOTAL BUDGET:</td>
    <td class="amt bold"><?= $isBlank ? '' : number_format($total, 2, '.', '') ?></td>
    <?php if ($__a > 0): ?><td colspan="<?= $__a ?>"></td><?php endif; ?>
  </tr>

<?php endif; ?>

  <tr><td colspan="<?= $NW ?>" class="no-border" style="height:20px;"></td></tr>

  <!-- ─── Signatures (always present, blank when template) ─── -->
  <tr>
    <td colspan="<?= $NH ?>" class="no-border bold"><?= e(doc_fmt('ppmp', 'sig_prepared') ?: 'Prepared by:') ?></td>
    <td colspan="<?= $NH ?>" class="no-border bold"><?= e(doc_fmt('ppmp', 'sig_approved') ?: 'Approved by:') ?></td>
  </tr>
  <tr><td colspan="<?= $NW ?>" class="no-border" style="height:36px;"></td></tr>
  <tr>
    <td colspan="<?= $NH ?>" class="no-border center bold" style="border-top: 1px solid #000;">
      <?php $__ph = department_head($ppmp['dept_code'] ?? null); ?>
      <?= e(strtoupper(($ppmp['sig_name'] ?? '') ?: ($__ph['name'] ?: ($ppmp['preparer'] ?? '')))) ?>
    </td>
    <td colspan="<?= $NH ?>" class="no-border center bold" style="border-top: 1px solid #000;">
<?php
        /* What is saved on the PPMP wins; a blank one is the department's
           own head, the same person the editor fills in. */
        $__ab = ppmp_approved_by((array)$ppmp);   // the VP, unless typed on purpose
        $__hd = $__ab['vp'];
        $__hdName = $__ab['name'];
        $__hdPos  = $__ab['position'];
      ?>
      <?= e(strtoupper($__hdName)) ?>
    </td>
  </tr>
  <tr>
    <td colspan="<?= $NH ?>" class="no-border center small italic">Signature over Printed Name</td>
    <td colspan="<?= $NH ?>" class="no-border center small italic">Signature over Printed Name</td>
  </tr>
  <tr>
    <td colspan="<?= $NH ?>" class="no-border center small"><?= e($ppmp['sig_position'] ?? '') ?></td>
    <td colspan="<?= $NH ?>" class="no-border center small"><?= e($__hdPos) ?></td>
  </tr>
  <tr>
    <td colspan="<?= $NH ?>" class="no-border center small italic">[Head of the End-User or Implementing Unit]</td>
    <td colspan="<?= $NH ?>" class="no-border center small italic"></td>
  </tr>
  <tr><td colspan="<?= $NW ?>" class="no-border" style="height:16px;"></td></tr>
  <tr>
    <td colspan="<?= $NH ?>" class="no-border small">Date: <?= e($ppmp['date_prepared'] ?? '') ?></td>
    <td colspan="<?= $NH ?>" class="no-border small">Date: <?= e($ppmp['date_submitted_head'] ?? '') ?></td>
  </tr>
</table>

</body>
</html>
