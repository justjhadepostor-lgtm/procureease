<?php
// Renders a single editable row for ppmp-edit.php.
// Expects $r = the entry row (assoc array), $__ppCols = the year's columns (ppmp_cols($fy, true)).
//
// Type of Project and Mode of Procurement are searchable lists with "Others"
// to type anything else. Pre-Procurement Conference, Start and End of
// Procurement Activity, Expected Delivery Period and Source of Funds are
// dropdowns. The lists live in config/ppmp-options.php. Everything else stays
// free-text, like Excel.
//
// v94: the cells follow the columns of the year (Document Format). A built-in
// column taken off the form keeps its cell, hidden, so what is in it is kept;
// an added column is a text box, and everything typed in added columns rides
// along in .f-xraw so a column taken off later loses nothing either.
require_once __DIR__ . '/../config/ppmp-options.php';
require_once __DIR__ . '/../config/ppmp-columns.php';
$__ppCols = $__ppCols ?? ppmp_cols(null, true);
$__rx = ppmp_entry_extra($r);
$__rc = [
  'desc'    => '<textarea class="cell-textarea f-desc" oninput="markDirty()">' . e($r['description']) . '</textarea>'
             . '<input type="hidden" class="f-cat" value="' . e($r['category']) . '">'
             . '<input type="hidden" class="f-xraw" value="' . e(json_encode((object)$__rx, JSON_UNESCAPED_UNICODE)) . '">',
  'type'    => ppmp_combo('f-type', $r['proj_type'] ?? '', 'projectTypes'),
  'qty'     => ppmp_combo('f-qty', $r['quantity'] ?? '', 'quantities'),
  'mode'    => ppmp_combo('f-mode', $r['mode'] ?? '', 'modes'),
  'pre'     => ppmp_choice('f-pre', $r['pre_conference'] ?? '', PPMP_YES_NO),
  'start'   => ppmp_choice('f-start', $r['activity_start'] ?? '', PPMP_MONTHS),
  'end'     => ppmp_choice('f-end', $r['activity_end'] ?? '', PPMP_MONTHS),
  'deliv'   => ppmp_choice('f-deliv', $r['delivery'] ?? '', PPMP_MONTHS),
  'fund'    => fund_select('f-fund', $r['fund'] ?? 'GAA'),
  'amount'  => '<input class="cell-input cell-num f-amt money" type="text" inputmode="decimal" data-num-only value="' . ((float)$r['amount'] ?: '') . '" oninput="markDirty(); updateTotal();">',
  'docs'    => '<input class="cell-input f-docs"  value="' . e($r['supporting_docs'] ?? '') . '" oninput="markDirty()">',
  'remarks' => '<textarea class="cell-textarea f-rem" oninput="markDirty()" placeholder="Remarks">' . e($r['remarks']) . '</textarea>',
];
?>
<tr data-id="<?= (int)$r['id'] ?>">
<?php foreach ($__ppCols as $__c): if (!$__c['on']) continue; ?>
  <td><?= $__c['builtin'] ? $__rc[$__c['id']]
        : '<textarea class="cell-textarea f-x" data-col="' . e($__c['id']) . '" oninput="markDirty()">' . e($__rx[$__c['id']] ?? '') . '</textarea>' ?></td>
<?php endforeach; ?>
<?php foreach ($__ppCols as $__c): if ($__c['on'] || !$__c['builtin']) continue; ?>
  <td class="pp-off" hidden><?= $__rc[$__c['id']] ?></td>
<?php endforeach; ?>
  <td class="cell-center"><button type="button" class="del-row-btn" onclick="delRow(this)" title="Remove">×</button></td>
</tr>
