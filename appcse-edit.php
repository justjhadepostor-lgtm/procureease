<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/appcse-items.php';
require_once __DIR__ . '/config/appcse-signatories.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/appcse-review.php';
require_login();   // every department keeps its own APP-CSE
ensure_admin_tables();

$u   = current_user();
$pdo = db();
$fy  = active_fy();

// Always this account's own department — never another's
$deptCode = (string)($u['dept_code'] ?? '');
if ($deptCode === '') {
    flash('error', 'Your account has no department, so it has no APP-CSE. Please contact the Procurement Unit.');
    header('Location: user-dashboard.php'); exit;
}
$deptName = (string)($u['dept_name'] ?? $deptCode);

// The APP-CSE has a state of its own (config/appcse-review.php): it is
// read-only while it is with the Procurement Unit or approved, and open
// while it is a draft or sent back for revision.
$appcseLocked = false;
$ppmpId       = 0;      // the PPMP of the year — the set is submitted from it
$ppmpStatus   = '';
try {
    $__st = $pdo->prepare('SELECT id, status FROM ppmps WHERE dept_code = ? AND fy = ?');
    $__st->execute([$deptCode, $fy]);
    if ($__row = $__st->fetch()) {
        $ppmpId     = (int)$__row['id'];
        $ppmpStatus = (string)$__row['status'];
    }
} catch (Throwable $t) {}
$__acInfo  = appcse_info($fy, $deptCode);
$__acState = $__acInfo['status'];
$appcseLocked = in_array($__acState, ['submitted', 'approved'], true);
// In the Archive: read-only until it is restored
$appcseArchived = arc_appcse_is_archived($fy, $deptCode);
$__appcseLockedByReview = $appcseLocked;
if ($appcseArchived) $appcseLocked = true;
// Sent back by the Procurement Unit: open for changes, with its own Submit
$__acRev = (!$appcseArchived && $__acState === 'revision') ? appcse_revision($fy, $deptCode) : null;
// Submitted on its own: when it was sent back, or when its PPMP has already gone without it
$__acSolo = !$appcseArchived && ($__acRev !== null || ($__acState === 'draft' && $ppmpId > 0 && $ppmpStatus !== 'draft'));
$__appcseDoc = arc_load('appcse', $fy . ':' . $deptCode);

// Everyone prepares; the Department Admin files. Same rule as everywhere else.
$canSubmitAppcse = function_exists('can_submit_for_dept') ? can_submit_for_dept($u) : true;
$appcseSubmitter = function_exists('dept_submitter_name') ? dept_submitter_name($u) : '';

appcse_ensure_seeded($pdo, $fy, $deptCode);
$grouped = appcse_get_grouped($pdo, $fy, $deptCode);

// Load saved agency header info for this FY
// Defaults for a department that has not filled the agency block in yet.
// The office is its OWN name — this used to read 'Procurement Unit' for
// every department, which then printed on their forms.
$hdr = ['department'=>$deptName,'region'=>'III','address'=>'Barangay Zabali, Baler, Aurora','agency_code'=>'','contact_person'=>'','position'=>'','org_type'=>'','email'=>'','telephone'=>'','total_items'=>null,'sig1_name'=>'','sig1_position'=>'','sig2_name'=>'','sig2_position'=>'','sig3_name'=>'','sig3_position'=>'','sig_date_value'=>''];
try {
    $h = $pdo->prepare('SELECT * FROM appcse_header WHERE fy = ? AND dept_code = ?');
    $h->execute([$fy, $deptCode]);
    if ($row = $h->fetch()) {
        // A blank stored value keeps the default rather than printing nothing
        foreach ($row as $k => $v) {
            if (array_key_exists($k, $hdr) && (trim((string)$v) !== '' || str_starts_with((string)$k, 'sig') || $k === 'sig_date_value')) $hdr[$k] = $v;
        }
    }
} catch (Throwable $t) {}
// Keep the standing institutional defaults until the user actually edits them
if (trim((string)($hdr['department'] ?? '')) === '') $hdr['department'] = $deptName;
if (trim((string)($hdr['region'] ?? '')) === '') $hdr['region'] = 'III';
if (trim((string)($hdr['address'] ?? '')) === '') $hdr['address'] = 'Barangay Zabali, Baler, Aurora';

$activeNav = 'appcse';
$pageTitle = 'APP-CSE';
$__role = $u['role'] ?? '';
doc_fmt_use_year(active_fy());   // editors work on the active year
require __DIR__ . '/includes/head.php';

$MONTHS = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
?>


<div class="app">
<?php
  if     ($__role === 'admin')  require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($__role === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
  else                          require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">APP-CSE · FY <?= $fy ?></h1>
        <p class="page-sub"><?= e($deptName) ?> &mdash; enter monthly quantities; quarter totals and amounts compute automatically.</p>
        <?php require_once __DIR__ . '/includes/unit-switch.php'; echo unit_pick_html($u);   /* v87: which program / major or set */ ?>
      </div>
      <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;" data-dock>
        <?php require_once __DIR__ . '/config/doc-versions.php'; echo doc_history_button('appcse', $fy . ':' . $deptCode);   /* v79 */ ?>
        <?php require_once __DIR__ . '/includes/xl-io-ui.php'; echo xl_io_buttons('appcse', 'api/xl-export.php?doc=appcse', !$appcseLocked);   /* v105: APPCSE.xlsx */ ?>
        <button type="button" onclick="printAppcse()" id="printBtn" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px;">🖨️ Print</button>
        <?= arc_button($u, $__appcseDoc, 'appcse-edit.php') ?>
        <?php if (!$appcseLocked): ?>
        <button type="button" onclick="saveAppcse()" id="saveBtn" class="btn btn-ghost" style="padding: 8px 18px; font-size: 13px;">💾 Save</button>
          <?php if ($__acSolo && $canSubmitAppcse): ?>
            <form id="appcseSoloForm" method="POST" action="api/submit-appcse.php" style="margin: 0;">
              <button type="button" onclick="submitAppcseAlone()" id="submitBtn" class="btn btn-primary" style="padding: 8px 18px; font-size: 13px;">📤 Submit</button>
            </form>
          <?php elseif ($ppmpId > 0 && $canSubmitAppcse && $ppmpStatus === 'draft' && (require_once __DIR__ . '/config/unit-review.php') && ur_whole_school($u, (int)$fy)): ?>
            <?= ur_submit_link((int)$fy, (string)($u['school_code'] ?? '')) ?>
          <?php elseif (!$canSubmitAppcse && !$__acSolo && !$__acRev && $ppmpId > 0 && $ppmpStatus === 'draft' && (require_once __DIR__ . '/config/head-submit.php') && hs_applies($u)): /* v112: an end user submits to the Department Admin */ ?>
            <?= hs_user_controls($u, (int)$fy, 'return submitWithPpmp(event)') ?>
          <?php elseif ($ppmpId > 0 && $canSubmitAppcse && $ppmpStatus === 'draft'): ?>
            <form id="appcseSubmitForm" method="POST" action="api/submit-ppmp.php" style="margin: 0;">
              <input type="hidden" name="ppmp_id" value="<?= $ppmpId ?>">
              <input type="hidden" name="fy" value="<?= $fy ?>">
              <button type="button" onclick="submitWithPpmp(event)" id="submitBtn" class="btn btn-primary" style="padding: 8px 18px; font-size: 13px;">📤 Submit</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php render_flashes(); ?>
    <?php if (!$appcseLocked) { require_once __DIR__ . '/config/unit-review.php'; echo ur_bar($u, (int)$fy); }   /* v81: a program / major of a school */ ?>
    <?php
      // "✓ Submitted" — with the Procurement Unit, or approved by it
      if (!$appcseArchived && $__appcseLockedByReview) {
          $__to = $__acState === 'approved'
                ? 'Approved by the Procurement Unit' . (!empty($__acInfo['reviewed_at']) ? ' on ' . date('M j, Y', strtotime((string)$__acInfo['reviewed_at'])) : '') . '.'
                : 'now with the Procurement Unit';
          echo submitted_stamp($__acState === 'approved' ? 'approved' : 'submitted', $__acInfo['submitted_at'] ?? null, $__acInfo['submitted_by'] ?? null, $__to);
      }
    ?>

    <?php if ($__acRev): ?>
      <div id="appcseRevisionNote" style="display:flex; gap:11px; align-items:flex-start; padding:13px 17px; margin-bottom:16px;
                  background:#FFF4EC; border:1px solid #fed7aa; border-left:4px solid #F97316; border-radius:12px;
                  font-size:12.5px; color:#7c2d12; line-height:1.55;">
        <span style="font-size:16px; line-height:1;">&#8617;</span>
        <div><strong>Sent back for revision<?= $__acRev['by'] !== '' ? ' by ' . e($__acRev['by']) : '' ?>.</strong>
          <?= e($__acRev['note']) ?><br>
          Make the changes, then <strong>Submit</strong>: only this APP-CSE goes back to the Procurement Unit.</div>
      </div>
    <?php endif; ?>
    <?php if (!$appcseLocked && !$__acRev && $ppmpId === 0): ?>
      <div style="display:flex; gap:11px; align-items:flex-start; padding:13px 17px; margin-bottom:16px;
                  background: var(--navy-50); border:1px solid var(--navy-100); border-radius:12px;
                  font-size:12.5px; color:var(--ink); line-height:1.55;">
        <span style="font-size:16px; line-height:1;">&#128203;</span>
        <div>This APP-CSE is submitted together with your PPMP. Your department has no
        FY <?= $fy ?> PPMP yet, so there is nothing to send it with —
        <a href="ppmp-edit.php?fy=<?= $fy ?>" style="color: var(--navy-700); font-weight: 700;">start the FY <?= $fy ?> PPMP</a>
        first. Your work here is saved either way.</div>
      </div>
    <?php elseif (!$appcseLocked && !$canSubmitAppcse && !$__acRev
                  && !((require_once __DIR__ . '/config/head-submit.php') && hs_applies($u) && hs_get((string)($u['dept_code'] ?? ''), (int)$fy))): /* v134: not once it is submitted to the Department Admin ("✓ Submitted to …" says it) */ ?>
      <div style="display:flex; gap:11px; align-items:flex-start; padding:13px 17px; margin-bottom:16px;
                  background: var(--navy-50); border:1px solid var(--navy-100); border-radius:12px;
                  font-size:12.5px; color:var(--ink); line-height:1.55;">
        <span style="font-size:16px; line-height:1;">&#128203;</span>
        <div>Your work here is saved.
        <?php if ((require_once __DIR__ . '/config/head-submit.php') && hs_applies($u)): /* v112 */ ?>
        <?= 'Submit sends it, with the PPMP and the rest of your set, to your Department Admin' . ($appcseSubmitter !== '' ? ' (' . e($appcseSubmitter) . ')' : '') . ', who submits it on.' ?>
        <?php else: ?>
        <?= $appcseSubmitter !== ''
              ? 'Only your Department Admin (' . e($appcseSubmitter) . ') can submit it, together with the PPMP.'
              : 'Only your Department Admin can submit it, together with the PPMP.' ?>
        <?php endif; ?></div>
      </div>
    <?php endif; ?>

    <?php if ($appcseArchived): ?>
      <div id="appcseArchived" style="display:flex; gap:11px; align-items:flex-start; padding:13px 17px; margin-bottom:16px;
                  background: var(--navy-50); border:1px solid var(--navy-100); border-radius:12px;
                  font-size:12.5px; color:var(--ink); line-height:1.55;">
        <span style="font-size:16px; line-height:1;">&#128451;</span>
        <div>This APP-CSE is in the Archive, so it cannot be edited. Restore it (button above) to work on it again.
        You can still print it.</div>
      </div>
    <?php elseif ($appcseLocked): ?>
      <div style="display:flex; gap:11px; align-items:flex-start; padding:13px 17px; margin-bottom:16px;
                  background: var(--navy-50); border:1px solid var(--navy-100); border-radius:12px;
                  font-size:12.5px; color:var(--ink); line-height:1.55;">
        <span style="font-size:16px; line-height:1;">&#128274;</span>
        <div><?= $__acState === 'approved'   /* v133: "submitted / approved" is said once, in the ✓ box above */
                 ? 'This APP-CSE can no longer be edited. You can still print it.'
                 : 'This APP-CSE can no longer be edited while it is under review. You can still print it.' ?></div>
      </div>
    <?php endif; ?>

    <?php
      // The form itself — the same file the consolidated copy uses, so the
      // two can never look different from each other.
      $acReadOnly = $appcseLocked;
      require __DIR__ . '/includes/appcse-screen.php';
      // v104: APP-CSEs of earlier years restored from the Archive (view only)
      require_once __DIR__ . '/config/past-years.php';
      echo past_year_mine_html('appcse', $deptCode);
    ?>
  </main>
</div>

<script>
  const FY = <?= $fy ?>;
  let tempSeq = 0;

  function addRow(btn){
    const catTr = btn.closest('tr');
    const part = catTr.dataset.part, cat = catTr.dataset.cat;
    const tempId = 'new_' + (++tempSeq);

    const tr = document.createElement('tr');
    tr.dataset.id = '';
    tr.dataset.temp = tempId;
    tr.dataset.price = '0';
    tr.dataset.part = part;
    tr.dataset.cat = cat;

    let mCells = '';
    ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'].forEach(function(mn, i){
      mCells += '<td><input class="m" data-m="'+mn+'" value="" oninput="recalcRow(this.closest(\'tr\'))"></td>';
      if ((i+1) % 3 === 0){
        const qi = Math.floor(i/3);
        mCells += '<td class="qsum" data-q="'+qi+'">0</td><td class="qamt" data-qa="'+qi+'">0.00</td>';
      }
    });

    tr.innerHTML =
      '<td class="rowdel"><button type="button" class="rowdel-btn" title="Delete row" onclick="deleteRow(this)">✕</button></td>' +
      '<td class="code"><input class="f-code" value=""></td>' +
      '<td class="desc"><input class="f-desc" value=""></td>' +
      '<td><input class="f-unit" value=""></td>' +
      mCells +
      '<td class="total">0</td>' +
      '<td class="price"><input class="f-price money" inputmode="decimal" value="0" oninput="recalcRow(this.closest(\'tr\'))"></td>' +
      '<td class="amount">₱0.00</td>';

    catTr.parentNode.insertBefore(tr, catTr);
    tr.querySelector('.f-code').focus();
  }

  function deleteRow(btn){
    swiftConfirm({
      title: 'Delete this row?',
      text: 'This removes the item from the APP-CSE for this fiscal year.',
      confirmLabel: 'Delete',
      tone: 'danger',
      onConfirm: function(){ doDeleteRow(btn); }
    });
  }

  async function doDeleteRow(btn){
    const tr = btn.closest('tr');
    const id = tr.dataset.id;
    if (id){
      try {
        const res = await fetch('api/appcse-delete-item.php', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ fy: FY, id: id })
        });
        const data = await res.json();
        if (!data.ok){ alert(data.error||'Could not delete row.'); return; }
      } catch(e){ alert('Could not delete row. Check your connection.'); return; }
    }
    tr.remove();
    recalcGrand();
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
    // C. of Part I and II, when they can be typed (not when the APP-CSE is locked)
    const tc = {};
    document.querySelectorAll('#acFoot input.ac-c[data-c]').forEach(function(i){ tc['transport_' + (i.dataset.c === 'II' ? 2 : 1)] = i.value || '0'; });
    document.querySelectorAll('#acFoot input.ac-d-override').forEach(function(i){ tc['override_d_' + (i.dataset.d === 'II' ? 2 : 1)] = i.value || ''; });
    return Object.assign(tc, {
      department:     (document.getElementById('h_department')||{}).value || '',
      region:         (document.getElementById('h_region')||{}).value || '',
      address:        (document.getElementById('h_address')||{}).value || '',
      agency_code:    (document.getElementById('h_agency_code')||{}).value || '',
      contact_person: (document.getElementById('h_contact_person')||{}).value || '',
      position:       (document.getElementById('h_position')||{}).value || '',
      org_type:       (document.getElementById('h_org_type')||{}).value || '',
      email:          (document.getElementById('h_email')||{}).value || '',
      telephone:      (document.getElementById('h_telephone')||{}).value || '',
      total_items:    (document.getElementById('h_total_items')||{}).value || '',
      sig1_name:      (document.querySelector('[data-appcse-header=\"sig1_name\"]')||{}).value || '',
      sig1_position:  (document.querySelector('[data-appcse-header=\"sig1_position\"]')||{}).value || '',
      sig2_name:      (document.querySelector('[data-appcse-header=\"sig2_name\"]')||{}).value || '',
      sig2_position:  (document.querySelector('[data-appcse-header=\"sig2_position\"]')||{}).value || '',
      sig3_name:      (document.querySelector('[data-appcse-header=\"sig3_name\"]')||{}).value || '',
      sig3_position:  (document.querySelector('[data-appcse-header=\"sig3_position\"]')||{}).value || '',
      sig_date_value: (document.querySelector('[data-appcse-header=\"sig_date_value\"]')||{}).value || ''
    });
  }


  document.querySelectorAll('[data-appcse-header]').forEach(function(input){
    input.addEventListener('change', function(){ if (typeof scheduleAutoSave === 'function') scheduleAutoSave(); else if (typeof queueAutoSave === 'function') queueAutoSave(); });
    input.addEventListener('input', function(){ if (typeof scheduleAutoSave === 'function') scheduleAutoSave(); else if (typeof queueAutoSave === 'function') queueAutoSave(); });
  });

  // Print saves first, so the printout always matches what is on screen
  async function printAppcse(){
    const btn = document.getElementById('printBtn');
    const label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Preparing…';
    try {
      const res = await fetch('api/save-appcse.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ fy: FY, rows: collect(), header: collectHeader() })
      });
      const data = await res.json();
      btn.textContent = label; btn.disabled = false;
      if (data.ok) window.open('appcse-print.php?fy=' + FY, '_blank');
      else alert(data.error || 'Could not prepare the printout.');
    } catch(e){
      btn.textContent = label; btn.disabled = false;
      alert('Could not prepare the printout. Check your connection.');
    }
  }


  // ═══════════ Auto-save ═══════════
  // Quantities and prices save on their own shortly after you stop typing.
  let autoTimer = null, autoBusy = false;

  function showSaveState(text, tone){
    // Only a failure is shown; saving on its own goes on quietly (no repeated "Saved")
    if (tone !== 'err') text = '';
    let el = document.getElementById('autoSaveState');
    if (!el) {
      el = document.createElement('span');
      el.id = 'autoSaveState';
      el.style.cssText = 'font-size:12px; margin-right:10px; align-self:center;';
      const btn = document.getElementById('saveBtn');
      if (btn && btn.parentNode) btn.parentNode.insertBefore(el, btn);
    }
    el.dataset.state = tone === 'ok' ? 'saved' : (tone === 'busy' ? 'saving' : 'error');   // where saving is (not shown)
    el.textContent = text;
    el.style.color = tone === 'ok' ? '#16a34a' : (tone === 'busy' ? '#5A7896' : '#C2410C');
  }

  let autoAgain = false;
  async function autoSave(){
    if (autoBusy) { autoAgain = true; return; }     // saved again once this one is done
    autoBusy = true;
    showSaveState('Saving…', 'busy');
    try {
      const res = await fetch('api/save-appcse.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ fy: FY, rows: collect(), header: collectHeader() })
      });
      const data = await res.json();
      if (data.ok){
        (data.assigned||[]).forEach(function(a){
          const tr = document.querySelector('tbody tr[data-temp="'+a.temp+'"]');
          if (tr){ tr.dataset.id = a.id; tr.dataset.temp = ''; }
        });
        showSaveState('Saved', 'ok');
      } else {
        showSaveState('Not saved', 'err');
      }
    } catch(e){
      showSaveState('Not saved — offline?', 'err');
    }
    autoBusy = false;
    if (autoAgain) { autoAgain = false; queueAutoSave(); }
  }

  function queueAutoSave(){
    showSaveState('Unsaved…', 'busy');
    clearTimeout(autoTimer);
    autoTimer = setTimeout(autoSave, window.PE_AUTOSAVE_MS || 1500);
  }

  document.addEventListener('input',  e => { if (e.target.closest('.ac-wrap, .ac-official')) queueAutoSave(); });
  document.addEventListener('change', e => { if (e.target.closest('.ac-wrap, .ac-official')) queueAutoSave(); });
  window.addEventListener('beforeunload', function(){ if (autoTimer) { clearTimeout(autoTimer); autoSave(); } });

  async function saveAppcse(){
    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      const res = await fetch('api/save-appcse.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ fy: FY, rows: collect(), header: collectHeader() })
      });
      const data = await res.json();
      if (data.ok){
        // Apply real IDs assigned to newly-inserted rows so future saves update them
        (data.assigned||[]).forEach(function(a){
          const tr = document.querySelector('tbody tr[data-temp="'+a.temp+'"]');
          if (tr){ tr.dataset.id = a.id; tr.dataset.temp = ''; }
        });
        btn.textContent='✓ Saved'; setTimeout(()=>{btn.textContent='💾 Save';btn.disabled=false;},1200);
        return true;
      } else { btn.textContent='💾 Save'; btn.disabled=false; alert(data.error||'Could not save.'); return false; }
    } catch(e){ btn.textContent='💾 Save'; btn.disabled=false; alert('Could not save. Check your connection.'); return false; }
  }

  /* Sent back on its own: save, then send the APP-CSE alone to the Budget Unit */
  function submitAppcseAlone(){
    window.swiftConfirm({
      title: 'Submit the revised APP-CSE?',
      text: 'Only the APP-CSE goes back to the Procurement Unit. It cannot be edited while it is under review.',
      confirmLabel: 'Submit', tone: 'info',
      onConfirm: async function(){
        const btn = document.getElementById('submitBtn');
        if (btn){ btn.disabled = true; btn.textContent = 'Submitting…'; }
        const saved = await saveAppcse();
        if (!saved){ if (btn){ btn.disabled = false; btn.textContent = '📤 Submit'; } return; }
        document.getElementById('appcseSoloForm').submit();
      }
    });
  }

  /* Submit opens the window for the whole set — the same one the PPMP page
     shows (includes/submit-set-modal.php). The APP-CSE is saved first, so the
     window checks what is on screen; if the save fails nothing is sent.
     Submit there posts to the same endpoint the PPMP page uses. */
  window.submitSetSave = saveAppcse;
  function submitWithPpmp(ev){
    return window.openSubmitSet(ev);
  }

</script>
<?= xl_io_modal('appcse', ['fy' => (int)$fy]) /* v105: Import APPCSE.xlsx */ ?>
<?php /* Under review: the form above is rendered read-only from the markup
         (see $acReadOnly), and api/save-appcse.php refuses a save anyway. */ ?>
<?php if (!$appcseLocked && !$__acRev && $ppmpId > 0 && $canSubmitAppcse && $ppmpStatus === 'draft') { $submitSetFy = (int)$fy; require __DIR__ . '/includes/submit-set-modal.php'; }
      elseif (!$appcseLocked && !$__acRev && !$__acSolo && $ppmpId > 0 && $ppmpStatus === 'draft' && function_exists('hs_applies') && hs_applies($u)) { $submitSetFy = (int)$fy; $submitSetAction = 'api/submit-to-head.php'; require __DIR__ . '/includes/submit-set-modal.php'; }   /* v112 */ ?>
</body>
</html>
