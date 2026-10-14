<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/price-provision.php';   // + VAT, logistics, inflation
require_admin();
ensure_admin_tables();
$u = current_user();
$activeNav = 'nonps-consolidated';
$pageTitle = 'Non-PS Review';
$pdo = db();

$subId = (int)($_GET['sub'] ?? 0);

// Load the submission
$sub = null;
try {
    $q = $pdo->prepare("SELECT s.*, d.name AS dept_name, d.dept_group
                          FROM nonps_submissions s
                          LEFT JOIN departments d ON d.code = s.dept_code
                         WHERE s.id = ?");
    $q->execute([$subId]);
    $sub = $q->fetch();
} catch (Throwable $t) {}

if (!$sub) {
    flash('error', 'That Non-PS submission could not be found.');
    header('Location: nonps-consolidated.php'); exit;
}

// A past year's list (restored from the Archive) is for viewing only: open the list itself
if (fy_is_past((int)$sub['fy'])) { header('Location: nonps-print.php?id=' . (int)$sub['id']); exit; }
$group = dept_group_folder($sub['dept_code'], $sub['dept_group'] ?? null);

// Load items
$items = [];
try {
    $q = $pdo->prepare("SELECT * FROM nonps_items WHERE submission_id = ? ORDER BY item_no, id");
    $q->execute([$subId]);
    $items = $q->fetchAll();
} catch (Throwable $t) {}

$grandTotal = 0;
foreach ($items as $it) $grandTotal += (float)$it['total_cost'];

// This document renders with the format of its own fiscal year,
// not today's, so a filed document keeps the form it was filed under.
doc_fmt_use_year((int)($sub['fy'] ?? 0));
require __DIR__ . '/includes/head.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title"><?= e($sub['dept_name'] ?: $sub['dept_code']) ?> · Non-PS Supplies</h1>
        <p class="page-sub"><?= e($group) ?> · FY <?= (int)$sub['fy'] ?><?php if (!empty($sub['submitted_by'])): ?> · Submitted by <?= e($sub['submitted_by']) ?><?php endif; ?></p>
      </div>
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;" data-dock>
        <a href="nonps-consolidated.php?group=<?= urlencode($group) ?>" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">← Back</a>
        <?php require_once __DIR__ . '/config/doc-versions.php'; echo doc_history_button('nonps', (string)$subId);   /* v79 */ ?>
        <a href="nonps-print.php?sub=<?= $subId ?>" target="_blank" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">🖨️ Print</a>
        <?= arc_button(current_user(), arc_load('nonps', $subId), 'nonps-review.php?sub=' . $subId) ?>
        <?php
          // v77: editing a department's list needs its permission (config/edit-permission.php)
          require_once __DIR__ . '/config/edit-permission.php';
          $__withPu  = (string)$sub['status'] === 'submitted' && (int)($sub['archived'] ?? 0) !== 1;
          $__edState = pu_doc_edit_state('nonps', (string)$subId, (string)$sub['dept_code']);
          $__canEdit = $__withPu && in_array($__edState['state'], ['own', 'granted'], true);
        ?>
        <?php if ($sub['status'] !== 'approved'): ?>
          <?php if ($__canEdit): ?>
          <button type="button" id="addBtn" onclick="enableEdit()" class="btn btn-secondary" style="padding:8px 16px; font-size:13px;"
                  title="<?= $__edState['state'] === 'granted' ? e($sub['dept_code']) . ' granted you permission. ' : '' ?>Change, add or remove items. Editing closes once you approve it or send it back.">✎ Edit Non-PS</button>
          <?php elseif ($__withPu && $__edState['state'] === 'pending'): ?>
          <span class="btn btn-ghost" id="editPending" style="padding:8px 14px; font-size:13px; cursor:default;" title="Waiting for <?= e($sub['dept_code']) ?> to answer">⏳ Edit request sent</span>
          <?php elseif ($__withPu): ?>
          <button type="button" id="askEditBtn" onclick="document.getElementById('askEditCard').hidden=false; document.getElementById('askEditReason').focus();" class="btn btn-secondary" style="padding:8px 16px; font-size:13px;">✎ Request to edit</button>
          <?php endif; ?>
          <button type="button" onclick="askRevision()" class="btn btn-ghost" style="padding:8px 16px; font-size:13px; color:#b45309;">↩ Send back for revision</button>
          <button type="button" onclick="askApprove()" class="btn btn-primary" style="padding:8px 18px; font-size:13px;">✓ Approve</button>
        <?php else: ?>
          <span style="display:inline-flex; align-items:center; gap:6px; background:#f0fdf4; color:#14532d; border:1.5px solid #86efac; padding:8px 18px; border-radius:10px; font-size:13px; font-weight:700;">✓ Approved</span>
        <?php endif; ?>
      </div>
    </div>
    <?php render_flashes(); ?>

    <?php if (!empty($__withPu) && !$__canEdit && $__edState['state'] !== 'pending'): ?>
    <div class="card" id="askEditCard" style="margin-bottom:14px;"<?= $__edState['state'] === 'declined' ? '' : ' hidden' ?>>
      <div class="card-title">Ask <?= e($sub['dept_code']) ?> for permission to edit</div>
      <div class="card-sub"><?= $__edState['state'] === 'declined'
          ? e($sub['dept_code']) . ' declined your last request. You can ask again with your reason.'
          : 'The list is the department&#39;s. Say what you need to change; you can edit it once they grant it.' ?></div>
      <form method="POST" action="api/request-edit.php">
        <input type="hidden" name="doc_type" value="nonps">
        <input type="hidden" name="sub_id" value="<?= (int)$subId ?>">
        <label for="askEditReason" style="display:block; font-size:12px; font-weight:700; margin-bottom:6px;">Reason</label>
        <textarea class="form-textarea" id="askEditReason" name="reason" maxlength="1000" required style="width:100%; min-height:70px; box-sizing:border-box;"
                  placeholder="e.g. Correct the unit cost of the certificates to the canvassed price."></textarea>
        <button type="submit" class="btn btn-primary" style="margin-top:10px; padding:8px 16px; font-size:13px;">Send request</button>
      </form>
    </div>
    <?php endif; ?>

    <?php
      $status = $sub['status'];
      $badge = [
        'submitted' => ['#1c4c86', '#EAF4F9', '📤 Awaiting review'],
        'approved'  => ['#14532d', '#f0fdf4', '✓ Approved'],
        'revision'  => ['#b45309', '#fffbeb', '↩ Sent back for revision'],
      ][$status] ?? ['#64748B', '#f3f4f6', $status];
    ?>
    <div style="margin-bottom:14px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <span style="background:<?= $badge[1] ?>; color:<?= $badge[0] ?>; padding:5px 14px; border-radius:999px; font-size:12px; font-weight:700;"><?= $badge[2] ?></span>
      <?php if ($sub['status'] === 'approved'): ?>
        <span style="font-size:12px; color:var(--muted);">🔒 This list has been approved and can no longer be edited.</span>
      <?php else: ?>
        <span id="viewHint" style="font-size:12px; color:var(--muted);"><?php if ($__canEdit): ?>👁️ Viewing — click <strong>✎ Edit Non-PS</strong> above to change, add or remove items. It can be edited until you approve it or send it back.<?php elseif ($__withPu): ?>👁️ Viewing — to change it, ask <?= e($sub['dept_code']) ?> for permission first (<strong>✎ Request to edit</strong>).<?php else: ?>👁️ Viewing.<?php endif; ?></span>
      <?php endif; ?>
    </div>

    <form method="post" action="api/nonps-review-action.php" id="reviewForm">
      <input type="hidden" name="sub" value="<?= $subId ?>">
      <input type="hidden" name="do" value="save">
      <div class="card doc-paper" style="padding:32px 40px; background:white; overflow-x:auto;">
        <!-- Letterhead — same as what the user sees -->
        <div style="display:flex; flex-direction:column; align-items:center; gap:4px; margin-bottom:4px; text-align:center;">
          <?= doc_logo_pair('nonps', 'style="width:72px; height:72px; object-fit:contain; display:block; margin-bottom:4px;"') ?>
          <div style="font-family:Arial,sans-serif; font-size:12px;"><?= e(doc_fmt('nonps', 'rep_line')) ?></div>
          <div style="font-family:Arial,sans-serif; font-size:16px; font-weight:700; margin:2px 0;"><?= e(doc_fmt('nonps', 'org_name')) ?></div>
          <div style="font-family:Arial,sans-serif; font-size:12px;"><?= e(doc_fmt('nonps', 'address')) ?></div>
        </div>
        <div style="text-align:center; font-family:Arial,sans-serif; font-weight:700; font-size:13px; padding-top:8px;"><?= e(doc_fmt('nonps', 'unit_line')) ?></div>
        <div style="text-align:center; font-family:Arial,sans-serif; font-weight:700; font-size:13px; padding-top:8px;"><?= e(doc_fmt('nonps', 'doc_title')) ?></div>
        <div style="text-align:center; font-family:Arial,sans-serif; font-size:12px; padding:2px 0 18px;">FY <?= (int)$sub['fy'] ?></div>

        <table style="width:100%; border-collapse:collapse; font-family:Arial,sans-serif; font-size:12px; table-layout:fixed; background:white;">
          <colgroup>
            <col style="width:44px;"><col><col style="width:70px;"><col style="width:75px;"><col style="width:100px;"><col style="width:110px;">
            <col style="width:36px;" id="delCol">
          </colgroup>
          <thead>
            <tr>
              <th style="border:1px solid #000; padding:6px 4px; font-weight:700; text-align:center; background:white;"><?= e(doc_fmt('nonps', 'col_no')) ?></th>
              <th style="border:1px solid #000; padding:6px 8px; font-weight:700; text-align:center; background:white;"><?= e(doc_fmt('nonps', 'col_part')) ?></th>
              <th style="border:1px solid #000; padding:6px 4px; font-weight:700; text-align:center; background:white;"><?= e(doc_fmt('nonps', 'col_unit')) ?></th>
              <th style="border:1px solid #000; padding:6px 4px; font-weight:700; text-align:center; background:white;"><?= e(doc_fmt('nonps', 'col_qty')) ?></th>
              <th style="border:1px solid #000; padding:6px 6px; font-weight:700; text-align:center; background:white;"><?= e(doc_fmt('nonps', 'col_cost')) ?></th>
              <th style="border:1px solid #000; padding:6px 6px; font-weight:700; text-align:center; background:white;"><?= e(doc_fmt('nonps', 'col_total')) ?></th>
              <th class="delh" style="border:none; display:none;"></th>
            </tr>
          </thead>
          <tbody id="nonpsBody">
            <?php $n = 1; foreach ($items as $it): ?>
              <tr>
                <td style="border:1px solid #000; padding:4px; text-align:center; vertical-align:middle;" class="rownum"><?= $n++ ?></td>
                <td style="border:1px solid #000; padding:2px;">
                  <textarea name="particulars[]" rows="1" readonly style="width:100%; padding:4px 6px; border:none; outline:none; font-family:Arial,sans-serif; font-size:12px; resize:vertical; min-height:22px; background:transparent;"><?= e($it['particulars']) ?></textarea>
                </td>
                <td style="border:1px solid #000; padding:2px;">
                  <input type="text" name="unit[]" value="<?= e($it['unit']) ?>" readonly style="width:100%; padding:4px 6px; border:none; outline:none; font-family:Arial,sans-serif; font-size:12px; text-align:center; background:transparent;">
                </td>
                <td style="border:1px solid #000; padding:2px;">
                  <input type="number" name="quantity[]" value="<?= (int)$it['quantity'] ?>" min="0" oninput="recalc(this)" readonly style="width:100%; padding:4px 6px; border:none; outline:none; font-family:Arial,sans-serif; font-size:12px; text-align:center; background:transparent;">
                </td>
                <td style="border:1px solid #000; padding:2px;">
                  <input type="text" inputmode="decimal" class="money" name="unit_cost[]" value="<?= number_format((float)$it['unit_cost'], 2, '.', '') ?>" min="0" oninput="recalc(this)" readonly style="width:100%; padding:4px 6px; border:none; outline:none; font-family:Arial,sans-serif; font-size:12px; text-align:right; background:transparent;">
                </td>
                <td style="border:1px solid #000; padding:4px 6px; text-align:right; vertical-align:middle;">
                  <span class="row-total"><?= number_format((float)$it['total_cost'], 2) ?></span>
                </td>
                <td class="delcell" style="border:none; padding:0 4px; text-align:center; display:none;">
                  <button type="button" onclick="delRow(this)" title="Remove" style="background:none; border:none; color:#999; cursor:pointer; font-size:13px; padding:2px 4px;">✕</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="5" style="border:1px solid #000; padding:6px 8px; text-align:center; font-weight:700;">TOTAL</td>
              <td id="grandTotal" style="border:1px solid #000; padding:6px 6px; text-align:right; font-weight:700; text-decoration:underline;"><?= number_format($grandTotal, 2) ?></td>
              <td class="delcell" style="border:none; display:none;"></td>
            </tr>
            <?php /* + VAT, logistics and inflation, each a % of the TOTAL, and the total with them (config/price-provision.php) */
              $__np = nonps_provision((float)$grandTotal); ?>
            <?= nonps_charge_rows($__np, 'colspan="5" style="border:1px solid #000; padding:6px 8px; text-align:center; font-weight:700;"',
                                  'style="border:1px solid #000; padding:6px 6px; text-align:right; font-weight:700;"', false,
                                  '<td class="delcell" style="border:none; display:none;"></td>') ?>
            <tr>
              <td colspan="5" style="border:1px solid #000; padding:6px 8px; text-align:center; font-weight:700;"><?= e(doc_fmt('nonps', 'lbl_grand')) ?></td>
              <td id="npGrand" data-np-peso="0" style="border:1px solid #000; padding:6px 6px; text-align:right; font-weight:700; text-decoration:underline double;"><?= number_format($__np['grand'], 2) ?></td>
              <td class="delcell" style="border:none; display:none;"></td>
            </tr>
          </tfoot>
        </table>

        <!-- Signatories — same as what the user sees -->
        <div style="margin-top:26px; font-family:Arial,sans-serif; font-size:12px;">
          <div style="margin-bottom:42px;"><?= e(doc_fmt('nonps', 'sig_prepared')) ?></div>
          <div style="font-weight:700; text-transform:uppercase;"><?= e($sub['prepared_by'] ?: ($sub['submitted_by'] ?? '')) ?></div>
          <?php $__desig = trim((string)($sub['prepared_designation'] ?? ''));
                $__dn = trim((string)($sub['dept_name'] ?: $sub['dept_code'])); ?>
          <?php if ($__desig !== ''): ?><div><?= e($__desig) ?></div><?php endif; ?>
          <?php if (mb_strtolower($__desig) !== mb_strtolower($__dn)): /* once only */ ?><div><?= e($__dn) ?></div><?php endif; ?>
        </div>

        <div id="editControls" style="display:none; margin-top:18px; gap:10px; align-items:center;">
          <button type="button" onclick="addRow()" class="btn btn-ghost" style="padding:8px 16px; font-size:12.5px;">＋ Add row</button>
          <button type="submit" class="btn btn-primary" style="padding:8px 18px; font-size:12.5px;">💾 Save changes</button>
        </div>
      </div>
    </form>
  </main>
</div>

<!-- Hidden forms for the modal actions -->
<form method="post" action="api/nonps-review-action.php" id="approveForm" style="display:none;">
  <input type="hidden" name="sub" value="<?= $subId ?>">
  <input type="hidden" name="do" value="approve">
</form>
<form method="post" action="api/nonps-review-action.php" id="revisionForm" style="display:none;">
  <input type="hidden" name="sub" value="<?= $subId ?>">
  <input type="hidden" name="do" value="revision">
  <input type="hidden" name="note" id="revisionNote" value="">
</form>

<!-- Custom confirmation modal -->
<div id="confirmOverlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:9999; align-items:center; justify-content:center; padding:20px;">
  <div style="background:var(--white); border-radius:16px; max-width:420px; width:100%; padding:28px 28px 22px; box-shadow:0 20px 50px rgba(0,0,0,0.25); text-align:center;">
    <div id="cfIcon" style="width:64px; height:64px; border-radius:50%; margin:0 auto 16px; display:flex; align-items:center; justify-content:center; font-size:32px;"></div>
    <div id="cfTitle" style="font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:20px; color:var(--ink); margin-bottom:8px;"></div>
    <div id="cfText" style="font-size:13.5px; color:#64748B; line-height:1.6; margin-bottom:14px;"></div>
    <div id="cfNoteWrap" style="display:none; margin-bottom:16px; text-align:left;">
      <label style="font-size:12px; font-weight:600; color:var(--ink); display:block; margin-bottom:5px;">Note to the department (optional)</label>
      <textarea id="cfNote" rows="3" placeholder="e.g. Please revise the unit costs for items 2 and 3."
        style="width:100%; box-sizing:border-box; padding:9px 11px; border:1.5px solid #d7dee6; border-radius:9px; font-family:inherit; font-size:13px; resize:vertical;"></textarea>
    </div>
    <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
      <button type="button" onclick="closeConfirm()" class="btn btn-ghost" style="padding:9px 22px; font-size:13px;">Cancel</button>
      <button type="button" id="cfOk" class="btn btn-primary" style="padding:9px 26px; font-size:13px;">Confirm</button>
    </div>
  </div>
</div>

<?= pp_js() ?>
<script>
  // ── Custom confirmation modal (replaces browser confirm) ──
  function openConfirm(opts){
    document.getElementById('cfIcon').textContent = opts.icon;
    document.getElementById('cfIcon').style.background = opts.iconBg;
    document.getElementById('cfIcon').style.color = opts.iconColor;
    document.getElementById('cfTitle').textContent = opts.title;
    document.getElementById('cfText').textContent = opts.text;
    document.getElementById('cfNoteWrap').style.display = opts.showNote ? 'block' : 'none';
    const ok = document.getElementById('cfOk');
    ok.textContent = opts.okLabel;
    ok.style.background = opts.okColor;
    ok.onclick = opts.onOk;
    document.getElementById('confirmOverlay').style.display = 'flex';
  }
  function closeConfirm(){
    document.getElementById('confirmOverlay').style.display = 'none';
  }
  document.getElementById('confirmOverlay').addEventListener('click', function(e){
    if (e.target === this) closeConfirm();
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeConfirm();
  });

  function askApprove(){
    openConfirm({
      icon:'✓', iconBg:'#f0fdf4', iconColor:'#16a34a',
      title:'Approve this list?',
      text:'This will mark the department\u2019s Non-PS Supplies list as approved. It can no longer be approved again after this.',
      okLabel:'Yes, approve', okColor:'#16a34a', showNote:false,
      onOk:function(){ document.getElementById('approveForm').submit(); }
    });
  }

  function askRevision(){
    openConfirm({
      icon:'↩', iconBg:'#fffbeb', iconColor:'#b45309',
      title:'Send back for revision?',
      text:'The department will be able to edit and resubmit their Non-PS Supplies list.',
      okLabel:'Send back', okColor:'#b45309', showNote:true,
      onOk:function(){
        document.getElementById('revisionNote').value = document.getElementById('cfNote').value;
        document.getElementById('revisionForm').submit();
      }
    });
  }

  function peso(n){ return (isNaN(n)?0:n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  function recalc(){
    let grand = 0;
    document.querySelectorAll('#nonpsBody tr').forEach(function(tr){
      const q = parseFloat(tr.querySelector('input[name="quantity[]"]').value)||0;
      const c = parseFloat(tr.querySelector('input[name="unit_cost[]"]').value)||0;
      const t = q*c;
      tr.querySelector('.row-total').textContent = peso(t);
      grand += t;
    });
    document.getElementById('grandTotal').textContent = peso(grand);
    // + VAT, logistics and inflation, and the GRAND TOTAL
    PP.nonps(grand);
  }

  function renumber(){
    let n = 1;
    document.querySelectorAll('#nonpsBody tr').forEach(function(tr){ tr.querySelector('.rownum').textContent = n++; });
  }

  let editing = false;
  function enableEdit(){
    editing = true;
    document.querySelectorAll('#nonpsBody textarea, #nonpsBody input').forEach(function(el){ el.removeAttribute('readonly'); });
    document.querySelectorAll('.delcell, .delh').forEach(function(el){ el.style.display = 'table-cell'; });
    const ec = document.getElementById('editControls');
    if (ec) ec.style.display = 'flex';
    const ab = document.getElementById('addBtn');
    if (ab) ab.style.display = 'none';
    const hint = document.getElementById('viewHint');
    if (hint) hint.innerHTML = '✏️ Editing — make your changes, then click <strong>Save changes</strong>.';
  }

  function addRow(){
    const tb = document.getElementById('nonpsBody');
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td style="border:1px solid #000; padding:4px; text-align:center; vertical-align:middle;" class="rownum"></td>'+
      '<td style="border:1px solid #000; padding:2px;"><textarea name="particulars[]" rows="1" style="width:100%; padding:4px 6px; border:none; outline:none; font-family:Arial,sans-serif; font-size:12px; resize:vertical; min-height:22px; background:transparent;"></textarea></td>'+
      '<td style="border:1px solid #000; padding:2px;"><input type="text" name="unit[]" style="width:100%; padding:4px 6px; border:none; outline:none; font-family:Arial,sans-serif; font-size:12px; text-align:center; background:transparent;"></td>'+
      '<td style="border:1px solid #000; padding:2px;"><input type="number" name="quantity[]" value="0" min="0" oninput="recalc(this)" style="width:100%; padding:4px 6px; border:none; outline:none; font-family:Arial,sans-serif; font-size:12px; text-align:center; background:transparent;"></td>'+
      '<td style="border:1px solid #000; padding:2px;"><input type="text" inputmode="decimal" class="money" name="unit_cost[]" value="0.00" min="0" oninput="recalc(this)" style="width:100%; padding:4px 6px; border:none; outline:none; font-family:Arial,sans-serif; font-size:12px; text-align:right; background:transparent;"></td>'+
      '<td style="border:1px solid #000; padding:4px 6px; text-align:right; vertical-align:middle;"><span class="row-total">0.00</span></td>'+
      '<td class="delcell" style="border:none; padding:0 4px; text-align:center;"><button type="button" onclick="delRow(this)" title="Remove" style="background:none; border:none; color:#999; cursor:pointer; font-size:13px; padding:2px 4px;">✕</button></td>';
    tb.appendChild(tr);
    renumber();
    tr.querySelector('textarea').focus();
  }

  function delRow(btn){
    btn.closest('tr').remove();
    renumber(); recalc();
  }
</script>
</body>
</html>
