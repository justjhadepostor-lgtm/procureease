/* ═══════════════════════════════════════════════════════════════
   v105: the 📂 Import window and 📊 Export of the PPMP, APP-CSE, Non-PS,
   Annex and Market Scoping pages (includes/xl-io-ui.php sets window.XL_IO).

   Import: the file goes to api/xl-import.php, which only reads it; what it
   holds is shown first, and "Replace" puts it on the form the way typing
   would — then the page's own Save saves it, with every check it has.
   Export: what is on the screen is saved first (as Print does), then the
   file comes down (api/xl-export.php).
   =============================================================== */
(function () {
  const XL = window.XL_IO || {};
  const M = document.getElementById('xlModal'), body = document.getElementById('xlBody'), acts = document.getElementById('xlActions');
  let data = null, pick = 0;
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = n => (Math.round((parseFloat(n) || 0) * 100) / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const qty = n => { n = parseFloat(n) || 0; return (Math.round(n * 100) / 100).toLocaleString('en-US', {maximumFractionDigits: 2}); };
  function open(title, html, buttons) {
    document.getElementById('xlTitle').textContent = title;
    body.innerHTML = html;
    acts.innerHTML = '';
    (buttons || [['Close', 'btn btn-ghost', xlClose]]).forEach(function (b) {
      const el = document.createElement('button');
      el.type = 'button'; el.className = b[1]; el.textContent = b[0]; el.style.cssText = 'padding: 8px 16px; font-size: 13px;';
      if (b[3]) el.id = b[3];
      el.addEventListener('click', b[2]);
      acts.appendChild(el);
    });
    M.classList.add('active');
  }
  window.xlClose = function () { M.classList.remove('active'); data = null; };
  M.addEventListener('click', function (e) { if (e.target === M) xlClose(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && M.classList.contains('active')) xlClose(); });

  window.xlPick = function () { const f = document.getElementById('xlFile'); if (f) { f.value = ''; f.click(); } };
  const input = document.getElementById('xlFile');
  if (input) input.addEventListener('change', async function () {
    const f = input.files && input.files[0];
    if (!f) return;
    open('Import ' + XL.file, '<div class="xl-sub">Reading “' + esc(f.name) + '”…</div>', []);
    const fd = new FormData();
    fd.append('doc', XL.doc); fd.append('fy', XL.fy); fd.append('file', f);
    try {
      const r = await fetch('api/xl-import.php', { method: 'POST', body: fd });
      const d = await r.json();
      if (!d.ok) { open('Import ' + XL.file, '<div class="xl-err">' + esc(d.error || 'This file could not be read.') + '</div>'); return; }
      data = d; data.filename = f.name; pick = 0;
      render();
    } catch (e) {
      open('Import ' + XL.file, '<div class="xl-err">The file could not be sent. Check your connection and try again.</div>');
    }
  });

  /* ─── Export: what is on the screen is saved first (as Print does), then the file comes down ─── */
  const FLUSH = {
    ppmp: async function () { if (typeof dirty !== 'undefined' && dirty) { if (typeof saveTimer !== 'undefined') clearTimeout(saveTimer); await saveNow(true); } },
    appcse: async function () { if (typeof autoTimer !== 'undefined' && autoTimer) { clearTimeout(autoTimer); autoTimer = null; await saveAppcse(); } },
    nonps: async function () {
      const form = document.getElementById('nonpsForm');
      if (!form || !form.querySelector('textarea:not([readonly]), input[name="unit[]"]:not([readonly])')) return;
      const fd = new FormData(form); fd.append('ajax', '1'); fd.delete('submit_now'); fd.delete('print_after');
      await fetch('api/save-nonps.php', { method: 'POST', body: fd }).catch(() => null);
    },
    annex: async function () { if (typeof LOCKED !== 'undefined' && !LOCKED && typeof FORM_ID !== 'undefined' && FORM_ID) await postAnnex(collectAnnex()).catch(() => null); },
    scoping: async function () { if (typeof dirty !== 'undefined' && dirty && typeof LOCKED !== 'undefined' && !LOCKED) { const d = await postDoc(false).catch(() => null); if (d && d.ok) dirty = false; } },
  };
  const exp = document.getElementById('xlExportBtn');
  if (exp) exp.addEventListener('click', async function (e) {
    if (exp.dataset.busy) { e.preventDefault(); return; }
    e.preventDefault();
    exp.dataset.busy = '1';
    const label = exp.innerHTML;
    exp.innerHTML = 'Preparing…';
    try { await FLUSH[XL.doc](); } catch (x) {}
    const a = document.createElement('a');
    a.href = exp.getAttribute('href'); a.setAttribute('download', XL.file); a.setAttribute('data-no-loader', '');
    a.style.display = 'none';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { exp.innerHTML = label; delete exp.dataset.busy; }, 1200);
  });

  /* ─── what the file holds, before anything is put on the form ─── */
  function render() {
    const R = RENDER[XL.doc];
    const v = R();
    open('Import ' + XL.file, '<div class="xl-sub">' + esc(data.filename) + (v.where ? ' · ' + esc(v.where) : '') + '</div>' + v.html,
         v.can ? [['Cancel', 'btn btn-ghost', xlClose], [v.ok, 'btn btn-primary', apply, 'xlReplace']] : [['Close', 'btn btn-ghost', xlClose]]);
    body.querySelectorAll('[data-xl-sheet]').forEach(function (el) {
      el.addEventListener('click', function () { if (el.classList.contains('off')) return; pick = parseInt(el.dataset.xlSheet, 10); render(); });
    });
  }
  function sheetsPicker(list, label, okFn) {
    if (list.length < 2) return '';
    let h = '<div class="xl-mute" style="margin-top: 0;">' + esc(label) + '</div>';
    list.forEach(function (s, i) {
      const ok = okFn ? okFn(s) : '';
      h += '<label class="xl-sheet' + (i === pick ? ' on' : '') + (ok ? ' off' : '') + '" data-xl-sheet="' + i + '"><input type="radio" name="xlSheet"' + (i === pick ? ' checked' : '') + (ok ? ' disabled' : '') + '>'
         + '<div><b>Sheet “' + esc(s.name) + '”</b>' + (s.general_description ? ' — ' + esc(s.general_description) : '') + '<br><span class="xl-mute">' + (ok ? esc(ok) : esc(s.__line || '')) + '</span></div></label>';
    });
    return h;
  }
  const RENDER = {
    ppmp: function () {
      const L = data.sheets;
      L.forEach(s => s.__line = s.items.length + ' procurement item' + (s.items.length === 1 ? '' : 's') + ' · Total ₱' + money(s.total));
      const s = L[pick];
      let h = sheetsPicker(L, 'This file has ' + L.length + ' PPMPs for Fiscal Year ' + XL.fy + '. Choose the one to put on the PPMP you have open.');
      h += '<div class="xl-ok">✓ Found <b>' + s.items.length + ' procurement item' + (s.items.length === 1 ? '' : 's') + '</b> · Total budget <b>₱' + money(s.total) + '</b></div>';
      h += '<div class="xl-scroll"><table class="xl-tbl"><thead><tr><th>#</th><th>General Description</th><th>Type</th><th>Quantity</th><th>Mode</th><th>Pre-Conf.</th><th>Start</th><th>End</th><th>Delivery</th><th>Source</th><th class="n">Budget (₱)</th></tr></thead><tbody>';
      s.items.forEach(function (e, i) {
        h += '<tr><td>' + (i + 1) + '</td><td><div class="xl-desc">' + esc(e.description) + '</div></td><td>' + esc(e.proj_type) + '</td><td>' + esc(e.quantity) + '</td><td>' + esc(e.mode)
           + '</td><td>' + esc(e.pre_conference) + '</td><td>' + esc(e.activity_start) + '</td><td>' + esc(e.activity_end) + '</td><td>' + esc(e.delivery) + '</td><td>' + esc(e.fund) + '</td><td class="n">' + money(e.amount) + '</td></tr>';
      });
      h += '<tr class="tot"><td colspan="10" style="text-align: right;">TOTAL BUDGET</td><td class="n">' + money(s.total) + '</td></tr></tbody></table></div>';
      h += '<div class="xl-kv"><div>Supporting documents, Remarks</div><div>Also copied (Columns 11 and 12)</div>';
      if (s.prepared) h += '<div>Prepared by</div><div>' + esc(s.prepared.name) + (s.prepared.position ? ' — ' + esc(s.prepared.position) : '') + '</div>';
      if (s.approved) h += '<div>Approved by</div><div>' + esc(s.approved.name) + (s.approved.position ? ' — ' + esc(s.approved.position) : '') + '</div>';
      if (s.final !== null) h += '<div>Indicative / Final</div><div>' + (s.final ? 'FINAL' : 'INDICATIVE') + '</div>';
      h += '</div>';
      const now = document.querySelectorAll('#entriesBody tr').length;
      const set = (document.querySelector('#unitSelect option:checked') || {}).textContent;
      h += '<div class="xl-warn">⚠ The PPMP you have open' + (set ? ' (<b>' + esc(set.trim()) + '</b>)' : '') + ' has <b>' + now + ' item' + (now === 1 ? '' : 's') + '</b>. '
         + (now ? 'They will be <b>replaced</b>' : 'It will be filled in') + ' by the ' + s.items.length + ' item' + (s.items.length === 1 ? '' : 's') + ' above. You can still change everything before you submit.</div>';
      if (data.skipped && data.skipped.length) h += '<div class="xl-mute">Not used: ' + data.skipped.map(k => '“' + esc(k.name) + '” (' + esc(k.why) + ')').join(' · ') + '.</div>';
      return { html: h, can: true, where: 'sheet “' + s.name + '” · Fiscal Year ' + s.fy + (s.final !== null ? ' · ' + (s.final ? 'FINAL' : 'INDICATIVE') : ''), ok: '✓ Replace with ' + (s.items.length === 1 ? 'this item' : 'these ' + s.items.length + ' items') };
    },
    annex: function () {
      const L = data.sheets;
      L.forEach(s => s.__line = 'Annex ' + s.letter + ' · Project: ' + (s.project_name || '—') + (s.inclusive_dates ? ' · ' + s.inclusive_dates : '') + ' · ' + s.items.length + ' item' + (s.items.length === 1 ? '' : 's') + ' · Total ₱' + money(s.total));
      const bad = s => s.letter !== XL.letter ? 'Annex ' + s.letter + ' — it can only go into Annex ' + s.letter + '. Open Annex ' + s.letter + ' (or add it at the top) and import it there.' : '';
      if (bad(L[pick])) { const k = L.findIndex(s => !bad(s)); if (k >= 0) pick = k; }
      const s = L[pick];
      let h = sheetsPicker(L, 'This file has ' + L.length + ' Annex sheets. Choose the one to put in the Annex ' + XL.letter + ' you have open.', bad);
      if (L.length === 1) h += '<div class="xl-mute" style="margin-top: 0;">Sheet “' + esc(s.name) + '” · ' + esc(s.__line) + '</div>';
      if (bad(s)) return { html: h + '<div class="xl-err">' + esc(bad(s)) + '</div>', can: false };
      h += '<div class="xl-kv"><div>Unit</div><div>' + esc(s.unit || '—') + '</div><div>Project Name</div><div>' + esc(s.project_name || '—') + '</div><div>General Description</div><div>' + esc(s.general_description || '—') + '</div>'
         + '<div>Total Estimated Budget</div><div>₱' + money(s.total_estimated) + '</div><div>Inclusive Date/s</div><div>' + esc(s.inclusive_dates || '—') + '</div></div>';
      h += '<div class="xl-scroll"><table class="xl-tbl"><thead><tr><th>Group</th><th>Items and Specifications</th><th>Quantity</th><th class="n">Total Amount (₱)</th></tr></thead><tbody>';
      let last = '';
      s.items.forEach(function (it) {
        const q = it.qty_text && String(parseFloat(it.qty_text)) !== it.qty_text.trim() ? qty(it.quantity) + ' <span class="xl-mute">(“' + esc(it.qty_text) + '”)</span>' : qty(it.quantity);
        h += '<tr><td>' + (it.item_group !== last ? esc(it.item_group) : '') + '</td><td>' + esc(it.specification) + '</td><td>' + q + '</td><td class="n">' + money(it.total_amount) + '</td></tr>';
        last = it.item_group;
      });
      h += '<tr class="tot"><td colspan="3" style="text-align: right;">Total Amount</td><td class="n">' + money(s.total) + '</td></tr></tbody></table></div>';
      if (s.items.some(it => it.qty_text && String(parseFloat(it.qty_text)) !== it.qty_text.trim())) h += '<div class="xl-mute">The Quantity column of the Annex keeps the number only (“3 packs” → 3).</div>';
      h += '<div class="xl-warn">⚠ Your open <b>Annex ' + esc(XL.letter) + '</b> will be <b>replaced</b> by sheet “' + esc(s.name) + '” (Project Name, General Description, NEP Accounts, budgets, Inclusive Date/s and the items).</div>';
      return { html: h, can: true, where: 'sheet “' + s.name + '”', ok: '✓ Replace with sheet “' + s.name + '”' };
    },
    appcse: function () {
      const rows = {}; document.querySelectorAll('tbody tr[data-id]').forEach(function (tr) { const c = ((tr.querySelector('.f-code') || {}).value || '').trim().toUpperCase(); if (c && !rows[c]) rows[c] = tr; });
      const hit = [], miss = [];
      data.items.forEach(function (it) { if (!it.any) return; (rows[it.code] ? hit : miss).push(it); });
      data.__hit = hit;
      let h = '<div class="xl-ok">✓ <b>' + hit.length + ' item' + (hit.length === 1 ? '' : 's') + '</b> with quantities — matched to your APP-CSE by <b>Item Code</b>. The monthly quantities (Jan–Dec) are copied; the <b>prices stay the system’s</b>.</div>';
      h += '<div class="xl-scroll"><table class="xl-tbl"><thead><tr><th>Code</th><th>Item &amp; Specifications</th><th>Unit</th><th class="n">Total Qty</th><th class="n">Price (system)</th></tr></thead><tbody>';
      hit.forEach(function (it) {
        const tr = rows[it.code];
        const t = Object.values(it.m).reduce((a, b) => a + (parseFloat(b) || 0), 0);
        const price = parseFloat((tr.querySelector('input.f-price') || {}).value || tr.dataset.price) || 0;
        h += '<tr><td style="font-family: monospace; font-size: 11px;">' + esc(it.code) + '</td><td>' + esc((tr.querySelector('.f-desc') || {}).value || it.desc) + '</td><td>' + esc((tr.querySelector('.f-unit') || {}).value || it.unit) + '</td><td class="n">' + qty(t) + '</td><td class="n">' + money(price) + '</td></tr>';
      });
      if (!hit.length) h += '<tr><td colspan="5" class="xl-mute">No item of the file with a quantity is on your APP-CSE.</td></tr>';
      h += '</tbody></table></div>';
      const hd = data.header || {}, keys = Object.keys(hd);
      if (keys.length) h += '<div class="xl-kv"><div>Agency information</div><div>' + esc(keys.filter(k => hd[k] !== '').map(k => ({department: 'Department/Office', region: 'Region', address: 'Address', agency_code: 'Agency Code', org_type: 'Organization Type', contact_person: 'Contact Person', position: 'Position', email: 'E-mail', telephone: 'Telephone'}[k])).join(', ')) + ' — also copied</div></div>';
      if (miss.length) h += '<div class="xl-warn">Skipped — not on your APP-CSE list (' + miss.length + '): ' + miss.map(it => esc(it.code) + ' ' + esc(it.desc)).join('; ') + '</div>';
      h += '<div class="xl-warn">⚠ All quantities in your open APP-CSE will be <b>replaced</b> by the file’s' + (miss.length ? '' : ' (no item skipped)') + '.</div>';
      if (!hit.length) return { html: h.replace(/<div class="xl-warn">⚠ All quantities[\s\S]*$/, '') + '<div class="xl-err">Nothing to import: none of the items with a quantity in this file is on your APP-CSE list.</div>', can: false, where: 'sheet “' + data.sheet + '”' };
      return { html: h, can: true, where: 'sheet “' + data.sheet + '”', ok: '✓ Replace quantities' };
    },
    nonps: function () {
      const n = data.items.length;
      let h = '<div class="xl-ok">✓ Found <b>' + n + ' item' + (n === 1 ? '' : 's') + '</b> · Total <b>₱' + money(data.total) + '</b></div>';
      h += '<div class="xl-scroll"><table class="xl-tbl"><thead><tr><th>No.</th><th>Particulars</th><th>Unit</th><th class="n">Quantity</th><th class="n">Unit Cost</th><th class="n">Total Cost</th></tr></thead><tbody>';
      data.items.forEach(function (it, i) {
        h += '<tr><td>' + (i + 1) + '</td><td>' + esc(it.particulars) + '</td><td>' + esc(it.unit) + '</td><td class="n">' + qty(it.quantity) + '</td><td class="n">' + money(it.unit_cost) + '</td><td class="n">' + money(it.quantity * it.unit_cost) + '</td></tr>';
      });
      h += '<tr class="tot"><td colspan="5" style="text-align: right;">TOTAL</td><td class="n">' + money(data.total) + '</td></tr></tbody></table></div>';
      if (data.prepared) h += '<div class="xl-kv"><div>Prepared by</div><div>' + esc(data.prepared.name) + (data.prepared.position ? ' — ' + esc(data.prepared.position) : '') + '</div></div>';
      if (data.fy && data.fy !== XL.fy) h += '<div class="xl-warn">This list is for FY ' + data.fy + '; it goes into your FY ' + XL.fy + ' list.</div>';
      const now = document.querySelectorAll('#nonpsBody tr').length;
      h += '<div class="xl-warn">⚠ Your open list has <b>' + now + ' item' + (now === 1 ? '' : 's') + '</b>. ' + (now ? 'They will be <b>replaced</b>' : 'It will be filled in') + ' by the ' + n + ' item' + (n === 1 ? '' : 's') + ' above. VAT, logistics and inflation are worked out by the system as usual.</div>';
      return { html: h, can: true, where: 'sheet “' + data.sheet + '”' + (data.fy ? ' · FY ' + data.fy : ''), ok: '✓ Replace with ' + (n === 1 ? 'this item' : 'these ' + n + ' items') };
    },
    scoping: function () {
      let h = '<div class="xl-ok">✓ The whole document goes into the editor as it is: <b>' + data.tables + ' table' + (data.tables === 1 ? '' : 's') + ', its text, '
            + (data.checks ? data.checks + ' ticked box' + (data.checks === 1 ? '' : 'es') + ', ' : '') + data.pictures + ' picture' + (data.pictures === 1 ? '' : 's') + '</b>.</div>';
      if (data.pairs && data.pairs.length) {
        h += '<div class="xl-kv">';
        data.pairs.forEach(p => { h += '<div>' + esc(p[0]) + '</div><div>' + esc(p[1]) + '</div>'; });
        h += '</div>';
      }
      h += '<div class="xl-warn">⚠ What is in your open Market Scoping will be <b>replaced</b>. A ✓ drawn as a picture ticks the box of its row — please look the boxes over once after importing.</div>';
      return { html: h, can: true, where: 'Word document', ok: '✓ Replace with this document' };
    },
  };

  /* ─── on the form, then saved the way the page saves ─── */
  async function apply() {
    const btn = document.getElementById('xlReplace');
    if (btn) { btn.disabled = true; btn.textContent = 'Putting it on the form…'; }
    let msg = '';
    try { msg = await APPLY[XL.doc](); }
    catch (e) { msg = '!' + (e && e.message ? e.message : 'The data could not be put on the form.'); }
    if (msg && msg[0] === '!') { open('Import ' + XL.file, '<div class="xl-err">' + esc(msg.slice(1)) + '</div>'); return; }
    xlClose();
    if (msg === '#redirect') return;
    toast(msg);
  }
  function toast(t) {
    const el = document.createElement('div');
    el.setAttribute('role', 'status');
    el.style.cssText = 'position:fixed; left:50%; bottom:22px; transform:translateX(-50%); z-index:10000; background:#14532d; color:#fff; padding:12px 16px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.3); font-size:13px; max-width:calc(100vw - 32px);';
    el.textContent = t;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 6000);
  }
  const setVal = (id, v) => { const el = document.getElementById(id); if (el && v != null) el.value = v; };

  const APPLY = {
    ppmp: async function () {
      const s = data.sheets[pick];
      const tb = document.getElementById('entriesBody');
      tb.innerHTML = '';
      s.items.forEach(function (e) {
        const tr = document.createElement('tr');
        // the description, documents and remarks go into the row as they are written (rowHTML puts them in raw)
        tr.innerHTML = rowHTML(Object.assign({}, e, { category: 'training', description: esc(e.description), supporting_docs: esc(e.supporting_docs), remarks: esc(e.remarks), amount: e.amount ? String(e.amount) : '' }));
        tb.appendChild(tr);
      });
      if (s.prepared) { setVal('sigName', s.prepared.name); setVal('sigPos', s.prepared.position); }
      if (s.approved) { setVal('headName', s.approved.name); setVal('headPos', s.approved.position); }
      if (s.ppmp_no) setVal('ppmpNo', s.ppmp_no);
      if (s.final !== null) { const r = document.querySelector('input[name="isFinal"][value="' + (s.final ? 1 : 0) + '"]'); if (r) r.checked = true; }
      updateTotal();
      if (typeof saveTimer !== 'undefined') clearTimeout(saveTimer);
      await saveNow(false);
      const st = document.getElementById('saveStatus');
      if (st && st.dataset.state === 'error') return '!The items are on the form, but they could not be saved. Press 💾 Save to try again.';
      return 'Imported “' + data.filename + '”: ' + s.items.length + ' procurement item' + (s.items.length === 1 ? '' : 's') + ' (₱' + money(s.total) + ') are on your PPMP and saved.';
    },
    appcse: async function () {
      const by = {}; data.items.forEach(it => { if (!by[it.code]) by[it.code] = it; });
      let n = 0;
      document.querySelectorAll('tbody tr[data-id]').forEach(function (tr) {
        const c = ((tr.querySelector('.f-code') || {}).value || '').trim().toUpperCase();
        const it = by[c];
        tr.querySelectorAll('input.m').forEach(function (inp) {
          const v = it ? (parseFloat(it.m[inp.dataset.m]) || 0) : 0;
          inp.value = v ? String(v) : '';
        });
        if (it && it.any) n++;
      });
      const hd = data.header || {};
      Object.keys(hd).forEach(k => setVal('h_' + k, hd[k]));
      ['I', 'II'].forEach(function (p) {
        const v = (data.transport || {})[p];
        const inp = document.querySelector('#acFoot input.ac-c[data-c="' + p + '"]');
        if (inp && v != null) inp.value = v ? Number(v).toFixed(2) : '';
      });
      document.querySelectorAll('tbody tr[data-id]').forEach(recalcRow);
      const ok = await saveAppcse();
      if (ok === false) return '!The quantities are on the form, but they could not be saved. Press 💾 Save to try again.';
      return 'Imported “' + data.filename + '”: the quantities of ' + n + ' item' + (n === 1 ? '' : 's') + ' are on your APP-CSE and saved (system prices).';
    },
    nonps: async function () {
      const tb = document.getElementById('nonpsBody');
      tb.innerHTML = '';
      data.items.forEach(function (it) {
        addRow();
        const tr = tb.lastElementChild;
        tr.querySelector('[name="particulars[]"]').value = it.particulars;
        tr.querySelector('[name="unit[]"]').value = it.unit;
        tr.querySelector('[name="quantity[]"]').value = it.quantity;
        const uc = tr.querySelector('[name="unit_cost[]"]'); uc.value = Number(it.unit_cost).toFixed(2);
        recalc(uc);
      });
      grandTotal();
      if (data.prepared) { setVal('preparedBy', data.prepared.name); if (data.prepared.position) setVal('preparedDesignation', data.prepared.position); }
      const form = document.getElementById('nonpsForm');
      const fd = new FormData(form);
      fd.append('ajax', '1'); fd.delete('submit_now'); fd.delete('print_after');
      const r = await fetch('api/save-nonps.php', { method: 'POST', body: fd });
      const d = await r.json().catch(() => ({}));
      if (!d.ok) return '!The items are on the form, but they could not be saved' + (d.error ? ': ' + d.error : '.') + ' Press 💾 Save to try again.';
      return 'Imported “' + data.filename + '”: ' + data.items.length + ' item' + (data.items.length === 1 ? '' : 's') + ' (₱' + money(data.total) + ') are on your Non-PS list and saved.';
    },
    annex: async function () {
      const s = data.sheets[pick];
      const body = document.getElementById('annexBody');
      body.querySelectorAll('tr.item-row').forEach(tr => tr.remove());
      const groups = Array.prototype.slice.call(body.querySelectorAll('tr.grp-row'));
      const names = groups.map(g => g.dataset.group);
      const by = {};
      s.items.forEach(function (it) { const g = names.indexOf(it.item_group) >= 0 ? it.item_group : 'Others'; (by[g] = by[g] || []).push(it); });
      groups.forEach(function (g) {
        const list = by[g.dataset.group] || [];
        let ref = g;
        const blank = Math.max(5 - list.length, 1);
        list.concat(new Array(blank).fill(null)).forEach(function (it) {
          const tr = makeItemRow();
          tr.dataset.group = g.dataset.group;
          if (it) {
            tr.querySelector('.i-spec').value = it.specification;
            tr.querySelector('.i-qty').value = it.quantity ? String(it.quantity) : '';
            tr.querySelector('.i-amt').value = it.total_amount ? Number(it.total_amount).toFixed(2) : '';
          }
          ref.after(tr); ref = tr;
        });
      });
      if (s.unit) setVal('f_unit_name', s.unit);
      if (s.subtitle) setVal('f_subtitle', s.subtitle);
      if (s.item_note) setVal('f_item_note', s.item_note);
      setVal('f_project_name', s.project_name);
      setVal('f_general_description', s.general_description);
      setVal('f_nep_account_1', s.nep_account_1);
      setVal('f_allocated_budget_1', s.allocated_budget_1 ? Number(s.allocated_budget_1).toFixed(2) : '');
      setVal('f_nep_account_2', s.nep_account_2);
      setVal('f_allocated_budget_2', s.allocated_budget_2 ? Number(s.allocated_budget_2).toFixed(2) : '');
      setVal('f_inclusive_dates', s.inclusive_dates || 'as per schedule');
      const teb = document.getElementById('f_total_estimated');
      if (teb) {
        if (!(s.allocated_budget_1 + s.allocated_budget_2) && s.total_estimated) { teb.dataset.legacy = '1'; teb.value = money(s.total_estimated); }
        else delete teb.dataset.legacy;
      }
      recalc();
      await saveAnnex();
      if (!/✓/.test((document.getElementById('saveBtn') || {}).textContent || '')) return '!Annex ' + XL.letter + ' is filled in, but it could not be saved. Press 💾 Save to try again.';
      if (typeof FORM_ID !== 'undefined' && !FORM_ID) return '#redirect';
      return 'Imported sheet “' + s.name + '” of “' + data.filename + '”: Annex ' + XL.letter + ' is filled in and saved.';
    },
    scoping: async function () {
      const paper = document.getElementById('msPaper');
      paper.innerHTML = data.html;
      if (typeof msYesNo === 'function') msYesNo();
      if (typeof msBoxes === 'function') msBoxes();
      if (typeof refreshPlaceholder === 'function') refreshPlaceholder();
      await saveDoc();
      if (!/✓/.test((document.getElementById('saveBtn') || {}).textContent || '')) return '!The document is in the editor, but it could not be saved. Press 💾 Save to try again.';
      if (typeof DOC_ID !== 'undefined' && !DOC_ID) return '#redirect';
      return 'Imported “' + data.filename + '”: the document, its tables and its ' + data.pictures + ' picture' + (data.pictures === 1 ? '' : 's') + ' are in your Market Scoping and saved.';
    },
  };
})();
