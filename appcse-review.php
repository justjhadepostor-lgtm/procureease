<?php
/* ═══════════════════════════════════════════════════════════════
   A department's APP-CSE, as the Procurement Unit reviews it (v77).

   The APP-CSE comes straight to the Procurement Unit (APP-CSE Folders),
   not through the Budget Unit. Here the PU:
     · approves it — from then on it is counted in the APP-CSE (Consolidated);
     · or sends it back for revision, with a note;
     · and, to change it itself, first asks the department for permission
       (config/edit-permission.php). Once granted, the form opens for
       editing here until the PU approves it or sends it back.
   The same form the department filled in (includes/appcse-screen.php).
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/appcse-items.php';
require_once __DIR__ . '/config/appcse-review.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/edit-permission.php';
require_login();
ensure_admin_tables();

$u    = current_user();
$pdo  = db();
$role = (string)($u['role'] ?? '');
$fy   = (int)($_GET['fy'] ?? active_fy());
$deptCode = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['dept'] ?? '')));

if ($role === 'budget') {
    flash('error', 'The APP-CSE goes straight to the Procurement Unit; the Budget Unit reviews the PPMP.');
    header('Location: budget-dashboard.php'); exit;
}
if ($role !== 'admin' || $deptCode === '') { header('Location: appcse-edit.php'); exit; }   // a department opens its own on its editor

$info = appcse_info($fy, $deptCode);
if ($info['status'] === 'draft') {
    flash('error', "$deptCode has not submitted its FY $fy APP-CSE yet.");
    header('Location: appcse-folders.php'); exit;
}

$deptName = $deptCode;
try {
    $dn = $pdo->prepare('SELECT name FROM departments WHERE code = ?');
    $dn->execute([$deptCode]);
    $deptName = (string)($dn->fetchColumn() ?: $deptCode);
} catch (Throwable $t) {}

appcse_ensure_seeded($pdo, $fy, $deptCode);
$grouped = appcse_get_grouped($pdo, $fy, $deptCode);
$hdr = ['department' => $deptName, 'region' => 'III', 'address' => 'Barangay Zabali, Baler, Aurora', 'agency_code' => '',
        'contact_person' => '', 'position' => '', 'org_type' => '', 'email' => '', 'telephone' => ''];
try {
    $h = $pdo->prepare('SELECT * FROM appcse_header WHERE fy = ? AND dept_code = ?');
    $h->execute([$fy, $deptCode]);
    if ($row = $h->fetch()) {
        foreach ($row as $k => $v) if (array_key_exists($k, $hdr) && trim((string)$v) !== '') $hdr[$k] = $v;
    }
} catch (Throwable $t) {}

[$canReview, $whyNot] = appcse_can_review($u, $fy, $deptCode);
$archived  = arc_appcse_is_archived($fy, $deptCode);
$withPu    = $info['status'] === 'submitted' && !$archived && !fy_is_past($fy);
$edRef     = $fy . ':' . $deptCode;
$edState   = pu_doc_edit_state('appcse', $edRef, $deptCode, $u);
$canEdit   = $withPu && in_array($edState['state'], ['own', 'granted'], true);

$activeNav = 'appcse-folders';
$pageTitle = 'APP-CSE · ' . $deptCode;
doc_fmt_use_year($fy);
require __DIR__ . '/includes/head.php';
$stamp = fn($t) => $t ? date('M j, Y g:i A', strtotime((string)$t)) : '';
?>
<style>
  .acr-note { display:flex; gap:11px; align-items:flex-start; padding:13px 17px; margin-bottom:16px; border-radius:12px;
              font-size:12.5px; line-height:1.55; }
  .acr-note.back { background:#FFF4EC; border:1px solid #fed7aa; border-left:4px solid #F97316; color:#7c2d12; }
  .acr-note.ok   { background:#f0fdf4; border:1px solid #bbf7d0; border-left:4px solid #15803d; color:#14532d; }
  .acr-note.wait { background:#EAF4F9; border:1px solid #c7dcea; border-left:4px solid #1c4c86; color:#133458; }
  body[data-theme="dark"] .acr-note.back { background:#3b2412; border-color:#9a3412; color:#ffd2b0; }
  body[data-theme="dark"] .acr-note.ok   { background:#123222; border-color:#166534; color:#c9f2d7; }
  body[data-theme="dark"] .acr-note.wait { background:#132a40; border-color:#2f578a; color:#d6e7f5; }
  .acr-card label { display:block; font-size:12px; font-weight:700; color:var(--ink); margin-bottom:6px; }
  .acr-card textarea { width:100%; min-height:74px; box-sizing:border-box; }
  .acr-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-start; }
</style>
<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">APP-CSE · FY <?= $fy ?></h1>
        <p class="page-sub"><?= e($deptName) ?><?= $info['submitted_by'] !== '' ? ' &mdash; submitted by ' . e($info['submitted_by']) : '' ?></p>
      </div>
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;" data-dock>
        <a href="appcse-folders.php?dept=<?= urlencode($deptCode) ?>" class="btn btn-ghost back-btn" style="padding:8px 14px; font-size:13px; text-decoration:none;">← Back</a>
        <?php require_once __DIR__ . '/config/doc-versions.php'; echo doc_history_button('appcse', $fy . ':' . $deptCode);   /* v79 */ ?>
        <a href="appcse-print.php?fy=<?= $fy ?>&amp;dept=<?= urlencode($deptCode) ?>" target="_blank" class="btn btn-ghost" style="padding:8px 14px; font-size:13px; text-decoration:none;">🖨️ Print</a>
        <?php if ($canEdit): ?>
          <button type="button" onclick="saveAppcse()" id="saveBtn" class="btn btn-ghost" style="padding:8px 18px; font-size:13px;">💾 Save</button>
        <?php elseif ($withPu && $edState['state'] === 'pending'): ?>
          <span class="btn btn-ghost" id="editPending" style="padding:8px 14px; font-size:13px; cursor:default;" title="Waiting for <?= e($deptCode) ?> to answer">⏳ Edit request sent</span>
        <?php elseif ($withPu): ?>
          <button type="button" id="askEditBtn" class="btn btn-secondary" style="padding:8px 16px; font-size:13px;"
                  onclick="document.getElementById('askEditCard').hidden=false; document.getElementById('askEditReason').focus();">✎ Request to edit</button>
        <?php endif; ?>
      </div>
    </div>
    <?php render_flashes(); ?>

    <?php if ($info['status'] === 'approved'): ?>
      <div class="acr-note ok" id="appcseApproved"><span style="font-size:16px; line-height:1;">✓</span>
        <div><strong>Approved<?= $info['reviewed_by'] !== '' ? ' by ' . e($info['reviewed_by']) : '' ?><?= $info['reviewed_at'] ? ' on ' . e($stamp($info['reviewed_at'])) : '' ?>.</strong>
          It is counted in the APP-CSE (Consolidated).</div></div>
    <?php elseif ($info['status'] === 'revision'): ?>
      <div class="acr-note back" id="appcseSentBack"><span style="font-size:16px; line-height:1;">&#8617;</span>
        <div><strong>Sent back to <?= e($deptCode) ?><?= $info['note_by'] !== '' ? ' by ' . e($info['note_by']) : '' ?><?= $info['note_at'] ? ' on ' . e($stamp($info['note_at'])) : '' ?>.</strong>
          <?= e($info['note']) ?><br>The department is revising it and will submit it again.</div></div>
    <?php else: ?>
      <div class="acr-note wait" id="appcseWaiting"><span style="font-size:16px; line-height:1;">📤</span>
        <div><strong>Submitted<?= $info['submitted_at'] ? ' on ' . e($stamp($info['submitted_at'])) : '' ?> · waiting for your review.</strong>
          <?= $canEdit ? ($edState['state'] === 'granted' ? e($deptCode) . ' granted you permission to edit it: the form below is open until you approve it or send it back.' : 'You can edit it below.')
                       : 'To change it yourself, ask ' . e($deptCode) . ' for permission first.' ?></div></div>
    <?php endif; ?>

    <?php if ($withPu && !$canEdit && $edState['state'] !== 'pending'): ?>
      <div class="card acr-card" id="askEditCard"<?= $edState['state'] === 'declined' ? '' : ' hidden' ?>>
        <div class="card-title">Ask <?= e($deptCode) ?> for permission to edit</div>
        <div class="card-sub"><?= $edState['state'] === 'declined'
            ? e($deptCode) . ' declined your last request. You can ask again with your reason.'
            : 'The APP-CSE is the department&#39;s. Say what you need to change; you can edit it once they grant it.' ?></div>
        <form method="POST" action="api/request-edit.php">
          <input type="hidden" name="doc_type" value="appcse">
          <input type="hidden" name="fy" value="<?= $fy ?>">
          <input type="hidden" name="dept" value="<?= e($deptCode) ?>">
          <label for="askEditReason">Reason</label>
          <textarea class="form-textarea" id="askEditReason" name="reason" maxlength="1000" required
                    placeholder="e.g. Correct the quantity of bond paper to the approved 30 reams."></textarea>
          <button type="submit" class="btn btn-primary" style="margin-top:10px; padding:8px 16px; font-size:13px;">Send request</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($canReview): ?>
      <div class="card acr-card" id="appcseReview">
        <div class="card-title">Review</div>
        <div class="card-sub">Approve it, or send it back to <?= e($deptCode) ?> with what needs to change.</div>
        <div class="acr-actions">
          <form method="POST" action="api/appcse-review-action.php"
                data-confirm="The FY <?= $fy ?> APP-CSE of <?= e($deptCode) ?> will be approved and counted in the APP-CSE (Consolidated)."
                data-confirm-title="Approve this APP-CSE?" data-confirm-label="Approve" data-confirm-tone="info">
            <input type="hidden" name="fy" value="<?= $fy ?>">
            <input type="hidden" name="dept" value="<?= e($deptCode) ?>">
            <input type="hidden" name="do" value="approve">
            <button type="submit" class="btn btn-primary" id="appcseApprove" style="padding:8px 18px; font-size:13px;">✓ Approve</button>
          </form>
          <button type="button" class="btn btn-ghost" id="appcseAskRevision" style="padding:8px 16px; font-size:13px;"
                  onclick="document.getElementById('revisionForm').hidden=false; document.getElementById('acrNote').focus();">↩ Request revision</button>
        </div>
        <form method="POST" action="api/appcse-review-action.php" id="revisionForm" hidden style="margin-top:12px;"
              data-confirm="The APP-CSE goes back to <?= e($deptCode) ?>, with your note."
              data-confirm-title="Send the APP-CSE back?" data-confirm-label="Send back" data-confirm-tone="danger">
          <input type="hidden" name="fy" value="<?= $fy ?>">
          <input type="hidden" name="dept" value="<?= e($deptCode) ?>">
          <input type="hidden" name="do" value="revision">
          <label for="acrNote">What needs to change</label>
          <textarea class="form-textarea" id="acrNote" name="note" maxlength="1000" required
                    placeholder="e.g. Reduce the bond paper to 30 reams; add toner for the laboratory printer."></textarea>
          <button type="submit" class="btn btn-ghost" id="appcseSendBack" style="margin-top:10px; padding:8px 16px; font-size:13px;">↩ Send back the APP-CSE</button>
        </form>
      </div>
    <?php endif; ?>

    <?php
      $acReadOnly = !$canEdit;
      $acDeptCode = $deptCode;
      require __DIR__ . '/includes/appcse-screen.php';
    ?>
  </main>
</div>

<?php if ($canEdit): ?>
<script>
  /* Editing with the department's permission: the same as the department's own
     editor, saved to the department's APP-CSE (api/save-appcse.php checks the permission) */
  const FY = <?= $fy ?>, DEPT = <?= json_encode($deptCode) ?>;
  let tempSeq = 0;
  function addRow(btn){
    const catTr = btn.closest('tr');
    const tr = document.createElement('tr');
    tr.dataset.id = ''; tr.dataset.temp = 'new_' + (++tempSeq); tr.dataset.price = '0';
    tr.dataset.part = catTr.dataset.part; tr.dataset.cat = catTr.dataset.cat;
    let mCells = '';
    ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'].forEach(function(mn, i){
      mCells += '<td><input class="m" data-m="'+mn+'" value="" oninput="recalcRow(this.closest(\'tr\'))"></td>';
      if ((i+1) % 3 === 0){ const qi = Math.floor(i/3); mCells += '<td class="qsum" data-q="'+qi+'">0</td><td class="qamt" data-qa="'+qi+'">0.00</td>'; }
    });
    tr.innerHTML = '<td class="rowdel"><button type="button" class="rowdel-btn" title="Delete row" onclick="deleteRow(this)">✕</button></td>' +
      '<td class="code"><input class="f-code" value=""></td><td class="desc"><input class="f-desc" value=""></td><td><input class="f-unit" value=""></td>' +
      mCells + '<td class="total">0</td>' +
      '<td class="price"><input class="f-price money" inputmode="decimal" value="0" oninput="recalcRow(this.closest(\'tr\'))"></td><td class="amount">₱0.00</td>';
    catTr.parentNode.insertBefore(tr, catTr);
    tr.querySelector('.f-code').focus();
  }
  function deleteRow(btn){
    swiftConfirm({ title: 'Delete this row?', text: 'This removes the item from the department\'s APP-CSE.', confirmLabel: 'Delete', tone: 'danger',
      onConfirm: async function(){
        const tr = btn.closest('tr');
        if (tr.dataset.id){
          try {
            const r = await fetch('api/appcse-delete-item.php', { method:'POST', headers:{'Content-Type':'application/json'},
                                   body: JSON.stringify({ fy: FY, id: tr.dataset.id, dept: DEPT }) });
            const d = await r.json(); if (!d.ok){ alert(d.error || 'Could not delete row.'); return; }
          } catch(e){ alert('Could not delete row. Check your connection.'); return; }
        }
        tr.remove(); recalcGrand();
      }});
  }
  function collect(){
    const rows = [];
    document.querySelectorAll('tbody tr[data-id]').forEach(function(tr){
      const q = { id: tr.dataset.id || '', temp: tr.dataset.temp || '', part: tr.dataset.part, category: tr.dataset.cat };
      q.code  = (tr.querySelector('.f-code')||{}).value || '';
      q.desc  = (tr.querySelector('.f-desc')||{}).value || '';
      q.unit  = (tr.querySelector('.f-unit')||{}).value || '';
      q.price = parseFloat((tr.querySelector('.f-price')||{}).value) || 0;
      tr.querySelectorAll('input.m').forEach(function(inp){ q[inp.dataset.m] = parseFloat(inp.value)||0; });
      rows.push(q);
    });
    return rows;
  }
  function collectHeader(){
    const h = {};
    ['department','region','address','agency_code','contact_person','position','org_type','email','telephone'].forEach(function(k){
      h[k] = (document.getElementById('h_' + k)||{}).value || '';
    });
    document.querySelectorAll('#acFoot input.ac-c').forEach(function(i){ h['transport_' + (i.dataset.c === 'II' ? 2 : 1)] = i.value || '0'; });
    return h;
  }
  let busy = false, timer = null;
  function showErr(msg){
    let el = document.getElementById('autoSaveState');
    if (!el){ el = document.createElement('span'); el.id = 'autoSaveState'; el.style.cssText = 'font-size:12px; margin-right:10px; align-self:center; color:#C2410C;';
              const b = document.getElementById('saveBtn'); if (b && b.parentNode) b.parentNode.insertBefore(el, b); }
    el.textContent = msg || '';
  }
  async function saveNow(){
    if (busy) return false;
    busy = true;
    try {
      const r = await fetch('api/save-appcse.php', { method:'POST', headers:{'Content-Type':'application/json'},
                             body: JSON.stringify({ fy: FY, dept: DEPT, rows: collect(), header: collectHeader() }) });
      const d = await r.json();
      if (d.ok){
        (d.assigned||[]).forEach(function(a){ const tr = document.querySelector('tbody tr[data-temp="'+a.temp+'"]'); if (tr){ tr.dataset.id = a.id; tr.dataset.temp = ''; } });
        showErr(''); busy = false; return true;
      }
      showErr('Not saved — ' + (d.error || 'try again')); busy = false; return false;
    } catch(e){ showErr('Not saved — offline?'); busy = false; return false; }
  }
  async function saveAppcse(){
    const btn = document.getElementById('saveBtn'); btn.disabled = true; btn.textContent = 'Saving…';
    const ok = await saveNow();
    btn.textContent = ok ? '✓ Saved' : '💾 Save';
    setTimeout(function(){ btn.textContent = '💾 Save'; btn.disabled = false; }, ok ? 1100 : 0);
  }
  function queue(){ clearTimeout(timer); timer = setTimeout(saveNow, window.PE_AUTOSAVE_MS || 1500); }
  document.addEventListener('input',  e => { if (e.target.closest('.ac-wrap, .ac-official')) queue(); });
  document.addEventListener('change', e => { if (e.target.closest('.ac-wrap, .ac-official')) queue(); });
</script>
<?php endif; ?>
</body>
</html>
