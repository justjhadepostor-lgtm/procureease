<?php
/* ═══════════════════════════════════════════════════════════════
   APP-CSE (Consolidated) — the Procurement Unit's own copy.

   Every department prepares its own APP-CSE and submits it together with
   its PPMP. The pair travels the same route — department, then Budget,
   then the Procurement Unit. The moment the Procurement Unit gives final
   approval to a department's PPMP, that department's APP-CSE quantities
   are added into the form below.

   It is the SAME form the departments fill in — not a copy of it, the same
   file: includes/appcse-screen.php renders both, so the two cannot look
   different from each other.

   The page adds up the departments' own rows every time it is opened, so
   it is always in step with what has actually been approved. A department
   still with Budget, or sent back, is simply not counted yet.

   The Procurement Unit can change anything on it — any cell of any line,
   add lines, remove lines, and the agency block. Those changes are kept
   apart from the sum (config/appcse-items.php, "The Procurement Unit's
   edits") and laid over it, so they survive when more departments are
   counted, and no department's own APP-CSE is ever written to.
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/appcse-items.php';
require_once __DIR__ . '/config/appcse-review.php';
require_once __DIR__ . '/config/ui.php';
require_admin();
ensure_admin_tables();

$u   = current_user();
$pdo = db();
$fy  = (int)($_GET['fy'] ?? active_fy());
$activeNav = 'appcse-consolidated';
$pageTitle = 'APP-CSE (Consolidated)';

$months = APPCSE_MONTHS;

/* ─── Who is in, and who is still on the way ─────────────────── */
$approved = [];
$pending  = [];
foreach (appcse_counted_departments($pdo, $fy) as $d) {
    if ($d['status'] === 'approved') $approved[] = $d; else $pending[] = $d;
}
// When each department submitted its APP-CSE — newest first
$__subAt = [];
foreach (array_merge($approved, $pending) as $__d) $__subAt[$__d['code']] = (string)(appcse_info($fy, $__d['code'])['submitted_at'] ?? '');
$__newest = fn($a, $b) => sort_ts($__subAt[$b['code']] ?? null) <=> sort_ts($__subAt[$a['code']] ?? null);
usort($approved, $__newest);
usort($pending, $__newest);

/* ─── The consolidated form ──────────────────────────────────── */
$grouped = appcse_consolidated_grouped($pdo, $fy);
$hdr     = appcse_consolidated_header($pdo, $fy);
$removed = appcse_consolidated_removed($pdo, $fy);   // lines taken off, to put back
$edits   = 0;                                        // lines carrying the PU's changes
foreach ($grouped as $__byCat) foreach ($__byCat as $__rows) foreach ($__rows as $__r) {
    if (!empty($__r['__edited'])) $edits++;
}

/* Figures for the summary strip. The form below works these out again for
   itself; these are only what the four boxes at the top show. */
$lineCount = 0;          // lines that actually carry a quantity
$partTotal = ['I' => 0.0, 'II' => 0.0];
$deptTotals = [];        // what each counted department contributed
foreach ($grouped as $part => $byCat) {
    foreach ($byCat as $items) {
        foreach ($items as $it) {
            $qty = 0.0;
            foreach ($months as $m) $qty += (float)($it[$m] ?? 0);
            if ($qty <= 0) continue;
            $lineCount++;
            $partTotal[$part] += $qty * (float)$it['unit_price'];
        }
    }
}
$subTotal   = $partTotal['I'] + $partTotal['II'];
require_once __DIR__ . '/config/price-provision.php';
$grandTotal = $subTotal * (1 + appcse_rate((int)$fy) / 100);   // + the year's provision for price changes (v94)

// What each counted department's APP-CSE comes to with the 10%: D of Part I +
// D of Part II (A + 10% of A + C), the same figure as its own "Grand Total
// (with 10%)" and its card in APP-CSE Folders (v78)
if ($approved) {
    require_once __DIR__ . '/config/price-provision.php';
    foreach ($approved as $__d) {
        $__c = (string)$__d['code'];
        $deptTotals[$__c] = appcse_summary(appcse_part_totals((int)$fy, $__c), appcse_transport((int)$fy, $__c), (int)$fy)['d'];
    }
}

$fyOptions = range(active_fy() - 2, active_fy() + 2);
doc_fmt_use_year($fy);   // render with the format of the year being viewed
require __DIR__ . '/includes/head.php';
?>
<style>
  .dept-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 11px; border-radius: 999px; font-size: 11.5px; font-weight: 600;
    border: 1px solid var(--border); background: var(--white); margin: 0 6px 6px 0;
    text-decoration: none; color: var(--ink);
  }
  .dept-chip.in  { background: var(--green-50); border-color: #86efac; color: #14532d; }
  .dept-chip.in:hover { border-color: #15803d; }
  .dept-chip.out { color: var(--muted); }
  .dept-chip.wait { background: var(--navy-50); border-color: var(--navy-500); color: var(--ink); border-style: dashed; }
  .dept-chip.wait:hover { border-style: solid; }
  .dept-chip.wait .st { font-weight: 500; color: var(--ink); }
  .dept-chip .amt { font-family: 'Space Grotesk', sans-serif; color: var(--navy-800); }
  /* Departments, in three groups (counted · with you · still in the workflow) */
  .ac-tools { display:flex; align-items:center; gap:10px 14px; flex-wrap:wrap; margin-bottom:10px; }
  .ac-tools .search-field { flex: 0 1 280px; min-width: 0; }
  .ac-group { border-top:1px solid var(--border); padding:10px 0 4px; }
  .ac-tools + .ac-group { border-top:none; }
  .ac-group-head { display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; margin-bottom:8px;
    font-family:'Space Grotesk',sans-serif; font-size:12.5px; font-weight:700; color:var(--ink); }
  .ac-group-head .n { font-size:11px; padding:1px 8px; border-radius:999px; background:var(--navy-100); color:var(--ink); }
  .ac-group-hint { font-family:'Inter',sans-serif; font-size:11.5px; font-weight:500; color:var(--muted); }
  details.ac-group > summary { cursor:pointer; list-style:none; }
  details.ac-group > summary::-webkit-details-marker { display:none; }
  details.ac-group > summary::before { content:'▸'; color:var(--muted); font-size:11px; }
  details.ac-group[open] > summary::before { content:'▾'; }
  details.ac-group[open] .ac-group-hint { visibility:hidden; }
  .dept-chip.is-hidden { display:none; }
  .ac-nomatch { font-size:12.5px; color:var(--muted); padding:6px 0; }
  .consol-note { display:flex; gap:11px; align-items:flex-start; padding:13px 17px; margin-bottom:16px;
    background: var(--navy-50); border:1px solid var(--navy-100); border-radius:12px;
    font-size:12.5px; color:var(--ink); line-height:1.6; }
  .consol-note .ic { font-size:16px; line-height:1.3; }
  .consol-note a { color: var(--navy-700); font-weight: 700; }
  .consol-note .ov-key { background:#fff4d6; box-shadow: inset 0 -2px 0 #b7791f; color:#133458; padding:0 5px; border-radius:3px; }
  .consol-changed { margin-top: 4px; font-weight: 600; }
  .btn-xs { padding: 6px 12px; font-size: 12px; border-radius: 8px; }
  .removed-line { display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding:8px 0; border-top:1px solid var(--border); font-size:12.5px; color:var(--ink); }
  .removed-line .rl-cat { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; flex: 0 1 260px; min-width:0; }
  .removed-line .rl-desc { flex: 1 1 260px; min-width:0; }
</style>

<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">APP-CSE (Consolidated) · FY <?= $fy ?></h1>
        <p class="page-sub">The approved departments' APP-CSE, on one form.</p>
      </div>
      <form method="GET" style="display:flex; gap:8px; align-items:center;" data-dock>
        <?php if ($approved): ?>
          <span id="autoSaveState" style="font-size:12px; align-self:center;"></span>
          <button type="button" onclick="saveConsol()" id="saveBtn" class="btn btn-ghost" style="padding: 8px 18px; font-size: 13px;">💾 Save</button>
        <?php endif; ?>
        <a class="btn btn-primary" style="text-decoration:none; white-space:nowrap;" id="printBtn"
           href="appcse-print.php?fy=<?= $fy ?>&dept=__ALL__" target="_blank"
           onclick="return printConsol(event)">Print consolidated</a>
      </form>
    </div>
    <?php render_flashes(); ?>

    <div class="card" style="margin-bottom: 16px;">
      <div class="card-title">Departments</div>
        <?php $__nWithPu = 0; foreach ($pending as $__d) if ($__d['status'] === 'submitted') $__nWithPu++; ?>
      <?php if (!$approved && !$pending): ?>
        <div style="color: var(--muted); font-size: 13px;">No department has a PPMP for FY <?= $fy ?> yet.</div>
      <?php else: ?>
        <?php
          // Submitted to the Procurement Unit but not approved yet: not counted,
          // but it can be opened (and approved) in APP-CSE Folders.
          $__withPu = []; $__rest = [];
          foreach ($pending as $d) {
              if ($d['status'] === 'submitted') $__withPu[] = $d; else $__rest[] = $d;
          }
          $__allDepts = count($approved) + count($pending);
        ?>
        <div class="ac-tools">
          <?= sort_bar('.chip-set') ?>
          <?php if ($__allDepts >= 20): /* a long list: find one by name */ ?>
            <?= search_field(['id' => 'acDeptSearch', 'placeholder' => 'Find a department…', 'label' => 'Find a department', 'attrs' => SEARCH_NO_DIGITS]) ?>
          <?php endif; ?></div>

        <?php if ($approved): ?>
        <div class="ac-group" id="acGroupCounted">
          <div class="ac-group-head">Counted <span class="n"><?= count($approved) ?></span>
            <span class="ac-group-hint">APP-CSE approved · on the form below</span></div>
          <span class="chip-set" id="acCounted">
          <?php foreach ($approved as $d): ?>
            <a class="dept-chip in" target="_blank" data-ts="<?= sort_ts($__subAt[$d['code']] ?? null) ?>"
               href="appcse-print.php?fy=<?= $fy ?>&dept=<?= urlencode($d['code']) ?>">
              <?= e($d['name']) ?>
              <span class="amt" title="<?= e(doc_fmt('appcse', 'lbl_grand_d')) ?>">&#8369;<?= number_format($deptTotals[$d['code']] ?? 0, 2) ?></span>
            </a>
          <?php endforeach; ?>
          </span></div>
        <?php endif; ?>

        <?php if ($__withPu): ?>
        <div class="ac-group" id="acGroupWaiting">
          <div class="ac-group-head">With you, waiting for your approval <span class="n"><?= count($__withPu) ?></span>
            <span class="ac-group-hint">submitted · not counted yet</span></div>
          <span class="chip-set" id="acWaiting">
          <?php foreach ($__withPu as $d): ?>
            <a class="dept-chip wait" data-ts="<?= sort_ts($__subAt[$d['code']] ?? null) ?>" title="Submitted to you — not counted until you approve it"
               href="appcse-review.php?fy=<?= $fy ?>&amp;dept=<?= urlencode($d['code']) ?>">
              <?= e($d['name']) ?></a>
          <?php endforeach; ?>
          </span></div>
        <?php endif; ?>

        <?php if ($__rest): ?>
        <details class="ac-group" id="acGroupWorkflow">
          <summary class="ac-group-head">Still in the workflow <span class="n"><?= count($__rest) ?></span>
            <span class="ac-group-hint">not with you yet · click to show</span></summary>
          <span class="ac-out">
          <?php foreach ($__rest as $d): ?>
            <span class="dept-chip out"><?= e($d['name']) ?> · <?= e(str_replace('_', ' ', $d['status'])) ?></span>
          <?php endforeach; ?>
          </span></details>
        <?php endif; ?>
        <div class="ac-nomatch" id="acNoMatch" hidden>No department matches.</div>
        <?php if ($__allDepts >= 20): ?>
        <script>
          // Find a department by name, in all three groups
          (function(){
            const box = document.getElementById('acDeptSearch'); if (!box) return;
            box.addEventListener('input', function(){
              const q = box.value.trim().toLowerCase(); let shown = 0;
              document.querySelectorAll('.ac-group .dept-chip').forEach(function(c){
                const hit = q === '' || c.textContent.toLowerCase().includes(q);
                c.classList.toggle('is-hidden', !hit); if (hit) shown++;
              });
              const wf = document.getElementById('acGroupWorkflow');
              if (wf && q !== '' && wf.querySelector('.dept-chip:not(.is-hidden)')) wf.open = true;
              document.getElementById('acNoMatch').hidden = shown > 0;
            });
          })();
        </script>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (!$approved): ?>
      <div class="card" style="text-align:center; padding: 56px 24px; color: var(--muted);">
        <div style="font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px;">Nothing to consolidate yet</div>
        <div style="font-size: 13.5px;">
          A department's quantities appear on the form here once you approve its APP-CSE in APP-CSE Folders.
        </div>
      </div>
    <?php else: ?>
      <?php if ($edits): ?>
      <div class="consol-note">
        <span class="ic">&#9998;</span>
        <div><div class="consol-changed" style="margin-top:0;"><?= $edits ?> line<?= $edits === 1 ? '' : 's' ?> changed on this copy.</div></div>
      </div>
      <?php endif; ?>
      <?php
        /* The same file the departments' own APP-CSE page renders. Not a copy
           of it — the same file — so the consolidated form and a department's
           form are the same document, down to the last column. */
        $acReadOnly   = false;
        $acConsolEdit = true;
        require __DIR__ . '/includes/appcse-screen.php';
      ?>

      <?php if ($removed): ?>
        <div class="card" style="margin-top: 16px;" id="removedLines">
          <div class="card-title">Lines taken off this form</div>
          <div class="card-sub" style="margin-bottom: 12px;">
            They are still in the departments' APP-CSE; only this consolidated copy leaves them out.
          </div>
          <?php foreach ($removed as $__r): ?>
            <div class="removed-line">
              <span class="rl-cat"><?= e($__r['category']) ?></span>
              <span class="rl-desc"><?= e(trim($__r['code'] . ' · ' . $__r['description'], ' ·')) ?></span>
              <button type="button" class="btn btn-ghost btn-xs" onclick="restoreLine(<?= e(json_encode($__r['__key'])) ?>)">Put back</button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<?php if ($approved): ?>
<script>
/* ═══ Editing the consolidated APP-CSE ═══
   Only what the Procurement Unit actually changes is sent: every input
   remembers the value it was drawn with, and a save carries the fields that
   now differ from it. The server keeps a changed field as the PU's own, and
   a field typed back to the departments' sum as following the sum again. */
  const FY = <?= $fy ?>;
  const MONTHS = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
  const HDR = ['department','region','address','agency_code','contact_person','position','org_type','email','telephone'];
  let tempSeq = 0;

  function fieldOf(inp){
    if (inp.classList.contains('f-code'))  return 'code';
    if (inp.classList.contains('f-desc'))  return 'description';
    if (inp.classList.contains('f-unit'))  return 'unit';
    if (inp.classList.contains('f-price')) return 'unit_price';
    if (inp.classList.contains('m'))       return inp.dataset.m;
    return null;
  }
  function remember(scope){
    (scope || document).querySelectorAll('.ac-wrap input, .ac-official input.hdr').forEach(function(i){ i.dataset.orig = i.value; });
  }
  remember();

  function collect(){
    const rows = [];
    document.querySelectorAll('.ac-wrap tbody tr[data-id]').forEach(function(tr){
      const isNew = !tr.dataset.key;
      const fields = {};
      tr.querySelectorAll('input').forEach(function(inp){
        const f = fieldOf(inp);
        if (!f) return;
        if (isNew || inp.value !== inp.dataset.orig) fields[f] = inp.value;
      });
      if (!Object.keys(fields).length) return;
      if (isNew && !Object.values(fields).some(function(v){ return String(v).trim() !== '' && String(v).trim() !== '0'; })) return;
      rows.push({ key: tr.dataset.key || '', temp: tr.dataset.temp || '',
                  part: tr.dataset.part, category: tr.dataset.cat, fields: fields });
      collect.trs.push(tr);
    });
    return rows;
  }
  collect.trs = [];
  function collectHeader(){
    const h = {};
    HDR.forEach(function(k){
      const i = document.getElementById('h_' + k);
      if (i && i.value !== i.dataset.orig) h[k] = i.value;
    });
    return h;
  }

  async function post(body){
    const res = await fetch('api/save-appcse-consolidated.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(Object.assign({ fy: FY }, body))
    });
    return res.json();
  }

  function showSaveState(text, tone){
    // Only a failure is shown; saving on its own goes on quietly (no repeated "Saved")
    if (tone !== 'err') text = '';
    const el = document.getElementById('autoSaveState');
    if (!el) return;
    el.dataset.state = tone === 'ok' ? 'saved' : (tone === 'busy' ? 'saving' : 'error');   // where saving is (not shown)
    el.textContent = text;
    el.style.color = tone === 'ok' ? 'var(--green-500)' : (tone === 'busy' ? 'var(--muted)' : '#C2410C');
  }

  let autoTimer = null, busy = false, again = false;
  async function save(){
    if (busy) { again = true; return true; }      // saved again once this one is done
    collect.trs = [];
    const rows = collect(), header = collectHeader(), trs = collect.trs.slice();
    if (!rows.length && !Object.keys(header).length){ showSaveState('Saved', 'ok'); return true; }
    busy = true;
    showSaveState('Saving…', 'busy');
    try {
      const data = await post({ action: 'save', rows: rows, header: header });
      if (!data.ok){ showSaveState('Not saved', 'err'); alert(data.error || 'Could not save.'); busy = false; again = false; return false; }
      (data.assigned || []).forEach(function(a){
        const tr = document.querySelector('.ac-wrap tbody tr[data-temp="' + a.temp + '"]');
        if (tr){ tr.dataset.key = a.key; tr.dataset.added = '1'; tr.dataset.temp = ''; }
      });
      // What is on screen is now what is saved; mark the cells that are the PU's own
      Object.keys(data.edited || {}).forEach(function(key){
        const tr = document.querySelector('.ac-wrap tbody tr[data-key="' + CSS.escape(key) + '"]');
        if (!tr) return;
        const own = data.edited[key];
        tr.querySelectorAll('input').forEach(function(inp){
          const f = fieldOf(inp);
          if (f) inp.classList.toggle('ov', own.indexOf(f) !== -1);
        });
      });
      // What was sent is now what is saved. Only that: a box typed in while
      // this was on its way is still unsaved, and goes with the next save.
      rows.forEach(function(r, ix){
        const tr = trs[ix]; if (!tr) return;
        tr.querySelectorAll('input').forEach(function(inp){
          const f = fieldOf(inp);
          if (f && Object.prototype.hasOwnProperty.call(r.fields, f)) inp.dataset.orig = r.fields[f];
        });
      });
      Object.keys(header).forEach(function(k){ const i = document.getElementById('h_' + k); if (i) i.dataset.orig = header[k]; });
      showSaveState('Saved', 'ok');
      busy = false;
      if (again) { again = false; queue(); }
      return true;
    } catch (e) {
      showSaveState('Not saved — offline?', 'err');
      busy = false; again = false;
      return false;
    }
  }
  async function flush(){ clearTimeout(autoTimer); autoTimer = null; return save(); }

  function queue(){ showSaveState('Unsaved…', 'busy'); clearTimeout(autoTimer); autoTimer = setTimeout(save, window.PE_AUTOSAVE_MS || 1500); }
  document.addEventListener('input',  function(e){ if (e.target.closest('.ac-wrap, .ac-official')) queue(); });
  // Leaving with a change still waiting: send it on the way out, the same
  // way the departments' own APP-CSE page does.
  window.addEventListener('beforeunload', function(){
    if (!autoTimer) return;
    clearTimeout(autoTimer); autoTimer = null;
    const rows = collect(), header = collectHeader();
    if (!rows.length && !Object.keys(header).length) return;
    fetch('api/save-appcse-consolidated.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, keepalive: true,
      body: JSON.stringify({ fy: FY, action: 'save', rows: rows, header: header })
    });
  });

  async function saveConsol(){
    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    const ok = await flush();
    btn.textContent = ok ? '✓ Saved' : '💾 Save';
    setTimeout(function(){ btn.textContent = '💾 Save'; btn.disabled = false; }, ok ? 1200 : 0);
  }

  // Print saves first, so the printout always matches the screen
  function printConsol(ev){
    ev.preventDefault();
    const href = document.getElementById('printBtn').getAttribute('href');
    const w = window.open('', '_blank');
    flush().then(function(ok){
      if (ok && w) w.location = href;
      else if (ok) window.open(href, '_blank');
      else if (w) w.close();
    });
    return false;
  }

  function addRow(btn){
    const catTr = btn.closest('tr');
    const tr = document.createElement('tr');
    tr.dataset.id = '';
    tr.dataset.key = '';
    tr.dataset.temp = 'new_' + (++tempSeq);
    tr.dataset.price = '0';
    tr.dataset.part = catTr.dataset.part;
    tr.dataset.cat = catTr.dataset.cat;
    let cells = '';
    MONTHS.forEach(function(mn, i){
      cells += '<td><input class="m ov" data-m="' + mn + '" value="" oninput="recalcRow(this.closest(\'tr\'))"></td>';
      if ((i + 1) % 3 === 0){
        const q = Math.floor(i / 3);
        cells += '<td class="qsum" data-q="' + q + '">0</td><td class="qamt" data-qa="' + q + '">0.00</td>';
      }
    });
    tr.innerHTML =
      '<td class="rowdel wide"><button type="button" class="rowdel-btn" title="Remove this line from the consolidated APP-CSE" onclick="deleteRow(this)">✕</button></td>' +
      '<td class="code"><input class="f-code ov" value=""></td>' +
      '<td class="desc"><input class="f-desc ov" value=""></td>' +
      '<td><input class="f-unit ov" value=""></td>' + cells +
      '<td class="total">0</td>' +
      '<td class="price"><input class="f-price money ov" inputmode="decimal" value="0" oninput="recalcRow(this.closest(\'tr\'))"></td>' +
      '<td class="amount">₱0.00</td>';
    catTr.parentNode.insertBefore(tr, catTr);
    tr.querySelector('.f-code').focus();
  }

  function deleteRow(btn){
    const tr = btn.closest('tr');
    if (!tr.dataset.key){ tr.remove(); recalcGrand(); return; }   // never saved
    const own = tr.dataset.added === '1';
    swiftConfirm({
      title: own ? 'Delete this line?' : 'Take this line off the consolidated APP-CSE?',
      text: own ? 'This line was added on the consolidated copy. It is deleted.'
                : 'Only the consolidated copy leaves it out. The departments\' own APP-CSE keep it, and you can put it back from the list under the form.',
      confirmLabel: own ? 'Delete' : 'Take off',
      tone: 'danger',
      onConfirm: async function(){
        if (!(await flush())) return;
        const data = await post({ action: 'remove', key: tr.dataset.key });
        if (data.ok) location.reload(); else alert(data.error || 'Could not remove the line.');
      }
    });
  }

  function resetLine(btn){
    const tr = btn.closest('tr');
    swiftConfirm({
      title: 'Put this line back to the departments\' sum?',
      text: 'Your changes to this line are dropped, and it shows what the approved departments asked for.',
      confirmLabel: 'Put back', tone: 'info',
      onConfirm: async function(){
        if (!(await flush())) return;
        const data = await post({ action: 'reset', key: tr.dataset.key });
        if (data.ok) location.reload(); else alert(data.error || 'Could not reset the line.');
      }
    });
  }

  async function restoreLine(key){
    if (!(await flush())) return;
    const data = await post({ action: 'restore', key: key });
    if (data.ok) location.reload(); else alert(data.error || 'Could not put the line back.');
  }
</script>
<?php endif; ?>
</body>
</html>
