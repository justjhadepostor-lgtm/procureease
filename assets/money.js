/* ═══════════════════════════════════════════════════════════════
   Money boxes — every peso amount typed into ProcureEase shows its
   thousands separators: 12,000 · 1,250,000.50

   An amount box opts in with class="money" (and type="text",
   inputmode="decimal"). While it is typed in, the commas are put in
   as you go, and the cursor stays where it was.

   Everything that READS the box still gets the plain number: its
   .value is "12000.50", never "12,000.50" — so every total, every save
   and every check works exactly as before. A form sent the ordinary way
   (or read with new FormData(form)) sends the plain number too.
   =============================================================== */
(function () {
  'use strict';
  var NATIVE = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  if (!NATIVE || !NATIVE.get || !NATIVE.set) return;

  function strip(s) { return String(s == null ? '' : s).replace(/,/g, ''); }

  /* 12000.5 → 12,000.5 · keeps what was typed after the point; anything
     that is not a plain number is left exactly as it was typed */
  function fmt(v) {
    var raw = strip(v).trim();
    if (raw === '') return '';
    var m = raw.match(/^(-?)(\d*)(\.\d*)?$/);
    if (!m) return String(v == null ? '' : v);
    var whole = m[2].replace(/^0+(?=\d)/, '');
    return m[1] + whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (m[3] || '');
  }

  /* v95: a box marked data-num-only takes a number and nothing else —
     digits and one decimal point (two places), no letters, no words */
  function onlyNum(s) {
    var t = strip(s).replace(/[^0-9.]/g, '');
    var p = t.indexOf('.');
    if (p !== -1) t = t.slice(0, p + 1) + t.slice(p + 1).replace(/\./g, '').slice(0, 2);
    return t;
  }

  function wire(el) {
    if (!el || el.__money) return;
    el.__money = true;
    var numOnly = el.hasAttribute('data-num-only');
    if (numOnly) NATIVE.set.call(el, onlyNum(NATIVE.get.call(el)));
    Object.defineProperty(el, 'value', {
      configurable: true,
      get: function () { return strip(NATIVE.get.call(this)); },
      set: function (v) { NATIVE.set.call(this, fmt(numOnly ? onlyNum(v) : v)); }
    });
    NATIVE.set.call(el, fmt(NATIVE.get.call(el)));
    el.addEventListener('input', function () {
      var cur = NATIVE.get.call(el);
      var f = fmt(numOnly ? onlyNum(cur) : cur);
      if (f === cur) return;
      var pos = el.selectionStart == null ? cur.length : el.selectionStart;
      var keep = (numOnly ? onlyNum(cur.slice(0, pos)) : strip(cur.slice(0, pos))).length;   // characters before the cursor that stay
      NATIVE.set.call(el, f);
      var i = 0, n = 0;
      while (i < f.length && n < keep) { if (f.charAt(i) !== ',') n++; i++; }
      try { el.setSelectionRange(i, i); } catch (e) {}
    }, true);   // first, so the page's own handlers (totals, autosave) see the cleaned number
  }

  function scan(root) {
    if (!root || root.nodeType !== 1) return;
    if (root.matches && root.matches('input.money')) wire(root);
    if (root.querySelectorAll) {
      var list = root.querySelectorAll('input.money');
      for (var i = 0; i < list.length; i++) wire(list[i]);
    }
  }

  // Boxes as the page is read, and boxes added later (a new row)
  new MutationObserver(function (muts) {
    for (var i = 0; i < muts.length; i++) {
      var added = muts[i].addedNodes;
      for (var j = 0; j < added.length; j++) scan(added[j]);
    }
  }).observe(document.documentElement, { childList: true, subtree: true });
  document.addEventListener('DOMContentLoaded', function () { scan(document.body); });

  // A form sends the plain number, whichever way it is sent
  document.addEventListener('formdata', function (e) {
    var form = e.target, fd = e.formData;
    if (!form || !form.elements || !fd) return;
    var names = {};
    for (var i = 0; i < form.elements.length; i++) {
      var el = form.elements[i];
      if (el.__money && el.name) names[el.name] = true;
    }
    Object.keys(names).forEach(function (name) {
      var vals = fd.getAll(name);
      fd.delete(name);
      vals.forEach(function (v) { fd.append(name, typeof v === 'string' ? strip(v) : v); });
    });
  }, true);

  window.moneyFmt = fmt;
})();
