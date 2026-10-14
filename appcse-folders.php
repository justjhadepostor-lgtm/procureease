<?php
/* ═══════════════════════════════════════════════════════════════
   APP-CSE Folders — the Procurement Unit's (v77).

   The APP-CSE no longer goes through the Budget Unit: it comes straight
   to the Procurement Unit, with the rest of the set. Laid out like PPMP
   Folders and the Annex: two folders (Administrative, Academic), what is
   waiting for review at the top, then a folder for every department.
   Opening a department shows its APP-CSE; Open takes it to the review page
   (appcse-review.php), where it is approved or sent back for revision.
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/appcse-review.php';
require_once __DIR__ . '/config/price-provision.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/ui.php';
require_admin();
ensure_admin_tables();

$u    = current_user();
$pdo  = db();
$fy   = active_fy();
$activeNav = 'appcse-folders';
$pageTitle = 'APP-CSE Folders';

$openGroup = $_GET['group'] ?? '';
if (!in_array($openGroup, ['Academic', 'Administrative'], true)) $openGroup = '';
$openDept     = trim((string)($_GET['dept'] ?? ''));
$statusFilter = ($_GET['status'] ?? '') === 'revision' ? 'revision' : 'all';
$search       = preg_replace('/[0-9]/', '', trim((string)($_GET['q'] ?? '')));

$departments = [];
try {
    $departments = $pdo->query("SELECT code, name, dept_group, display_order FROM departments ORDER BY display_order, name")->fetchAll();
} catch (Throwable $t) {}
require_once __DIR__ . '/config/academic-units.php'; $__rowsAll = $departments;   // v104: the programs and majors too — their APP-CSEs are in their school's folder
$departments = pe_folder_listable($departments);   // v104: a school is one folder — its programs and majors are inside it (was v81: the units, not the school)
if (($__od = trim((string)($_GET['dept'] ?? ''))) !== '' && au_is_unit($__od)) { header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?dept=' . urlencode(au_school_of($__od))); exit; }   // v104: a program / major opens its school's folder

$departments = au_active_rows($departments);   // v92: a deactivated department is not shown
$__allDepts = au_active_rows($__rowsAll); $departments = os_without_sets($departments);   // v90: an office's sets are inside the office's one folder
// v88: restored APP-CSEs of past years, in their department's folder (view only), as on the other folders
require_once __DIR__ . '/config/past-years.php';
$pastByDept = past_year_docs('appcse');
$yearOpen = (int)($_GET['year'] ?? 0);

// Each department with its APP-CSE of the year, once it has been submitted
$deptIndex = [];
foreach ($departments as $d) $deptIndex[$d['code']] = $d + ['forms' => [], 'pending' => 0, 'approved' => 0, 'hasRevision' => false, 'lastSubmitted' => null, 'waitingSince' => null];
foreach ($__allDepts as $d) {
    $__k = pe_folder_of((string)$d['code']);   // v90: a set's APP-CSE is in its office's folder — v104: a program's / major's in its school's
    if (!isset($deptIndex[$__k])) continue;
    if (arc_appcse_is_archived($fy, (string)$d['code'])) continue;
    $i = appcse_info($fy, (string)$d['code']);
    if ($i['status'] === 'draft') continue;
    $sum = appcse_summary(appcse_part_totals($fy, (string)$d['code']), appcse_transport($fy, (string)$d['code']), (int)$fy);
    $f = $i + ['dept_code' => $d['code'], 'dept_name' => $d['name'], 'fy' => $fy, 'amount' => $sum['d']];
    $deptIndex[$__k]['forms'][] = $f;
    if ($i['status'] === 'approved') $deptIndex[$__k]['approved']++;
    if ($i['status'] === 'submitted') { $deptIndex[$__k]['pending']++; $deptIndex[$__k]['waitingSince'] = $i['submitted_at']; }
    if ($i['status'] === 'revision') $deptIndex[$__k]['hasRevision'] = true;
    if (!empty($i['submitted_at'])) $deptIndex[$__k]['lastSubmitted'] = $i['submitted_at'];
}

$academicSupportCodes = [];
function anx_subcategory(array $d, array $academicSupport): string {
    if (in_array(strtoupper((string)($d['code'] ?? '')), ['LIB', 'REG', 'NSTP', 'SCA', 'IEAS', 'LAB'], true)) {
        if (($d['dept_group'] ?? '') === 'Special Programs') return 'Special Projects';
        if (($d['dept_group'] ?? '') === 'Executive Offices') return 'Executive Offices';
        return 'Support Units';
    }
        if (($d['dept_group'] ?? '') === 'Schools & Colleges') return 'Schools & Colleges';
    if (in_array($d['code'], $academicSupport, true)) return 'Academic Support';
    if (($d['dept_group'] ?? '') === 'Executive Offices')   return 'Executive Offices';
    if (($d['dept_group'] ?? '') === 'Special Programs')    return 'Special Projects';
    return 'Support Units';
}
$subOrder = [
    'Academic'       => ['Schools & Colleges', 'Academic Support'],
    'Administrative' => ['Executive Offices', 'Support Units', 'Special Projects'],
];
$subMeta = [
    'Schools & Colleges' => ['icon' => 'M22 10v6M2 10l10-5 10 5-10 5z', 'hint' => 'Degree-granting schools and colleges'],
    'Academic Support'   => ['icon' => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20',  'hint' => 'Library, registrar, laboratories, and student services'],
    'Executive Offices'  => ['icon' => 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', 'hint' => 'Office of the President and Vice Presidents'],
    'Support Units'      => ['icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-14L4 7m8 4v10M4 7v10l8 4', 'hint' => 'Finance, HR, IT, and operations'],
    'Special Projects'   => ['icon' => 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5', 'hint' => 'Extension, R&D, gender & development, DRRM, and other special programs'],
];
$groupOf = fn(array $d) => dept_group_folder((string)$d['code'], $d['dept_group'] ?? null);
function anx_rel_time(?string $t): string {
    if (!$t) return '';
    $secs = time() - strtotime($t);
    if ($secs < 60)     return $secs . 's ago';
    if ($secs < 3600)   return floor($secs / 60)    . 'm ago';
    if ($secs < 86400)  return floor($secs / 3600)  . 'h ago';
    if ($secs < 604800) return floor($secs / 86400) . 'd ago';
    return date('M j, Y', strtotime($t));
}
$landing = ($openDept === '' && $openGroup === '' && $search === '' && $statusFilter === 'all');
if ($openDept !== '' && !isset($deptIndex[$openDept])) { header('Location: appcse-folders.php'); exit; }

require __DIR__ . '/includes/head.php';
?>
<style>
  .annex-row { background:var(--white); border:1px solid var(--border); border-left:4px solid #1c4c86; border-radius:12px; padding:16px 18px; margin-bottom:12px; }

  .group-folders { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-top: 6px; }
  @media (max-width: 820px) { .group-folders { grid-template-columns: minmax(0, 1fr); } }
  .group-folder { display: flex; gap: 18px; align-items: flex-start; min-width: 0; padding: 24px 24px 22px;
    background: var(--white); border: 1px solid var(--border); border-left: 5px solid var(--navy-500);
    border-radius: 14px; text-decoration: none; color: var(--ink); transition: border-color .15s, box-shadow .15s; }
  .group-folder:hover { border-color: var(--navy-500); box-shadow: 0 8px 22px rgba(19, 52, 88, .10); }
  .group-folder.g-admin { border-left-color: var(--orange-500); }
  .group-folder .gf-icon { width: 58px; height: 58px; flex-shrink: 0; border-radius: 14px; display: grid; place-items: center;
    background: var(--navy-50); color: var(--ink); }
  .group-folder .gf-body { min-width: 0; flex: 1 1 auto; }
  .group-folder .gf-name { font-family: 'Space Grotesk', sans-serif; font-size: 19px; font-weight: 700; color: var(--ink); }
  .group-folder .gf-sub { font-size: 12.5px; color: var(--muted); margin-top: 3px; }
  .group-folder .gf-stats { font-size: 12.5px; color: var(--ink); margin-top: 12px; display: flex; flex-wrap: wrap; gap: 4px 12px; }
  .group-folder .gf-wait { display: inline-block; margin-top: 12px; padding: 5px 11px; border-radius: 999px; font-size: 12px; font-weight: 700;
    background: var(--navy-100); color: var(--ink); border: 1px solid var(--navy-500); }
  .group-folder .gf-latest { font-size: 12px; color: var(--muted); margin-top: 6px; overflow-wrap: anywhere; }

  .pu-queue { background: var(--white); border: 1px solid var(--navy-500); border-radius: 14px; padding: 14px 16px 6px; margin: 4px 0 18px; }
  .pu-queue-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-family: 'Space Grotesk', sans-serif;
    font-size: 13.5px; font-weight: 700; color: var(--ink); margin-bottom: 8px; }
  .pu-queue-head .n { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--navy-100); color: var(--ink); }
  .pu-q-row { display: flex; align-items: center; gap: 10px 14px; flex-wrap: wrap; padding: 10px 0; border-top: 1px solid var(--border); }
  .pu-q-info { flex: 1 1 280px; min-width: 0; }
  .pu-q-title { font-weight: 700; font-size: 13.5px; color: var(--ink); overflow-wrap: anywhere; }
  .pu-q-meta { font-size: 12px; color: var(--muted); margin-top: 2px; overflow-wrap: anywhere; }
  .pu-q-actions, .file-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .pu-q-actions .btn, .file-actions .btn { padding: 7px 13px; font-size: 12.5px; text-decoration: none; }
  .file-amount { color: var(--navy-700); }
  body:not([data-theme="dark"]) .file-amount { color: #2f578a; }
  .anx-state { padding: 2px 8px; border-radius: 999px; font-size: 10px; margin-left: 6px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
  /* the state tag keeps its own light colours in dark mode too */
  .anx-state.st-submitted { background: #EAF4F9 !important; color: #1c4c86 !important; }
  .anx-state.st-approved  { background: #f0fdf4 !important; color: #14532d !important; }
  .anx-state.st-revision  { background: #fffbeb !important; color: #9a3412 !important; }
</style>
<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
<?php if ($openGroup === '' && $openDept === ''): ?>
    <div class="page-head">
      <div>
        <h1 class="page-title">APP-CSE Folders · FY <?= $fy ?></h1>
        <p class="page-sub"><?= $landing ? 'Every APP-CSE comes here straight from the department. Open a folder to review them.'
                                          : 'Every department has its own folder. The APP-CSEs submitted by departments are here.' ?></p></div></div>
<?php endif; ?>
    <?php render_flashes(); ?>

<?php if ($landing):
  // Departments, forms on hand, and what is waiting for review, per folder
  $g = [];
  foreach (['Administrative', 'Academic'] as $gn) $g[$gn] = ['depts' => 0, 'withForms' => 0, 'waiting' => 0, 'latest' => null];
  foreach ($deptIndex as $d) {
      $gn = $groupOf($d);
      $g[$gn]['depts']++;
      if ($d['approved'] > 0) $g[$gn]['withForms']++;
      $g[$gn]['waiting'] += $d['pending'];
      if ($d['waitingSince'] !== null && ($g[$gn]['latest'] === null || strcmp($d['waitingSince'], $g[$gn]['latest']['at']) > 0)) {
          $g[$gn]['latest'] = ['at' => $d['waitingSince'], 'code' => $d['code']];
      }
  }
  $gm = [
      'Administrative' => ['cls' => 'g-admin', 'icon' => '🏢', 'sub' => 'Executive, finance, operations, and special projects'],
      'Academic'       => ['cls' => 'g-acad',  'icon' => '🎓', 'sub' => 'Teaching, learning, research, and student affairs'],
  ];
?>
    <form method="GET" class="filter-row" role="search" style="align-items: center;">
      <?= search_field(['name' => 'q', 'value' => '', 'placeholder' => 'Search all departments…',
                        'label' => 'Search all departments', 'attrs' => SEARCH_NO_DIGITS]) ?>
    </form>

    <div class="group-folders">
      <?php foreach ($g as $gn => $s): $m = $gm[$gn]; ?>
        <a href="appcse-folders.php?group=<?= urlencode($gn) ?>" class="group-folder <?= $m['cls'] ?>">
          <div class="gf-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
          <div class="gf-body">
            <div class="gf-name"><?= $m['icon'] ?> <?= e($gn) ?></div>
            <div class="gf-sub"><?= e($m['sub']) ?></div>
            <div class="gf-stats">
              <span><?= $s['depts'] ?> department<?= $s['depts'] === 1 ? '' : 's' ?></span>
              <span><?= $s['withForms'] ?> with an approved APP-CSE</span></div>
            <?php if ($s['waiting']): ?>
              <div class="gf-wait"><?= $s['waiting'] ?> submitted · waiting for your review</div>
              <div class="gf-latest">Latest: <?= e($s['latest']['code']) ?> · <?= e(anx_rel_time($s['latest']['at'])) ?></div>
            <?php endif; ?>
            </div></a>
      <?php endforeach; ?></div>

<?php elseif ($openDept === ''):
  // ── The department folders of one group (or a search across both) ──
  $countByGroup = ['Academic' => 0, 'Administrative' => 0];
  foreach ($deptIndex as $d) $countByGroup[$groupOf($d)]++;
  $qbase = fn($grp) => 'appcse-folders.php?' . http_build_query(array_filter([
      'q' => $search ?: null, 'status' => $statusFilter !== 'all' ? $statusFilter : null,
      'group' => $grp !== 'all' ? $grp : null]));
  $tab = 'text-decoration: none; padding: 10px 18px; border-radius: 10px; font-family: \'Space Grotesk\', sans-serif; font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 8px;';
?>
    <div class="breadcrumb">
      <a href="appcse-folders.php">APP-CSE Folders</a>
      <span>›</span>
      <span><?= $openGroup !== '' ? e($openGroup) : 'Search results' ?></span></div>

    <div class="section-head">
      <div>
        <h2 class="section-title"><?= $openGroup !== '' ? e($openGroup) . ' folders' : 'Department folders' ?></h2></div></div>

    <div style="display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap;">
      <a href="appcse-folders.php" style="<?= $tab ?> border: 1.5px solid var(--border); background: var(--white); color: var(--ink);">← Back</a>
      <?php /* Inside a folder, only that folder's own button */ ?>
      <?php if ($openGroup !== 'Administrative'): ?>
      <a href="<?= $qbase('Academic') ?>" style="<?= $tab ?> border: 1.5px solid <?= $openGroup === 'Academic' ? 'var(--navy-700)' : 'var(--navy-100)' ?>; background: <?= $openGroup === 'Academic' ? 'var(--accent)' : 'var(--white)' ?>; color: <?= $openGroup === 'Academic' ? 'var(--accent-ink)' : 'var(--ink)' ?>;">🎓 Academic
        <span style="font-weight: 600; opacity: 0.7; font-size: 12px;"><?= $countByGroup['Academic'] ?></span></a>
      <?php endif; ?>
      <?php if ($openGroup !== 'Academic'): ?>
      <a href="<?= $qbase('Administrative') ?>" style="<?= $tab ?> border: 1.5px solid <?= $openGroup === 'Administrative' ? 'var(--orange-600)' : 'var(--orange-100)' ?>; background: <?= $openGroup === 'Administrative' ? 'var(--accent)' : 'var(--white)' ?>; color: <?= $openGroup === 'Administrative' ? 'var(--accent-ink)' : 'var(--ink)' ?>;">🏢 Administrative
        <span style="font-weight: 600; opacity: 0.7; font-size: 12px;"><?= $countByGroup['Administrative'] ?></span></a>
      <?php endif; ?></div>

    <form method="GET" class="filter-row" role="search" style="align-items: center;">
      <?= search_field(['name' => 'q', 'value' => $search, 'placeholder' => 'Search department…',
                        'label' => 'Search departments', 'attrs' => SEARCH_NO_DIGITS]) ?>
      <?php if ($openGroup !== ''): ?><input type="hidden" name="group" value="<?= e($openGroup) ?>"><?php endif; ?>
      <div class="chips">
        <a href="appcse-folders.php?<?= http_build_query(array_filter(['q' => $search ?: null, 'group' => $openGroup ?: null])) ?>" class="chip <?= $statusFilter === 'all' ? 'active' : '' ?>">All folders</a>
        <a href="appcse-folders.php?<?= http_build_query(array_filter(['group' => $openGroup ?: null, 'status' => 'revision'])) ?>" class="chip revision <?= $statusFilter === 'revision' ? 'active' : '' ?>">With revisions</a></div></form>

<?php
  $searchLower = mb_strtolower($search);
  $anyShown = false;
  foreach (['Academic', 'Administrative'] as $groupName):
    if ($openGroup !== '' && $openGroup !== $groupName) continue;
    $groupDepts = array_values(array_filter($deptIndex, function ($d) use ($groupOf, $groupName, $statusFilter, $searchLower) {
        if ($groupOf($d) !== $groupName) return false;
        if ($statusFilter === 'revision' && !$d['hasRevision']) return false;
        if ($searchLower !== '' && strpos(mb_strtolower($d['name']), $searchLower) === false
                                && strpos(mb_strtolower($d['code']), $searchLower) === false) return false;
        return true;
    }));
    if (!$groupDepts) continue;
    $anyShown = true;
    $cls = $groupName === 'Academic' ? 'cat-navy' : 'cat-orange';
    $bySub = [];
    foreach ($groupDepts as $d) $bySub[anx_subcategory($d, $academicSupportCodes)][] = $d;
?>
    <?php if ($openGroup === ''): /* a search across both folders: say which is which */ ?>
    <div class="folder-group-header <?= $cls ?>">
      <div class="folder-group-badge"><?= e($groupName) ?></div></div>
    <?php endif; ?>

    <?php
      // APP-CSEs in this folder waiting for review, newest first
      $queue = [];
      foreach ($groupDepts as $d) foreach ($d['forms'] as $s) if ($s['status'] === 'submitted') $queue[] = $s;
      usort($queue, fn($a, $b) => strcmp((string)$b['submitted_at'], (string)$a['submitted_at']));
    ?>
    <?php if ($queue): ?>
      <?= sort_bar('appcseList') ?>
      <div class="pu-queue" id="appcseList">
        <div class="pu-queue-head">Submitted · waiting for your review
          <span class="n"><?= count($queue) ?></span></div>
        <?php foreach ($queue as $s): ?>
          <div class="pu-q-row" data-ts="<?= sort_ts($s['submitted_at'] ?? null) ?>">
            <div class="pu-q-info">
              <div class="pu-q-title"><?= e($s['dept_code']) ?> · <?= e($s['dept_name'] ?: $s['dept_code']) ?></div>
              <div class="pu-q-meta">
                FY <?= (int)$s['fy'] ?> APP-CSE · ₱<?= number_format((float)$s['amount'], 2) ?> (with the <?= e(nonps_rate_text(appcse_rate((int)$s['fy']))) ?>%)
                <?php if (!empty($s['submitted_at'])): ?> · submitted <?= e(anx_rel_time($s['submitted_at'])) ?><?php endif; ?>
                <?php if (!empty($s['submitted_by'])): ?> by <?= e($s['submitted_by']) ?><?php endif; ?></div></div>
            <div class="pu-q-actions">
              <a href="appcse-review.php?fy=<?= (int)$s['fy'] ?>&amp;dept=<?= urlencode($s['dept_code']) ?>" class="btn btn-primary">Open</a></div></div>
        <?php endforeach; ?></div>
    <?php endif; ?>

    <?= sort_bar('.dept-grid', 'Waiting on you first') ?>
    <?php foreach ($subOrder[$groupName] as $subName):
      if (empty($bySub[$subName])) continue;
      $subDepts = $bySub[$subName];
      // Waiting for review first, newest first; then those that submitted; then the rest in their order
      usort($subDepts, function ($a, $b) {
          $aw = $a['waitingSince'] !== null; $bw = $b['waitingSince'] !== null;
          if ($aw !== $bw) return $aw ? -1 : 1;
          if ($aw && ($c = strcmp($b['waitingSince'], $a['waitingSince'])) !== 0) return $c;
          $ah = $a['lastSubmitted'] !== null; $bh = $b['lastSubmitted'] !== null;
          if ($ah !== $bh) return $ah ? -1 : 1;
          if ($ah && ($c = strcmp($b['lastSubmitted'], $a['lastSubmitted'])) !== 0) return $c;
          return (int)$a['display_order'] <=> (int)$b['display_order'];
      });
      $sm = $subMeta[$subName];
    ?>
      <div class="folder-subgroup-header <?= $cls ?>">
        <div class="folder-subgroup-badge">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $sm['icon'] ?>"/></svg>
          <?= e($subName) ?>
          <span class="folder-subgroup-count"><?= count($subDepts) ?></span></div>
        <div class="folder-subgroup-hint"><?= e($sm['hint']) ?></div></div>
      <div class="folder-grid dept-grid">
      <?php foreach ($subDepts as $d):
        $n = (int)$d['approved'];   // counted once approved by the Super Admin
        $__nothing = !$d['forms'] && empty($pastByDept[$d['code']]);
      ?>
        <a href="appcse-folders.php?dept=<?= urlencode($d['code']) ?>" data-ts="<?= sort_ts($d['lastSubmitted']) ?>" class="folder <?= $cls ?><?= $d['hasRevision'] ? ' has-revision' : '' ?>">
          <?php if ($d['hasRevision']): ?><span class="folder-badge">Revision</span><?php endif; ?>
          <div class="folder-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
          <div class="folder-info">
            <div class="folder-code"><?= strtoupper((string)$d['code']) === 'BUDGET-OFC' ? 'Budget' : e($d['code']) ?></div>
            <?php if (strtoupper((string)$d['code']) !== 'BUDGET-OFC'): ?><div class="folder-full"><?= e($d['name']) ?></div><?php endif; ?>
            <div class="folder-meta">
              <?php if ($__nothing): ?>
                <span>Empty folder</span>
              <?php else: ?>
                <span>FY <?= (int)$fy ?> APP-CSE</span>
                <?php if ($d['forms']): ?>
                <span class="dot"></span>
                <span><?= $d['pending'] ? 'Awaiting your review' : ($d['hasRevision'] ? 'Sent back' : 'Approved') ?></span>
                <?php endif; ?>
                <?= past_year_tag($pastByDept[$d['code']] ?? []) ?>
              <?php endif; ?></div></div></a>
      <?php endforeach; ?></div>
    <?php endforeach; ?>
<?php endforeach; ?>
    <?php if (!$anyShown): ?>
      <div class="card" style="text-align:center; padding:40px 20px; color:var(--muted);">No department folders match<?= $search !== '' ? ' "' . e($search) . '"' : '' ?>.</div>
    <?php endif; ?>

<?php elseif ($yearOpen > 0 && isset($deptIndex[$openDept])):
  // ── One past year inside a department's folder (restored, view only) — v88 ──
  echo past_year_page_html('appcse-folders.php', 'APP-CSE Folders', $deptIndex[$openDept], $groupOf($deptIndex[$openDept]),
                           $yearOpen, $pastByDept[$openDept][$yearOpen] ?? []);
else:
  // ── One department's folder ──
  $dept = $deptIndex[$openDept];
  $deptGroup = $groupOf($dept);
?>
    <div class="breadcrumb">
      <a href="appcse-folders.php">APP-CSE Folders</a>
      <span>›</span>
      <a href="appcse-folders.php?group=<?= urlencode($deptGroup) ?>"><?= e($deptGroup) ?></a>
      <span>›</span>
      <span><?= e($dept['code']) ?> — <?= e($dept['name']) ?></span></div>
    <div class="section-head">
      <div>
        <h2 class="section-title"><?= e($dept['code']) ?> · <?= e($dept['name']) ?></h2>
        <p class="section-sub">APP-CSE · FY <?= $fy ?><?php if (!empty($dept['dept_group'])): ?> · Group: <?= e($dept['dept_group']) ?><?php endif; ?></p></div>
      <a href="appcse-folders.php?group=<?= urlencode($deptGroup) ?>" class="btn btn-ghost">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back to folders
      </a></div>

    <?php if (!$dept['forms']): ?>
      <div style="background: var(--white); border: 1px dashed var(--border); border-radius: 14px; padding: 40px 20px; text-align: center; color: var(--muted); font-size: 14px;"> No APP-CSE submitted in this folder for FY <?= $fy ?> yet.
      </div>
    <?php else: ?>
      <?php foreach ($dept['forms'] as $s):
        $label = ['submitted' => '📤 Awaiting review', 'approved' => '✓ Approved', 'revision' => '↩ Sent back for revision'][$s['status']] ?? $s['status'];
      ?>
        <div class="card appcse-file" style="margin-bottom: 16px; padding: 0; overflow: hidden;<?= $s['status'] === 'revision' ? 'border-left: 4px solid var(--orange-500);' : '' ?>">
          <div style="padding: 14px 18px; display: flex; align-items: center; gap: 12px 14px; flex-wrap: wrap; background: var(--navy-50); border-bottom: 1px solid var(--border);">
            <div style="background: var(--navy-100); color: var(--navy-700); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div style="flex: 1 1 240px; min-width: 0;">
              <div style="font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; color: var(--ink); overflow-wrap: anywhere;">FY <?= (int)$s['fy'] ?> APP-CSE<?= ($__sl = pe_folder_label((string)$s['dept_code'])) !== '' ? ' · ' . e($__sl) : '' ?>
                <span class="anx-state st-<?= e($s['status']) ?>"><?= e($label) ?></span></div>
              <div style="font-size: 12px; color: var(--muted); margin-top: 3px;">
                <?php if (!empty($s['submitted_at'])): ?>Submitted <?= e(date('M j, Y g:i A', strtotime($s['submitted_at']))) ?><?php endif; ?>
                <?php if (!empty($s['submitted_by'])): ?> · By <?= e($s['submitted_by']) ?><?php endif; ?>
                <?php if ($s['status'] === 'approved' && !empty($s['reviewed_at'])): ?> · Approved <?= e(date('M j, Y', strtotime($s['reviewed_at']))) ?><?php endif; ?></div></div>
            <div class="file-actions">
              <span class="file-amount" style="font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 700;" title="Grand total with the <?= e(nonps_rate_text(appcse_rate((int)$s['fy']))) ?>% (D of Part I + Part II)">₱<?= number_format((float)$s['amount'], 2) ?></span>
              <a href="appcse-review.php?fy=<?= (int)$s['fy'] ?>&amp;dept=<?= urlencode($s['dept_code']) ?>" class="btn btn-primary" style="padding: 8px 14px; font-size: 13px; text-decoration: none;">Open</a></div></div></div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?= past_year_folders_html('appcse-folders.php', $dept['code'], $pastByDept[$dept['code']] ?? []) ?>
<?php endif; ?>
  </main></div></body></html>
