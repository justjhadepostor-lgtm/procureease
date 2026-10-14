<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/nonps-compute.php';
require_admin();
ensure_admin_tables();
$u = current_user();
$activeNav = 'nonps-consolidated';
$pageTitle = 'Consolidated Non-PS — Edit';
$pdo = db();

$activeFY = active_fy();
$fy = (int)($_GET['fy'] ?? $activeFY);

// Get all depts that either (a) have PPMP-sourced items or (b) have admin manual items for this FY
$deptCodes = [];
try {
    $q1 = $pdo->prepare("SELECT DISTINCT p.dept_code FROM ppmp_entries e JOIN ppmps p ON p.id = e.ppmp_id
                          WHERE p.fy = ? AND e.category IN ('training','consulting','nonps')
                            AND p.status IN ('submitted','budget_approved','approved')");
    $q1->execute([$fy]);
    foreach ($q1->fetchAll(PDO::FETCH_COLUMN) as $dc) $deptCodes[$dc] = true;
    $q2 = $pdo->prepare("SELECT DISTINCT dept_code FROM nonps_admin_items WHERE fy = ?");
    $q2->execute([$fy]);
    foreach ($q2->fetchAll(PDO::FETCH_COLUMN) as $dc) $deptCodes[$dc] = true;
} catch (Throwable $t) {}
$deptCodes = array_keys($deptCodes);

// Load dept name lookup
$deptNames = [];
try {
    $q = $pdo->query("SELECT code, name FROM departments");
    foreach ($q->fetchAll() as $r) $deptNames[$r['code']] = $r['name'];
} catch (Throwable $t) {}

// Build the consolidated data structure — one entry per (dept_code)
$byDept = [];
foreach ($deptCodes as $dc) {
    $byDept[$dc] = [
        'code'  => $dc,
        'name'  => $deptNames[$dc] ?? $dc,
        'items' => nonps_compute_effective($fy, $dc),
    ];
}

// Grand totals
$grandItemCount = 0;
$grandTotal = 0;
foreach ($byDept as $d) {
    $grandItemCount += count($d['items']);
    foreach ($d['items'] as $it) $grandTotal += $it['total_cost'];
}

require __DIR__ . '/includes/head.php';
?>
<style>
  .nonps-table { width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
  .nonps-table th, .nonps-table td { border: 1px solid #000; padding: 3px 5px; vertical-align: middle; }
  .nonps-table th { background: #121358; color: white; font-weight: 700; text-align: center; font-size: 11px; }
  .nonps-table .dept-header td { background: #CFE4F0; color: #121358; text-align: left; font-weight: 700; font-size: 12px; padding: 6px 8px; }
  .nonps-table input, .nonps-table textarea { width: 100%; border: none; outline: none; background: transparent; font-family: Arial; font-size: 12px; padding: 2px 4px; }
  .nonps-table input:focus, .nonps-table textarea:focus { background: #CFE4F0; }
  .nonps-table .num  { text-align: right; }
  .nonps-table .ctr  { text-align: center; }
  .nonps-table .chip { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 9px; font-weight: 700; letter-spacing: 0.05em; margin-right: 4px; vertical-align: middle; }
  .chip-training   { background: #1c2c52; color: #ffffff; }
  .chip-consulting { background: #2f3f8a; color: #ffffff; }
  .chip-nonps      { background: #3368a0; color: #ffffff; }
  .chip-manual     { background: #4a8fb3; color: #ffffff; }
  .edited-marker   { color: #232f72; font-size: 10px; margin-left: 4px; }
  .del-btn { background: none; border: none; color: #999; cursor: pointer; font-size: 13px; padding: 2px 4px; }
  .del-btn:hover { color: #121358; }
  .add-row-btn { background: white; border: 1.5px dashed var(--navy-500); color: var(--navy-700); border-radius: 6px; padding: 4px 12px; font-size: 11px; font-weight: 700; cursor: pointer; margin: 6px; }
  .add-row-btn:hover { background: var(--navy-50); }
  .save-bar { position: sticky; bottom: 0; background: white; border-top: 1px solid var(--border); padding: 12px 20px; margin: 20px -32px -32px; text-align: right; z-index: 40; }
</style>

<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div style="display: flex; align-items: center; gap: 14px;">
        <a href="nonps-consolidated.php" style="color: var(--navy-700); text-decoration: none; font-size: 14px; font-weight: 600;">← Folders</a>
        <div>
          <h1 class="page-title" style="margin: 0;">Consolidated Non-PS — FY <?= $fy ?></h1>
        </div>
      </div>
      <div style="display: flex; gap: 8px; align-items: center;">
        <form method="GET" style="display: inline-flex; gap: 6px; align-items: center;">
          <label class="form-label" style="margin: 0;">FY</label>
          <select name="fy" onchange="this.form.submit()" class="form-select">
            <?php for ($y = $activeFY - 2; $y <= $activeFY + 3; $y++): ?>
              <option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>>FY <?= $y ?></option>
            <?php endfor; ?>
          </select>
        </form>
      </div>
    </div>
    <?php render_flashes(); ?>

    <div class="stats" style="margin-bottom: 16px;">
      <div class="stat"><div class="stat-label">Departments</div><div class="stat-value"><?= count($byDept) ?></div><div class="stat-meta">Contributing to FY <?= $fy ?></div></div>
      <div class="stat"><div class="stat-label">Total items</div><div class="stat-value"><?= number_format($grandItemCount) ?></div><div class="stat-meta">Across all departments</div></div>
      <div class="stat"><div class="stat-label">Grand total</div><div class="stat-value" style="color: var(--navy-700);">₱<?= number_format($grandTotal, 0) ?></div><div class="stat-meta">Sum across all items</div></div>
    </div>

    <?php if (!$byDept): ?>
      <div class="card" style="text-align: center; padding: 60px 24px;">
        <div style="font-size: 48px; margin-bottom: 12px;">📋</div>
        <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 18px; color: var(--ink);">Nothing to consolidate yet</div>
        <div style="font-size: 13.5px; color: var(--muted); max-width: 500px; margin: 10px auto 0;">
          Once departments start submitting PPMPs with Training, Consulting, or Non-PS Supplies entries, their items will appear here for consolidation.
        </div>
      </div>
    <?php else: ?>
      <div class="card doc-paper" style="padding: 20px; background: white; overflow-x: auto;">
        <form id="nonpsForm">
          <input type="hidden" name="fy" value="<?= $fy ?>">
          <table class="nonps-table">
            <thead>
              <tr>
                <th style="width: 40px;">No.</th>
                <th>Particulars</th>
                <th style="width: 70px;">Unit</th>
                <th style="width: 70px;">Qty</th>
                <th style="width: 100px;">Unit Cost</th>
                <th style="width: 120px;">Total</th>
                <th style="width: 36px;"></th>
              </tr>
            </thead>
            <tbody id="nonpsBody">
              <?php $globalIdx = 0;
              foreach ($byDept as $dc => $d): ?>
                <tr class="dept-header" data-dept="<?= e($dc) ?>">
                  <td colspan="7">
                    <?= e($dc) ?> · <?= e($d['name']) ?> ·
                    <span style="font-weight: 500; color: var(--muted); font-size: 11px;"><?= count($d['items']) ?> item<?= count($d['items']) === 1 ? '' : 's' ?></span>
                  </td>
                </tr>
                <?php $n = 1; foreach ($d['items'] as $it):
                  $globalIdx++;
                  $chip = '';
                  if ($it['source_kind'] === 'manual') {
                    $chip = '<span class="chip chip-manual">MANUAL</span>';
                  } elseif (in_array($it['source_category'], ['training','consulting','nonps'], true)) {
                    $chip = '<span class="chip chip-' . e($it['source_category']) . '">' . strtoupper(e($it['source_category'])) . '</span>';
                  }
                ?>
                  <tr class="item-row" data-dept="<?= e($dc) ?>" data-source-key="<?= e($it['source_key']) ?>" data-source-kind="<?= e($it['source_kind']) ?>" data-source-category="<?= e($it['source_category'] ?? '') ?>">
                    <td class="ctr"><?= $n++ ?></td>
                    <td>
                      <?= $chip ?>
                      <input type="text" class="particulars" value="<?= e($it['particulars']) ?>" oninput="markDirty(this)">
                      <?php if ($it['is_admin_edited']): ?><span class="edited-marker" title="Admin-edited">✎</span><?php endif; ?>
                    </td>
                    <td><input type="text" class="unit ctr" value="<?= e($it['unit']) ?>" oninput="markDirty(this)"></td>
                    <td><input type="number" class="qty ctr" min="0" value="<?= (int)$it['quantity'] ?>" oninput="recalc(this)"></td>
                    <td><input type="number" step="0.01" class="price num" min="0" value="<?= number_format($it['unit_cost'], 2, '.', '') ?>" oninput="recalc(this)"></td>
                    <td class="num row-total">₱<?= number_format($it['total_cost'], 2) ?></td>
                    <td class="ctr"><button type="button" class="del-btn" onclick="deleteRow(this)" title="Remove">✕</button></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="add-row" data-dept="<?= e($dc) ?>">
                  <td colspan="7" style="text-align: center; padding: 4px; background: #fafafa;">
                    <button type="button" class="add-row-btn" onclick="addRow('<?= e($dc) ?>')">＋ Add manual item to <?= e($dc) ?></button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </form>
      </div>
    <?php endif; ?>

    <div class="save-bar">
      <span id="saveStatus" style="color: var(--ink); font-size: 12px; margin-right: 12px;">All changes save automatically.</span>
      <button type="button" onclick="saveNow()" class="btn btn-primary" style="padding: 9px 20px;">💾 Save now</button>
    </div>
  </main>
</div>

<script>
  const FY = <?= $fy ?>;
  let dirty = false;
  let saveTimer = null;

  function recalc(el) {
    const row = el.closest('tr');
    const qty = parseFloat(row.querySelector('.qty').value) || 0;
    const price = parseFloat(row.querySelector('.price').value) || 0;
    row.querySelector('.row-total').textContent = '₱' + (qty * price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    markDirty();
  }
  function markDirty() {
    dirty = true;
    const s = document.getElementById('saveStatus');
    s.textContent = 'Unsaved changes...';
    s.style.color = 'var(--orange-600)';
    scheduleAutoSave();
  }
  function scheduleAutoSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNow, 2000);
  }
  function deleteRow(btn) {
    if (window.swiftConfirm) {
      swiftConfirm({ title: 'Remove this item?', text: 'It will be hidden from the department Non-PS list.', confirmLabel: 'Remove', tone: 'danger',
        onConfirm: function() { doDelete(btn); }});
    } else {
      if (confirm('Remove this item?')) doDelete(btn);
    }
  }
  function doDelete(btn) {
    const row = btn.closest('tr');
    row.dataset.deleted = '1';
    row.style.display = 'none';
    markDirty();
  }
  function addRow(dept) {
    const tbody = document.getElementById('nonpsBody');
    const addRowTr = tbody.querySelector('tr.add-row[data-dept="' + dept + '"]');
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.dataset.dept = dept;
    tr.dataset.sourceKey = 'manual-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
    tr.dataset.sourceKind = 'manual';
    tr.dataset.sourceCategory = '';
    tr.innerHTML = `
      <td class="ctr">•</td>
      <td>
        <span class="chip chip-manual">MANUAL</span>
        <input type="text" class="particulars" placeholder="Item description" oninput="markDirty(this)">
      </td>
      <td><input type="text" class="unit ctr" value="unit" oninput="markDirty(this)"></td>
      <td><input type="number" class="qty ctr" min="0" value="1" oninput="recalc(this)"></td>
      <td><input type="number" step="0.01" class="price num" min="0" value="0.00" oninput="recalc(this)"></td>
      <td class="num row-total">₱0.00</td>
      <td class="ctr"><button type="button" class="del-btn" onclick="deleteRow(this)" title="Remove">✕</button></td>`;
    tbody.insertBefore(tr, addRowTr);
    tr.querySelector('.particulars').focus();
    markDirty();
  }
  async function saveNow() {
    if (!dirty) return;
    const rows = [];
    document.querySelectorAll('#nonpsBody tr.item-row').forEach(tr => {
      rows.push({
        dept_code: tr.dataset.dept,
        source_key: tr.dataset.sourceKey,
        source_kind: tr.dataset.sourceKind,
        source_category: tr.dataset.sourceCategory || null,
        particulars: tr.querySelector('.particulars').value.trim(),
        unit: tr.querySelector('.unit').value.trim(),
        quantity: parseInt(tr.querySelector('.qty').value) || 0,
        unit_cost: parseFloat(tr.querySelector('.price').value) || 0,
        deleted: tr.dataset.deleted === '1' ? 1 : 0,
      });
    });
    const s = document.getElementById('saveStatus');
    s.textContent = 'Saving...';
    s.style.color = 'var(--navy-700)';
    try {
      const res = await fetch('api/save-nonps-consolidated.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fy: FY, rows }),
      });
      const j = await res.json();
      if (j.ok) {
        dirty = false;
        s.textContent = '✓ Saved · ' + new Date().toLocaleTimeString();
        s.style.color = '#16a34a';
      } else {
        s.textContent = '⚠ Save failed: ' + (j.error || 'unknown');
        s.style.color = 'var(--orange-600)';
      }
    } catch (e) {
      s.textContent = '⚠ Save failed: ' + e.message;
      s.style.color = 'var(--orange-600)';
    }
  }
  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; }});
</script>
</body>
</html>
