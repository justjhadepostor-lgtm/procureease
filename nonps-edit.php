<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/companions.php';
require_once __DIR__ . '/config/price-provision.php';   // + VAT, logistics, inflation
require_login();
ensure_admin_tables();
$u = current_user();
// Everyone prepares and saves; only the Department Admin submits.
$__canSubmit = can_submit_for_dept($u);
$__headName  = dept_submitter_name($u);

$activeNav = (($u['role'] ?? '') === 'admin') ? 'my-nonps' : 'nonps';
$pageTitle = 'Edit Non-PS Supply';
$pdo = db();

// The Procurement Unit is a department too and prepares its own list, so it is
// not sent to the inbox here — that is reached from the sidebar instead.
$deptCode = $u['dept_code'];

// Two modes: ?id=X (open existing) or ?fy=X (create/find for FY)
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT s.*, d.name AS dept_name FROM nonps_submissions s
                       LEFT JOIN departments d ON d.code = s.dept_code WHERE s.id = ?");
    $stmt->execute([$id]);
    $sub = $stmt->fetch();
    if (!$sub || $sub['dept_code'] !== $deptCode) {
        flash('error', 'That Non-PS list does not exist or does not belong to your department.');
        header('Location: nonps-list.php'); exit;
    }
    arc_redirect_if_archived((int)($sub['archived'] ?? 0) === 1, 'Non-PS list', 'nonps-print.php?id=' . (int)$sub['id']);
    fy_past_stop((int)$sub['fy'], 'Non-PS list', 'nonps-print.php?id=' . (int)$sub['id']);   // a past year's list is for viewing only
} else {
    $fy = active_fy();
    $stmt = $pdo->prepare("SELECT s.*, d.name AS dept_name FROM nonps_submissions s
                       LEFT JOIN departments d ON d.code = s.dept_code
                          WHERE s.dept_code = ? AND s.fy = ?
                            AND (s.archived IS NULL OR s.archived = 0)");
    $stmt->execute([$deptCode, $fy]);
    $sub = $stmt->fetch();

    if (!$sub) {
        // One list per department per year: if this year's is in the Archive,
        // it is restored from there rather than started again.
        $__ar = $pdo->prepare('SELECT COUNT(*) FROM nonps_submissions WHERE dept_code = ? AND fy = ? AND archived = 1');
        $__ar->execute([$deptCode, $fy]);
        if ((int)$__ar->fetchColumn() > 0) {
            flash('error', "Your FY $fy Non-PS list is in the Archive. Restore it from the Archive to work on it again.");
            header('Location: archive.php'); exit;
        }
        $pdo->prepare("INSERT INTO nonps_submissions (dept_code, fy, status) VALUES (?, ?, 'draft')")
            ->execute([$deptCode, $fy]);
        $stmt->execute([$deptCode, $fy]);
        $sub = $stmt->fetch();
    }
}

$deptName = $sub['dept_name'] ?? ($u['dept_name'] ?? $deptCode);

$q = $pdo->prepare("SELECT * FROM nonps_items WHERE submission_id = ? ORDER BY item_no, id");
$q->execute([$sub['id']]);
$items = $q->fetchAll();

$editable = in_array($sub['status'], ['draft','revision'], true);
// Before the PPMP is submitted, this list goes with it
[$__solo] = companion_solo_submit($deptCode, (int)$sub['fy'], (string)$sub['status']);

doc_fmt_use_year(active_fy());   // editors work on the active year
require __DIR__ . '/includes/head.php';
?>
<div class="app">
<?php $__role = $u['role'] ?? '';
      if     ($__role === 'admin')  require __DIR__ . '/includes/sidebar-admin.php';
      elseif ($__role === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
      else                          require __DIR__ . '/includes/sidebar-user.php'; ?>
  <main class="main">
    <div class="page-head">
      <div style="display: flex; align-items: center; gap: 12px;">
        <div>
          <h1 class="page-title" style="margin: 0;">List of Non-PS Supplies · FY <?= (int)$sub['fy'] ?></h1>
          <p class="page-sub" style="margin: 4px 0 0;"><?= e($deptName) ?></p><?php require_once __DIR__ . '/includes/unit-switch.php'; echo unit_pick_html($u);   /* v87: which program / major or set */ ?></div></div>
      <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;" data-dock>
        <?php require_once __DIR__ . '/config/doc-versions.php'; echo doc_history_button('nonps', (string)(int)$sub['id']);   /* v79 */ ?>
        <?php require_once __DIR__ . '/includes/xl-io-ui.php'; echo xl_io_buttons('nonps', 'api/xl-export.php?doc=nonps&id=' . (int)$sub['id'], $editable);   /* v105: NONPS.xlsx */ ?>
        <?php if ($editable): ?>
          <button type="submit" form="nonpsForm" name="print_after" value="1" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px;">🖨️ Print</button>
          <?= arc_button($u, arc_load('nonps', (int)$sub['id']), 'nonps-edit.php?id=' . (int)$sub['id'])   /* v115: Archive between Print and Save */ ?>
          <button type="submit" form="nonpsForm" class="btn btn-ghost" style="padding: 8px 16px; font-size: 13px;">💾 Save</button>
          <?php if (!$__canSubmit && !$__solo && (require_once __DIR__ . '/config/head-submit.php') && hs_applies($u)): /* v112: an end user submits to the Department Admin */ ?>
          <?= hs_user_controls($u, (int)$sub['fy'], 'return openSubmitSet(event)') ?>
          <?php elseif (!$__canSubmit): ?>
          <span style="font-size:11.5px; color:var(--muted); align-self:center;">
            <?= $__headName !== '' ? e($__headName) . ' submits for your department.' : 'Only your Department Admin can submit.' ?>
          </span>
          <?php elseif (!$__solo && (require_once __DIR__ . '/config/unit-review.php') && ur_whole_school($u, (int)$sub['fy'])): ?>
          <?= ur_submit_link((int)$sub['fy'], (string)($u['school_code'] ?? '')) ?>
          <?php elseif (!$__solo): ?>
          <a href="ppmp-edit.php?fy=<?= (int)$sub['fy'] ?>" onclick="return openSubmitSet(event)" class="btn btn-primary" id="submitWithPpmp" style="padding: 8px 18px; font-size: 13px; text-decoration: none;"
             title="The Non-PS list is submitted together with your PPMP">📤 Submit</a>
          <?php else: ?>
          <button type="submit" form="nonpsForm" name="submit_now" value="1" class="btn btn-primary"
                  data-confirm="This will send your Non-PS Supplies list directly to the Procurement Unit for review."
                  data-confirm-title="Submit to Procurement?" data-confirm-label="Submit" data-confirm-tone="info"
                  style="padding: 8px 18px; font-size: 13px;">Submit</button>
          <?php endif; ?>
        <?php else: ?>
          <a href="nonps-print.php?id=<?= (int)$sub['id'] ?>" target="_blank" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px;">🖨️ Print</a>
          <?= arc_button($u, arc_load('nonps', (int)$sub['id']), 'nonps-edit.php?id=' . (int)$sub['id'])   /* v115: after Print */ ?>
        <?php endif; ?></div></div>
    <?php render_flashes(); ?>
    <?php if ($editable) { require_once __DIR__ . '/config/unit-review.php'; echo ur_bar($u, (int)$sub['fy']); }   /* v81: a program / major of a school */ ?>
    <?php if (!empty($sub) && empty($msForm ?? 0)): /* "✓ Submitted" — where it is now */ ?>
      <?= submitted_stamp((string)($sub['status'] ?? 'draft'), $sub['submitted_at'] ?? null, $sub['submitted_by'] ?? null,
            ($sub['status'] ?? '') === 'approved'
              ? 'Approved by the Procurement Unit' . (!empty($sub['reviewed_at']) ? ' on ' . date('M j, Y', strtotime($sub['reviewed_at'])) : '') . '.'
              : 'now with the Procurement Unit') ?>
    <?php endif; ?>

    <?php
      $status = $sub['status'];
      $sMeta = [
        'draft'     => ['color'=>'#64748B','bg'=>'#f9fafb','label'=>' Draft — not yet submitted','sub'=>'Fill in your items and click Submit to send to the Procurement Unit. You can also print anytime.'],
        'submitted' => ['color'=>'#1c4c86','bg'=>'#EAF4F9','label'=>' Submitted — awaiting Procurement Unit review','sub'=>'You cannot edit while it is under review.'],
        'revision'  => ['color'=>'#2f578a','bg'=>'#FFF0E4','label'=>' For Revision','sub'=>'Procurement asked for changes. Revise the items below and re-submit.'],
        'approved'  => ['color'=>'#14532d','bg'=>'#f0fdf4','label'=>'✓ Approved by the Procurement Unit','sub'=>'This list has been received and reviewed.'],
      ][$status];
    ?>
    <?php if ($status === 'revision'): /* submitted/approved: the "✓ Submitted" stamp above */ ?>
    <div class="card" style="border-left: 4px solid #133458; background: <?= $sMeta['bg'] ?>;">
      <div class="ns-status-label" style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; color: <?= $sMeta['color'] ?>;"><?= $sMeta['label'] ?></div>
      <div style="font-size: 12.5px; color: var(--muted); margin-top: 4px;"><?= $sMeta['sub'] ?></div>
      <?php if ($status === 'revision' && !empty($sub['review_note'])): ?>
        <div style="margin-top: 10px; padding: 10px 14px; background: white; border: 1px solid var(--orange-100); border-radius: 8px; font-size: 13px;">
          <strong style="color: var(--orange-600);">Note from Procurement:</strong> <?= e($sub['review_note']) ?></div>
      <?php endif; ?></div>
    <?php endif; ?>

    <form id="nonpsForm" method="POST" action="api/save-nonps.php">
      <input type="hidden" name="submission_id" value="<?= (int)$sub['id'] ?>">
      <input type="hidden" name="fy" value="<?= (int)$sub['fy'] ?>">

      <div class="card doc-paper" style="padding: 32px 40px; background: white;">
        <!-- Letterhead: centered vertical stack (aligned/pantay-pantay) -->
        <div style="display: flex; flex-direction: column; align-items: center; gap: 4px; margin-bottom: 4px; text-align: center;">
          <?= doc_logo_pair('nonps', 'style="width: 72px; height: 72px; object-fit: contain; display: block; margin-bottom: 4px;"') ?>
          <div style="font-family: Arial, sans-serif; font-size: 12px;"><?= e(doc_fmt('nonps', 'rep_line')) ?></div>
          <div style="font-family: Arial, sans-serif; font-size: 16px; font-weight: 700; margin: 2px 0;"><?= e(doc_fmt('nonps', 'org_name')) ?></div>
          <div style="font-family: Arial, sans-serif; font-size: 12px;"><?= e(doc_fmt('nonps', 'address')) ?></div></div>
        <div style="text-align: center; font-family: Arial, sans-serif; font-weight: 700; font-size: 13px; padding-top: 8px;"><?= e(doc_fmt('nonps', 'unit_line')) ?></div>
        <div style="text-align: center; font-family: Arial, sans-serif; font-weight: 700; font-size: 13px; padding-top: 8px;"><?= e(doc_fmt('nonps', 'doc_title')) ?></div>
        <div style="text-align: center; font-family: Arial, sans-serif; font-size: 12px; padding: 2px 0 18px;">FY <?= (int)$sub['fy'] ?></div>

        <table id="nonpsTable" style="width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; table-layout: fixed;">
          <colgroup>
            <col style="width: 44px;"><col><col style="width: 70px;"><col style="width: 75px;"><col style="width: 100px;"><col style="width: 110px;">
            <?php if ($editable): ?><col style="width: 36px;"><?php endif; ?></colgroup>
          <thead>
            <tr>
              <th style="border: 1px solid #000; padding: 6px 4px; font-weight: 700; text-align: center; background: white;"><?= e(doc_fmt('nonps', 'col_no')) ?></th>
              <th style="border: 1px solid #000; padding: 6px 8px; font-weight: 700; text-align: center; background: white;"><?= e(doc_fmt('nonps', 'col_part')) ?></th>
              <th style="border: 1px solid #000; padding: 6px 4px; font-weight: 700; text-align: center; background: white;"><?= e(doc_fmt('nonps', 'col_unit')) ?></th>
              <th style="border: 1px solid #000; padding: 6px 4px; font-weight: 700; text-align: center; background: white;"><?= e(doc_fmt('nonps', 'col_qty')) ?></th>
              <th style="border: 1px solid #000; padding: 6px 6px; font-weight: 700; text-align: center; background: white;"><?= e(doc_fmt('nonps', 'col_cost')) ?></th>
              <th style="border: 1px solid #000; padding: 6px 6px; font-weight: 700; text-align: center; background: white;"><?= e(doc_fmt('nonps', 'col_total')) ?></th>
              <?php if ($editable): ?><th style="border: none;"></th><?php endif; ?></tr></thead>
          <tbody id="nonpsBody">
            <?php $n = 1; foreach ($items as $it): ?>
              <tr>
                <td style="border: 1px solid #000; padding: 4px; text-align: center; vertical-align: middle;"><?= $n++ ?></td>
                <td style="border: 1px solid #000; padding: 2px;">
                  <textarea name="particulars[]" rows="1" <?= $editable ? '' : 'readonly' ?> style="width: 100%; padding: 4px 6px; border: none; outline: none; font-family: Arial, sans-serif; font-size: 12px; resize: vertical; min-height: 22px; background: transparent;"><?= e($it['particulars']) ?></textarea></td>
                <td style="border: 1px solid #000; padding: 2px;">
                  <input type="text" name="unit[]" value="<?= e($it['unit']) ?>" <?= $editable ? '' : 'readonly' ?> style="width: 100%; padding: 4px 6px; border: none; outline: none; font-family: Arial, sans-serif; font-size: 12px; text-align: center; background: transparent;"></td>
                <td style="border: 1px solid #000; padding: 2px;">
                  <input type="number" name="quantity[]" value="<?= (int)$it['quantity'] ?>" min="0" oninput="recalc(this)" <?= $editable ? '' : 'readonly' ?> style="width: 100%; padding: 4px 6px; border: none; outline: none; font-family: Arial, sans-serif; font-size: 12px; text-align: center; background: transparent;"></td>
                <td style="border: 1px solid #000; padding: 2px;">
                  <input type="text" inputmode="decimal" class="money" name="unit_cost[]" value="<?= number_format((float)$it['unit_cost'], 2, '.', '') ?>" min="0" oninput="recalc(this)" <?= $editable ? '' : 'readonly' ?> style="width: 100%; padding: 4px 6px; border: none; outline: none; font-family: Arial, sans-serif; font-size: 12px; text-align: right; background: transparent;"></td>
                <td style="border: 1px solid #000; padding: 4px 6px; text-align: right; vertical-align: middle;">
                  <span class="row-total"><?= number_format((float)$it['total_cost'], 2) ?></span></td>
                <?php if ($editable): ?>
                  <td style="border: none; padding: 0 4px; text-align: center;">
                    <button type="button" onclick="delRow(this)" title="Remove" style="background: none; border: none; color: var(--row-x); cursor: pointer; font-size: 13px; padding: 2px 4px;">✕</button></td>
                <?php endif; ?></tr>
            <?php endforeach; ?></tbody>
          <tfoot>
            <tr>
              <td colspan="5" style="border: 1px solid #000; padding: 6px 8px; text-align: center; font-weight: 700;">TOTAL</td>
              <td id="grandTotal" style="border: 1px solid #000; padding: 6px 6px; text-align: right; font-weight: 700; text-decoration: underline;">0.00</td>
              <?php if ($editable): ?><td style="border: none;"></td><?php endif; ?></tr>
            <?php /* + VAT, logistics and inflation, each a % of the TOTAL, and the total with them (config/price-provision.php) */
              require_once __DIR__ . '/config/price-provision.php';
              $__np = nonps_provision((float)array_sum(array_map(fn($i) => (float)$i['total_cost'], $items))); ?>
            <?= nonps_charge_rows($__np, 'colspan="5" style="border: 1px solid #000; padding: 6px 8px; text-align: center; font-weight: 700;"',
                                  'style="border: 1px solid #000; padding: 6px 6px; text-align: right; font-weight: 700;"', false,
                                  $editable ? '<td style="border: none;"></td>' : '') ?>
            <tr>
              <td colspan="5" style="border: 1px solid #000; padding: 6px 8px; text-align: center; font-weight: 700;"><?= e(doc_fmt('nonps', 'lbl_grand')) ?></td>
              <td id="npGrand" data-np-peso="0" style="border: 1px solid #000; padding: 6px 6px; text-align: right; font-weight: 700; text-decoration: underline double;"><?= number_format($__np['grand'], 2) ?></td>
              <?php if ($editable): ?><td style="border: none;"></td><?php endif; ?></tr></tfoot></table>

        <div style="margin-top: 24px; font-family: Arial, sans-serif; font-size: 12px;">
          <div style="margin-bottom: 42px;"><?= e(doc_fmt('nonps', 'sig_prepared')) ?></div>
          <?php
            /* The column defaults to '' rather than NULL, and ?? only steps
               aside for NULL — so every existing list showed this line blank.
               A stored name always wins; a blank falls back to whoever filed
               the list, and only while it is still being prepared, to whoever
               is preparing it. A filed list never shows its reader's name. */
            $preparedBy = trim((string)($sub['prepared_by'] ?? ''));
            if ($preparedBy === '') $preparedBy = trim((string)($sub['submitted_by'] ?? ''));
            if ($preparedBy === '' && $editable) $preparedBy = trim((string)($u['full_name'] ?? ''));
            // The designation belongs to whoever prepared this, so they set it.
            // Blank falls back to their position, then to the department name.
            $preparedDesig = trim((string)($sub['prepared_designation'] ?? ''));
            if ($preparedDesig === '') $preparedDesig = trim((string)($u['position'] ?? ''));
            if ($preparedDesig === '') $preparedDesig = $deptName;
          ?>
          <?php if ($editable): ?>
            <div style="display: flex; align-items: center; gap: 6px; max-width: 340px;">
              <input type="text" name="prepared_by" id="preparedBy" value="<?= e($preparedBy) ?>"
                     placeholder="Name of the person who prepared this"
                     style="flex: 1; font-family: Arial, sans-serif; font-size: 12px; font-weight: 700; text-transform: uppercase;
                            border: none; border-bottom: 1px dashed #999; background: transparent; padding: 3px 2px; outline: none;"
                     onfocus="this.style.borderBottomColor='#1c4c86'" onblur="this.style.borderBottomColor='#999'">
              <button type="button" onclick="clearPreparedBy()" title="Clear this name"
                      style="background: none; border: none; color: var(--row-x-strong); font-size: 15px; cursor: pointer; padding: 2px 4px; line-height: 1;">✕</button></div>
          <?php else: ?>
            <div style="font-weight: 700; text-transform: uppercase;"><?= e($preparedBy) ?></div>
          <?php endif; ?>
          <?php if ($editable): ?>
            <div style="display: flex; align-items: center; gap: 6px; max-width: 340px; margin-top: 2px;">
              <input type="text" name="prepared_designation" id="preparedDesignation" value="<?= e($preparedDesig) ?>"
                     placeholder="Your designation, e.g. Unit Head"
                     maxlength="150"
                     style="flex: 1; font-family: Arial, sans-serif; font-size: 12px;
                            border: none; border-bottom: 1px dashed #999; background: transparent; padding: 3px 2px; outline: none;"
                     onfocus="this.style.borderBottomColor='#1c4c86'" onblur="this.style.borderBottomColor='#999'"></div>
          <?php else: ?>
            <div><?= e($preparedDesig) ?></div>
          <?php endif; ?>
          </div></div>

      <?php if ($editable): ?>
        <div style="display: flex; gap: 10px; margin-top: 18px; align-items: center; flex-wrap: wrap;">
          <button type="button" onclick="addRow()" class="btn btn-ghost" style="padding: 8px 16px; font-size: 12.5px;">＋ Add row</button></div>
      <?php endif; ?></form>
      <?php require_once __DIR__ . '/config/past-years.php'; echo past_year_mine_html('nonps', (string)$deptCode);   /* v104: restored, earlier years */ ?></main></div>

<?= pp_js() ?>
<script> function recalc(el) {
    const row = el.closest('tr');
    const qty = parseFloat(row.querySelector('[name="quantity[]"]').value) || 0;
    const uc  = parseFloat(row.querySelector('[name="unit_cost[]"]').value) || 0;
    const cents = Math.round(qty * Math.round(uc * 100));
    row.querySelector('.row-total').textContent = (cents / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    grandTotal();
  }
  function grandTotal() {
    let sumCents = 0;
    document.querySelectorAll('#nonpsBody .row-total').forEach(el => {
      sumCents += Math.round((parseFloat(el.textContent.replace(/,/g, '')) || 0) * 100);
    });
    const sum = sumCents / 100;
    document.getElementById('grandTotal').textContent = sum.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    // + VAT, logistics and inflation, and the GRAND TOTAL
    PP.nonps(sum);
  }
  function addRow() {
    const tbody = document.getElementById('nonpsBody');
    const n = tbody.children.length + 1;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="border: 1px solid #000; padding: 4px; text-align: center; vertical-align: middle;">${n}</td>
      <td style="border: 1px solid #000; padding: 2px;"><textarea name="particulars[]" rows="1" style="width: 100%; padding: 4px 6px; border: none; outline: none; font-family: Arial, sans-serif; font-size: 12px; resize: vertical; min-height: 22px; background: transparent;"></textarea></td>
      <td style="border: 1px solid #000; padding: 2px;"><input type="text" name="unit[]" style="width: 100%; padding: 4px 6px; border: none; outline: none; font-family: Arial, sans-serif; font-size: 12px; text-align: center; background: transparent;"></td>
      <td style="border: 1px solid #000; padding: 2px;"><input type="number" name="quantity[]" value="1" min="0" oninput="recalc(this)" style="width: 100%; padding: 4px 6px; border: none; outline: none; font-family: Arial, sans-serif; font-size: 12px; text-align: center; background: transparent;"></td>
      <td style="border: 1px solid #000; padding: 2px;"><input type="text" inputmode="decimal" class="money" name="unit_cost[]" value="0.00" min="0" oninput="recalc(this)" style="width: 100%; padding: 4px 6px; border: none; outline: none; font-family: Arial, sans-serif; font-size: 12px; text-align: right; background: transparent;"></td>
      <td style="border: 1px solid #000; padding: 4px 6px; text-align: right; vertical-align: middle;"><span class="row-total">0.00</span></td>
      <td style="border: none; padding: 0 4px; text-align: center;"><button type="button" onclick="delRow(this)" title="Remove" style="background: none; border: none; color: var(--row-x); cursor: pointer; font-size: 13px; padding: 2px 4px;">✕</button></td>`;
    tbody.appendChild(tr);
    renumber();
  }
  function delRow(btn) {
    if (window.swiftConfirm) {
      swiftConfirm({ title: 'Remove this row?', text: 'This item will be removed from the list.', confirmLabel: 'Remove', tone: 'danger',
        onConfirm: function(){ btn.closest('tr').remove(); renumber(); grandTotal(); }});
    } else { btn.closest('tr').remove(); renumber(); grandTotal(); }
  }
  function renumber() {
    document.querySelectorAll('#nonpsBody tr').forEach((tr, i) => {
      tr.firstElementChild.textContent = (i + 1);
    });
  }
  grandTotal();
</script>
<script>
  /* v105: the rows go as one field, so a list of any length is saved whole — as separate
     fields a long list (an imported NONPS.xlsx) ran past PHP's limit on fields (api/save-nonps.php) */
  (function(){
    const form = document.getElementById('nonpsForm');
    if (!form) return;
    form.addEventListener('formdata', function (e) {
      const fd = e.formData;
      const P = fd.getAll('particulars[]'), U = fd.getAll('unit[]'), Q = fd.getAll('quantity[]'), C = fd.getAll('unit_cost[]');
      if (!P.length) return;
      ['particulars[]', 'unit[]', 'quantity[]', 'unit_cost[]'].forEach(function (k) { fd.delete(k); });
      fd.append('items_json', JSON.stringify(P.map(function (p, i) { return [p, U[i] || '', Q[i] || '', C[i] || '']; })));
    });
  })();
</script>
<script> function clearPreparedBy(){
    var f = document.getElementById('preparedBy');
    if (f){ f.value = ''; f.focus(); if (window.markDirty) markDirty(); }
  }
</script>

<script>
  // ═══════════ Auto-save ═══════════
  // The list saves itself shortly after you stop typing, so what is on screen
  // is always what gets printed and submitted.
  (function(){
    const form = document.getElementById('nonpsForm');
    if (!form || !form.querySelector('input, textarea')) return;
    const editable = <?= $editable ? 'true' : 'false' ?>;
    if (!editable) return;

    let timer = null, busy = false, lastOk = true;

    function state(text, tone){
    // Only a failure is shown; saving on its own goes on quietly (no repeated "Saved")
    if (tone !== 'err') text = '';
      let el = document.getElementById('autoSaveState');
      if (!el) {
        el = document.createElement('span');
        el.id = 'autoSaveState';
        el.style.cssText = 'font-size:12px; margin-right:10px; align-self:center;';
        const bar = form.closest('body').querySelector('.page-head > div:last-child');
        if (bar) bar.insertBefore(el, bar.firstChild);
      }
      el.dataset.state = tone === 'ok' ? 'saved' : (tone === 'busy' ? 'saving' : 'error');   // where saving is (not shown)
    el.textContent = text;
      el.style.color = tone === 'ok' ? '#16a34a' : (tone === 'busy' ? '#5A7896' : '#C2410C');
    }

    async function save(){
      if (busy) return;
      busy = true;
      state('Saving…', 'busy');
      try {
        const fd = new FormData(form);
        fd.append('ajax', '1');
        fd.delete('submit_now');     // auto-save must never submit to Procurement
        fd.delete('print_after');
        const res  = await fetch('api/save-nonps.php', { method: 'POST', body: fd });
        const data = await res.json();
        lastOk = !!data.ok;
        state(data.ok ? 'Saved' : 'Not saved', data.ok ? 'ok' : 'err');
      } catch (e) {
        lastOk = false;
        state('Not saved — offline?', 'err');
      }
      busy = false;
    }

    // Submit opens the window for the whole set; save first (includes/submit-set-modal.php)
    window.submitSetSave = async function(){
      clearTimeout(timer); timer = null;
      while (busy) await new Promise(function(r){ setTimeout(r, 100); });
      await save();
      if (!lastOk) alert('Your Non-PS list could not be saved, so it was not submitted. Try again.');
      return lastOk;
    };

    function queue(){
      state('Unsaved…', 'busy');
      clearTimeout(timer);
      timer = setTimeout(save, window.PE_AUTOSAVE_MS || 1500);
    }

    form.addEventListener('input',  queue);
    form.addEventListener('change', queue);
    window.addEventListener('beforeunload', function(){ if (timer) { clearTimeout(timer); save(); } });
  })();
</script>
<?= xl_io_modal('nonps', ['fy' => (int)$sub['fy']]) /* v105: Import NONPS.xlsx */ ?>
<?php if ($editable && $__canSubmit && !$__solo) { $submitSetFy = (int)$sub['fy']; require __DIR__ . '/includes/submit-set-modal.php'; }
      elseif ($editable && !$__solo && hs_applies($u)) { $submitSetFy = (int)$sub['fy']; $submitSetAction = 'api/submit-to-head.php'; require __DIR__ . '/includes/submit-set-modal.php'; }   /* v112 */ ?>
</body></html>
