<?php
/* ═══════════════════════════════════════════════════════════════
   The Position picker, as on the registration form: type to search the
   list (config/positions.php), or choose "Others" — always last — and type
   a position that is not on it.

   What is posted is ONE field, name="position", holding the position
   picked or the one typed under Others — so the page that receives it
   reads it exactly as before.

   Set before including:  $pcValue — the position on file.
                          $pcRequired — true: it cannot be saved empty (v123)
   =============================================================== */
require_once __DIR__ . '/../config/positions.php';
$pcValue  = trim((string)($pcValue ?? ''));
$pcChoice = $pcValue === '' ? '' : (in_array($pcValue, reg_positions(), true) ? $pcValue : 'Others');
$pcOther  = $pcChoice === 'Others' ? $pcValue : '';
?>
<style>
  .pos-combo { position: relative; }
  .pos-combo .combo-input { padding-right: 38px; }
  .pos-combo.is-chosen .combo-input { font-weight: 600; }
  .pos-combo .combo-toggle { position: absolute; top: 50%; right: 6px; transform: translateY(-50%); display: grid; place-items: center;
    width: 28px; height: 28px; padding: 0; background: none; border: none; border-radius: 7px; color: var(--muted); cursor: pointer;
    transition: background .15s, color .15s, transform .2s; }
  .pos-combo .combo-toggle:hover { background: var(--navy-50); color: var(--ink); }
  .pos-combo.is-open .combo-toggle { transform: translateY(-50%) rotate(180deg); }
  .pos-combo .combo-list { display: none; position: absolute; z-index: 60; top: calc(100% + 5px); left: 0; right: 0; max-height: 244px;
    overflow-y: auto; margin: 0; padding: 5px; list-style: none; background: #ffffff; border: 1px solid var(--border); border-radius: 11px;
    box-shadow: 0 12px 32px rgba(15, 43, 74, .16); overscroll-behavior: contain; }
  .pos-combo.is-open .combo-list { display: block; }
  /* the open list sits above the cards that follow (a card is its own layer — backdrop blur) */
  .card:has(.pos-combo.is-open) { position: relative; z-index: 70; }
  .pos-combo.is-open { z-index: 71; }
  .pos-combo .combo-opt { display: flex; align-items: baseline; gap: 9px; padding: 9px 11px; border-radius: 8px; font-size: 13.5px;
    color: #133458; cursor: pointer; scroll-margin: 5px; }
  .pos-combo .combo-opt:hover, .pos-combo .combo-opt.is-active { background: #EAF4F9; }
  .pos-combo .combo-opt[aria-selected="true"] { background: #EAF4F9; font-weight: 600; }
  .pos-combo .combo-opt mark { background: #fde68a; color: inherit; padding: 0 1px; border-radius: 2px; }
  .pos-combo .combo-name { flex: 1; min-width: 0; line-height: 1.35; }
  .pos-combo .combo-group { display: block; font-size: 10.5px; color: #516678; margin-top: 2px; }
  .pos-combo .combo-empty { padding: 16px 12px; text-align: center; font-size: 12.5px; color: #516678; line-height: 1.6; }
  .pos-combo .combo-empty strong { display: block; color: #133458; font-size: 13px; margin-bottom: 3px; }
  .pos-hint { font-size: 11.5px; color: var(--muted); margin-top: 5px; }
  .pos-sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
  body[data-theme="dark"] .pos-combo .combo-list { background: #ffffff !important; border-color: #7a9bb8 !important; }
  body[data-theme="dark"] .pos-combo .combo-opt { color: #133458 !important; }
  body[data-theme="dark"] .pos-combo .combo-group, body[data-theme="dark"] .pos-combo .combo-empty { color: #516678 !important; }
  body[data-theme="dark"] .pos-combo .combo-empty strong { color: #133458 !important; }
</style>
<div class="pos-combo" id="pfPosCombo">
  <input type="text" id="pf_position" class="form-input combo-input" placeholder="Type to search your position…"
         value="<?= e($pcChoice) ?>" role="combobox" aria-expanded="false" aria-controls="pfPosList"
         aria-autocomplete="list" aria-haspopup="listbox" autocomplete="off" autocapitalize="off" spellcheck="false">
  <button type="button" class="combo-toggle" id="pfPosToggle" tabindex="-1" aria-label="Show all positions">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg></button>
  <ul class="combo-list" id="pfPosList" role="listbox" aria-label="Positions"></ul>
</div>
<input type="hidden" name="position" id="pf_position_value" value="<?= e($pcValue) ?>">
<input type="text" id="pf_position_other" class="form-input" maxlength="100" value="<?= e($pcOther) ?>" placeholder="Type your position"
       aria-label="Your position (Others)" style="margin-top: 8px;"<?= $pcChoice === 'Others' ? '' : ' hidden' ?>>
<div class="pos-hint" id="pfPosHint">Search your position — not on the list? Choose &ldquo;Others&rdquo; at the end and type it.</div>
<div id="pfPosStatus" class="pos-sr" role="status" aria-live="polite"></div>
<script>
(function () {
  var POS = <?= json_encode(reg_positions(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var OTHERS = 'Others';
  var wrap = document.getElementById('pfPosCombo'), input = document.getElementById('pf_position');
  var toggle = document.getElementById('pfPosToggle'), list = document.getElementById('pfPosList');
  var value = document.getElementById('pf_position_value'), other = document.getElementById('pf_position_other');
  var hint = document.getElementById('pfPosHint'), status = document.getElementById('pfPosStatus'), HINT = hint.innerHTML;
  var choice = <?= json_encode($pcChoice) ?>, open = false, matches = [], activeIx = -1;
  var REQUIRED = <?= !empty($pcRequired) ? 'true' : 'false' ?>;   // v123

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function mark(text, term) {
    if (!term) return esc(text);
    var i = text.toLowerCase().indexOf(term.toLowerCase());
    return i < 0 ? esc(text) : esc(text.slice(0, i)) + '<mark>' + esc(text.slice(i, i + term.length)) + '</mark>' + esc(text.slice(i + term.length));
  }
  function startsWord(n, t) { var i = n.indexOf(t); while (i > -1) { if (i === 0 || !/[a-z0-9]/.test(n.charAt(i - 1))) return true; i = n.indexOf(t, i + 1); } return false; }
  function filter(term) {
    term = (term || '').trim().toLowerCase();
    if (!term) return POS.slice();
    var hits = [];
    POS.forEach(function (p, i) {
      var n = p.toLowerCase(), s = n === term ? 0 : n.indexOf(term) === 0 ? 1 : startsWord(n, term) ? 2 : n.indexOf(term) > -1 ? 3 : -1;
      if (s > -1) hits.push({ p: p, s: s, i: i });
    });
    hits.sort(function (a, b) { return a.s - b.s || a.i - b.i; });
    return hits.map(function (h) { return h.p; });
  }
  function say(m) { status.textContent = m; }
  function sync() { value.value = choice === OTHERS ? other.value.trim() : choice; }
  function render(term) {
    var found = filter(term); matches = found.concat([OTHERS]); activeIx = -1; list.innerHTML = '';
    if (!found.length) { var e0 = document.createElement('li'); e0.className = 'combo-empty';
      e0.innerHTML = '<strong>No position found</strong>Choose &ldquo;Others&rdquo; below to type it.'; list.appendChild(e0); }
    var typed = (term || '').trim();
    matches.forEach(function (p, ix) {
      var li = document.createElement('li'); li.className = 'combo-opt'; li.id = 'pf-pos-opt-' + ix; li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', choice === p ? 'true' : 'false');
      li.innerHTML = p === OTHERS
        ? '<span class="combo-name"><strong>Others</strong><span class="combo-group">' + (typed && !found.length ? 'Type &ldquo;' + esc(typed) + '&rdquo; as your position' : 'My position is not on the list') + '</span></span>'
        : '<span class="combo-name">' + mark(p, term) + '</span>';
      li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(p, typed); });
      li.addEventListener('mousemove', function () { if (activeIx !== ix) setActive(ix, false); });
      list.appendChild(li);
    });
    say(found.length + (found.length === 1 ? ' position found.' : ' positions found.') + ' Others is last.');
  }
  function setActive(ix, scroll) {
    var rows = list.querySelectorAll('.combo-opt');
    rows.forEach(function (r) { r.classList.remove('is-active'); }); activeIx = ix;
    if (ix < 0 || ix >= rows.length) { input.removeAttribute('aria-activedescendant'); return; }
    rows[ix].classList.add('is-active'); input.setAttribute('aria-activedescendant', rows[ix].id);
    if (scroll !== false) rows[ix].scrollIntoView({ block: 'nearest' });
  }
  function openList(t) { render(t !== undefined ? t : ''); open = true; wrap.classList.add('is-open'); input.setAttribute('aria-expanded', 'true'); }
  function closeList() { open = false; wrap.classList.remove('is-open'); input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); activeIx = -1; }
  function choose(p, typed) {
    choice = p; input.value = p; wrap.classList.add('is-chosen'); hint.innerHTML = HINT; hint.style.color = ''; closeList();
    if (p === OTHERS) {
      other.hidden = false;
      if (typed && !filter(typed).length && !other.value) other.value = typed;
      other.focus(); say('Others selected. Type your position.');
    } else { other.hidden = true; other.value = ''; say(p + ' selected.'); }
    sync();
  }
  function clearChoice() { if (!choice) return; choice = ''; wrap.classList.remove('is-chosen'); other.hidden = true; sync(); }

  input.addEventListener('input', function () { clearChoice(); openList(input.value); });
  input.addEventListener('focus', function () { openList(choice ? '' : input.value); if (choice) input.select(); });
  input.addEventListener('click', function () { if (!open) openList(choice ? '' : input.value); });
  toggle.addEventListener('click', function () { if (open) { closeList(); return; } input.focus(); openList(choice ? '' : input.value); });
  input.addEventListener('keydown', function (ev) {
    switch (ev.key) {
      case 'ArrowDown': ev.preventDefault(); if (!open) { openList(choice ? '' : input.value); setActive(0); } else setActive(activeIx + 1 >= matches.length ? 0 : activeIx + 1); break;
      case 'ArrowUp': ev.preventDefault(); if (!open) { openList(choice ? '' : input.value); setActive(matches.length - 1); } else setActive(activeIx - 1 < 0 ? matches.length - 1 : activeIx - 1); break;
      case 'Enter':
        if (open && activeIx > -1 && matches[activeIx]) { ev.preventDefault(); choose(matches[activeIx], input.value.trim()); }
        else if (open && matches.length === 2) { ev.preventDefault(); choose(matches[0], input.value.trim()); }
        break;
      case 'Escape': if (open) { ev.preventDefault(); closeList(); } break;
      case 'Tab': closeList(); break;
    }
  });
  /* Leaving the search: an exact or single match is taken; text that matches
     nothing becomes "Others" with that text, so nothing typed is lost */
  function settle(focusOther) {
    if (choice) { input.value = choice; return; }
    var typed = input.value.trim(); if (!typed) { sync(); return; }
    var exact = POS.filter(function (p) { return p.toLowerCase() === typed.toLowerCase(); })[0], m = filter(typed);
    if (exact) choose(exact); else if (m.length === 1) choose(m[0]);
    else if (!m.length) { choose(OTHERS, typed); if (!focusOther) other.blur(); }
    else input.value = '';
  }
  input.addEventListener('blur', function () {
    setTimeout(function () {
      if (document.activeElement === other || document.activeElement === input) return;
      closeList(); settle(true);
    }, 120);
  });
  document.addEventListener('click', function (ev) { if (!wrap.contains(ev.target)) closeList(); });
  other.addEventListener('input', function () { hint.innerHTML = HINT; hint.style.color = ''; sync(); });
  var form = input.closest('form');
  if (form) form.addEventListener('submit', function (ev) {
    closeList(); settle(false);   // saved straight from the search box: what was typed counts
    if (choice === OTHERS && !other.value.trim()) {
      ev.preventDefault(); ev.stopImmediatePropagation();
      hint.textContent = 'You chose “Others” — please type your position.'; hint.style.color = 'var(--orange-500)';
      other.focus(); return;
    }
    sync();
    if (REQUIRED && !value.value.trim()) {   // v123: Position is required, as on Create account
      ev.preventDefault(); ev.stopImmediatePropagation();
      hint.textContent = 'Please choose your position from the list, or choose “Others” and type it.'; hint.style.color = 'var(--orange-500)';
      input.focus(); return;
    }
  }, true);
  if (choice) wrap.classList.add('is-chosen');
})();
</script>
