<?php
require_once __DIR__ . '/config/auth.php';
require_login();  // The Procurement Unit (admin) prepares its own PPMP too — same access model as the other "My office" editors (nonps/annex/market-scoping)

$u = current_user();
$deptCode = $u['dept_code'];
$categoryOpen = $_GET['category'] ?? null;  // if set, show file list for that category
$yearOpen     = (int)($_GET['year'] ?? 0);   // v88: a past fiscal year's documents (restored from the Archive)
$restoredOpen = !empty($_GET['restored']);   // v111: My PPMPs › Restored — this office's restored PPMPs

// v104: "My PPMPs" opens the PPMP itself — no Goods / Infrastructure / Consulting Services /
// Uncategorized folders first — for every account (end users, Department Admins, the Budget Unit).
// A past fiscal year's documents (?year=…) still open here.
if ($categoryOpen === null && $yearOpen === 0 && !$restoredOpen) {
    if (!empty($_GET['saved'])) flash('success', 'Your draft has been saved.');
    header('Location: ppmp-edit.php?fy=' . active_fy());
    exit;
}

// Load all PPMPs for this dept
$stmt = db()->prepare("SELECT id, fy, updated_at, submitted_at, status FROM ppmps
                         WHERE dept_code = ?
                           AND id NOT IN (SELECT ppmp_id FROM archived_ppmps)
                        ORDER BY FIELD(status, 'draft', 'submitted', 'budget_approved', 'approved'),
                                 updated_at DESC, fy DESC");
$stmt->execute([$deptCode]);
$myPPMPs = $stmt->fetchAll();

/** Map the free-typed project type onto one of the three NGPA categories. Same mapping as consolidation.php. */
function ppmp_ngpa_bucket(string $projType): ?string {
    $t = strtolower(trim($projType));
    if ($t === '') return null;
    if (strpos($t, 'infra') !== false)  return 'infra';
    if (strpos($t, 'consult') !== false) return 'consult';
    if (strpos($t, 'good') !== false)   return 'goods';
    return null;
}

// Load entries grouped by ppmp and procurement category (NGPA: Goods / Infrastructure /
// Consulting Services), bucketed from each entry's "Type of Project" — same mapping used
// on the Consolidation page. Entries with no match fall into 'other' (Uncategorized).
$entries = [];
if ($myPPMPs) {
    $ids = array_column($myPPMPs, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT ppmp_id, proj_type, amount FROM ppmp_entries WHERE ppmp_id IN ($placeholders)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $e) {
        $bucket = ppmp_ngpa_bucket((string)($e['proj_type'] ?? ''));
        $entries[$e['ppmp_id']][$bucket ?? 'other'][] = $e;
    }
}

// Load pending edit requests for this dept (the Procurement Unit asks before it edits a PPMP)
require_once __DIR__ . '/config/edit-permission.php';
ensure_edit_request_columns();
$stmt = db()->prepare('SELECT * FROM edit_requests WHERE dept_code = ? AND status = ? AND closed_at IS NULL ORDER BY requested_at DESC');
$stmt->execute([$deptCode, 'pending']);
$editReqs = $stmt->fetchAll();
$editReqByFY = [];
foreach ($editReqs as $r) { if (in_array((string)($r['doc_type'] ?? ''), ['', 'ppmp'], true)) $editReqByFY[$r['fy']] = $r; }   // the PPMP's own, for its file card

// Load unresolved revision requests (from Budget or Admin) so we can show a dedicated slot
$revReqByFY = [];
try {
    $stmt = db()->prepare('SELECT * FROM revision_requests WHERE dept_code = ? AND resolved = 0 ORDER BY requested_at DESC');
    $stmt->execute([$deptCode]);
    foreach ($stmt->fetchAll() as $r) {
        // keep only the newest per FY
        if (!isset($revReqByFY[$r['fy']])) $revReqByFY[$r['fy']] = $r;
    }
} catch (Throwable $t) { /* table may lack resolved column on very old installs */ }

// Procurement categories (NGPA: Goods / Infrastructure / Consulting Services), plus a
// catch-all for entries whose Type of Project doesn't match any of the three.
$CATEGORIES = [
    ['code' => 'goods',   'name' => 'Goods',                 'short' => 'Goods',       'desc' => 'Supplies, equipment, and other goods'],
    ['code' => 'infra',   'name' => 'Infrastructure',        'short' => 'Infra',       'desc' => 'Infrastructure and civil works projects'],
    ['code' => 'consult', 'name' => 'Consulting Services',   'short' => 'Consulting',  'desc' => 'Professional or expert services'],
    ['code' => 'other',   'name' => 'Uncategorized',         'short' => 'Uncategorized', 'desc' => 'No Type of Project set, or one that doesn\'t match Goods, Infrastructure, or Consulting Services'],
];

// For a given category, list ppmp files that have entries in that category
function filesForCategory(string $cat, array $ppmps, array $entries): array {
    $out = [];
    foreach ($ppmps as $p) {
        $catEntries = $entries[$p['id']][$cat] ?? [];
        if (!$catEntries) continue;
        $total = 0;
        foreach ($catEntries as $e) $total += (float)$e['amount'];
        $out[] = [
            'fy'      => $p['fy'],
            'entries' => count($catEntries),
            'amount'  => $total,
            'updated' => $p['updated_at'],
            'status'  => $p['status'],
        ];
    }
    return $out;
}

$activeNav = (($u['role'] ?? '') === 'admin') ? 'my-ppmp' : 'ppmps';
$pageTitle = 'My PPMPs';
require __DIR__ . '/includes/head.php';
?>

<div class="app">
<?php
  $__role = $u['role'] ?? '';
  if     ($__role === 'admin')  require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($__role === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
  else                          require __DIR__ . '/includes/sidebar-user.php';
?>

  <main class="main">

<?php
  // v88: this department's documents of past years that are not in the Archive (restored), view only
  require_once __DIR__ . '/config/past-years.php';
  $__past = past_year_docs_of((string)$deptCode);
?>
<?php if ($restoredOpen): /* v111: the "Restored" folder of My PPMPs (view only) */ ?>
    <?= past_year_restored_page_html([['My PPMPs', 'user-dashboard.php'], ['Restored']], 'Restored PPMPs', 'user-dashboard.php',
                                     past_year_ppmps_of((string)$deptCode)) ?>
<?php elseif ($yearOpen > 0): ?>
    <div class="breadcrumb">
      <a href="user-dashboard.php">My PPMPs</a><span>›</span><span>FY <?= $yearOpen ?></span></div>
    <div class="section-head" id="pastYearPage">
      <div>
        <h2 class="section-title">FY <?= $yearOpen ?></h2>
        <p class="section-sub">Restored from the Archive · view only</p></div>
      <a href="user-dashboard.php" class="btn btn-ghost">← Back</a></div>
    <?= !empty($__past[$yearOpen]) ? past_year_files_html($__past[$yearOpen])
          : '<div style="background: var(--white); border: 1px dashed var(--border); border-radius: 14px; padding: 40px 20px; text-align: center; color: var(--muted); font-size: 14px;">No FY ' . $yearOpen . ' documents here. Restore them from the Archive to see them.</div>' ?>
<?php elseif ($categoryOpen === null): ?>
    <!-- ── FOLDER GRID VIEW ─── -->
    <?php
      // One PPMP per department per fiscal year, so there is nothing to
      // create twice. This button opens the current year's PPMP, starting
      // it if the department has not begun one. It replaces the separate
      // "New PPMP" item that used to sit in the sidebar.
      $__thisFY   = active_fy();
      $__fyExists = false;
      foreach ($myPPMPs as $__p) { if ((int)$__p['fy'] === $__thisFY) { $__fyExists = true; break; } }
    ?>
    <div class="page-head">
      <div>
        <h1 class="page-title">My PPMPs · FY <?= $__thisFY ?></h1>
        <p class="page-sub">Project Procurement Management Plans · <?= e($u['dept_name']) ?></p><?php require_once __DIR__ . '/includes/unit-switch.php'; echo unit_pick_html($u);   /* v87: which program / major or set */ ?></div>
      <a href="ppmp-edit.php?fy=<?= $__thisFY ?>" class="btn btn-primary" style="text-decoration: none; white-space: nowrap;">
        <?= $__fyExists ? 'Open FY ' . $__thisFY . ' PPMP' : '+ New PPMP · FY ' . $__thisFY ?></a></div>
    <script> function toggleExcelMenu(e) {
        e.stopPropagation();
        const m = document.getElementById('excelMenu');
        m.style.display = m.style.display === 'none' ? 'block' : 'none';
      }
      document.addEventListener('click', (e) => {
        const m = document.getElementById('excelMenu');   // (not on the page in every state)
        if (m && !e.target.closest('#excelMenuWrap')) m.style.display = 'none';
      });
    </script>

<?php
if (!empty($_GET['saved'])) {
    echo '<div class="flash flash-success" style="margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      <span>Your draft has been saved. It\'s pinned at the top of your PPMPs — click it anytime to continue editing.</span></div>';
}
render_flashes(); ?>

<?php
// ─── Quick stats overview ─────────────────────────────────
$__totalPPMPs   = count($myPPMPs);
$__draftCount   = 0;
$__submittedCnt = 0;
$__approvedCnt  = 0;
foreach ($myPPMPs as $__p) {
    if ($__p['status'] === 'draft') $__draftCount++;
    elseif (in_array($__p['status'], ['submitted','budget_approved'], true)) $__submittedCnt++;
    elseif ($__p['status'] === 'approved') $__approvedCnt++;
}
?>

<?php if ($__totalPPMPs > 0): ?>
  <div class="stats" style="margin-bottom: 22px;">
    <div class="stat">
      <div class="stat-label">Total PPMPs</div>
      <div class="stat-value" style="color: var(--navy-800);"><?= $__totalPPMPs ?></div>
      <div class="stat-meta"><?= e($u['dept_code']) ?> · all fiscal years</div></div>
    <div class="stat">
      <div class="stat-label">Drafts</div>
      <div class="stat-value" style="color: <?= $__draftCount > 0 ? 'var(--orange-600)' : 'var(--muted)' ?>;"><?= $__draftCount ?></div>
      <div class="stat-meta">Still being edited</div></div>
    <div class="stat">
      <div class="stat-label">In review</div>
      <div class="stat-value" style="color: var(--navy-700);"><?= $__submittedCnt ?></div>
      <div class="stat-meta">With Budget or Procurement</div></div>
    <div class="stat">
      <div class="stat-label">Approved</div>
      <div class="stat-value" style="color: #16a34a;"><?= $__approvedCnt ?></div>
      <div class="stat-meta">Fully approved</div></div></div>
<?php endif; ?>

<?php
// ─── Deadline banner — show any active/upcoming/passed deadline ──────
try {
    $today = date('Y-m-d');
    $ds = db()->prepare('SELECT * FROM submission_deadlines WHERE fy >= ? ORDER BY deadline_date ASC');
    $ds->execute([active_fy()]);
    $upcoming = $ds->fetchAll();
    foreach ($upcoming as $d):
        $daysLeft = floor((strtotime($d['deadline_date']) - strtotime($today)) / 86400);
        // Has this dept already submitted for that FY?
        $c = db()->prepare("SELECT COUNT(*) FROM ppmps WHERE dept_code = ? AND fy = ?
                             AND status IN ('submitted','budget_approved','approved')");
        $c->execute([$u['dept_code'], (int)$d['fy']]);
        $alreadySubmitted = (int)$c->fetchColumn() > 0;
        if ($alreadySubmitted) continue; // no need to nag them

        if ($daysLeft < 0) {
            $bg = 'var(--orange-50)'; $bd = 'var(--orange-500)'; $fg = 'var(--orange-600)'; $icon = '⏰';
            $title = "OVERDUE: FY " . (int)$d['fy'] . " PPMP submission was due " . date('F j, Y', strtotime($d['deadline_date']));
            $sub   = "The deadline passed " . abs((int)$daysLeft) . " " . (abs((int)$daysLeft) == 1 ? 'day' : 'days') . " ago. Please submit as soon as possible.";
        } elseif ($daysLeft === 0.0 || $daysLeft === 0) {
            $bg = 'var(--orange-50)'; $bd = 'var(--orange-500)'; $fg = 'var(--orange-600)'; $icon = '⏰';
            $title = "DUE TODAY: FY " . (int)$d['fy'] . " PPMP submission";
            $sub   = "Today is the last day to submit your FY " . (int)$d['fy'] . " PPMP.";
        } elseif ($daysLeft <= 7) {
            $bg = 'var(--orange-50)'; $bd = 'var(--orange-500)'; $fg = 'var(--orange-600)'; $icon = '';
            $title = "Due in " . (int)$daysLeft . " " . ($daysLeft == 1 ? 'day' : 'days') . ": FY " . (int)$d['fy'] . " PPMP";
            $sub   = "Submission deadline: " . date('l, F j, Y', strtotime($d['deadline_date']));
        } else {
            $bg = 'var(--navy-50)'; $bd = 'var(--navy-500)'; $fg = 'var(--navy-700)'; $icon = '';
            $title = "Upcoming: FY " . (int)$d['fy'] . " PPMP submission";
            $sub   = "Deadline: " . date('l, F j, Y', strtotime($d['deadline_date'])) . " · " . (int)$daysLeft . " days from today";
        }
?>
  <div style="background: <?= $bg ?>; border: 1px solid <?= $bd ?>; border-left: 4px solid <?= $bd ?>; border-radius: 10px; padding: 14px 18px; margin-bottom: 12px; display: flex; align-items: center; gap: 14px;">
    <div style="font-size: 26px;"><?= $icon ?></div>
    <div style="flex: 1;">
      <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13.5px; color: <?= $fg ?>; letter-spacing: 0.02em;">
        <?= e($title) ?></div>
      <div style="font-size: 12.5px; color: var(--ink); margin-top: 3px;">
        <?= e($sub) ?>
        <?php if (!empty($d['note'])): ?>
          <br><em style="color: var(--muted); font-size: 11.5px;"><?= e($d['note']) ?></em>
        <?php endif; ?></div></div>
    <a href="new-ppmp.php" style="background: #093c5d; color: white; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 12px; white-space: nowrap;">Start / continue PPMP →</a></div>
<?php endforeach;
} catch (Throwable $t) { /* table may not exist */ } ?>

    <?php foreach ($editReqs as $__er): ?>
      <div class="edit-req-banner" id="editReqTop<?= (int)$__er['id'] ?>">
        <div class="edit-req-banner-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div>
        <div style="flex: 1;">
          <div class="edit-req-title">Edit permission requested</div>
          <div class="edit-req-desc">
            The Procurement Unit (<strong><?= e($__er['requested_by']) ?></strong>) asks to edit your
            <strong><?= e(pu_edit_doc_label($__er)) ?></strong>. It can't make changes unless you grant access.
            <?php if (trim((string)$__er['reason']) !== ''): ?>
              <br><span style="font-weight: 600;">Reason:</span> <?= nl2br(e($__er['reason'])) ?>
            <?php endif; ?>
          </div>
          <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
            <form method="POST" action="api/respond-edit.php" style="display: inline;" data-confirm="This edit request will be declined." data-confirm-title="Decline edit request?" data-confirm-label="Decline" data-confirm-tone="danger">
              <input type="hidden" name="request_id" value="<?= (int)$__er['id'] ?>">
              <input type="hidden" name="response" value="declined">
              <input type="hidden" name="return_to" value="user-dashboard.php">
              <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px;">Decline</button></form>
            <form method="POST" action="api/respond-edit.php" style="display: inline;"
                  data-confirm="<?= in_array((string)($__er['doc_type'] ?? ''), ['', 'ppmp'], true)
                      ? 'The Procurement Unit will be able to edit this PPMP until it gives final approval or sends the PPMP back to you.'
                      : 'The Procurement Unit will be able to edit this ' . e(PU_EDIT_DOCS[(string)$__er['doc_type']] ?? 'document') . ' until it approves it or sends it back to you.' ?>"
                  data-confirm-title="Grant edit access?" data-confirm-label="Grant access" data-confirm-tone="info">
              <input type="hidden" name="request_id" value="<?= (int)$__er['id'] ?>">
              <input type="hidden" name="response" value="granted">
              <input type="hidden" name="return_to" value="user-dashboard.php">
              <button type="submit" class="btn btn-primary" style="padding: 8px 14px; font-size: 13px;">Grant edit access</button></form>
          </div></div></div>
    <?php endforeach; ?>

<?php
// Collect all draft PPMPs so we can pin them at the top
$myDrafts = array_values(array_filter($myPPMPs, fn($p) => $p['status'] === 'draft'));

// PPMPs that need revision = drafts that have an unresolved revision request for their FY
$needsRevision = [];
$plainDrafts   = [];
foreach ($myDrafts as $d) {
    if (isset($revReqByFY[$d['fy']])) $needsRevision[] = $d;
    else                              $plainDrafts[]   = $d;
}

// ─── Alloted budget for this department (set by Budget Unit from GAA) ─
$myBudgets = [];
try {
    $bstmt = db()->prepare('SELECT fy, alloted_budget, travel_budget, training_budget, supply_budget, equipment_budget, notes, set_by, updated_at
                              FROM dept_budgets WHERE dept_code = ?
                          ORDER BY fy DESC LIMIT 4');
    // v81: a unit of a school has no allotment of its own — it shows the school's (or its program's share)
    require_once __DIR__ . '/config/academic-units.php';
    $__isUnit = au_is_unit((string)$deptCode) || os_pooled((string)$deptCode);   // v83: an office's sets too
    $bstmt->execute([$__isUnit ? au_school_of((string)$deptCode) : $deptCode]);
    $myBudgets = $bstmt->fetchAll();
} catch (Throwable $t) {}
$__poolOf = [];
if (!empty($__isUnit)) {
    require_once __DIR__ . '/config/allot-shares.php';
    foreach ($myBudgets as $__i => $__b) {
        $__p = allot_pool((string)$deptCode, (int)$__b['fy']);
        $__poolOf[(int)$__b['fy']] = $__p + ['projected' => array_sum(allot_ppmp_totals($__p['units'], (int)$__b['fy']))];
        $myBudgets[$__i]['alloted_budget'] = (float)($__p['allot'] ?? 0);
    }
}

// Projected (sum of my PPMP entries) per FY
$myProjectedByFY = [];
$myProjectedCentsByFY = [];
foreach ($myPPMPs as $p) {
    $fyKey = (int)$p['fy'];
    $myProjectedCentsByFY[$fyKey] = $myProjectedCentsByFY[$fyKey] ?? 0;
    foreach (($entries[$p['id']] ?? []) as $catEntries) {
        foreach ($catEntries as $en) {
            // Store totals as integer centavos to avoid floating-point drift.
            $myProjectedCentsByFY[$fyKey] += (int)round((float)($en['amount'] ?? 0) * 100);
        }
    }
}
foreach ($myProjectedCentsByFY as $fyKey => $cents) $myProjectedByFY[$fyKey] = $cents / 100;
?>

<?php if ($myBudgets): ?>
  <div class="section-head" style="margin-bottom: 12px;">
    <div>
      <h2 class="section-title">Total Allocation</h2>
</div></div>
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-bottom: 24px;">
    <?php foreach ($myBudgets as $b):
      $bfy    = (int)$b['fy'];
      $altCents = (int)round((float)$b['alloted_budget'] * 100);
      $prjCents = isset($__poolOf[$bfy])
          ? (int)round((float)($__poolOf[$bfy]['projected'] ?? 0) * 100)
          : (int)($myProjectedCentsByFY[$bfy] ?? 0);
      $remCents = $altCents - $prjCents;
      $alt    = $altCents / 100;
      $prj    = $prjCents / 100;
      $rem    = $remCents / 100;
      $pct    = $altCents > 0 ? min(100, ($prjCents / $altCents) * 100) : 0;
      $over   = ($prjCents > $altCents);
      $barClr = $over ? 'var(--orange-500)' : ($pct >= 85 ? '#f59e0b' : '#16a34a');
      $catLabels = ['travel'=>'Travel','training'=>'Training','supply'=>'Supply','equipment'=>'Equipment'];
      $catColors = ['travel'=>'#0ea5e9','training'=>'#8b5cf6','supply'=>'#f59e0b','equipment'=>'#10b981'];
      $catBudgets = [];
      foreach ($catLabels as $__ck=>$__cl) $catBudgets[$__ck] = (float)($b[$__ck.'_budget'] ?? 0);
      $catSpent = array_fill_keys(array_keys($catLabels), 0.0);
      try {
        $cs = db()->prepare("SELECT e.budget_category, SUM(e.amount) spent FROM ppmp_entries e JOIN ppmps p ON p.id=e.ppmp_id WHERE p.dept_code=? AND p.fy=? GROUP BY e.budget_category");
        $cs->execute([$__isUnit ? au_school_of((string)$deptCode) : $deptCode, $bfy]);
        foreach ($cs->fetchAll() as $__cr) if (isset($catSpent[$__cr['budget_category']])) $catSpent[$__cr['budget_category']] = (float)$__cr['spent'];
      } catch (Throwable $t) {}
      $catTotal = array_sum($catBudgets); $catUsedTotal = array_sum($catSpent);
      $catParts=[]; $catCursor=0; foreach ($catBudgets as $__ck=>$__cv) { $share=$catTotal>0?($__cv/$catTotal*100):0; if($share>0){$catParts[]=$catColors[$__ck].' '.$catCursor.'% '.($catCursor+$share).'%';$catCursor+=$share;} }
      $donutGradient = $catParts ? 'conic-gradient('.implode(',',$catParts).')' : 'conic-gradient(#e5e7eb 0% 100%)';
    ?>
      <div class="card" style="padding: 20px; margin: 0; border-left: 4px solid <?= $barClr ?>;">
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px;">
          <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 14px; color: var(--navy-800);"> FY <?= $bfy ?></div>
          <?php if ($over): ?>
            <span style="background: var(--orange-50); color: var(--orange-600); border: 1.5px solid var(--orange-100); padding: 2px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;"> Over budget</span>
          <?php elseif ($alt > 0 && $pct >= 85): ?>
            <span style="background: #fef3c7; color: #b45309; border: 1.5px solid #fde68a; padding: 2px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;">Near limit</span>
          <?php else: ?>
            <span style="background: var(--green-50); color: #14532d; border: 1.5px solid #86efac; padding: 2px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;">Within budget</span>
          <?php endif; ?></div>

        <div style="font-family: 'Space Grotesk', sans-serif; font-size: 26px; font-weight: 700; color: var(--navy-800); margin-bottom: 4px;">
          ₱<?= number_format($alt, 2) ?></div>
        <div style="font-size: 11.5px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; margin-bottom: 14px;"> Total Allocation
        </div>
        <?php if (isset($__poolOf[$bfy])): ?>
          <div class="pool-note" style="font-size: 12px; color: var(--muted); margin: -8px 0 12px;"><?= e(ucfirst($__poolOf[$bfy]['label'])) ?> · shared by <?= count($__poolOf[$bfy]['units']) ?> <?= e(count($__poolOf[$bfy]['units']) === 1 ? rtrim($__poolOf[$bfy]['noun'] ?? 'units', 's') : ($__poolOf[$bfy]['noun'] ?? 'units')) ?></div>
        <?php endif; ?>

        <?php if ($catTotal > 0): ?>
          <div style="display:flex;gap:16px;align-items:center;margin:14px 0;padding:12px;border:1px solid var(--border);border-radius:12px">
            <div aria-label="Budget allocation by category" style="width:112px;height:112px;flex:0 0 112px;border-radius:50%;background:<?= e($donutGradient) ?>;display:grid;place-items:center;position:relative"><div style="width:66px;height:66px;border-radius:50%;background:var(--card,#fff);display:grid;place-items:center;text-align:center;font-size:10px;font-weight:800;color:var(--ink)">₱<?= number_format($catTotal,0) ?><span style="font-size:8px;font-weight:600">Guide</span></div></div>
            <div style="display:grid;gap:6px;min-width:0;flex:1">
              <?php foreach ($catLabels as $__ck=>$__cl): $__remain = $catBudgets[$__ck]-$catSpent[$__ck]; ?>
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;font-size:11px"><span style="display:flex;gap:6px;align-items:center"><i style="width:8px;height:8px;border-radius:50%;background:<?= $catColors[$__ck] ?>;display:inline-block"></i><?= e($__cl) ?></span><span style="text-align:right"><strong>₱<?= number_format($catBudgets[$__ck],2) ?></strong><br><span style="color:var(--muted)">Remaining ₱<?= number_format($remain,2) ?></span></span></div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div style="height: 8px; background: var(--navy-50); border-radius: 4px; overflow: hidden; margin-bottom: 12px;">
          <div style="height: 100%; width: <?= $pct ?>%; background: <?= $barClr ?>; transition: width 0.4s;"></div></div>

        <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
          <span style="color: var(--muted);"><?= isset($__poolOf[$bfy]) ? 'Projected (all their PPMPs):' : 'Projected (my PPMPs):' ?></span>
          <strong style="color: var(--ink); font-family: 'Space Grotesk', sans-serif;">₱<?= number_format($prj, 2) ?></strong></div>
        <div style="display: flex; justify-content: space-between; font-size: 12px;">
          <span style="color: var(--muted);"><?= $over ? 'Over by:' : 'Remaining:' ?></span>
          <strong style="color: <?= $over ? 'var(--orange-600)' : '#16a34a' ?>; font-family: 'Space Grotesk', sans-serif;">
            ₱<?= number_format(abs($rem), 2) ?></strong></div>

        <?php if (!empty($b['notes'])): ?>
          <div style="margin-top: 12px; padding: 8px 10px; background: var(--navy-50); border-radius: 6px; font-size: 11.5px; color: var(--muted); line-height: 1.5;">
            <?= e($b['notes']) ?></div>
        <?php endif; ?>
        <?php if (!empty($b['set_by'])): ?>
          <div style="margin-top: 8px; font-size: 10.5px; color: var(--muted);"> Set by <?= e($b['set_by']) ?> · <?= reltime_span($b['updated_at']) ?></div>
        <?php endif; ?></div>
    <?php endforeach; ?></div>
<?php endif; ?>

<?php if ($needsRevision): ?>
  <div class="section-head" style="margin-bottom: 12px;">
    <div>
      <h2 class="section-title" style="color: var(--orange-600);">↩ Needs revision</h2>
</div></div>
  <div class="folder-grid" style="margin-bottom: 24px;">
    <?php foreach ($needsRevision as $d):
      $rev = $revReqByFY[$d['fy']];
      $entryCount = 0; $subtotal = 0;
      foreach (($entries[$d['id']] ?? []) as $catEntries) {
        foreach ($catEntries as $en) { $entryCount++; $subtotal += (float)($en['amount'] ?? 0); }
      }
      // Strip the "[Budget review] " / "[Admin review] " prefix for cleaner display
      $revNote = preg_replace('/^\[[^\]]+\]\s*/', '', $rev['note'] ?? '');
    ?>
      <a href="ppmp-edit.php?fy=<?= (int)$d['fy'] ?>" class="folder" style="border: 2px solid var(--orange-500); background: var(--white);">
        <span class="folder-badge" style="background: var(--orange-600); color: white; border: 1px solid var(--orange-600);">For revision</span>
        <div class="folder-icon" style="background: var(--orange-100); color: var(--orange-600);">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg></div>
        <div class="folder-info" style="min-width: 0;">
          <div class="folder-code">PPMP · FY <?= (int)$d['fy'] ?></div>
          <div class="folder-full" style="color: var(--orange-600); font-weight: 600;">Click to revise &amp; resubmit</div>
          <?php if ($revNote): ?>
            <div style="margin-top: 8px; padding: 8px 10px; background: white; border: 1px solid var(--orange-100); border-radius: 8px; font-size: 11.5px; color: var(--ink); line-height: 1.5;">
              <strong style="color: var(--orange-600);">Note:</strong> <?= e(mb_strlen($revNote) > 120 ? mb_substr($revNote, 0, 120) . '…' : $revNote) ?></div>
          <?php endif; ?>
          <div class="folder-meta" style="margin-top: 8px;">
            <span><?= $entryCount ?> entrie<?= $entryCount === 1 ? '' : 's' ?></span>
            <span class="dot"></span>
            <span>₱<?= number_format($subtotal, 2) ?></span>
            <?php if (!empty($rev['requested_by'])): ?>
              <span class="dot"></span>
              <span>from <?= e($rev['requested_by']) ?></span>
            <?php endif; ?></div></div></a>
    <?php endforeach; ?></div>
<?php endif; ?>

<?php if ($plainDrafts): ?>
  <div class="section-head" style="margin-bottom: 12px;">
    <div>
      <h2 class="section-title" style="color: var(--orange-600);">📝 Drafts in progress</h2>
      <p class="section-sub">Unsubmitted PPMPs — click any to continue editing. Autosave keeps your work.</p></div></div>
  <div class="folder-grid" style="margin-bottom: 24px;">
    <?php foreach ($plainDrafts as $d):
      $entryCount = 0; $subtotal = 0;
      foreach (($entries[$d['id']] ?? []) as $catEntries) {
        foreach ($catEntries as $en) { $entryCount++; $subtotal += (float)($en['amount'] ?? 0); }
      }
    ?>
      <div style="position: relative;">
        <a href="ppmp-edit.php?fy=<?= (int)$d['fy'] ?>" class="folder" style="border: 2px solid var(--orange-500); background: var(--white);">
          <span class="folder-badge" style="background: var(--accent); color: var(--accent-ink); border: 1px solid var(--orange-600);">Draft</span>
          <div class="folder-icon" style="background: var(--orange-100); color: var(--orange-600);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg></div>
          <div class="folder-info">
            <div class="folder-code">PPMP · FY <?= (int)$d['fy'] ?></div>
            <div class="folder-full">Continue editing</div>
            <div class="folder-meta">
              <span><?= $entryCount ?> entrie<?= $entryCount === 1 ? '' : 's' ?></span>
              <span class="dot"></span>
              <span>₱<?= number_format($subtotal, 2) ?></span></div></div></a>
        <form method="POST" action="api/archive-draft-ppmp.php" style="position: absolute; top: 12px; right: 12px; margin: 0;"
              data-confirm="Move the draft PPMP for FY <?= (int)$d['fy'] ?> to the archive? You can restore it later from the Archive page."
              data-confirm-title="Archive draft?" data-confirm-label="Archive" data-confirm-tone="info">
          <input type="hidden" name="ppmp_id" value="<?= (int)$d['id'] ?>">
          <button type="submit" title="Archive this draft" aria-label="Archive draft"
                  style="width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border); background: white; color: var(--navy-700); cursor: pointer; display: grid; place-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg></button></form></div>
    <?php endforeach; ?></div>
<?php endif; ?>

    <div class="section-head">
      <div>
        <h2 class="section-title">My PPMP folders</h2>
        <p class="section-sub">Each procurement category is a designated folder containing your fiscal-year files.</p></div></div>

    <div class="folder-grid">
      <?php foreach ($CATEGORIES as $cat):
        $files = filesForCategory($cat['code'], $myPPMPs, $entries);
        $totalEntries = array_sum(array_column($files, 'entries'));
        $hasReq = false;
        foreach ($files as $f) { if (isset($editReqByFY[$f['fy']])) { $hasReq = true; break; } }
      ?>
        <a href="?category=<?= e($cat['code']) ?>" class="folder<?= $hasReq ? ' has-edit-req' : '' ?>">
          <?php if ($hasReq): ?><span class="folder-badge">Edit request</span><?php endif; ?>
          <div class="folder-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
          <div class="folder-info">
            <div class="folder-code"><?= e($cat['name']) ?></div>
            <div class="folder-full"><?= e($cat['desc']) ?></div>
            <div class="folder-meta">
              <span><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></span>
              <span class="dot"></span>
              <span><?= $totalEntries ?> total entries</span></div></div></a>
      <?php endforeach; ?></div>

    <?php if ($__past): /* v88: past fiscal years — restored from the Archive */ ?>
    <div class="section-head" id="pastYears" style="margin-top: 24px;">
      <div><h2 class="section-title">📂 Restored</h2>
        <p class="section-sub">Documents restored from the Archive · view only</p></div></div>
    <div class="folder-grid">
      <?php foreach ($__past as $__fy => $__docs): $__n = count($__docs); ?>
        <a href="user-dashboard.php?year=<?= (int)$__fy ?>" class="folder past-year-folder">
          <div class="folder-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
          <div class="folder-info"><div class="folder-code">FY <?= (int)$__fy ?></div>
            <div class="folder-full">Restored from the Archive</div>
            <div class="folder-meta"><span><?= $__n ?> file<?= $__n === 1 ? '' : 's' ?></span><span class="dot"></span><span>View only</span></div></div></a>
      <?php endforeach; ?></div>
    <?php endif; ?>

<?php else:
  // ── FILE LIST VIEW inside a category folder ──
  $cat = null;
  foreach ($CATEGORIES as $c) if ($c['code'] === $categoryOpen) { $cat = $c; break; }
  if (!$cat) { header('Location: user-dashboard.php'); exit; }
  $files = filesForCategory($cat['code'], $myPPMPs, $entries);
?>
    <div class="breadcrumb">
      <a href="user-dashboard.php">My PPMPs</a>
      <span>›</span>
      <span><?= e($cat['name']) ?></span></div>

    <div class="section-head">
      <div>
        <h2 class="section-title"><?= e($cat['name']) ?></h2>
        <p class="section-sub"><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?> · <?= e($cat['desc']) ?></p></div>
      <a href="user-dashboard.php" class="btn btn-ghost">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back to folders
      </a></div>

    <?php if (!$files): ?>
      <div style="background: white; border: 1px dashed var(--border); border-radius: 14px; padding: 40px 20px; text-align: center; color: var(--muted); font-size: 14px;"> No <?= e($cat['name']) ?> files yet. <a href="new-ppmp.php" style="color: var(--navy-500); font-weight: 600;">Start a new PPMP</a> to add one.
      </div>
    <?php else: ?>
      <div class="files-list">
      <?php foreach ($files as $f):
        $req = $editReqByFY[$f['fy']] ?? null;
      ?>
        <div class="file<?= $req ? ' has-edit-req' : '' ?>">
          <div class="file-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
          <div>
            <div class="file-name"> FY <?= (int)$f['fy'] ?> · <?= e($cat['short']) ?>
              <?php if ($req): ?><span class="edit-req-tag">Edit request</span><?php endif; ?></div>
            <div class="file-meta">
              <span><?= $f['entries'] ?> <?= $f['entries'] === 1 ? 'entry' : 'entries' ?></span>
              <span class="dot"></span>
              <span>Updated <?= e(date('M j, Y', strtotime($f['updated']))) ?></span>
              <span class="dot"></span>
              <span>By <?= e($u['full_name']) ?></span></div></div>
          <span class="file-amount">₱<?= number_format($f['amount'], 2) ?></span>
          <a href="ppmp-edit.php?fy=<?= (int)$f['fy'] ?>&category=<?= e($cat['code']) ?>"
             class="btn <?= $req ? 'btn-primary' : 'btn-ghost' ?>"
             style="padding: 8px 14px; font-size: 13px;">
            <?= $req ? 'Review request' : 'Open file' ?></a></div>

        <?php if ($req): ?>
        <div class="edit-req-banner" style="margin-top: -4px;">
          <div class="edit-req-banner-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg></div>
          <div style="flex: 1;">
            <div class="edit-req-title">Reason from <?= e($req['requested_by']) ?></div>
            <div class="edit-req-desc"><?= nl2br(e($req['reason'])) ?></div>
            <div style="margin-top: 12px; display: flex; gap: 8px;">
              <form method="POST" action="api/respond-edit.php" style="display: inline;" data-confirm="This edit request will be declined." data-confirm-title="Decline edit request?" data-confirm-label="Decline" data-confirm-tone="danger">
                <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                <input type="hidden" name="response" value="declined">
                <input type="hidden" name="return_to" value="user-dashboard.php?category=<?= e($cat['code']) ?>">
                <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px;">Decline</button></form>
              <form method="POST" action="api/respond-edit.php" style="display: inline;"
                    data-confirm="<?= in_array((string)($__er['doc_type'] ?? ''), ['', 'ppmp'], true)
                      ? 'The Procurement Unit will be able to edit this PPMP until it gives final approval or sends the PPMP back to you.'
                      : 'The Procurement Unit will be able to edit this ' . e(PU_EDIT_DOCS[(string)$__er['doc_type']] ?? 'document') . ' until it approves it or sends it back to you.' ?>"
                    data-confirm-title="Grant edit access?" data-confirm-label="Grant access" data-confirm-tone="info">
                <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                <input type="hidden" name="response" value="granted">
                <input type="hidden" name="return_to" value="user-dashboard.php?category=<?= e($cat['code']) ?>">
                <button type="submit" class="btn btn-primary" style="padding: 8px 14px; font-size: 13px;"> Grant edit access
                </button></form></div></div></div>
        <?php endif; ?>
      <?php endforeach; ?></div>
    <?php endif; ?>
<?php endif; ?></main></div></body></html>
