<?php
/* ═══════════════════════════════════════════════════════════════
   My Department — where our submissions are.

   Everyone in a department prepares and saves; the Department Admin is the
   one who submits, so they are the one who needs to see where the work has
   reached. This page follows that same rule: whoever may submit for the
   department may track it. A department that has no Department Admin yet
   falls back to everyone, exactly as submitting does.

   It covers the department's own documents only — the PPMP with its APP-CSE,
   the Non-PS list, the Annex and the Market Scoping.
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/appcse-items.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/appcse-review.php';
require_user_role();
ensure_admin_tables();

$u   = current_user();
$pdo = db();

if (!can_submit_for_dept($u)) {
    $head = dept_submitter_name($u);
    flash('error', $head !== ''
        ? "Only your Department Admin ($head) can track the department's submissions."
        : "Only your Department Admin can track the department's submissions.");
    header('Location: ' . (($u['role'] ?? '') === 'budget' ? 'budget-home.php' : 'user-dashboard.php'));
    exit;
}

$dept     = (string)($u['dept_code'] ?? '');
$deptName = (string)($u['dept_name'] ?? $dept);
$fy       = (int)($_GET['fy'] ?? active_fy());

$activeNav = 'dept-tracking';
$pageTitle = 'My Department';

/* ─── Where each stage sits, in the order the work travels ──── */
$STAGES = [
    'draft'           => ['With us',            'Still being prepared here',            'var(--muted)',        'var(--navy-50)'],
    'revision'        => ['Sent back',          'Returned for changes',                 'var(--orange-600)',   'var(--orange-50)'],
    'submitted'       => ['With Budget',        'Waiting on the Budget Unit',         'var(--navy-700)',     'var(--navy-50)'],
    'budget_approved' => ['With Procurement',   'Budget approved; awaiting final',      '#b45309',             '#fef3c7'],
    'approved'        => ['Approved',           'Final approval granted',               '#15803d',             'var(--green-50)'],
];

function stage_of(string $status, bool $hasRevision): string {
    if ($hasRevision) return 'revision';
    return in_array($status, ['draft', 'submitted', 'budget_approved', 'approved'], true)
        ? $status : 'draft';
}

/* ─── The department's PPMP, and the APP-CSE that travels with it ─── */
$ppmp = null;
try {
    $s = $pdo->prepare("SELECT p.*,
                              (SELECT COUNT(*) FROM revision_requests r
                                WHERE r.dept_code = p.dept_code AND r.fy = p.fy AND r.resolved = 0) AS has_revision,
                              (SELECT COUNT(*) FROM ppmp_entries e WHERE e.ppmp_id = p.id) AS entry_count,
                              (SELECT COALESCE(SUM(amount),0) FROM ppmp_entries e WHERE e.ppmp_id = p.id) AS total_amount
                         FROM ppmps p WHERE p.dept_code = ? AND p.fy = ?");
    $s->execute([$dept, $fy]);
    $ppmp = $s->fetch() ?: null;
} catch (Throwable $t) {}

/* Archived documents are not shown here — they are in the Archive. Count
   them so the page can say so. */
$__inArchive = 0;
if ($ppmp && arc_ppmp_is_archived((int)$ppmp['id'])) { $ppmp = null; $__inArchive++; }
$__appcseArchived = arc_appcse_is_archived($fy, $dept);
if ($__appcseArchived) $__inArchive++;

$appcseLines = 0;
$appcseValue = 0.0;
try {
    $months = implode(' + ', array_map(fn($m) => "`$m`", APPCSE_MONTHS));
    if ($months === '') $months = '0';
    $s = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(($months) * unit_price), 0) AS v
                          FROM appcse_items
                         WHERE dept_code = ? AND fy = ? AND ($months) > 0");
    $s->execute([$dept, $fy]);
    if (!$__appcseArchived && ($r = $s->fetch())) { $appcseLines = (int)$r['n']; $appcseValue = (float)$r['v']; }
} catch (Throwable $t) {}
// v80: the APP-CSE's amount is its Grand Total with the 10% (D of Part I + Part II), as everywhere else
if ($appcseLines > 0) {
    require_once __DIR__ . '/config/price-provision.php';
    $appcseValue = (float)appcse_summary(appcse_part_totals((int)$fy, (string)$dept), appcse_transport((int)$fy, (string)$dept), (int)$fy)['d'];
}

/* ─── The other documents ───────────────────────────────────── */
$others = [];
try {
    $s = $pdo->prepare('SELECT id, status, submitted_at, reviewed_at, archived FROM nonps_submissions WHERE dept_code = ? AND fy = ?');
    $s->execute([$dept, $fy]);
    foreach ($s->fetchAll() as $r) {
        if ((int)($r['archived'] ?? 0) === 1) { $__inArchive++; continue; }
        $others[] = ['label' => 'Non-PS Supplies', 'status' => (string)$r['status'],
                     'when' => $r['submitted_at'] ?? null, 'link' => 'nonps-list.php'];
    }
} catch (Throwable $t) {}
try {
    $s = $pdo->prepare('SELECT id, annex, status, submitted_at, archived FROM annex_forms WHERE dept_code = ? AND fy = ?');
    $s->execute([$dept, $fy]);
    foreach ($s->fetchAll() as $r) {
        if ((int)($r['archived'] ?? 0) === 1) { $__inArchive++; continue; }
        $others[] = ['label' => 'Annex ' . ($r['annex'] ?? ''), 'status' => (string)$r['status'],
                     'when' => $r['submitted_at'] ?? null, 'link' => 'annex-edit.php?id=' . (int)$r['id']];
    }
} catch (Throwable $t) {}
try {
    $s = $pdo->prepare('SELECT id, status, submitted_at, archived FROM market_scoping WHERE dept_code = ? AND fy = ?');
    $s->execute([$dept, $fy]);
    foreach ($s->fetchAll() as $r) {
        if ((int)($r['archived'] ?? 0) === 1) { $__inArchive++; continue; }
        $others[] = ['label' => 'Market Scoping', 'status' => (string)$r['status'],
                     'when' => $r['submitted_at'] ?? null, 'link' => 'market-scoping-edit.php?id=' . (int)$r['id']];
    }
} catch (Throwable $t) {}

$ppmpStage = $ppmp ? stage_of((string)$ppmp['status'], (int)$ppmp['has_revision'] > 0) : null;
$fyOptions = range(active_fy() - 2, active_fy() + 1);
require __DIR__ . '/includes/head.php';
?>
<style>
  .trail { display:flex; gap:0; margin:18px 0 6px; flex-wrap:wrap; }
  .trail-step { flex:1; min-width:140px; position:relative; padding:14px 14px 14px 20px;
    background:var(--white); border:1px solid var(--border); border-right:none; }
  .trail-step:first-child { border-radius:12px 0 0 12px; }
  .trail-step:last-child  { border-radius:0 12px 12px 0; border-right:1px solid var(--border); }
  .trail-step.done  { background:var(--green-50); border-color:#86efac; }
  /* Dark mode never redefines --green-50, so it stays near-white while the
     text token flips to white — 1.05:1, effectively invisible. The finished
     steps get a dark green surface of their own instead: 9.1:1 for the step
     name, 7.1:1 for the line under it. */
  body[data-theme="dark"] .trail-step.done { background:#14532d; border-color:#15803d; }
  body[data-theme="dark"] .trail-step.done .trail-name { color:#ffffff; }
  body[data-theme="dark"] .trail-step.done .trail-sub  { color:#cdeccd; }
  .trail-step.here  { background:var(--navy-50); border-color:var(--navy-500); box-shadow:inset 0 0 0 1px var(--navy-500); }
  .trail-name { font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:12.5px; color:var(--ink); }
  .trail-sub  { font-size:11px; color:var(--muted); margin-top:3px; line-height:1.4; }
  .trail-step.here .trail-name { color:var(--navy-800); }
  .doc-row { display:flex; align-items:center; gap:12px; padding:12px 15px; border:1px solid var(--border);
    border-radius:10px; background:var(--white); margin-bottom:8px; }
  .doc-row:hover { border-color:var(--navy-100); background:var(--navy-50); }
  .doc-main { flex:1; min-width:0; }
  .doc-name { font-size:13.5px; font-weight:600; color:var(--ink); }
  .doc-meta { font-size:11.5px; color:var(--muted); margin-top:2px; }
  .pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:10.5px; font-weight:700; white-space:nowrap; }
  /* the APP-CSE's own state (v77): readable in dark mode too */
  body[data-theme="dark"] .pill.ac-approved { background:#123222 !important; color:#c9f2d7 !important; }
</style>

<div class="app">
<?php
  // Budget tracks its own department here like any other unit, so it keeps
  // its own sidebar while doing so. It still sees only its own department:
  // every query on this page is filtered by the signed-in user's dept_code.
  if (($u['role'] ?? '') === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
  else                                 require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">My Department · FY <?= $fy ?></h1>
        <?php require_once __DIR__ . '/config/academic-units.php'; $__sc = (string)($u['school_code'] ?? ''); ?>
        <p class="page-sub"><?= au_has_units($__sc) ? e(($u['school_name'] ?? $__sc) . ' (' . $__sc . ')') : e($deptName) ?></p>
        <?php require_once __DIR__ . '/includes/unit-switch.php'; echo unit_pick_html($u);   /* v87: which program / major or set */ ?>
      </div>
      <form method="GET">
        <select name="fy" class="filter-select" onchange="this.form.submit()">
          <?php foreach ($fyOptions as $y): ?>
            <option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>>FY <?= $y ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php render_flashes(); ?>

    <?php /* v93: the department's allotment, what its PPMPs use, and what is left */
      require_once __DIR__ . '/config/academic-units.php';
      require_once __DIR__ . '/config/allot-shares.php';
      require_once __DIR__ . '/config/funds.php';
      $__top   = au_school_of($dept);
      $__codes = au_has_units($__top) ? array_keys(au_units_of($__top)) : os_family($__top);
      if (!$__codes) $__codes = [$__top];
      $__allot = allot_school($__top, (int)$fy);
      $__used  = round(array_sum(allot_ppmp_totals($__codes, (int)$fy)), 2);
      $__left  = $__allot === null ? null : round($__allot - $__used, 2);
      $__fund  = '';
      try {
          $__fs = $pdo->prepare('SELECT * FROM dept_budgets WHERE dept_code = ? AND fy = ?');
          $__fs->execute([$__top, (int)$fy]);
          $__fund = trim((string)(($__fs->fetch() ?: [])['fund_source'] ?? ''));
      } catch (Throwable $t) {}
      if ($__fund === '' && $__allot !== null) $__fund = 'GAA';
      $__many = count($__codes) > 1;
      $__noun = au_has_units($__top) ? 'programs/majors' : 'sets';
    ?>
    <div class="stats" id="deptBalance" style="margin-bottom: 22px;">
      <div class="stat">
        <div class="stat-label">Allotted budget</div>
        <div class="stat-value" style="color: var(--navy-700);"><?= $__allot === null ? 'Not set yet' : '₱' . number_format($__allot, 2) ?></div>
        <div class="stat-meta"><?= $__allot === null ? 'The Budget Unit has not set the FY ' . (int)$fy . ' allotment yet' : 'FY ' . (int)$fy . ' · Source of fund: ' . e(fund_label($__fund)) ?></div></div>
      <div class="stat">
        <div class="stat-label">PPMP Total Amount</div>
        <div class="stat-value">₱<?= number_format($__used, 2) ?></div>
        <div class="stat-meta"><?= $__many ? 'All ' . count($__codes) . ' ' . e($__noun) . ' together' : 'Our PPMP' ?></div></div>
      <div class="stat">
        <div class="stat-label"><?= $__left !== null && $__left < 0 ? 'Over by' : 'Remaining balance' ?></div>
        <div class="stat-value" id="deptRemaining" style="color: <?= $__left === null ? 'var(--muted)' : ($__left < 0 ? 'var(--orange-600)' : 'var(--green-500)') ?>;"><?= $__left === null ? '—' : '₱' . number_format(abs($__left), 2) ?></div>
        <div class="stat-meta"><?= $__left === null ? 'Shown once the allotment is set' : 'Allotted budget less the PPMP Total Amount' ?></div></div></div>

    <?php // v81: a school with programs and majors — the Department Admin's overview of all of them
      require_once __DIR__ . '/config/academic-units.php';
      $__isSchool = au_has_units((string)($u['school_code'] ?? ''));
      if ($__isSchool) require __DIR__ . '/includes/school-overview.php'; ?>
    <?php if ($__isSchool): ?>
    <div class="section-head" style="margin-top:6px;">
      <div><h2 class="section-title">Where it has reached: <?= e(au_label($dept)) ?></h2>
</div>
    </div>
    <?php endif; ?>

    <div class="section-head">
      <div><h2 class="section-title">PPMP &amp; APP-CSE</h2>
</div>
    </div>

    <?php if (!$ppmp): ?>
      <div class="card" style="text-align:center; padding:48px 24px; color:var(--muted);">
        <div style="font-size:15px; font-weight:600; color:var(--ink); margin-bottom:5px;">No PPMP for FY <?= $fy ?> yet</div>
        <div style="font-size:13px;">Once one is prepared it will be tracked here.</div>
      </div>
    <?php else: ?>
      <?php
        $order = ['draft', 'submitted', 'budget_approved', 'approved'];
        $hereIx = $ppmpStage === 'revision' ? 0 : array_search($ppmpStage, $order, true);
      ?>
      <div class="trail">
        <?php foreach ($order as $ix => $st):
          $meta = $STAGES[$st];
          $cls  = $ix < $hereIx ? 'done' : ($ix === $hereIx ? 'here' : '');
        ?>
          <div class="trail-step <?= $cls ?>">
            <div class="trail-name"><?= e($meta[0]) ?></div>
            <div class="trail-sub"><?= e($meta[1]) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($ppmpStage === 'revision'): ?>
        <div style="padding:12px 16px; margin:10px 0 16px; background:var(--orange-50);
                    border:1px solid var(--orange-100); border-radius:10px; font-size:12.5px; color:var(--orange-600);">
          <strong>Sent back for revision.</strong> Open the PPMP, make the changes asked for, then submit it again.
        </div>
      <?php endif; ?>

      <div class="doc-row" style="margin-top:14px;">
        <div class="doc-main">
          <div class="doc-name">PPMP</div>
          <div class="doc-meta">
            <?= (int)$ppmp['entry_count'] ?> entr<?= (int)$ppmp['entry_count'] === 1 ? 'y' : 'ies' ?>
            &middot; &#8369;<?= number_format((float)$ppmp['total_amount'], 2) ?>
            <?php if (!empty($ppmp['submitted_at'])): ?>
              &middot; submitted <?= e(date('j M Y', strtotime($ppmp['submitted_at']))) ?>
              <?= !empty($ppmp['submitted_by']) ? 'by ' . e($ppmp['submitted_by']) : '' ?>
            <?php endif; ?>
          </div>
        </div>
        <span class="pill" style="background:<?= $STAGES[$ppmpStage][3] ?>; color:<?= $STAGES[$ppmpStage][2] ?>;">
          <?= e($STAGES[$ppmpStage][0]) ?>
        </span>
        <a class="btn btn-ghost" style="padding:6px 14px; font-size:12px;" href="ppmp-edit.php?fy=<?= $fy ?>">Open</a>
      </div>

      <div class="doc-row">
        <div class="doc-main">
          <div class="doc-name">APP-CSE</div>
          <div class="doc-meta">
            <?php if ($appcseLines > 0): ?>
              <?= $appcseLines ?> item line<?= $appcseLines === 1 ? '' : 's' ?>
              &middot; <span title="<?= function_exists('doc_fmt') ? e(doc_fmt('appcse', 'lbl_grand_d', (int)$fy)) : 'Grand Total' ?>">&#8369;<?= number_format($appcseValue, 2) ?></span>
              <?php $__acInfo = appcse_info((int)$fy, (string)$dept); $__acSt = $__acInfo['status']; ?>
              <?php if (in_array($__acSt, ['submitted', 'approved'], true) && !empty($__acInfo['submitted_at'])): /* v78: when it was sent, like the PPMP above */ ?>
                &middot; submitted <?= e(date('j M Y', strtotime($__acInfo['submitted_at']))) ?>
                <?= $__acInfo['submitted_by'] !== '' ? 'by ' . e($__acInfo['submitted_by']) : '' ?>
              <?php endif; ?>
              <?= $__acSt === 'approved' ? '&middot; counted in the consolidated APP-CSE' : ($__acSt === 'submitted' ? '' : '&middot; reviewed by Procurement') ?>
            <?php else: ?>
              No quantities entered yet — the PPMP cannot be submitted until there are some
            <?php endif; ?>
          </div>
        </div>
        <?php /* v77: the APP-CSE's own state (config/appcse-review.php) — it goes to the Procurement Unit */
              $__acStage = appcse_status((int)$fy, (string)$dept);
              $__acPill = ['draft'     => ['With us', 'var(--muted)', 'var(--navy-50)'],
                           'submitted' => ['With Procurement', '#b45309', '#fef3c7'],
                           'revision'  => ['Sent back', 'var(--orange-600)', 'var(--orange-50)'],
                           'approved'  => ['Approved', '#15803d', 'var(--green-50)']][$__acStage]; ?>
        <span class="pill ac-<?= e($__acStage) ?>" style="background:<?= $__acPill[2] ?>; color:<?= $__acPill[1] ?>;">
          <?= e($__acPill[0]) ?>
        </span>
        <a class="btn btn-ghost" style="padding:6px 14px; font-size:12px;" href="appcse-edit.php">Open</a>
      </div>
    <?php endif; ?>

    <div class="section-head" style="margin-top:26px;">
      <div><h2 class="section-title">Our other documents</h2></div>
    </div>

    <?php if (!$others): ?>
      <div class="card" style="text-align:center; padding:40px 24px; color:var(--muted); font-size:13px;">
        Nothing else prepared for FY <?= $fy ?> yet.
      </div>
    <?php else: ?>
      <?php foreach ($others as $d):
        $st = (string)$d['status'];
        $map = ['draft' => ['Draft', 'var(--muted)', 'var(--navy-50)'],
                'submitted' => ['With Procurement', 'var(--navy-700)', 'var(--navy-50)'],
                'revision' => ['Sent back', 'var(--orange-600)', 'var(--orange-50)'],
                'approved' => ['Approved', '#15803d', 'var(--green-50)']];
        $m = $map[$st] ?? [ucfirst($st), 'var(--muted)', 'var(--navy-50)'];
      ?>
        <div class="doc-row">
          <div class="doc-main">
            <div class="doc-name"><?= e($d['label']) ?></div>
            <div class="doc-meta">
              <?= $d['when'] ? 'Submitted ' . e(date('j M Y', strtotime($d['when']))) : 'Not submitted yet' ?>
            </div>
          </div>
          <span class="pill" style="background:<?= $m[2] ?>; color:<?= $m[1] ?>;"><?= e($m[0]) ?></span>
          <a class="btn btn-ghost" style="padding:6px 14px; font-size:12px;" href="<?= e($d['link']) ?>">Open</a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($__inArchive > 0): ?>
      <div class="doc-row" id="inArchive" style="margin-top:14px; background:var(--navy-50);">
        <div class="doc-main">
          <div class="doc-name">&#128451; <?= $__inArchive ?> FY <?= $fy ?> document<?= $__inArchive === 1 ? ' is' : 's are' ?> in the Archive</div>
          <div class="doc-meta">Archived documents are not listed here. Open or restore them from the Archive.</div>
        </div>
        <a class="btn btn-ghost" style="padding:6px 14px; font-size:12px;" href="archive.php">Open Archive</a>
      </div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
