<?php
/* ═══════════════════════════════════════════════════════════════
   v138: Guide — video tutorials for filling out the PPMP, the APP-CSE
   and the Market Scoping forms, for every role (sidebar › Guide).
   The videos play here (YouTube, privacy-enhanced); "Open on YouTube"
   opens the same video in a new tab.
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_login();
$u = current_user();
$activeNav = 'guide';
$pageTitle = 'Guide';
$role = $u['role'] ?? 'user';

/* The tutorials: [title, what it covers, YouTube video id] */
$GUIDE_VIDEOS = [
    ['PPMP', 'How to fill out the Project Procurement Management Plan (PPMP).', 'EI4mP7eoUF0'],
    ['APP-CSE', 'How to fill out the Annual Procurement Plan for Common-Use Supplies and Equipment (APP-CSE).', 'iE_7aRMhyOM'],
    ['Market Scoping', 'How to fill out the Market Scoping Checklist.', 'xDNTRuYIENU'],
];

/* v139: slide guides — [label, what it covers, image prefix in assets/guide/].
   The slides are the images assets/guide/<prefix>-01.jpg, -02.jpg, … in order;
   they are part of the system, so they show without an internet connection. */
$GUIDE_SLIDES = [
    ['Annex', 'Step-by-step slides on filling out the Annex.', 'annex'],
    ['Non-PS', 'Step-by-step slides on filling out the List of Non-PS Supplies and Materials.', 'nonps'],
];
$guideSlideFiles = function (string $prefix): array {
    $files = glob(__DIR__ . '/assets/guide/' . $prefix . '-[0-9][0-9].jpg') ?: [];
    sort($files, SORT_STRING);
    return array_map(fn($f) => 'assets/guide/' . basename($f) . '?v=' . filemtime($f), $files);
};
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<style>
  .guide-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 18px; }
  .guide-card { padding: 18px; display: flex; flex-direction: column; gap: 12px; }
  .guide-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 17px; font-weight: 700; color: var(--ink); margin: 0; }
  .guide-card p { font-size: 13px; color: var(--muted); line-height: 1.55; margin: 0; }
  .guide-video { position: relative; width: 100%; aspect-ratio: 16 / 9; border-radius: 12px; overflow: hidden; background: #0b1b2e; }
  .guide-video iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
  .guide-link { align-self: flex-start; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600;
                color: var(--navy-700); text-decoration: none; }
  .guide-link:hover { text-decoration: underline; }
  body[data-theme="dark"] .guide-link { color: #9fd0ff; }
  .guide-note { font-size: 12px; color: var(--muted); margin-top: 16px; }
  @media (max-width: 420px) { .guide-list { grid-template-columns: 1fr; } }
  /* v139: slide viewer (Annex, Non-PS) — one per row, so the slides are big enough to read */
  .guide-slide-list { grid-template-columns: 1fr; max-width: 1100px; margin-top: 18px; }
  .guide-slides { position: relative; width: 100%; aspect-ratio: 16 / 9; border-radius: 12px; overflow: hidden;
                  background: #0b1b2e; cursor: pointer; outline: none; }
  .guide-slides:focus-visible { box-shadow: 0 0 0 3px var(--navy-500); }
  .guide-slides img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; display: block; }
  .guide-slides:fullscreen { border-radius: 0; cursor: default; }
  .guide-slide-nav { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .guide-slide-nav .btn { padding: 7px 12px; font-size: 13px; }
  .guide-slide-nav .btn:disabled { opacity: .45; cursor: default; }
  .guide-slide-count { font-size: 13px; font-weight: 600; color: var(--muted); min-width: 52px; text-align: center; }
  .guide-slide-nav .guide-full { margin-left: auto; }
</style>
<div class="app">
<?php
  if ($role === 'admin')       require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($role === 'budget')  require __DIR__ . '/includes/sidebar-budget.php';
  else                         require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Guide</h1>
        <p class="page-sub">Video tutorials on filling out the PPMP, the APP-CSE and the Market Scoping forms, and slides on the Annex and the Non-PS List.</p></div></div>

    <div class="guide-list" id="guideList">
      <?php foreach ($GUIDE_VIDEOS as [$title, $about, $vid]): ?>
        <div class="card guide-card" id="guide-<?= e(strtolower(str_replace(' ', '-', $title))) ?>">
          <h2><?= e($title) ?></h2>
          <p><?= e($about) ?></p>
          <div class="guide-video">
            <iframe src="https://www.youtube-nocookie.com/embed/<?= e($vid) ?>?rel=0" title="<?= e($title) ?> tutorial" loading="lazy"
                    allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                    referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>
          <a class="guide-link" href="https://youtu.be/<?= e($vid) ?>" target="_blank" rel="noopener">▶ Open on YouTube</a>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="guide-list guide-slide-list" id="guideSlides">
      <?php foreach ($GUIDE_SLIDES as [$title, $about, $prefix]):
              $slides = $guideSlideFiles($prefix);
              if (!$slides) continue;
              $n = count($slides); ?>
        <div class="card guide-card" id="guide-<?= e($prefix) ?>">
          <h2><?= e($title) ?></h2>
          <p><?= e($about) ?></p>
          <div class="guide-slides" tabindex="0" role="region" aria-label="<?= e($title) ?> slides"
               data-slides="<?= e(json_encode($slides)) ?>" data-title="<?= e($title) ?>">
            <img src="<?= e($slides[0]) ?>" alt="<?= e($title) ?> slide 1 of <?= $n ?>"></div>
          <div class="guide-slide-nav">
            <button type="button" class="btn btn-ghost guide-prev" disabled>‹ Previous</button>
            <span class="guide-slide-count" aria-live="polite">1 / <?= $n ?></span>
            <button type="button" class="btn btn-ghost guide-next"<?= $n < 2 ? ' disabled' : '' ?>>Next ›</button>
            <button type="button" class="btn btn-ghost guide-full">⛶ Full screen</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="guide-note">The videos need an internet connection. If one does not play here, use “Open on YouTube”.</p>
  </main>
</div>
<script>
/* v139: slide viewer — Previous / Next, click the slide for the next one,
   ← → keys while the slide is focused or full screen. */
document.querySelectorAll('.guide-card .guide-slides').forEach(function (box) {
  var card = box.closest('.guide-card');
  var slides = JSON.parse(box.getAttribute('data-slides') || '[]');
  var title = box.getAttribute('data-title') || '';
  var img = box.querySelector('img');
  var prev = card.querySelector('.guide-prev'), next = card.querySelector('.guide-next');
  var count = card.querySelector('.guide-slide-count'), full = card.querySelector('.guide-full');
  var i = 0;
  function show(k) {
    if (k < 0 || k >= slides.length) return;
    i = k;
    img.src = slides[i];
    img.alt = title + ' slide ' + (i + 1) + ' of ' + slides.length;
    count.textContent = (i + 1) + ' / ' + slides.length;
    prev.disabled = i === 0;
    next.disabled = i === slides.length - 1;
    if (slides[i + 1]) (new Image()).src = slides[i + 1];
  }
  prev.addEventListener('click', function () { show(i - 1); });
  next.addEventListener('click', function () { show(i + 1); });
  box.addEventListener('click', function () { show(i + 1); });
  box.addEventListener('keydown', function (ev) {
    if (ev.key === 'ArrowRight' || ev.key === 'PageDown') { ev.preventDefault(); show(i + 1); }
    else if (ev.key === 'ArrowLeft' || ev.key === 'PageUp') { ev.preventDefault(); show(i - 1); }
  });
  if (!box.requestFullscreen) full.hidden = true;
  full.addEventListener('click', function () {
    if (document.fullscreenElement) document.exitFullscreen();
    else box.requestFullscreen().then(function () { box.focus(); }).catch(function () {});
  });
  show(0);
});
</script>
</body>
</html>
