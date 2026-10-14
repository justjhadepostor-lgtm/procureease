<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_admin();
ensure_admin_tables();

$u = current_user();
$activeNav = 'tracking';
$pageTitle = 'PPMP Tracking';
$pdo = db();

// Filters
$stageFilter  = $_GET['stage']  ?? 'all';   // all | draft | submitted | budget_approved | approved | revision
$groupFilter  = $_GET['group']  ?? 'all';   // all | Academic | Administrative
$fyFilter     = $_GET['fy']     ?? 'all';
$search       = trim($_GET['q'] ?? '');
$search        = preg_replace('/[0-9]/', '', $search); // search is characters only — no numbers

/* Load the PPMPs still moving through the workflow.

   Tracking covers the ACTIVE fiscal year onward. A PPMP from a year that has
   already ended belongs to the Archive, not here — the page is headed
   "FY <active>", and a finished year is no longer being tracked.

   Two conditions, so this is correct on every install:
     - the PPMP has not been archived, and
     - its fiscal year is not older than the active one, which also covers a
       system whose year was rolled over before archiving existed.
   Nothing is deleted: every one of these PPMPs is still open in the Archive. */
$trackFrom = active_fy();
$stmt = $pdo->prepare("
    SELECT p.id, p.dept_code, p.fy, p.status, p.submitted_at, p.submitted_by,
           p.budget_reviewed_at, p.budget_reviewed_by,
           p.admin_reviewed_at,  p.admin_reviewed_by,
           d.name AS dept_name, d.dept_group,
           (SELECT COUNT(*) FROM revision_requests r
             WHERE r.dept_code = p.dept_code AND r.fy = p.fy AND r.resolved = 0) AS has_revision,
           (SELECT COUNT(*) FROM ppmp_entries e WHERE e.ppmp_id = p.id) AS entry_count,
           (SELECT COALESCE(SUM(amount),0) FROM ppmp_entries e WHERE e.ppmp_id = p.id) AS total_amount
      FROM ppmps p
 LEFT JOIN departments d ON d.code = p.dept_code
     WHERE p.fy >= ?
       AND NOT EXISTS (SELECT 1 FROM archived_ppmps a WHERE a.ppmp_id = p.id)
  ORDER BY (p.submitted_at IS NOT NULL) DESC, p.submitted_at DESC, p.updated_at DESC");
$stmt->execute([$trackFrom]);
$rows = $stmt->fetchAll();

// v90: a department's draft is its own. One with the Budget Unit shows where it is, not what is in it.
$rows = array_values(array_filter($rows, fn($r) => ppmp_visible_to($r, $u) || $r['status'] === 'submitted'));
foreach ($rows as &$__r) $__r['__open'] = ppmp_visible_to($__r, $u);
unset($__r);

// How many are sitting in the Archive instead, so the page can say so
$archivedCount = 0;
try {
    $archivedCount = (int)$pdo->query('SELECT COUNT(*) FROM archived_ppmps')->fetchColumn();
} catch (Throwable $t) { $archivedCount = 0; }

// Compute location stage per PPMP
$computed = [];
foreach ($rows as $r) {
    if     ($r['has_revision'])                   $stage = -1;
    elseif ($r['status'] === 'draft')             $stage = 0;
    elseif ($r['status'] === 'submitted')         $stage = 1;
    elseif ($r['status'] === 'budget_approved')   $stage = 2;
    else                                          $stage = 3;
    // Same rule as the Annex, Market Scoping and Non-PS folders, from one
    // place — config/auth.php — so adding a unit on the Departments page
    // files it correctly here too, with no code change.
    $cat = dept_group_folder($r['dept_code'], $r['dept_group'] ?? null);
    $r['stage'] = $stage;
    $r['group'] = $cat;
    $computed[] = $r;
}

/* What the Budget Unit approved last — now waiting on the Procurement
   Unit — comes first, newest first. Everything else keeps the order it had
   (most recently submitted first). */
foreach ($computed as $__i => &$__r) { $__r['__i'] = $__i; $__r['__pu'] = ppmp_with_pu_since($__r); }
unset($__r);
usort($computed, function ($a, $b) {
    if (($a['__pu'] !== null) !== ($b['__pu'] !== null)) return $a['__pu'] !== null ? -1 : 1;
    if ($a['__pu'] !== null) {
        $c = strcmp((string)$b['__pu'], (string)$a['__pu']);
        if ($c !== 0) return $c;
    }
    return $a['__i'] <=> $b['__i'];
});

// Apply filters
$filtered = array_filter($computed, function($r) use ($stageFilter, $groupFilter, $fyFilter, $search) {
    if ($groupFilter !== 'all' && $r['group'] !== $groupFilter)              return false;
    if ($fyFilter    !== 'all' && (string)$r['fy'] !== $fyFilter)            return false;
    if ($stageFilter !== 'all') {
        $sm = ['draft'=>0,'submitted'=>1,'budget_approved'=>2,'approved'=>3,'revision'=>-1];
        if (!isset($sm[$stageFilter]) || $r['stage'] !== $sm[$stageFilter])  return false;
    }
    if ($search !== '') {
        $s = mb_strtolower($search);
        if (strpos(mb_strtolower($r['dept_code']), $s) === false
         && strpos(mb_strtolower($r['dept_name']), $s) === false
         && strpos(mb_strtolower($r['submitted_by'] ?? ''), $s) === false)       return false;
    }
    return true;
});

// Counts for filter chips.
// The stage chips show how many rows *would* land in each stage AFTER the
// current group/FY/search filters (but ignoring the current stage filter,
// since the chips themselves are used to change the stage).
$counts = ['all' => 0, 'revision' => 0, 'draft' => 0, 'submitted' => 0, 'budget_approved' => 0, 'approved' => 0];
foreach ($computed as $r) {
    if ($groupFilter !== 'all' && $r['group'] !== $groupFilter)   continue;
    if ($fyFilter    !== 'all' && (string)$r['fy'] !== $fyFilter) continue;
    if ($search !== '') {
        $s = mb_strtolower($search);
        if (strpos(mb_strtolower($r['dept_code']), $s) === false
         && strpos(mb_strtolower($r['dept_name']), $s) === false
         && strpos(mb_strtolower($r['submitted_by'] ?? ''), $s) === false) continue;
    }
    $counts['all']++;
    if     ($r['stage'] === -1) $counts['revision']++;
    elseif ($r['stage'] ===  0) $counts['draft']++;
    elseif ($r['stage'] ===  1) $counts['submitted']++;
    elseif ($r['stage'] ===  2) $counts['budget_approved']++;
    elseif ($r['stage'] ===  3) $counts['approved']++;
}

/* The four stops a PPMP passes through, named once.
   The badge on each card, the filter chips and the step tracker all read
   from here, so one stage can never be called three different things. */
const TRACK_STOPS = ['With the department', 'With Budget Unit',
                     'With Procurement Unit', 'Approved'];

$stageMeta = [
    -1 => ['label'=>'Sent back',            'color'=>'var(--orange-600)','bg'=>'var(--orange-50)','border'=>'var(--orange-100)'],
     /* #3b7597 measured 4.03:1 in light and 2.33:1 in dark — under the 4.5:1
        minimum in both. --navy-800 flips with the theme (#093c5d light,
        #e8f4fc dark) and clears 10:1 either way. */
     0 => ['label'=>TRACK_STOPS[0],         'color'=>'var(--navy-800)',  'bg'=>'var(--navy-50)',  'border'=>'var(--border)'],
     1 => ['label'=>TRACK_STOPS[1],         'color'=>'var(--navy-700)',  'bg'=>'var(--navy-100)', 'border'=>'var(--navy-500)'],
     2 => ['label'=>TRACK_STOPS[2],         'color'=>'var(--navy-700)',  'bg'=>'var(--navy-100)', 'border'=>'var(--navy-500)'],
     3 => ['label'=>TRACK_STOPS[3],         'color'=>'#14532d',          'bg'=>'var(--green-50)', 'border'=>'#86efac'],
];

$qbase = function(string $k, $v) use ($search, $fyFilter, $groupFilter, $stageFilter) {
    // Start from the current filter state
    $params = [
        'q'     => $search ?: null,
        'fy'    => $fyFilter !== 'all' ? $fyFilter : null,
        'group' => $groupFilter !== 'all' ? $groupFilter : null,
        'stage' => $stageFilter !== 'all' ? $stageFilter : null,
    ];
    // Override the one being changed (null clears it when 'all')
    $params[$k] = ($v !== 'all' && $v !== '') ? $v : null;
    return '?' . http_build_query(array_filter($params, fn($x) => $x !== null && $x !== ''));
};

// Available FYs for the dropdown
$fys = array_values(array_unique(array_map(fn($r) => (int)$r['fy'], $computed)));
rsort($fys);
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<div class="app">
  <?php require_once __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">PPMP Tracking · FY <?= active_fy() ?></h1>
        <p class="page-sub">Where each PPMP currently sits in the review workflow.</p></div></div>

    <!-- Stage filter chips -->
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px;">
      <?php
        // Same names as the badges below — $stageMeta is the single source.
        $chips = [
            'all'             => ['Everything',                $counts['all'],             '#121358',           'var(--navy-50)'],
            'submitted'       => [$stageMeta[1]['label'],      $counts['submitted'],       'var(--navy-700)',   'var(--navy-100)'],
            'budget_approved' => [$stageMeta[2]['label'],      $counts['budget_approved'], 'var(--navy-700)',   'var(--navy-100)'],
            'approved'        => [$stageMeta[3]['label'],      $counts['approved'],        '#14532d',           'var(--green-50)'],
            'revision'        => [$stageMeta[-1]['label'],     $counts['revision'],        'var(--orange-600)', 'var(--orange-50)'],
        ];
        foreach ($chips as $k => [$lbl, $cnt, $fg, $bg]):
          $active = $stageFilter === $k;
      ?>
        <a href="<?= $qbase('stage', $k) ?>" style="text-decoration: none; padding: 8px 14px; border-radius: 10px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 12px; border: 1.5px solid <?= $active ? $fg : 'var(--border)' ?>; background: <?= $active ? $fg : $bg ?>; color: <?= $active ? 'white' : $fg ?>; display: inline-flex; align-items: center; gap: 6px;">
          <?= e($lbl) ?>
          <span style="opacity: 0.75; font-weight: 600; font-size: 11px;"><?= $cnt ?></span></a>
      <?php endforeach; ?></div>

    <!-- Search + FY + group -->
    <form method="GET" class="filter-row" role="search" style="align-items: center; margin-bottom: 18px;">
      <?php if ($stageFilter !== 'all'): ?><input type="hidden" name="stage" value="<?= e($stageFilter) ?>"><?php endif; ?>
      <?= search_field(['name' => 'q', 'value' => $search, 'placeholder' => 'Search dept code, name, or submitter…',
                        'label' => 'Search PPMP tracking', 'attrs' => SEARCH_NO_DIGITS]) ?>
      <select name="group" class="filter-select" onchange="this.form.submit()">
        <option value="all"            <?= $groupFilter === 'all' ? 'selected' : '' ?>>All departments</option>
        <option value="Academic"       <?= $groupFilter === 'Academic' ? 'selected' : '' ?>>🎓 Academic only</option>
        <option value="Administrative" <?= $groupFilter === 'Administrative' ? 'selected' : '' ?>>🏢 Administrative only</option></select>
      </form>

    <?php if (!$filtered): ?>
      <div class="card" style="text-align: center; padding: 60px 24px; color: var(--muted);">
        <div style="font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px;">No PPMPs match these filters</div>
        <div style="font-size: 13.5px;">Try clearing the filters or picking a different stage.</div></div>
    <?php else: ?>
      <?= sort_bar('trackList', 'Waiting on you first') ?>
      <div id="trackList">
    <?php foreach ($filtered as $r):
      $sm  = $stageMeta[$r['stage']];
      $stg = $r['stage'];
    ?>
      <?php if ($r['__open']): ?>
      <a href="ppmp-file-view.php?id=<?= (int)$r['id'] ?>" data-ts="<?= sort_ts($r['submitted_at'] ?? null) ?>" style="text-decoration: none; color: inherit; display: block; margin-bottom: 12px;">
      <?php else: ?>
      <div data-ts="<?= sort_ts($r['submitted_at'] ?? null) ?>" style="display: block; margin-bottom: 12px;">
      <?php endif; ?>
        <div class="card" style="padding: 18px 22px; border-left: 4px solid <?= $sm['border'] ?>; transition: transform 0.15s ease, box-shadow 0.15s ease;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 14px rgba(41,54,129,0.08)'" onmouseout="this.style.transform=''; this.style.boxShadow=''">
          <!-- Header: dept + stage badge + amount -->
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 14px; margin-bottom: 16px;">
            <div style="flex: 1; min-width: 0;">
              <div style="font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <?= e(os_folder_of($r['dept_code'])) ?><?php $__sl = os_set_label($r['dept_code']); if ($__sl !== ''): ?> · <?= e($__sl) ?><?php endif; ?> · FY <?= (int)$r['fy'] ?>
                <span style="background: <?= $r['group'] === 'Academic' ? 'var(--navy-50)' : 'var(--orange-50)' ?>; color: <?= $r['group'] === 'Academic' ? 'var(--navy-700)' : 'var(--orange-600)' ?>; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">
                  <?= e($r['group']) ?></span></div>
              <div style="font-size: 12.5px; color: var(--muted); margin-top: 3px;">
                <?= e($r['dept_name']) ?>
                <?php if ($r['submitted_by']): ?>
                  &nbsp;·&nbsp; Submitted by <?= e($r['submitted_by']) ?>
                <?php endif; ?>
                <?php if ($r['__open']): ?>
                &nbsp;·&nbsp; <?= (int)$r['entry_count'] ?> entries
                &nbsp;·&nbsp; ₱<?= number_format((float)$r['total_amount'], 2) ?>
                <?php endif; ?></div></div>
            <span style="background: <?= $sm['bg'] ?>; color: <?= $sm['color'] ?>; padding: 6px 14px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; font-family: 'Space Grotesk', sans-serif; border: 1.5px solid <?= $sm['border'] ?>; white-space: nowrap;">
              <?= e($sm['label']) ?></span></div>

          <!-- Mini step tracker: circles + straight lines -->
          <div style="position: relative; padding: 0 12px;">
            <div style="position: absolute; left: calc(12px + 16px); right: calc(12px + 16px); top: 15px; height: 3px; background: var(--navy-100); border-radius: 3px;"></div>
            <?php
              $doneStages = max(0, $stg);
              $pct = min(100, ($doneStages / 3) * 100);
            ?>
            <div style="position: absolute; left: calc(12px + 16px); top: 15px; height: 3px; width: calc((100% - (24px + 32px)) * <?= $pct/100 ?>); background: #15803d; border-radius: 3px;"></div>

            <div style="display: grid; grid-template-columns: repeat(4, 1fr); position: relative;">
              <?php
                /* The stop names come from $stageMeta, so the tracker cannot
                   drift away from the badge above it. The second caption line
                   is gone: it said "Reviewing" under every middle step whether
                   or not that step had finished, which was simply untrue on an
                   approved PPMP. A finished step is a tick — including the last
                   one, which used to keep showing "4" after final approval. */
                $isRev  = ($stg === -1);
                $lastIx = count(TRACK_STOPS) - 1;
                foreach (TRACK_STOPS as $i => $lb):
                  // "Approved" is a finished stop, not one still in progress —
                  // so a fully approved PPMP ends on a tick, not on a number.
                  $done   = ($stg > $i) || ($stg === $lastIx && $i === $lastIx);
                  $active = !$done && (($stg === $i) || ($isRev && $i === 0));

                  if ($done)                 { $bg = '#15803d'; $bd = '#15803d'; $glyph = '✓'; }
                  // The accent, not the tint: --orange-500 is pale in dark mode
                  // and on a document page, so a white glyph vanished on it.
                  // --accent and --accent-ink flip together.
                  elseif ($active && $isRev) { $bg = 'var(--accent)'; $bd = 'var(--accent)'; $glyph = '!'; }
                  elseif ($active)           { $bg = 'var(--accent)'; $bd = 'var(--accent)'; $glyph = (string)($i + 1); }
                  else                       { $bg = 'var(--white)'; $bd = 'var(--border)'; $glyph = (string)($i + 1); }
                  // Theme tokens, so the names read in dark mode too — they
                  // used to be dark green on the dark navy card.
                  $col = ($done || $active) ? 'var(--ink)' : 'var(--muted)';
              ?>
                <div style="display: flex; flex-direction: column; align-items: center; position: relative; z-index: 1;">
                  <div style="width: 32px; height: 32px; border-radius: 50%; background: <?= $bg ?>; border: 2.5px solid <?= $bd ?>; display: flex; align-items: center; justify-content: center; color: <?= $done ? '#ffffff' : ($active ? 'var(--accent-ink)' : 'var(--muted)') ?>; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 12px;">
                    <?= $glyph ?></div>
                  <div style="margin-top: 6px; text-align: center; font-family: 'Space Grotesk', sans-serif; font-size: 10.5px; font-weight: <?= $done || $active ? '700' : '600' ?>; color: <?= $col ?>;">
                    <?= e($lb) ?></div></div>
              <?php endforeach; ?></div></div></div><?= $r['__open'] ? '</a>' : '</div>' ?>
    <?php endforeach; ?></div>
    <?php endif; ?></main></div></body></html>
