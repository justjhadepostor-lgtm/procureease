/* ═══════════════════════════════════════════════════════════════
   Back stays where you were.

   The sidebar section you are in (PPMP Tracking, My Department, the
   Archive, …) stays your section while you open a file from it, look at
   its APP-CSE, print it and so on — until you click another item in the
   sidebar. So:
     · the sidebar keeps that section highlighted on the pages you open
       from it (a file, a review, a printout);
     · a Back button that would take you to some other section's page
       (e.g. PPMP Folders) takes you back to the page you came from instead.
   Back buttons that lead to a page inside the file (the file itself, from
   its printout or editor) are left as they are.

   Kept per browser tab (sessionStorage) and per account. Without it — a
   new tab, no JavaScript — every Back works exactly as before.
   =============================================================== */
(function () {
  'use strict';
  var uid = document.documentElement.getAttribute('data-uid') || (document.body && document.body.getAttribute('data-uid')) || window.PE_UID;
  if (!uid) return;
  var KEY = 'pe_section';
  // Sidebar items that open another page than their own name
  var ALIAS = { 'nonps-list.php': ['nonps-edit.php'], 'annex.php': ['annex-edit.php'] };
  function base(href) {
    try { var p = new URL(href, location.href).pathname; return p.substring(p.lastIndexOf('/') + 1); } catch (e) { return ''; }
  }
  function read() {
    try { var s = JSON.parse(sessionStorage.getItem(KEY) || 'null'); return s && String(s.uid) === String(uid) ? s : null; } catch (e) { return null; }
  }
  function write(s) { try { s.uid = uid; sessionStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} }

  function run() {
    var links = Array.prototype.slice.call(document.querySelectorAll('.sidebar .nav a[href]'))
      .filter(function (a) { var h = a.getAttribute('href'); return h && h.indexOf('javascript:') !== 0 && h !== '#' && base(h) !== 'logout.php'; });
    var stored = read();
    var cur = base(location.href);

    // Clicking a sidebar item starts a new section
    links.forEach(function (a) {
      a.addEventListener('click', function () { write({ nav: base(a.href), url: a.href, bases: allBases(), fresh: 1 }); });
    });
    function allBases() {
      var b = {};
      links.forEach(function (a) { var x = base(a.href); b[x] = 1; (ALIAS[x] || []).forEach(function (y) { b[y] = 1; }); });
      return Object.keys(b);
    }

    var active = document.querySelector('.sidebar .nav a.active');
    var activeBase = active ? base(active.href) : '';
    var isSection = !!active && (activeBase === cur || (ALIAS[activeBase] || []).indexOf(cur) > -1 && !!stored && stored.fresh && stored.nav === activeBase);
    if (links.length && isSection) {
      write({ nav: activeBase, url: location.href, bases: allBases() });
      return;
    }
    if (!stored) return;
    if (stored.fresh) { stored.fresh = 0; write(stored); }

    // A page opened from a section: keep that section highlighted…
    if (links.length) {
      var keep = links.filter(function (a) { return base(a.href) === stored.nav; })[0];
      if (keep && keep !== active) {
        if (active) active.classList.remove('active');
        keep.classList.add('active');
      }
    }
    // …and Back returns to it, not to another section's page
    var bases = links.length ? allBases() : (stored.bases || []);
    Array.prototype.forEach.call(document.querySelectorAll('a[href]'), function (a) {
      var t = (a.textContent || '').replace(/\s+/g, ' ').trim();
      var isBack = a.classList.contains('back-btn') || t.indexOf('←') === 0 || /^Back$/.test(t);
      if (!isBack || a.closest('.sidebar')) return;
      var b = base(a.href);
      if (bases.indexOf(b) < 0) return;                 // back to the file, the editor… — left as it is
      if (a.href === stored.url) return;
      a.setAttribute('data-back-default', a.getAttribute('href'));
      a.setAttribute('href', stored.url);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
