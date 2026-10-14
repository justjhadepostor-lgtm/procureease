<?php
if (!isset($pageTitle)) $pageTitle = 'ProcureEase';
if (file_exists(__DIR__ . '/../config/notify.php')) require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/ui.php';   // search_field(), sort_bar()

// Load user preferences (dark mode, high contrast) if signed in
$__prefs = ['dark_mode' => '0', 'high_contrast' => '0'];
if (function_exists('current_user') && current_user()) {
    try {
        $s = db()->prepare('SELECT key_name, value FROM user_prefs WHERE user_id = ?');
        $s->execute([current_user()['id']]);
        foreach ($s->fetchAll() as $r) $__prefs[$r['key_name']] = $r['value'];
    } catch (Throwable $t) { /* user_prefs may not exist yet */ }
}
// Deadline reminders are swept once a day, triggered by the first page load
require_once __DIR__ . '/../config/notify.php';
maybe_run_daily_reminders();
// v80: once anyone is signed in, setup.php is turned off for good (config/setup-lock.php)
if (function_exists('current_user') && current_user()) {
    require_once __DIR__ . '/../config/setup-lock.php';
    setup_lock_now();
}
// Settings > Backup schedule — the automatic backup, when one is due
if (function_exists('current_user') && current_user()) {
    require_once __DIR__ . '/../config/backup-lib.php';
    maybe_run_scheduled_backup();
}

$__bodyAttr = '';
if (($__prefs['dark_mode']     ?? '0') === '1') $__bodyAttr .= ' data-theme="dark"';
if (($__prefs['high_contrast'] ?? '0') === '1') $__bodyAttr .= ' data-contrast="high"';
if (!empty($activeNav)) $__bodyAttr .= ' data-nav="' . e($activeNav) . '"';
$__cu = function_exists('current_user') ? current_user() : null;
if (!empty($__cu['id'])) $__bodyAttr .= ' data-uid="' . (int)$__cu['id'] . '"';   // assets/back-nav.js: Back stays in your section
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — ProcureEase</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
<?= theme_css() /* nothing since v43: Theme colours was taken off Settings */ ?>
<?php if (!empty($extraCss)) echo $extraCss; ?>
<?= function_exists('csrf_meta') ? csrf_meta() : '' /* v79: this session's security token (config/csrf.php) */ ?>
<script src="assets/csrf.js?v=<?= @filemtime(__DIR__ . '/../assets/csrf.js') ?>"></script>
<script src="assets/money.js?v=<?= @filemtime(__DIR__ . '/../assets/money.js') ?>"></script>
<script src="assets/back-nav.js?v=<?= @filemtime(__DIR__ . '/../assets/back-nav.js') ?>" defer></script>
<script src="assets/zero-clear.js?v=<?= @filemtime(__DIR__ . '/../assets/zero-clear.js') ?>"></script>
<?php /* v97: the loading icon while the next page is on its way · v103: the document buttons float (assets/dock.js) */ ?>
<script src="assets/dock.js?v=<?= @filemtime(__DIR__ . '/../assets/dock.js') ?>" defer></script>
<script src="assets/loader.js?v=<?= @filemtime(__DIR__ . '/../assets/loader.js') ?>"></script>
</head>
<body<?= $__bodyAttr ?>>

<header class="topbar">
  <?php /* v96: on a phone or tablet the side menu opens from this button */ ?>
  <button type="button" class="nav-toggle" id="navToggle" aria-label="Open the menu" aria-controls="appSidebar" aria-expanded="false" onclick="peNav(true)">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
  </button>
  <div class="institution">
    <img src="assets/ascot-logo.png" class="school-mark" alt="ASCOT">
    <div class="school-name">Aurora State College of Technology</div>
  </div>
<?php $u = current_user(); if ($u): ?>
  <div class="topbar-user">

    <!-- 1. NAME (avatar + full name) -->
    <div class="tb-name">
      <?php if (!empty($u['avatar_path'])): ?>
        <img src="<?= e($u['avatar_path']) ?>" alt="" class="avatar" style="object-fit: cover; object-position: <?= e($u['avatar_pos'] ?? '50% 50%') ?>;">
      <?php else: ?>
        <div class="avatar"><?= e(initials($u['full_name'])) ?></div>
      <?php endif; ?>
      <span><?= e($u['full_name']) ?></span>
    </div>

    <!-- 2. MODE (light/dark toggle) -->
    <?php $__isDark = ($__prefs['dark_mode'] ?? '0') === '1'; ?>
    <button type="button" class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle dark mode" title="<?= $__isDark ? 'Switch to light mode' : 'Switch to dark mode' ?>">
      <span id="themeIconLight" style="display: <?= $__isDark ? 'none' : 'inline-flex' ?>;" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <g stroke="#E0A800" stroke-width="2.2" stroke-linecap="round">
            <line x1="12" y1="2.5" x2="12" y2="5"/>
            <line x1="12" y1="19" x2="12" y2="21.5"/>
            <line x1="2.5" y1="12" x2="5" y2="12"/>
            <line x1="19" y1="12" x2="21.5" y2="12"/>
            <line x1="5.2" y1="5.2" x2="6.9" y2="6.9"/>
            <line x1="17.1" y1="17.1" x2="18.8" y2="18.8"/>
            <line x1="5.2" y1="18.8" x2="6.9" y2="17.1"/>
            <line x1="17.1" y1="6.9" x2="18.8" y2="5.2"/>
          </g>
          <circle cx="12" cy="12" r="4.8" fill="#E0A800"/>
        </svg>
      </span>
      <span id="themeIconDark" style="display: <?= $__isDark ? 'inline-flex' : 'none' ?>;" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M20.5 14.2A8.5 8.5 0 1 1 9.8 3.5a7 7 0 0 0 10.7 10.7z" fill="var(--orange-50)"/>
          <circle cx="18.5" cy="5.5" r="0.8" fill="var(--orange-50)"/>
          <circle cx="15" cy="3.5" r="0.5" fill="var(--orange-50)"/>
          <circle cx="20" cy="9" r="0.5" fill="var(--orange-50)"/>
        </svg>
      </span>
    </button>
    <script>
      function toggleTheme() {
        var body = document.body;
        var isDark = body.getAttribute('data-theme') === 'dark';
        var next = isDark ? '0' : '1';
        if (next === '1') {
          body.setAttribute('data-theme', 'dark');
          document.getElementById('themeIconLight').style.display = 'none';
          document.getElementById('themeIconDark').style.display = 'inline-flex';
        } else {
          body.removeAttribute('data-theme');
          document.getElementById('themeIconLight').style.display = 'inline-flex';
          document.getElementById('themeIconDark').style.display = 'none';
        }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/toggle-theme.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send('value=' + next);
      }
    </script>

    <?php if (function_exists('get_notifications')):
        $__notifs  = get_notifications((int)$u['id'], 8);
        $__unread  = unread_notif_count((int)$u['id']);
    ?>
      <!-- 3. NOTIF (bell) -->
      <div class="notif-wrap">
        <button type="button" class="notif-btn" onclick="toggleNotif(event)" aria-label="Notifications">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <?php if ($__unread > 0): ?>
            <span class="notif-badge"><?= $__unread > 99 ? '99+' : $__unread ?></span>
          <?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-head">
            <div class="notif-head-title">Notifications</div>
            <?php if ($__unread > 0): ?>
              <form method="POST" action="api/mark-notif-read.php" style="margin:0;">
                <input type="hidden" name="scope" value="all">
                <button type="submit" class="notif-mark-all">Mark all read</button>
              </form>
            <?php endif; ?>
          </div>
          <div class="notif-list">
            <?php if (!$__notifs): ?>
              <div class="notif-empty">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4; margin-bottom: 8px;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <div>No notifications yet</div>
              </div>
            <?php else: foreach ($__notifs as $n):
              $typeIcon = [
                'announcement' => '📢',
                'approval'     => '✓',
                'revision'     => '⚠',
                'submission'   => '📥',
                'final'        => '✓',
              ][$n['type']] ?? '🔔';
              $accent = [
                'announcement' => 'var(--navy-700)',
                'approval'     => '#15803d',
                'revision'     => 'var(--navy-500)',
                'submission'   => 'var(--navy-700)',
                'final'        => '#15803d',
              ][$n['type']] ?? 'var(--navy-500)';
            ?>
              <a href="api/notif-read.php?id=<?= (int)$n['id'] ?><?= $n['link'] ? '&to=' . urlencode($n['link']) : '' ?>" class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>" style="border-left-color: <?= $accent ?>;">
                <div class="notif-item-icon"><?= $typeIcon ?></div>
                <div class="notif-item-body">
                  <div class="notif-item-title"><?= e($n['title']) ?></div>
                  <?php if (!empty($n['body'])): ?>
                    <div class="notif-item-text"><?= e(mb_substr($n['body'], 0, 120)) . (mb_strlen($n['body']) > 120 ? '…' : '') ?></div>
                  <?php endif; ?>
                  <div class="notif-item-time"><?= reltime_span($n['created_at']) ?></div>
                </div>
              </a>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
      <script>
        function toggleNotif(e) {
          e.stopPropagation();
          document.getElementById('notifDropdown').classList.toggle('open');
        }
        document.addEventListener('click', (e) => {
          const dd = document.getElementById('notifDropdown');
          if (!dd) return;
          if (!e.target.closest('.notif-wrap')) dd.classList.remove('open');
        });
      </script>
    <?php endif; ?>
  </div>
<?php endif; ?>
</header>
<script>
/* v96: the side menu as a drawer on narrow screens (assets/style.css, "Responsive") */
(function () {
  var mq = window.matchMedia('(max-width: 900px)');
  function sb() { return document.querySelector('.app > .sidebar'); }
  window.peNav = function (open) {
    var s = sb(); if (!s) return;
    document.body.classList.toggle('nav-open', !!open);
    var t = document.getElementById('navToggle'); if (t) t.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { var a = s.querySelector('a.active') || s.querySelector('a'); if (a) try { a.focus({preventScroll: true}); } catch (e) {} }
  };
  document.addEventListener('DOMContentLoaded', function () {
    var s = sb(); if (!s) return;
    document.body.classList.add('has-sidebar');
    s.id = s.id || 'appSidebar';
    var bd = document.createElement('div'); bd.className = 'nav-backdrop'; bd.addEventListener('click', function () { peNav(false); });
    document.body.appendChild(bd);
    var x = document.createElement('button'); x.type = 'button'; x.className = 'nav-close'; x.setAttribute('aria-label', 'Close the menu'); x.innerHTML = '&times;';
    x.addEventListener('click', function () { peNav(false); }); s.insertBefore(x, s.firstChild);
    s.addEventListener('click', function (e) { if (mq.matches && e.target.closest('a[href]')) peNav(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.body.classList.contains('nav-open')) { peNav(false); var t = document.getElementById('navToggle'); if (t) t.focus(); } });
    (mq.addEventListener ? mq.addEventListener.bind(mq, 'change') : mq.addListener.bind(mq))(function () { if (!mq.matches) peNav(false); });
  });
})();
</script>
<?php
/* v97: "Submitted!" — played once on the page a submission lands on
   (celebrate_submitted() in config/auth.php). It goes by itself after a
   moment; a click or any key closes it at once. */
$__sub = function_exists('take_submitted') ? take_submitted() : null;
if ($__sub): ?>
<style>
  #peSubmitted { position: fixed; inset: 0; z-index: 100001; display: flex; align-items: center; justify-content: center;
    padding: 16px; background: rgba(15,43,74,.28); animation: psFade .22s ease both; cursor: pointer; }
  #peSubmitted.out { animation: psFadeOut .3s ease both; }
  #peSubmitted .ps-card { background: var(--white, #fff); color: var(--ink, #0f2b4a); border: 1px solid var(--border, #d5e0ec);
    border-radius: 18px; box-shadow: 0 20px 50px rgba(15,43,74,.28); padding: 26px 34px 22px; text-align: center;
    min-width: 240px; max-width: min(420px, 100%); animation: psPop .42s cubic-bezier(.2,1.3,.4,1) both; }
  #peSubmitted svg { width: 84px; height: 84px; display: block; margin: 0 auto 12px; overflow: visible; }
  #peSubmitted .ps-ring { fill: none; stroke: #16a34a; stroke-width: 3; stroke-dasharray: 151; stroke-dashoffset: 151;
    animation: psDraw .5s ease-out .15s forwards, psFill .3s ease .6s forwards; }
  #peSubmitted .ps-tick { fill: none; stroke: #fff; stroke-width: 4.5; stroke-linecap: round; stroke-linejoin: round;
    stroke-dasharray: 40; stroke-dashoffset: 40; animation: psDraw .32s ease-out .75s forwards; }
  #peSubmitted .ps-glow { fill: none; stroke: #16a34a; stroke-width: 2; opacity: 0; transform-origin: 26px 26px;
    animation: psGlow .7s ease-out .8s; }
  #peSubmitted .ps-title { font: 700 22px/1.2 'Space Grotesk', Inter, system-ui, sans-serif; margin: 0 0 4px; }
  #peSubmitted .ps-what { font-size: 14px; font-weight: 600; }
  #peSubmitted .ps-to { font-size: 13px; color: var(--muted, #456077); margin-top: 2px; }
  @keyframes psFade { from { opacity: 0; } to { opacity: 1; } }
  @keyframes psFadeOut { from { opacity: 1; } to { opacity: 0; } }
  @keyframes psPop { from { opacity: 0; transform: scale(.82) translateY(8px); } to { opacity: 1; transform: none; } }
  @keyframes psDraw { to { stroke-dashoffset: 0; } }
  @keyframes psFill { to { fill: #16a34a; } }
  @keyframes psGlow { 0% { opacity: .55; transform: scale(1); } 100% { opacity: 0; transform: scale(1.45); } }
  @media (prefers-reduced-motion: reduce) {
    #peSubmitted, #peSubmitted .ps-card, #peSubmitted.out { animation: none; }
    #peSubmitted .ps-ring { animation: none; stroke-dashoffset: 0; fill: #16a34a; }
    #peSubmitted .ps-tick { animation: none; stroke-dashoffset: 0; }
    #peSubmitted .ps-glow { display: none; }
  }
  @media print { #peSubmitted { display: none !important; } }
</style>
<div id="peSubmitted" role="status" aria-live="assertive" title="Click to close">
  <div class="ps-card">
    <svg viewBox="0 0 52 52" aria-hidden="true">
      <circle class="ps-glow" cx="26" cy="26" r="24"/>
      <circle class="ps-ring" cx="26" cy="26" r="24"/>
      <path class="ps-tick" d="M15 27 l7.5 7.5 L37.5 19"/>
    </svg>
    <div class="ps-title">Submitted!</div>
    <?php if (($__sub['what'] ?? '') !== ''): ?><div class="ps-what"><?= e($__sub['what']) ?></div><?php endif; ?>
    <?php if (($__sub['to'] ?? '') !== ''): ?><div class="ps-to">sent to the <?= e($__sub['to']) ?></div><?php endif; ?>
  </div>
</div>
<script>
(function () {
  var o = document.getElementById('peSubmitted'); if (!o) return;
  var gone = false;
  function close() {
    if (gone) return; gone = true;
    document.removeEventListener('keydown', close, true);
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { o.remove(); return; }
    o.classList.add('out'); setTimeout(function () { o.remove(); }, 300);
  }
  o.addEventListener('click', close);
  document.addEventListener('keydown', close, true);
  setTimeout(close, 2600);
})();
</script>
<?php endif; ?>

<style>
  /* Live time on screen, absolute date on print — see reltime_span() helper */
  .reltime-abs { display: none; }
  @media print {
    .reltime { display: none !important; }
    .reltime-abs { display: inline !important; }
  }
</style>

<script>
// ─── Prevent auto-scroll to top on navigation ─────────────
if ('scrollRestoration' in history) {
  history.scrollRestoration = 'manual';
}
</script>

<script>
// ─── Live relative-time updater ───────────────────────────
// Any element with class="reltime" and data-time="ISO8601" auto-refreshes
// every 30 seconds, so times stay accurate without page reloads.
(function() {
  function relFmt(iso) {
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';
    const secs = Math.max(0, Math.floor((Date.now() - then) / 1000));
    if (secs < 10)       return 'just now';
    if (secs < 60)       return secs + 's ago';
    if (secs < 3600)     return Math.floor(secs / 60) + 'm ago';
    if (secs < 86400)    return Math.floor(secs / 3600) + 'h ago';
    if (secs < 604800)   return Math.floor(secs / 86400) + 'd ago';
    if (secs < 2592000)  return Math.floor(secs / 604800) + 'w ago';
    // Older than ~30d: show absolute date
    const d = new Date(iso);
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getMonth()] + ' ' + d.getDate();
  }
  function tick() {
    document.querySelectorAll('.reltime[data-time]').forEach(el => {
      const t = relFmt(el.getAttribute('data-time'));
      if (t && el.textContent !== t) el.textContent = t;
    });
  }
  tick();
  setInterval(tick, 30 * 1000);        // every 30 seconds
  window.addEventListener('focus', tick); // and whenever the tab regains focus
})();
</script>

<!-- ─── Swift Confirm: pleasant custom confirmation dialog ─── -->
<div id="swiftConfirm" style="display:none; position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center;">
  <div id="swiftConfirmBackdrop" style="position:absolute; inset:0; background:rgba(32,39,89,0.45); opacity:0; transition:opacity 0.2s ease; backdrop-filter:blur(2px);"></div>
  <div id="swiftConfirmBox" role="dialog" aria-modal="true" style="position:relative; background:var(--white); border-radius:16px; max-width:400px; width:calc(100% - 40px); padding:26px 26px 22px; box-shadow:0 20px 60px rgba(32,39,89,0.28); transform:scale(0.9) translateY(10px); opacity:0; transition:transform 0.22s cubic-bezier(0.34,1.56,0.64,1), opacity 0.18s ease;">
    <div id="swiftConfirmIcon" style="width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; margin-bottom:16px; font-size:26px; background:var(--orange-50);">⚠️</div>
    <div id="swiftConfirmTitle" style="font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:17px; color:var(--navy-800); margin-bottom:6px;">Are you sure?</div>
    <div id="swiftConfirmText" style="font-size:13.5px; color:var(--muted); line-height:1.55; margin-bottom:22px;">This action cannot be undone.</div>
    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <button type="button" id="swiftConfirmCancel" style="border:1.5px solid var(--border); background:var(--white); color:var(--ink); padding:9px 18px; border-radius:10px; font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:13px; cursor:pointer; transition:background 0.12s;">Cancel</button>
      <button type="button" id="swiftConfirmOk" style="border:none; background:var(--accent); color:#093c5d; padding:9px 20px; border-radius:10px; font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:13px; cursor:pointer; transition:filter 0.12s;">Confirm</button>
    </div>
  </div>
</div>
<script>
(function(){
  const overlay = document.getElementById('swiftConfirm');
  const backdrop = document.getElementById('swiftConfirmBackdrop');
  const box = document.getElementById('swiftConfirmBox');
  const iconEl = document.getElementById('swiftConfirmIcon');
  const titleEl = document.getElementById('swiftConfirmTitle');
  const textEl = document.getElementById('swiftConfirmText');
  const okBtn = document.getElementById('swiftConfirmOk');
  const cancelBtn = document.getElementById('swiftConfirmCancel');
  let onConfirm = null;

  function open(){
    overlay.style.display = 'flex';
    requestAnimationFrame(() => {
      backdrop.style.opacity = '1';
      box.style.transform = 'scale(1) translateY(0)';
      box.style.opacity = '1';
    });
  }
  function close(){
    backdrop.style.opacity = '0';
    box.style.transform = 'scale(0.9) translateY(10px)';
    box.style.opacity = '0';
    setTimeout(() => { overlay.style.display = 'none'; onConfirm = null; }, 200);
  }

  // Public API: swiftConfirm({title, text, confirmLabel, tone, onConfirm})
  window.swiftConfirm = function(opts){
    opts = opts || {};
    titleEl.textContent = opts.title || 'Are you sure?';
    textEl.textContent  = opts.text  || 'This action cannot be undone.';
    okBtn.textContent   = opts.confirmLabel || 'Confirm';
    const tone = opts.tone || 'danger';
    if (tone === 'success') {
      iconEl.textContent = '✅'; iconEl.style.background = 'var(--green-50, #dcfce7)';
      okBtn.style.background = '#15803d';
    } else if (tone === 'info') {
      iconEl.textContent = 'ℹ️'; iconEl.style.background = 'var(--navy-50)';
      okBtn.style.background = 'var(--navy-700)';
    } else {
      iconEl.textContent = opts.icon || '⚠️'; iconEl.style.background = 'var(--orange-50)';
      okBtn.style.background = 'var(--orange-500)';
    }
    onConfirm = opts.onConfirm || null;
    // The word on the button ("Restore", "Archive", "Delete"…) is dark on a
    // light button and white on a dark one, in light and in dark mode alike.
    okBtn.style.color = '';
    const bg = (getComputedStyle(okBtn).backgroundColor.match(/[\d.]+/g) || ['255','255','255']).slice(0, 3)
      .map(Number).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    const L = 0.2126 * bg[0] + 0.7152 * bg[1] + 0.0722 * bg[2];
    okBtn.style.color = (1.05 / (L + 0.05)) >= ((L + 0.05) / (0.0232 + 0.05)) ? '#ffffff' : '#0f2b4a';
    open();
  };

  okBtn.addEventListener('click', () => { const cb = onConfirm; close(); if (cb) setTimeout(cb, 210); });
  cancelBtn.addEventListener('click', close);
  backdrop.addEventListener('click', close);
  document.addEventListener('keydown', (e) => {
    if (overlay.style.display === 'flex') {
      if (e.key === 'Escape') close();
      if (e.key === 'Enter') okBtn.click();
    }
  });


  // ─── Sign-out confirmation (used by every sidebar) ───
  window.confirmSignOut = function(e){
    if (e) e.preventDefault();
    swiftConfirm({
      title: 'Sign out?',
      text: 'You will need to sign in again to get back into ProcureEase.',
      confirmLabel: 'Sign out',
      tone: 'danger',
      onConfirm: function(){ window.location.href = 'logout.php'; }
    });
    return false;
  };

  // Auto-wire: any <form data-confirm="..."> or element with data-confirm submits via swiftConfirm
  document.addEventListener('submit', function(e){
    const form = e.target;
    if (form.dataset && form.dataset.confirm && !form.dataset.confirmed) {
      e.preventDefault();
      swiftConfirm({
        title: form.dataset.confirmTitle || 'Please confirm',
        text: form.dataset.confirm,
        confirmLabel: form.dataset.confirmLabel || 'Confirm',
        tone: form.dataset.confirmTone || 'danger',
        onConfirm: function(){ form.dataset.confirmed = '1'; form.submit(); }
      });
    }
  }, true);
})();
</script>
<?php if (($__prefs['high_contrast'] ?? '0') === '1'): ?>
<script>
/* High contrast (Settings > Accessibility). Every piece of writing is made
   pure black or pure white — whichever stands out more from what it is
   sitting on: the page, the side bar, a coloured button or tag, a sheet of
   paper — in light mode and in dark mode alike, and again whenever
   something changes under it (hovering, opening, switching the theme). */
(function(){
  var W = [0.2126, 0.7152, 0.0722];
  function rgba(s){ var m = s && s.match(/[\d.]+/g); return m ? [+m[0], +m[1], +m[2], m.length > 3 ? +m[3] : 1] : null; }
  function lum(c){ var t = 0; for (var i = 0; i < 3; i++) { var v = c[i] / 255; t += W[i] * (v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)); } return t; }
  function pass(root){
    var memo = new Map();
    function under(el){                      // the colour showing behind el's writing
      if (!el || el.nodeType !== 1) return [255, 255, 255];
      if (memo.has(el)) return memo.get(el);
      var b = rgba(getComputedStyle(el).backgroundColor), out;
      if (b && b[3] >= 0.999) out = b.slice(0, 3);
      else { var p = under(el.parentElement); out = (b && b[3] > 0) ? p.map(function(v, k){ return b[k] * b[3] + v * (1 - b[3]); }) : p; }
      memo.set(el, out); return out;
    }
    function worded(el){
      if (/^(SCRIPT|STYLE|OPTION|svg|path)$/.test(el.tagName)) return false;
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) return true;
      for (var n = el.firstChild; n; n = n.nextSibling) {
        if (n.nodeType === 3 && n.nodeValue.trim()) return true;
        if (n.nodeType === 1 && n.tagName.toLowerCase() === 'svg') return true;   // icons drawn in the text colour
      }
      return false;
    }
    var list = root.querySelectorAll ? root.querySelectorAll('*') : [];
    var all = [root]; for (var i = 0; i < list.length; i++) all.push(list[i]);
    all.forEach(function(el){
      if (el.nodeType !== 1 || !worded(el)) return;
      var c = lum(under(el)) > 0.179 ? '#000000' : '#ffffff';
      if (el.style.getPropertyValue('color') !== c || el.style.getPropertyPriority('color') !== 'important') el.style.setProperty('color', c, 'important');
    });
  }
  var timer = null;
  function soon(root, wait){ clearTimeout(timer); timer = setTimeout(function(){ pass(root || document.body); }, wait == null ? 120 : wait); }
  function start(){
    pass(document.body);
    setTimeout(function(){ pass(document.body); }, 450);        // after the page's own fade-ins
    new MutationObserver(function(){ soon(document.body); })
      .observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'open', 'hidden', 'data-theme']});
    var hov = null;
    function near(e){ var t = e.target; return (t && t.closest) ? (t.closest('a,button,summary,label,tr,li,.btn,[role="button"]') || t) : null; }
    ['mouseover', 'mouseout', 'focusin', 'focusout'].forEach(function(ev){
      document.addEventListener(ev, function(e){
        var el = near(e); if (!el) return;
        clearTimeout(hov); hov = setTimeout(function(){ pass(el.parentElement || el); }, 230);  // after the hover colour settles
      }, true);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
<?php endif; ?>
<script>
/* Wide tables scroll in a panel of their own without losing their width. */
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.main .card > table').forEach(function(t){
    var w = document.createElement('div'); w.className = 'tbl-scroll';
    t.parentNode.insertBefore(w, t); w.appendChild(t);
  });
});
</script>
<script>
/* Search fields and "Sort by date" (config/ui.php). */
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.search-field').forEach(function(f){
    var i = f.querySelector('input'), b = f.querySelector('.sf-clear');
    if (!i || !b) return;
    function sync(){ b.hidden = (i.value === ''); f.classList.toggle('has-value', i.value !== ''); }
    i.addEventListener('input', sync); sync();
    b.addEventListener('click', function(){
      // The page is showing results for what was searched: show everything again
      var showing = (i.defaultValue || '') !== '';
      i.value = ''; sync();
      i.dispatchEvent(new Event('input', {bubbles: true}));
      if (showing && i.form && (i.form.getAttribute('method') || 'get').toLowerCase() === 'get') { i.form.submit(); return; }
      i.focus();
    });
    i.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && i.value !== '') { e.preventDefault(); b.click(); }
    });
  });
  document.querySelectorAll('.sort-bar[data-sort-for]').forEach(function(bar){
    var sel = bar.querySelector('select'); if (!sel) return;
    var spec = bar.getAttribute('data-sort-for');
    var lists = /^[.#]/.test(spec) ? Array.prototype.slice.call(document.querySelectorAll(spec))
                                   : [document.getElementById(spec)].filter(Boolean);
    if (!lists.length) { bar.hidden = true; return; }
    lists.forEach(function(l){
      Array.prototype.forEach.call(l.children, function(c, n){ if (c.hasAttribute('data-ts')) c.setAttribute('data-i', n); });
    });
    var key = 'pe-sort:' + location.pathname + ':' + spec;
    try { var v = localStorage.getItem(key); if (v && sel.querySelector('option[value="' + v + '"]')) sel.value = v; } catch (e) {}
    function apply(){
      var mode = sel.value;
      lists.forEach(function(l){
        var items = Array.prototype.filter.call(l.children, function(c){ return c.hasAttribute('data-ts'); });
        items.sort(function(a, b){
          var ia = +a.getAttribute('data-i'), ib = +b.getAttribute('data-i');
          if (mode === 'default') return ia - ib;
          var x = +a.getAttribute('data-ts') || 0, y = +b.getAttribute('data-ts') || 0;
          if (!x !== !y) return x ? -1 : 1;          // never-submitted last
          return (mode === 'old' ? x - y : y - x) || (ia - ib);
        });
        items.forEach(function(c){ l.appendChild(c); });
      });
    }
    sel.addEventListener('change', function(){ try { localStorage.setItem(key, sel.value); } catch (e) {} apply(); });
    apply();
  });
});
</script>
<?php if (function_exists('current_user') && current_user()): ?>
<script>
/* The auto-save delay (config/app-settings.php): every editor waits this
   long after the last change before it saves on its own. */
window.PE_AUTOSAVE_MS = <?= (int)autosave_ms() ?>;

/* The session timeout (config/app-settings.php). The server signs a person out after
   that long without activity; here, a minute before, a notice offers to
   stay signed in, and working on the page (typing, clicking) keeps the
   session alive even before anything is saved. */
(function(){
  var LIMIT = <?= (int)session_timeout_min() ?> * 60000, WARN = Math.min(60000, LIMIT / 2);
  var lastServer = Date.now(), lastPing = Date.now(), bar = null;
  var _fetch = window.fetch;
  window.fetch = function(){ lastServer = Date.now(); return _fetch.apply(this, arguments); };
  function ping(){ lastPing = Date.now(); _fetch('api/keepalive.php', {credentials: 'same-origin'}).then(function(r){
      if (r.status === 401) { location.reload(); return; } lastServer = Date.now(); hide(); }).catch(function(){}); }
  function hide(){ if (bar) { bar.remove(); bar = null; } }
  ['keydown', 'mousedown', 'touchstart', 'input'].forEach(function(ev){
    document.addEventListener(ev, function(){ if (Date.now() - lastPing > 60000) ping(); }, {passive: true, capture: true});
  });
  setInterval(function(){
    var idle = Date.now() - lastServer;
    if (idle >= LIMIT) { location.reload(); return; }       // the server has signed out; show the sign-in page
    if (idle >= LIMIT - WARN && !bar) {
      bar = document.createElement('div');
      bar.id = 'sessionWarn'; bar.setAttribute('role', 'alert');
      bar.style.cssText = 'position:fixed; left:50%; bottom:22px; transform:translateX(-50%); z-index:10000; background:#0f2b4a; color:#fff; padding:12px 16px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.3); font-size:13px; display:flex; gap:12px; align-items:center; max-width:calc(100vw - 32px); flex-wrap:wrap;';
      bar.innerHTML = '<span>You will be signed out in a minute because nothing has been done for a while.</span>';
      var b = document.createElement('button'); b.type = 'button'; b.textContent = 'Stay signed in';
      b.style.cssText = 'background:#fff; color:#0f2b4a; border:none; border-radius:8px; padding:7px 12px; font-weight:700; cursor:pointer; font-family:inherit;';
      b.onclick = ping; bar.appendChild(b); document.body.appendChild(bar);
    }
  }, 5000);
})();
</script>
<?php endif; ?>
