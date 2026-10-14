<?php
/**
 * Report view — shows the departments' own documents, laid out exactly as the
 * units filled them in. One page per department, ready to print.
 */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/richtext.php';
require_once __DIR__ . '/config/doc-format.php';  // the approving official's name lives there
require_admin();
ensure_admin_tables();

$u   = current_user();
$pdo = db();

$type = $_GET['type'] ?? 'ppmp';
if (!in_array($type, ['ppmp', 'nonps', 'scoping'], true)) $type = 'ppmp';

$fy = (int)($_GET['fy'] ?? active_fy());
if ($fy < 2000 || $fy > 2100) $fy = active_fy();
doc_fmt_use_year($fy);   // v94: the rates and wording of the year being reported

$TITLES = [
    'ppmp'    => 'Project Procurement Management Plan',
    'nonps'   => 'List of Non-PS Supplies and Materials',
    'scoping' => 'Market Scoping',
];

// ─── Gather the approved documents for this year ───
$docs = [];
try {
    if ($type === 'ppmp') {
        $q = $pdo->prepare("SELECT p.id, p.dept_code, p.fy, p.submitted_by, d.name AS dept_name
                              FROM ppmps p
                         LEFT JOIN departments d ON d.code = p.dept_code
                             WHERE p.fy = ? AND p.status = 'approved'
                          ORDER BY d.name, p.dept_code");
        $q->execute([$fy]);
        foreach ($q->fetchAll() as $row) {
            $e = $pdo->prepare("SELECT * FROM ppmp_entries WHERE ppmp_id = ? ORDER BY id");
            $e->execute([$row['id']]);
            $row['entries'] = $e->fetchAll();
            $docs[] = $row;
        }
    } elseif ($type === 'nonps') {
        $q = $pdo->prepare("SELECT s.*, d.name AS dept_name
                              FROM nonps_submissions s
                         LEFT JOIN departments d ON d.code = s.dept_code
                             WHERE s.fy = ? AND s.status = 'approved'
                               AND (s.archived IS NULL OR s.archived = 0)
                          ORDER BY d.name, s.dept_code");
        $q->execute([$fy]);
        foreach ($q->fetchAll() as $row) {
            $i = $pdo->prepare("SELECT * FROM nonps_items WHERE submission_id = ? ORDER BY item_no, id");
            $i->execute([$row['id']]);
            $row['items'] = $i->fetchAll();
            $docs[] = $row;
        }
    } else {
        $q = $pdo->prepare("SELECT m.*, d.name AS dept_name
                              FROM market_scoping m
                         LEFT JOIN departments d ON d.code = m.dept_code
                             WHERE m.fy = ? AND m.status = 'approved'
                          ORDER BY d.name, m.dept_code");
        $q->execute([$fy]);
        $docs = $q->fetchAll();
    }
} catch (Throwable $t) {}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<title><?= e($TITLES[$type]) ?> — FY <?= $fy ?></title>
<style>
  @page { size: Letter portrait; margin: 0.5in; }
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; background: var(--navy-50); margin: 0; padding: 22px; color: var(--ink); }

  .toolbar { max-width: 8.5in; margin: 0 auto 12px; }
  .toolbar .btn {
    display: inline-block; padding: 8px 18px; border-radius: 8px; background: #2f578a;
    color: white; border: none; font-weight: 600; font-size: 12.5px; cursor: pointer;
    font-family: Arial, sans-serif; margin-right: 6px; text-decoration: none;
  }
  .toolbar .btn.ghost { background: white; color: var(--ink); border: 1.5px solid var(--border); }

  /* Same sheet the departments print from */
  .paper {
    max-width: 8.5in; margin: 0 auto 22px; background: white; padding: 0.5in;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
  }

  .letterhead { display: flex; flex-direction: column; align-items: center; gap: 4px; margin-bottom: 4px; text-align: center; }
  .letterhead img { width: 72px; height: 72px; object-fit: contain; display: block; margin-bottom: 4px;
                    -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .letterhead .rp     { font-size: 10pt; }
  .letterhead .school { font-size: 14pt; font-weight: 700; margin: 2px 0; }
  .letterhead .addr   { font-size: 10pt; }

  .lh-section { text-align: center; font-weight: 700; font-size: 11pt; padding: 6px 0 2px; }
  .lh-title   { text-align: center; font-weight: 700; font-size: 11pt; padding: 8px 0 0; }
  .lh-fy      { text-align: center; font-size: 10pt; padding: 2px 0 12px; }

  table.items { width: 100%; border-collapse: collapse; font-size: 10pt; margin-bottom: 20px; }
  table.items th, table.items td { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }
  table.items th { text-align: center; font-weight: 700; background: white; }
  table.items td.center { text-align: center; }
  table.items td.right  { text-align: right; }
  table.items tfoot td { font-weight: 700; }

  .signatory { margin-top: 20px; font-size: 10pt; }
  .sig-prep-label { margin-bottom: 42px; }
  .sig-name { font-weight: 700; text-transform: uppercase; font-size: 10pt; }
  .sig-pos  { font-size: 10pt; }

  /* Market Scoping keeps its own document typeface */
  .scoping-title { text-align: center; font-weight: 700; font-size: 12pt; margin: 10px 0 16px; }
  .scoping-body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.5; }
  .scoping-body img { max-width: 100%; height: auto; }
  .scoping-body table { border-collapse: collapse; }
  .scoping-body table td, .scoping-body table th { border: 1px solid #66a3bf; padding: 5px 8px; }

  .print-tip { max-width: 8.5in; margin: 0 auto 12px; padding: 10px 14px; background: #fff8e1;
               border-left: 4px solid #f59e0b; font-size: 12px; color: #92400e; border-radius: 6px; }
  .empty { max-width: 8.5in; margin: 0 auto; background: var(--white); padding: 40px; text-align: center;
           color: var(--muted); border-radius: 8px; }

  @media print {
    .toolbar, .print-tip { display: none !important; }
    body { background: white; padding: 0; }
    .paper { box-shadow: none; padding: 0; max-width: none; margin: 0; page-break-after: always; }
    .paper:last-of-type { page-break-after: auto; }
  }
  /* v96: on a phone the report scrolls inside its sheet */
  @media screen and (max-width: 900px) { .paper { overflow-x: auto; max-width: 100%; } }
</style></head>
<body>

<div class="toolbar">
  <a href="reports.php" class="btn ghost">← Back</a>
  <button onclick="window.print()" class="btn">🖨️ Print</button>
</div>


<div class="print-tip">
  <strong>Names and positions can be corrected here before printing.</strong>
  To remove the URL and timestamp from the printout, expand <strong>"More settings"</strong>
  in the print dialog and uncheck <strong>"Headers and footers"</strong>.
</div>

<?php if (!$docs): ?>
  <div class="empty">
    No approved <?= e($TITLES[$type]) ?> for FY <?= $fy ?> yet.<br>
    Documents appear here once the Procurement Unit has approved them.
  </div>
<?php endif; ?>

<?php foreach ($docs as $doc): ?>
  <div class="paper">

    <div class="letterhead">
      <img src="assets/ascot-logo.png" alt="ASCOT">
      <div class="rp">Republic of the Philippines</div>
      <div class="school">AURORA STATE COLLEGE OF TECHNOLOGY</div>
      <div class="addr">Baler, Aurora</div>
    </div>
    <div class="lh-section">PROCUREMENT UNIT</div>
    <div class="lh-title"><?= e(strtoupper($TITLES[$type])) ?></div>
    <div class="lh-fy">
      FY <?= $fy ?> &nbsp;·&nbsp; <?= e($doc['dept_name'] ?: $doc['dept_code']) ?>
    </div>

    <?php if ($type === 'ppmp'):
      $total = 0; foreach ($doc['entries'] as $en) $total += (float)$en['amount']; ?>
      <table class="items">
        <thead>
          <tr>
            <th style="width:30%;">Description</th>
            <th style="width:12%;">Type of Project</th>
            <th style="width:18%;">Mode of Procurement</th>
            <th style="width:15%;">Schedule</th>
            <th style="width:9%;">Source of Funds</th>
            <th style="width:16%;">Estimated Budget</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($doc['entries'] as $en): ?>
            <tr>
              <td><?= e($en['description']) ?></td>
              <td class="center"><?= e($en['proj_type'] ?? '') ?></td>
              <td><?= e($en['mode'] ?? '') ?></td>
              <td class="center"><?= e(trim(($en['activity_start'] ?? '') . ' – ' . ($en['activity_end'] ?? ''), ' –')) ?></td>
              <td class="center"><?= e($en['fund'] ?? '') ?></td>
              <td class="right"><?= number_format((float)$en['amount'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$doc['entries']): ?>
            <tr><td colspan="6" class="center" style="padding:14px; font-style:italic;">No entries.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="5" class="center">TOTAL</td>
              <td class="right"><?= number_format($total, 2) ?></td></tr>
        </tfoot>
      </table>
      <div class="signatory">
        <div class="sig-prep-label">Prepared by:</div>
        <div class="sig-name" contenteditable="true" spellcheck="false" style="outline:none; border-bottom:1px dashed transparent;" onmouseover="this.style.borderBottomColor='var(--border)'" onmouseout="this.style.borderBottomColor='transparent'"><?= e($doc['submitted_by'] ?? '') ?></div>
        <div class="sig-pos" contenteditable="true" spellcheck="false" style="outline:none; border-bottom:1px dashed transparent;" onmouseover="this.style.borderBottomColor='var(--border)'" onmouseout="this.style.borderBottomColor='transparent'"><?= e($doc['dept_name'] ?: $doc['dept_code']) ?></div>
      </div>

    <?php elseif ($type === 'nonps'):
      $total = 0; foreach ($doc['items'] as $it) $total += (float)$it['total_cost']; ?>
      <table class="items">
        <colgroup>
          <col style="width:44px;"><col><col style="width:65px;"><col style="width:70px;">
          <col style="width:90px;"><col style="width:100px;">
        </colgroup>
        <thead>
          <tr>
            <th>No.</th><th>Particulars</th><th>Unit</th>
            <th>Quantity</th><th>Unit Cost</th><th>Total Cost</th>
          </tr>
        </thead>
        <tbody>
          <?php $n = 1; foreach ($doc['items'] as $it): ?>
            <tr>
              <td class="center"><?= $n++ ?></td>
              <td><?= e($it['particulars']) ?></td>
              <td class="center"><?= e($it['unit']) ?></td>
              <td class="center"><?= (int)$it['quantity'] ?></td>
              <td class="right"><?= number_format((float)$it['unit_cost'], 2) ?></td>
              <td class="right"><?= number_format((float)$it['total_cost'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$doc['items']): ?>
            <tr><td colspan="6" class="center" style="padding:14px; font-style:italic;">No items.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="5" class="center">TOTAL</td>
              <td class="right"><?= number_format($total, 2) ?></td></tr>
          <?php require_once __DIR__ . '/config/price-provision.php'; $__np = nonps_provision((float)$total);   // + VAT, logistics, inflation ?>
          <?= nonps_charge_rows($__np, 'colspan="5" class="center"', 'class="right"', false) ?>
          <tr><td colspan="5" class="center"><?= e(doc_fmt('nonps', 'lbl_grand')) ?></td>
              <td class="right" style="text-decoration: underline double;"><?= number_format($__np['grand'], 2) ?></td></tr>
        </tfoot>
      </table>
      <div class="signatory">
        <div class="sig-prep-label">Prepared by:</div>
        <div class="sig-name" contenteditable="true" spellcheck="false" style="outline:none; border-bottom:1px dashed transparent;" onmouseover="this.style.borderBottomColor='var(--border)'" onmouseout="this.style.borderBottomColor='transparent'"><?= e(($doc['prepared_by'] ?? '') ?: ($doc['submitted_by'] ?? '')) ?></div>
        <div class="sig-pos" contenteditable="true" spellcheck="false" style="outline:none; border-bottom:1px dashed transparent;" onmouseover="this.style.borderBottomColor='var(--border)'" onmouseout="this.style.borderBottomColor='transparent'"><?= e($doc['dept_name'] ?: $doc['dept_code']) ?></div>
        <div style="margin-top:26px;">
          <div class="sig-name" contenteditable="true" spellcheck="false" style="outline:none; border-bottom:1px dashed transparent;" onmouseover="this.style.borderBottomColor='var(--border)'" onmouseout="this.style.borderBottomColor='transparent'"><?= e(doc_signatory('nonps', 'sig_head_name', procurement_head(), 'name')) ?></div>
          <div class="sig-pos" contenteditable="true" spellcheck="false" style="outline:none; border-bottom:1px dashed transparent;" onmouseover="this.style.borderBottomColor='var(--border)'" onmouseout="this.style.borderBottomColor='transparent'"><?= e(doc_signatory('nonps', 'sig_head_position', procurement_head(), 'position')) ?></div>
        </div>
      </div>

    <?php else: ?>
      <?php if (!empty($doc['title'])): ?>
        <div class="scoping-title"><?= e($doc['title']) ?></div>
      <?php endif; ?>
      <div class="scoping-body"><?= rt_sanitize((string)$doc['content']) ?></div>
    <?php endif; ?>

  </div>
<?php endforeach; ?>

<?php $__pu = current_user(); if (!empty($__pu['id'])): /* Back stays in your section (assets/back-nav.js) */ ?>
<script>window.PE_UID = <?= (int)$__pu['id'] ?>;</script><script src="assets/back-nav.js"></script>
<?php endif; ?>
</body></html>
