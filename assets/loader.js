/* ═══════════════════════════════════════════════════════════════
   v97: the loading icon.

   While the next page is on its way (a link, a button that sends a form,
   a page that moves on by itself after saving), a spinner (v117: on its
   own, no word) shows in the middle of the screen, over a light veil that
   also stops a second click from sending the same thing twice.

   - It waits a moment before showing, so a page that loads at once does
     not flash it.
   - Downloads (Excel, backups) leave the page where it is, so they do not
     show it: links to ppmp-excel.php, "?download=", the Reports Excel
     files, a[download], anything opening in a new tab, and any form or
     link marked data-no-loader.
   - When the page asks "Leave site?" (unsaved changes), it does not show.
   - Coming back with the browser's Back button, it is taken off; and it
     never stays up longer than 15 seconds.
   - peLoader.show() / peLoader.hide() for a page's own scripts.
   =============================================================== */
(function () {
  if (window.peLoader) return;
  var DELAY = 180, FAILSAFE = 15000;
  var el = null, showTimer = null, failTimer = null, skipUntil = 0;

  function build() {
    if (el) return el;
    var css = document.createElement('style');
    css.id = 'peLoaderCss';
    css.textContent =
      '#peLoader{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;' +
      'background:rgba(15,43,74,.18);opacity:0;visibility:hidden;transition:opacity .18s ease,visibility 0s linear .18s;cursor:progress}' +
      '#peLoader.on{opacity:1;visibility:visible;transition:opacity .18s ease}' +
      /* v117: the spinner alone — no "Loading…", no card around it */
      '#peLoader .pl-card{display:flex;align-items:center;justify-content:center}' +
      /* v116: twelve bars going round, fading behind the darkest — in the system's colour (the old ring's) */
      '#peLoader .pl-spin{position:relative;width:62px;height:62px;flex:none;color:var(--accent,#1e5285)}' +   /* v118: a little bigger (was 46px) */
      '#peLoader .pl-spin i{position:absolute;left:46%;top:7%;width:8%;height:23%;border-radius:999px;background:currentColor;' +
      'transform-origin:50% 187%;opacity:.15;animation:plFade 1s linear infinite}' +
      '@keyframes plFade{0%{opacity:1}100%{opacity:.15}}' +
      '@media (prefers-reduced-motion: reduce){#peLoader .pl-spin i{animation-duration:2s}}' +
      '@media print{#peLoader{display:none!important}}';
    (document.head || document.documentElement).appendChild(css);
    el = document.createElement('div');
    el.id = 'peLoader';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.setAttribute('aria-label', 'Loading');   // v117: said to screen readers; nothing written on the screen
    var bars = '';
    for (var k = 0; k < 12; k++) {   // each bar at its hour, darkest in turn (clockwise)
      bars += '<i style="transform:rotate(' + (k * 30) + 'deg);animation-delay:' + (-(12 - k) / 12).toFixed(3) + 's"></i>';
    }
    el.innerHTML = '<div class="pl-card"><span class="pl-spin" aria-hidden="true">' + bars + '</span></div>';
    (document.body || document.documentElement).appendChild(el);
    return el;
  }

  function show(now) {
    clearTimeout(showTimer);
    showTimer = setTimeout(function () {
      build().classList.add('on');
      clearTimeout(failTimer);
      failTimer = setTimeout(hide, FAILSAFE);
    }, now ? 0 : DELAY);
  }
  function hide() {
    clearTimeout(showTimer); clearTimeout(failTimer);
    if (el) el.classList.remove('on');
  }
  window.peLoader = { show: function () { show(true); }, hide: hide };

  /* Links and forms that download a file, or open somewhere else */
  var DL = /(^|\/)ppmp-excel\.php|[?&]download=|reports\.php\?(.*&)?generate=(app|app-cse|nonps)(&|#|$)/i;
  function skip() { skipUntil = Date.now() + 1500; }

  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    var t = (a.getAttribute('target') || '').toLowerCase();
    if (a.hasAttribute('download') || a.hasAttribute('data-no-loader') || (t && t !== '_self')
        || /^(mailto:|tel:|javascript:|#)/i.test(href) || DL.test(href)
        || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button > 0) skip();
  }, true);

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.getAttribute) return;
    var t = (f.getAttribute('target') || '').toLowerCase();
    var act = f.getAttribute('action') || '';
    if (f.hasAttribute('data-no-loader') || (t && t !== '_self') || DL.test(act)) skip();
  }, true);

  /* Registered after the page's own scripts have run, so it comes after the
     page's "Leave site?" checks and can see whether one of them stopped the move. */
  function onLeave(e) {
    if (e.defaultPrevented || Date.now() < skipUntil) return;
    show(false);
  }
  function listen() { setTimeout(function () { window.addEventListener('beforeunload', onLeave); }, 0); }
  if (document.readyState !== 'loading') listen();
  else document.addEventListener('DOMContentLoaded', listen);

  /* Back / Forward brings the page back from memory: take it off */
  window.addEventListener('pageshow', hide);
})();
