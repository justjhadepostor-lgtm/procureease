/* ═══════════════════════════════════════════════════════════════
   v103: the document buttons float (v99 on the PPMP; now on every document).

   A page marks the row of buttons at its top with data-dock (Print, Save,
   Submit, Archive, Version history, Approve …). Once that row is scrolled
   out of sight, the same buttons float at the bottom-right of the screen;
   back at the top they go away again.

   The floating ones are stand-ins: each one presses the real button at the
   top, so it does exactly what that button does — its confirmation, its
   form, its checks — and nothing is done twice. A button that is hidden or
   switched off at the top is hidden or switched off here too.
   =============================================================== */
(function () {
  function css() {
    if (document.getElementById('peDockCss')) return;
    var s = document.createElement('style');
    s.id = 'peDockCss';
    s.textContent =
      '.pe-dock{position:fixed;right:22px;bottom:22px;z-index:900;display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end;' +
      'padding:10px 12px;background:var(--white,#fff);border:1px solid var(--border,#d5e0ec);border-radius:14px;' +
      'box-shadow:0 12px 30px rgba(15,43,74,.22);opacity:0;visibility:hidden;transform:translateY(12px);' +
      'transition:opacity .18s ease,transform .18s ease,visibility 0s linear .18s;max-width:calc(100vw - 32px)}' +
      '.pe-dock.on{opacity:1;visibility:visible;transform:none;transition:opacity .18s ease,transform .18s ease}' +
      '.pe-dock .btn{margin:0}' +
      '.pe-dock .dock-state{font-size:11.5px;color:var(--muted,#456077);padding:0 4px}' +
      '@media (max-width:600px){.pe-dock{left:12px;right:12px;bottom:12px}}' +
      '@media (prefers-reduced-motion:reduce){.pe-dock{transition:none}}' +
      '@media print{.pe-dock{display:none!important}}';
    document.head.appendChild(s);
  }
  function shown(el) {
    if (!el || el.hidden) return false;
    var cs = window.getComputedStyle(el);
    return cs.display !== 'none' && cs.visibility !== 'hidden' && el.getClientRects().length > 0;
  }
  function build(src) {
    css();
    var dock = document.createElement('div');
    dock.id = src.getAttribute('data-dock-id') || 'docDock';
    dock.className = 'pe-dock';
    dock.setAttribute('role', 'toolbar');
    dock.setAttribute('aria-label', 'Document actions');
    dock.setAttribute('aria-hidden', 'true');
    var pairs = [];
    // The page's own buttons and button-looking links, in their order
    Array.prototype.forEach.call(src.querySelectorAll('button, a.btn, a[class*="btn-"]'), function (b) {
      if (b.closest('.pe-dock')) return;
      if (b.closest('[data-no-dock]')) return;   // v105: Export / Import stay at the top only
      if (b.type === 'hidden') return;
      var c = document.createElement(b.tagName === 'A' ? 'a' : 'button');
      if (c.tagName === 'BUTTON') c.type = 'button';
      if (b.tagName === 'A') { c.href = b.getAttribute('href') || '#'; if (b.target) c.target = b.target; }
      c.className = b.className; c.innerHTML = b.innerHTML;
      var st = b.getAttribute('style'); if (st) c.setAttribute('style', st);
      if (b.title) c.title = b.title;
      c.setAttribute('tabindex', '-1');
      c.addEventListener('click', function (ev) {
        ev.preventDefault();
        // A button that opens a little menu under itself: open it where it is
        var oc = b.getAttribute('onclick') || '';
        if (/toggle|Menu/.test(oc)) window.scrollTo({ top: 0, behavior: 'auto' });
        b.click();
      });
      dock.appendChild(c);
      pairs.push([b, c]);
    });
    if (!pairs.length) return;
    // The PPMP's "Saving… / Saved" goes along (v99)
    var st0 = src.getAttribute('data-dock-status') ? document.getElementById(src.getAttribute('data-dock-status')) : null;
    if (st0) {
      var s2 = document.createElement('span'); s2.className = 'dock-state'; dock.insertBefore(s2, dock.firstChild);
      new MutationObserver(function () { s2.textContent = st0.textContent; }).observe(st0, { childList: true, characterData: true, subtree: true });
    }
    document.body.appendChild(dock);
    function sync() {
      pairs.forEach(function (p) {
        p[1].style.display = shown(p[0]) ? '' : 'none';
        if (p[0].tagName === 'BUTTON') p[1].disabled = !!p[0].disabled;
        if (p[0].innerHTML !== p[1].innerHTML) p[1].innerHTML = p[0].innerHTML;   // "Saving…", "✓ Saved"
      });
    }
    var on = false;
    new IntersectionObserver(function (en) {
      on = !en[0].isIntersecting;
      if (on) sync();
      dock.classList.toggle('on', on);
      dock.setAttribute('aria-hidden', on ? 'false' : 'true');
      pairs.forEach(function (p) { p[1].setAttribute('tabindex', on ? '0' : '-1'); });
    }).observe(src);
    // Labels and on/off follow the real buttons while the dock is up
    new MutationObserver(function () { if (on) sync(); }).observe(src, { subtree: true, childList: true, attributes: true, characterData: true });
  }
  function init() {
    if (!('IntersectionObserver' in window)) return;
    var src = document.querySelector('[data-dock]');
    if (src) build(src);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
