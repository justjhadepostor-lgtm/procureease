<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/annex-extra.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/archive.php';
require_admin();
ensure_admin_tables();

$u   = current_user();
$pdo = db();

$formId = (int)($_GET['id'] ?? 0);
$form = null;
try {
    $s = $pdo->prepare("SELECT a.*, d.name AS dept_name, d.dept_group
                          FROM annex_forms a
                     LEFT JOIN departments d ON d.code = a.dept_code
                         WHERE a.id = ?");
    $s->execute([$formId]);
    $form = $s->fetch();
} catch (Throwable $t) {}

if (!$form) {
    flash('error', 'That annex could not be found.');
    header('Location: annex.php'); exit;
}

$fy     = (int)$form['fy'];
// A past year's annex (restored from the Archive) is for viewing only: open the form itself
if (fy_is_past($fy)) { header('Location: annex-print.php?id=' . (int)$form['id']); exit; }
$status = $form['status'];
$group  = dept_group_folder($form['dept_code'], $form['dept_group'] ?? null);


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

$items = [];
try {
    $it = $pdo->prepare('SELECT * FROM annex_items WHERE form_id = ? ORDER BY sort_order, id');
    $it->execute([$formId]);
    $items = $it->fetchAll();
} catch (Throwable $t) {}

$f = [
    'project_name'        => $form['project_name']        ?? '',
    'general_description' => $form['general_description'] ?? '',
    'nep_account_1'       => $form['nep_account_1']       ?? '',
    'allocated_budget_1'  => $form['allocated_budget_1']  ?? '',
    'nep_account_2'       => $form['nep_account_2']       ?? '',
    'allocated_budget_2'  => $form['allocated_budget_2']  ?? '',
    'inclusive_dates'     => $form['inclusive_dates']     ?? '',
    // NEP Account #1 + #2 (config/annex-extra.php)
    'total_estimated'     => annex_estimated($form) > 0 ? number_format(annex_estimated($form), 2, '.', ',') : '',
];
$unitLine   = annex_unit_line($form, (string)$form['dept_code']);
$itemsTotal = 0.0;
foreach ($items as $__it) $itemsTotal += (float)$__it['total_amount'];

$B_GROUPS = ['Supplies and Materials','Honoraria','Venue (Proposed Location)','Meals and Snack','Others','Registration Fee'];

$activeNav = 'annex';
$pageTitle = 'Annex Review';
// This document renders with the format of its own fiscal year,
// not today's, so a filed document keeps the form it was filed under.
doc_fmt_use_year((int)($form['fy'] ?? 0));
require __DIR__ . '/includes/head.php';
?>
<style>
  .annex-tabs { display:flex; gap:8px; flex-wrap:wrap; margin:14px 0 18px; }
  .annex-tab { padding:9px 22px; border-radius:9px; border:1.5px solid var(--border); background:var(--white); color:var(--ink); font-weight:700; font-size:14px; text-decoration:none; font-family:'Space Grotesk',sans-serif; }
  .annex-tab.active { background:#133458; color:#fff; border-color:var(--ink); }

  /* Sheet mimics an A4/long bond page */
  .annex-sheet { background:var(--white); border:1px solid #cfd8e2; border-radius:8px; padding:30px 40px; max-width:820px; margin:0 auto; font-family: Arial, 'Helvetica Neue', sans-serif; color:var(--ink); }
  .annex-head { text-align:center; margin-bottom:14px; }
  .annex-head .rep { font-size:12px; }
  .annex-head .sch { font-weight:bold; font-size:14px; }
  .annex-head .addr { font-size:11px; }
  .annex-head .unit { font-size:12px; font-weight:bold; color:#c00000; margin-top:2px; }
  .annex-head .atitle { font-size:16px; font-weight:bold; letter-spacing:4px; margin-top:12px; }
  .annex-head .asub { font-size:12.5px; }
  .annex-head .afy { font-size:12px; }
  .annex-note-bar { background:#ffff00; padding:5px 8px; font-style:italic; font-size:11.5px; line-height:1.5; margin-bottom:0; }

  /* Field box grid — bordered like the form */
  table.annex-fields { width:100%; border-collapse:collapse; font-size:12px; }
  table.annex-fields td { border:1px solid #000; padding:4px 7px; vertical-align:middle; }
  table.annex-fields td.lb { width:38%; }
  table.annex-fields td.lb small { color:var(--ink); font-size:10px; font-style:italic; }
  table.annex-fields td.colon { width:16px; text-align:center; }
  table.annex-fields td.nn { width:34px; text-align:left; font-weight:bold; }
  table.annex-fields .section-bar td { background:#808080; -webkit-print-color-adjust:exact; print-color-adjust:exact; height:16px; }
  table.annex-fields td.gray-cell { background:#808080; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  table.annex-fields input { width:100%; box-sizing:border-box; border:none; background:transparent; font-size:12px; font-family:inherit; padding:2px 3px; }
  table.annex-fields input.num { text-align:right; }
  table.annex-fields input:focus { outline:1px solid #4A7AAD; }
  table.annex-fields input::placeholder { color:#aaa; font-style:italic; }
  table.annex-tbl input::placeholder { color:#bbb; font-style:italic; }
  table.annex-fields td.bold { font-weight:bold; font-style:italic; }

  /* Items table */
  table.annex-tbl { width:100%; border-collapse:collapse; margin-top:16px; font-size:12px; }
  table.annex-tbl th, table.annex-tbl td { border:1px solid #000; padding:4px 7px; }
  table.annex-tbl th { text-align:center; font-weight:bold; font-size:12px; }
  table.annex-tbl td.num { text-align:right; }
  table.annex-tbl tr.grp-row td { background:#808080; -webkit-print-color-adjust:exact; print-color-adjust:exact; color:var(--ink); font-style:italic; font-weight:bold; }
  table.annex-tbl td input { width:100%; box-sizing:border-box; border:none; background:transparent; padding:3px 4px; font-size:12px; font-family:inherit; }
  table.annex-tbl td.num input { text-align:right; }
  table.annex-tbl td input:focus { outline:1px solid #4A7AAD; }
  table.annex-tbl tr.total-row td { font-weight:bold; font-style:italic; text-align:center; }
  table.annex-tbl .delrow-a { border:none; background:none; color:#dc2626; font-size:15px; cursor:pointer; }
  table.annex-tbl .addrow-a { border:1px solid #4A7AAD; background:var(--white); color:var(--navy-500); border-radius:5px; padding:0 7px; font-size:12px; font-weight:700; cursor:pointer; }
  .annex-instr { margin-top:20px; font-size:10.5px; line-height:1.6; }
  .annex-instr .h { font-weight:bold; }
</style>
<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title"><?= e($form['dept_name'] ?: $form['dept_code']) ?> · Annex</h1>
        <p class="page-sub"><?= e($group) ?> · FY <?= $fy ?><?php if (!empty($form['submitted_by'])): ?> · Submitted by <?= e($form['submitted_by']) ?><?php endif; ?></p>
      </div>
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;" data-dock>
        <a href="annex.php?group=<?= urlencode($group) ?>" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">← Back</a>
        <?php require_once __DIR__ . '/config/doc-versions.php'; echo doc_history_button('annex', (string)$formId);   /* v79 */ ?>
        <a href="annex-print.php?id=<?= $formId ?>" target="_blank" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">🖨️ Print</a>
        <?= arc_button(current_user(), arc_load('annex', $formId), 'annex-review.php?id=' . $formId) ?>
        <?php if ($status !== 'approved'): ?>
          <button type="button" onclick="askRevision()" class="btn btn-ghost" style="padding:8px 16px; font-size:13px; color:#C2410C;">↩ Send back for revision</button>
          <button type="button" onclick="askApprove()" class="btn btn-primary" style="padding:8px 18px; font-size:13px;">✓ Approve</button>
        <?php else: ?>
          <span style="display:inline-flex; align-items:center; gap:6px; background:#f0fdf4; color:#14532d; border:1.5px solid #86efac; padding:8px 18px; border-radius:10px; font-size:13px; font-weight:700;">✓ Approved</span>
        <?php endif; ?>
      </div>
    </div>
    <?php render_flashes(); ?>

    <?php
      $badge = [
        'submitted' => ['#1c4c86', '#EAF4F9', '📤 Awaiting review'],
        'approved'  => ['#14532d', '#f0fdf4', '✓ Approved'],
        'revision'  => ['#C2410C', '#FFF4EC', '↩ Sent back for revision'],
      ][$status] ?? ['#5A7896', '#f3f4f6', $status];
    ?>
    <div style="margin-bottom:14px;">
      <span style="background:<?= $badge[1] ?>; color:<?= $badge[0] ?>; padding:5px 14px; border-radius:999px; font-size:12px; font-weight:700;"><?= $badge[2] ?></span>
    </div>

    <div class="annex-sheet" id="annexSheet">
      <div class="annex-head">
        <div class="rep"><?= e(doc_fmt('annex', 'rep_line')) ?></div>
        <div class="sch"><?= e(doc_fmt('annex', 'org_name')) ?></div>
        <div class="addr"><?= e(doc_fmt('annex', 'address')) ?></div>
        <div class="unit"><?= e(mb_strtoupper($unitLine)) ?></div>
        <div class="atitle">A N N E X&nbsp;&nbsp;<?= e($form['annex'] ?? 'B') ?></div>
        <div class="asub"><?= e($subtitle) ?></div>
        <div class="afy"><?= e(doc_fmt('annex', 'lbl_fy')) ?> <?= $fy ?></div>
      </div>

      <div class="annex-note-bar">
        *Use another sheet for different projects/program/activity<br>
        *<?= e($itemNote) ?>
      </div>

      <table class="annex-fields">
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_project')) ?></td>
          <td class="colon">:</td>
          <td colspan="2" style="text-align:center;"><input id="f_project_name" value="<?= e($f['project_name'] !== '' ? $f['project_name'] : 'Trainings and Seminars') ?>" style="text-align:center;"></td>
        </tr>
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_general')) ?><br><small>(As categorized in the PPMP)</small></td>
          <td class="colon">:</td>
          <td colspan="2" style="text-align:center;"><input id="f_general_description" value="<?= e($f['general_description']) /* v102: blank until the unit types it */ ?>" style="text-align:center;"></td>
        </tr>
        <tr><td colspan="2" style="text-align:center;"><?= e(doc_fmt('annex', 'lbl_item')) ?></td><td colspan="2" class="gray-cell">&nbsp;</td></tr>
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_nep')) ?></td>
          <td class="nn">#1</td>
          <td colspan="2"><input id="f_nep_account_1" value="<?= e($f['nep_account_1']) ?>" placeholder="e.g. Training and Seminar"></td>
        </tr>
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_alloc')) ?></td>
          <td class="colon">:</td>
          <td colspan="2"><input id="f_allocated_budget_1" class="num money" inputmode="decimal" value="<?= e($f['allocated_budget_1']) ?>" placeholder="e.g. 20,000.00"></td>
        </tr>
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_nep')) ?><br><small>(If NEP Account #1 allocation is not sufficient)</small></td>
          <td class="nn">#2</td>
          <td colspan="2"><input id="f_nep_account_2" value="<?= e($f['nep_account_2']) ?>" placeholder="Alternative NEP Account"></td>
        </tr>
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_alloc')) ?></td>
          <td class="colon">:</td>
          <td colspan="2"><input id="f_allocated_budget_2" class="num money" inputmode="decimal" value="<?= e($f['allocated_budget_2']) ?>" placeholder="e.g. 20,000.00"></td>
        </tr>
        <tr>
          <td class="lb bold"><?= e(doc_fmt('annex', 'lbl_totbudget')) ?></td>
          <td class="colon">:</td>
          <td colspan="2"><input id="f_total_estimated" class="num money" inputmode="decimal" value="<?= e($f['total_estimated'] ?? '') ?>" placeholder="17,500.00" style="font-weight:bold; font-style:italic;" readonly></td>
        </tr>
        <tr>
          <td class="lb" style="font-style:italic;"><?= e(doc_fmt('annex', 'lbl_dates')) ?> <small>(tentative)</small></td>
          <td class="colon">:</td>
          <td colspan="2"><input id="f_inclusive_dates" value="<?= e($f['inclusive_dates'] !== '' ? $f['inclusive_dates'] : 'as per schedule') ?>" style="text-align:center;"></td>
        </tr>
      </table>

      <table class="annex-tbl" id="annexTable">
        <thead>
          <tr>
            <th style="width:56%;"><?= e(doc_fmt('annex', 'col_items')) ?></th>
            <th style="width:16%;"><?= e(doc_fmt('annex', 'col_qty')) ?></th>
            <th style="width:24%;"><?= e(doc_fmt('annex', 'col_total')) ?></th>
            <th style="width:30px; border:none;"></th>
          </tr>
        </thead>
        <tbody id="annexBody">
          <?php
            $byGroup = [];
            foreach ($items as $row) {
                $g = $row['item_group'] !== '' ? $row['item_group'] : 'Others';
                $byGroup[$g][] = $row;
            }
            foreach ($B_GROUPS as $g):
              $groupRows = $byGroup[$g] ?? [];
              // Show at least 5 blank rows per group (like the printed form), plus any saved rows
              $blankCount = max(5 - count($groupRows), 1);
          ?>
            <tr class="grp-row" data-group="<?= e($g) ?>">
              <td colspan="3"><?= e($g) ?></td>
              <td style="text-align:center; border:none;"><button type="button" class="addrow-a" title="Add item to <?= e($g) ?>" onclick="addRowA(this)">＋</button></td>
            </tr>
            <?php foreach ($groupRows as $row): ?>
              <tr class="item-row" data-group="<?= e($g) ?>">
                <td><input class="i-spec" value="<?= e($row['specification']) ?>"></td>
                <td class="num"><input class="i-qty" value="<?= $row['quantity']!=0 ? rtrim(rtrim(number_format((float)$row['quantity'],2,'.',''),'0'),'.') : '' ?>" oninput="recalc()"></td>
                <td class="num"><input class="i-amt money" inputmode="decimal" value="<?= $row['total_amount']!=0 ? number_format((float)$row['total_amount'],2,'.','') : '' ?>" oninput="recalc()"></td>
                <td style="text-align:center; border:none;"><button type="button" class="delrow-a" onclick="delRowA(this)">×</button></td>
              </tr>
            <?php endforeach; ?>
            <?php for ($k=0; $k<$blankCount; $k++): ?>
              <tr class="item-row" data-group="<?= e($g) ?>">
                <td><input class="i-spec"></td>
                <td class="num"><input class="i-qty" oninput="recalc()"></td>
                <td class="num"><input class="i-amt money" inputmode="decimal" oninput="recalc()"></td>
                <td style="text-align:center; border:none;"><button type="button" class="delrow-a" onclick="delRowA(this)">×</button></td>
              </tr>
            <?php endfor; ?>
          <?php endforeach; ?>
          <tr class="total-row">
            <td style="text-align:center; font-weight:bold; font-style:italic;"><?= e(doc_fmt('annex', 'col_total')) ?></td>
            <td></td>
            <td class="num" id="annexTotal" style="font-weight:bold; font-style:italic;">₱<?= number_format($itemsTotal, 2) ?></td>
            <td style="border:none;"></td>
          </tr>
        </tbody>
      </table>

      <div class="annex-instr">
        <div class="h"><?= e(doc_fmt('annex', 'instr_title')) ?></div>
        <?= doc_block('annex', 'instr_body') ?>
      </div>
    </div>

    
  </main>
</div>

<!-- Hidden forms for the modal actions -->
<form method="post" action="api/annex-review-action.php" id="approveForm" style="display:none;">
  <input type="hidden" name="id" value="<?= $formId ?>">
  <input type="hidden" name="do" value="approve">
</form>
<form method="post" action="api/annex-review-action.php" id="revisionForm" style="display:none;">
  <input type="hidden" name="id" value="<?= $formId ?>">
  <input type="hidden" name="do" value="revision">
  <input type="hidden" name="note" id="revisionNote" value="">
</form>

<script>
  // Everything here is read-only for the reviewer
  document.querySelectorAll('.annex-sheet input, .annex-sheet textarea').forEach(function(el){
    el.setAttribute('readonly','readonly');
    el.style.background = 'transparent';
  });
  document.querySelectorAll('.annex-sheet .addrow-a, .annex-sheet .delrow-a').forEach(function(el){ el.style.display = 'none'; });

  function askApprove(){
    swiftConfirm({
      title: 'Approve this annex?',
      text: 'This marks the department\u2019s annex as approved. It can no longer be approved again afterwards.',
      confirmLabel: 'Yes, approve',
      tone: 'info',
      onConfirm: function(){ document.getElementById('approveForm').submit(); }
    });
  }

  function askRevision(){
    swiftConfirm({
      title: 'Send back for revision?',
      text: 'The department will be able to edit and resubmit their annex.',
      confirmLabel: 'Send back',
      tone: 'danger',
      onConfirm: function(){
        const note = prompt('Note to the department (optional):') || '';
        document.getElementById('revisionNote').value = note;
        document.getElementById('revisionForm').submit();
      }
    });
  }

  function recalc(){}
  function addRowA(){}
  function delRowA(){}
</script>
</body>
</html>
