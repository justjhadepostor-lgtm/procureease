/* ═══════════════════════════════════════════════════════════════
   A number box that shows 0 empties when you click into it, so the
   number you want can be typed straight away. Leave it empty and it
   goes back to what it showed (0).

   Number boxes are: type="number", class="money", inputmode="decimal"
   or "numeric", and the APP-CSE's monthly quantity boxes (class "m").
   Read-only and disabled boxes are left alone. Nothing is saved by the
   emptying itself: the box only changes when something new is typed.
   =============================================================== */
(function () {
  'use strict';
  function isNumberBox(el) {
    if (!el || el.tagName !== 'INPUT' || el.readOnly || el.disabled) return false;
    var t = (el.getAttribute('type') || 'text').toLowerCase();
    if (t !== 'text' && t !== 'number') return false;
    var im = (el.getAttribute('inputmode') || '').toLowerCase();
    return t === 'number' || el.classList.contains('money') || im === 'decimal' || im === 'numeric'
        || (el.classList.contains('m') && !!el.closest('table.appcse'));
  }
  function isZero(v) { return /^\s*0+(\.0+)?\s*$/.test(String(v).replace(/,/g, '')); }

  document.addEventListener('focusin', function (e) {
    var el = e.target;
    if (!isNumberBox(el) || !isZero(el.value)) return;
    el.dataset.zeroWas = el.value;
    el.value = '';
  });
  document.addEventListener('focusout', function (e) {
    var el = e.target;
    if (!el || el.dataset == null || el.dataset.zeroWas === undefined) return;
    var was = el.dataset.zeroWas;
    delete el.dataset.zeroWas;
    if (String(el.value).trim() === '') el.value = was;     // nothing typed: back to 0, as it was
  });
})();
