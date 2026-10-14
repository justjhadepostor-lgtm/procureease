<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/ppmp-expense.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/appcse-review.php';
require_login();  // any signed-in user; ownership is checked below
ensure_admin_tables();
$u = current_user();

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT p.*, d.name AS dept_name, d.code AS dept_code
                         FROM ppmps p
                    LEFT JOIN departments d ON d.code = p.dept_code
                        WHERE p.id = ?');
$stmt->execute([$id]);
$ppmp = $stmt->fetch();

// Where to send someone who can't view this PPMP
$__home = ($u['role'] === 'admin') ? 'admin-dashboard.php'
        : (($u['role'] === 'budget') ? 'budget-dashboard.php' : 'user-dashboard.php');

if (!$ppmp) {
    header('Location: ' . $__home);
    exit;
}

// Access rule: admin + budget can view any PPMP; a regular user can only
// view PPMPs belonging to their own department.
$__role = $u['role'] ?? 'user';
if ($__role !== 'admin' && $__role !== 'budget') {
    if (($u['dept_code'] ?? '') !== ($ppmp['dept_code'] ?? '')) {
        flash('error', 'You can only view your own department\'s PPMPs.');
        header('Location: ' . $__home);
        exit;
    }
}
// v90: the Budget Unit sees a PPMP once it is submitted; the Procurement Unit once the Budget Unit approved it
if (($__role === 'admin' || $__role === 'budget') && !ppmp_visible_to($ppmp, $u)) {
    flash('error', $__role === 'admin'
        ? 'This PPMP is not with the Procurement Unit yet. It opens here once the Budget Unit approves it.'
        : 'This PPMP is still being prepared by the department. It opens here once it is submitted.');
    header('Location: ' . $__home);
    exit;
}

$stmt = db()->prepare('SELECT * FROM ppmp_entries WHERE ppmp_id = ? ORDER BY id');
$stmt->execute([$id]);
$entries = $stmt->fetchAll();

// Check for pending revision request
$stmt = db()->prepare('SELECT * FROM revision_requests WHERE dept_code = ? AND fy = ? AND resolved = 0 ORDER BY requested_at DESC LIMIT 1');
$stmt->execute([$ppmp['dept_code'], $ppmp['fy']]);
$revReq = $stmt->fetch();

// Categories map
$CAT_LABELS = ['training' => 'TRAINING', 'consulting' => 'CONSULTING', 'nonps' => 'NON-PS', 'appcse' => 'APP-CSE'];

// Totals
$grand = 0; $catTotals = ['training'=>0,'consulting'=>0,'nonps'=>0,'appcse'=>0];
foreach ($entries as $en) {
    $grand += (float)$en['amount'];
    if (isset($catTotals[$en['category']])) $catTotals[$en['category']] += (float)$en['amount'];
}

// ─── Alloted budget (set by admin/budget for this dept-FY) ─────────
$__alloted = 0; $__allotedNotes = ''; $__allotedBy = ''; $__allotedAt = null;
try {
    $bstmt = db()->prepare('SELECT alloted_budget, notes, set_by, updated_at FROM dept_budgets WHERE dept_code = ? AND fy = ?');
    $bstmt->execute([$ppmp['dept_code'], (int)$ppmp['fy']]);
    if ($b = $bstmt->fetch()) {
        $__alloted      = (float)$b['alloted_budget'];
        $__allotedNotes = $b['notes'] ?? '';
        $__allotedBy    = $b['set_by'] ?? '';
        $__allotedAt    = $b['updated_at'];
    }
} catch (Throwable $t) { /* table may not exist yet */ }
$__projected = $grand;
$__variance  = $__alloted - $__projected;

// Render inside the standard app shell so the PPMP is "connected" to the admin
// and budget interfaces (same topbar + role sidebar), not a standalone page.
$activeNav = 'ppmps';
$pageTitle = $ppmp['dept_code'] . ' FY ' . (int)$ppmp['fy'] . ' PPMP';
// This document renders with the format of its own fiscal year,
// not today's, so a filed document keeps the form it was filed under.
doc_fmt_use_year((int)($ppmp['fy'] ?? 0));
require_once __DIR__ . '/includes/head.php';
?>
<style>
  .main { min-width: 0; }
  .toolbar { background: white; border: 1px solid var(--border); border-radius: 12px; padding: 12px 16px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
  .toolbar-left, .toolbar-right { display: flex; align-items: center; gap: 10px; }
  /* The buttons wrap onto a second line instead of running past the card
     (Archive and the approval buttons went off the edge below 1600px). */
  .toolbar-left, .toolbar-right { flex-wrap: wrap; min-width: 0; }
  .toolbar-right { justify-content: flex-end; margin-left: auto; }
  body:not([data-theme="dark"]) .rt-title { color: #2f578a !important; }   /* 3.86:1 -> 7.0:1 */
  /* Where the paper is. Exactly one of these renders at a time — see the
     toolbar — so they all look the same and differ only in colour. */
  .wf-status { padding: 6px 14px; border-radius: 999px; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em; font-family: 'Space Grotesk', sans-serif;
    white-space: nowrap; border: 1.5px solid; }
  .back-btn { background: none; border: none; padding: 8px 12px; border-radius: 8px; color: #133458; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
  .back-btn:hover { background: var(--navy-50); }
  .file-crumb { display: flex; align-items: center; gap: 10px; padding-left: 12px; border-left: 1px solid var(--border); }
  .file-crumb-icon { width: 28px; height: 28px; border-radius: 6px; background: var(--navy-100); color: var(--navy-500); display: grid; place-items: center; }
  .readonly-tag { display: inline-block; background: var(--navy-100); color: #133458; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.06em; margin-left: 6px; }
  .rev-banner { background: var(--orange-50); border-bottom: 1px solid var(--orange-100); padding: 12px 24px; display: flex; align-items: center; gap: 12px; font-size: 13.5px; color: #8A6D1E; }
  .rev-banner-icon { width: 28px; height: 28px; border-radius: 50%; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; }
  .paper-container { padding: 0 0 40px; display: block; }
  .paper { width: 100%; max-width: 100%; padding: 24px 26px; background: white; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 2px 8px rgba(41, 54, 129, 0.06); color: #0F172A; font-size: 10pt; line-height: 1.45; }
  .letterhead { display: flex; align-items: center; gap: 18px; border-bottom: 3px solid var(--navy-900); padding-bottom: 14px; margin-bottom: 24px; }
  .letterhead-mark { width: 88px; height: 88px; object-fit: contain; flex-shrink: 0; }
  .letterhead-title { font-family: 'Space Grotesk', sans-serif; font-size: 15pt; font-weight: 700; color: var(--navy-900); }
  .letterhead-sub { font-size: 9pt; color: #444; margin-top: 4px; }
  .letterhead-tag { font-size: 8pt; color: var(--orange-600); text-transform: uppercase; letter-spacing: 0.14em; font-weight: 700; margin-top: 4px; }
  .doc-title { text-align: center; margin-bottom: 22px; }
  .doc-title h1 { font-family: 'Space Grotesk', sans-serif; font-size: 18pt; font-weight: 700; color: var(--navy-900); text-transform: uppercase; margin-bottom: 6px; }
  .doc-title .subtitle { font-size: 11pt; color: #333; font-weight: 500; }
  .meta-box { border: 1px solid #ccc; padding: 14px 18px; margin-bottom: 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px 24px; background: #F0F7F6; }
  .meta-item strong { display: block; color: #666; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; font-size: 7.5pt; margin-bottom: 3px; }
  .meta-item span { font-size: 10pt; font-weight: 500; }
  .section-heading { font-family: 'Space Grotesk', sans-serif; font-size: 11pt; font-weight: 700; color: var(--navy-900); margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid var(--navy-100); text-transform: uppercase; letter-spacing: 0.06em; }
  table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 8px; }
  th { background: white; color: #000; padding: 9px 10px; text-align: left; font-weight: 700; font-size: 8pt; text-transform: uppercase; border: 1px solid #000; }
  th.num { text-align: right; }
  td { padding: 9px 10px; border-bottom: 1px solid #B4E1EB; vertical-align: top; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }
  tbody tr:nth-child(even) td { background: #F0F7F6; }
  tr.total-row td { background: var(--navy-50) !important; border-top: 2px solid var(--navy-900); border-bottom: 2px solid var(--navy-900); color: var(--navy-900); font-weight: 700; font-size: 10pt; padding: 12px 10px; }
  .cat-tag { display: inline-block; font-size: 7pt; font-weight: 700; padding: 2px 7px; border-radius: 3px; text-transform: uppercase; letter-spacing: 0.06em; margin-right: 6px; }
  .cat-tag.training { background: var(--navy-100); color: #133458; }
  .cat-tag.consulting { background: var(--orange-100); color: var(--orange-600); }
  .cat-tag.nonps { background: #B4E1EB; color: var(--muted); }
  .cat-tag.appcse { background: var(--navy-800); color: white; }
  .cert-block { margin: 30px 0 24px; font-size: 9.5pt; color: #333; line-height: 1.6; padding: 14px 18px; border-left: 3px solid var(--navy-500); background: #F0F7F6; }
  .signatures { margin-top: 40px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; }
  .signature { text-align: center; font-size: 9pt; }
  .sig-label { text-transform: uppercase; font-size: 7.5pt; color: #666; font-weight: 700; letter-spacing: 0.08em; margin-bottom: 40px; }
  .sig-line { border-bottom: 1px solid #333; margin: 0 8px 6px; }
  .sig-name { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 10pt; }
  .sig-position { color: #666; font-size: 8.5pt; margin-top: 2px; }
  .xb-note { margin: 10px 0 0 auto; max-width: 560px; font-size: 11.5px; line-height: 1.5; padding: 8px 12px; border-radius: 8px;
             border: 1px solid #f5c99b; background: #fff7ed; color: #7c2d12; }
  body[data-theme="dark"] .paper .xb-note { color: #7c2d12; }
  @media print { .xb-note { display: none !important; } }
  @media print { .topbar, .sidebar, .toolbar, .rev-banner { display: none !important; } .main { padding: 0 !important; } .paper-container { padding: 0; overflow: visible; } .paper { box-shadow: none; border: none; width: auto; } @page { size: A4 landscape; margin: 10mm 8mm; } }
</style>
<div class="app">
<?php
  if     ($__role === 'admin')  require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($__role === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
  else                          require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
<?php render_flashes(); /* e.g. why an edit was refused after final approval */ ?>
<?php /* v99: the office's own PPMP — which set, ＋ Add Set (includes/unit-switch.php) */
  if (strcasecmp((string)($u['dept_code'] ?? ''), (string)$ppmp['dept_code']) === 0 && (int)$ppmp['fy'] === active_fy()) {
      require_once __DIR__ . '/includes/unit-switch.php';
      $__sb = set_bar_html($u, (int)$ppmp['fy'], false);
      // v104: My PPMPs opens here once the PPMP is submitted — a school's program / major is chosen here too (then its PPMP opens)
      if ($__sb === '') $__sb = unit_pick_html($u, 'ppmp-edit.php', (int)$ppmp['fy']);
      if ($__sb !== '') echo '<div style="margin: 0 0 14px;">' . $__sb . '</div>';
      // v104: My PPMPs opens the PPMP — its deadline banner and the Procurement Unit's edit requests come along
      require_once __DIR__ . '/includes/dept-notices.php'; pe_dept_notices($u);
  } ?>
<?php /* v77: the APP-CSE goes to the Procurement Unit on its own, so it has no note here */ ?>
<?php
  // "✓ Submitted" — where it is now, so nobody has to go and check
  if (empty($revReq)) {
      $__st = (string)($ppmp['status'] ?? 'draft');
      $__to = $__st === 'approved'
            ? 'Final approval given by the Procurement Unit' . (!empty($ppmp['admin_reviewed_at']) ? ' on ' . date('M j, Y', strtotime($ppmp['admin_reviewed_at'])) : '') . '.'
            : ($__st === 'budget_approved' ? 'approved by the Budget Unit, now with the Procurement Unit'
               : (ppmp_skips_budget($ppmp['dept_code'] ?? '') ? 'now with the Procurement Unit' : 'now with the Budget Unit'));
      echo submitted_stamp($__st, $ppmp['submitted_at'] ?? null, $ppmp['submitted_by'] ?? null, $__to);
  }
?>

<div class="toolbar">
  <div class="toolbar-left">
    <a href="<?= $u['role'] === 'admin' ? 'admin-dashboard.php?dept=' . e($ppmp['dept_code']) : $__home ?>" class="back-btn">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back
    </a>
    <div class="file-crumb">
      <div class="file-crumb-icon">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
      <div>
        <div style="font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:600;"><?= e($ppmp['dept_code']) ?> · FY <?= (int)$ppmp['fy'] ?> PPMP</div>
        <div style="font-size:11px;color:var(--muted);"><?= e($ppmp['submitted_by'] ?? 'Not yet submitted') ?><span class="readonly-tag">Read only</span></div></div></div></div>
  <div class="toolbar-right" data-dock>
    <?php /* v111: the office's restored PPMPs — in their own folder ("Restored"), not under its current PPMP */
      if (strcasecmp((string)($u['dept_code'] ?? ''), (string)$ppmp['dept_code']) === 0 && (int)$ppmp['fy'] === active_fy()) {
          require_once __DIR__ . '/config/past-years.php';
          $__rest = past_year_ppmps_of((string)$u['dept_code']);
          if ($__rest) echo '<a href="user-dashboard.php?restored=1" id="restoredBtn" data-no-dock class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; text-decoration: none;" title="PPMPs of earlier years restored from the Archive (view only)">📂 Restored (' . count($__rest) . ')</a>';
      } ?>
    <a href="ppmp-excel.php?id=<?= (int)$ppmp['id'] ?>" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; text-decoration: none; color: var(--green-500);"> Download Excel
    </a>
    <?php if (($u['role'] ?? '') === 'admin' && ($ppmp['status'] ?? 'draft') !== 'draft' && ($ppmp['dept_code'] ?? '') !== ($u['dept_code'] ?? '')): ?>
      <?php /* v77: the APP-CSE goes to the Procurement Unit on its own; this opens it in APP-CSE Folders */ ?>
      <a href="appcse-review.php?fy=<?= (int)$ppmp['fy'] ?>&amp;dept=<?= urlencode($ppmp['dept_code']) ?>"
         class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; text-decoration: none;"> APP-CSE
      </a>
    <?php endif; ?>
    <?php require_once __DIR__ . '/config/doc-versions.php'; echo doc_history_button('ppmp', (string)(int)$ppmp['id']);   /* v79: version history */ ?>
    <?php if (($u['role'] ?? '') === 'admin'): ?>
      <div style="position: relative;" id="printMenuWrap">
        <button type="button" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; display: flex; align-items: center; gap: 6px;" onclick="togglePrintMenu(event)"> Print PPMP
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></button>
        <div id="printMenu" style="display: none; position: absolute; top: calc(100% + 6px); right: 0; background: white; border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 8px 24px rgba(41, 54, 129, 0.15); min-width: 300px; z-index: 60; overflow: hidden;">
          <a href="ppmp-print.php?id=<?= (int)$ppmp['id'] ?>&mode=final" target="_blank" style="display: block; padding: 14px 16px; text-decoration: none; color: var(--ink); border-bottom: 1px solid var(--border);" onmouseover="this.style.background='var(--navy-50)'" onmouseout="this.style.background=''">
            <div style="font-weight: 700; font-size: 13.5px; margin-bottom: 3px; color: #133458;">✓ Print as FINAL</div>
            <div style="font-size: 11.5px; color: var(--muted); line-height: 1.5;">Force the FINAL checkbox. Use for approved/consolidated copies.</div></a>
          <a href="ppmp-print.php?id=<?= (int)$ppmp['id'] ?>&mode=indicative" target="_blank" style="display: block; padding: 14px 16px; text-decoration: none; color: var(--ink); border-bottom: 1px solid var(--border);" onmouseover="this.style.background='var(--navy-50)'" onmouseout="this.style.background=''">
            <div style="font-weight: 700; font-size: 13.5px; margin-bottom: 3px; color: var(--orange-600);">✏ Print as INDICATIVE</div>
            <div style="font-size: 11.5px; color: var(--muted); line-height: 1.5;">Force the INDICATIVE checkbox. Use for planning/draft copies.</div></a>
          <a href="ppmp-print.php?id=<?= (int)$ppmp['id'] ?>" target="_blank" style="display: block; padding: 14px 16px; text-decoration: none; color: var(--ink);" onmouseover="this.style.background='var(--navy-50)'" onmouseout="this.style.background=''">
            <div style="font-weight: 700; font-size: 13.5px; margin-bottom: 3px; color: var(--ink);"> Print as saved</div>
            <div style="font-size: 11.5px; color: var(--muted); line-height: 1.5;">Use the status currently stored on the PPMP (<?= $ppmp['is_final'] ? '<strong>FINAL</strong>' : '<strong>INDICATIVE</strong>' ?>).</div></a></div></div>
      <script> function togglePrintMenu(e) {
          e.stopPropagation();
          const m = document.getElementById('printMenu');
          m.style.display = m.style.display === 'none' ? 'block' : 'none';
        }
        document.addEventListener('click', (e) => {
          if (!e.target.closest('#printMenuWrap')) document.getElementById('printMenu').style.display = 'none';
        });
      </script>
    <?php else: ?>
      <a href="ppmp-print.php?id=<?= (int)$ppmp['id'] ?>" target="_blank" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; text-decoration: none;"> Print PPMP
      </a>
    <?php endif; ?>

    <?php
    $role = $u['role'] ?? '';
    $isBudget = ($role === 'budget');
    $isAdmin  = ($role === 'admin');
    // Anyone in the department that the PPMP belongs to. dept_admin belongs
    // here too — it is the role that submits, so it is the role that has to
    // be able to pull a submission back and to archive a finished one.
    $isOwner  = in_array($role, ['user', 'budget', 'dept_admin'], true)
                && ($u['dept_code'] === $ppmp['dept_code']);
    $canBudgetReview = $isBudget && $ppmp['status'] === 'submitted' && $u['dept_code'] !== $ppmp['dept_code'];
    // The Budget Unit's own PPMP is reviewed by the Procurement Unit alone,
    // so for that one department 'submitted' is already final-approval ready.
    $__ownReview     = ppmp_skips_budget($ppmp['dept_code']);
    $canAdminApprove = $isAdmin  && ($ppmp['status'] === 'budget_approved'
                                     || ($__ownReview && $ppmp['status'] === 'submitted'));
    $waitingBudget   = $isAdmin  && $ppmp['status'] === 'submitted' && !$__ownReview;
    $fullyApproved   = $ppmp['status'] === 'approved';
    // Unsubmitting is the other half of submitting, so it follows the same
    // rule: whoever may submit for the department may pull it back — while
    // it is still 'submitted' and the Budget Unit has not opened it yet.
    $canUnsubmit     = $isOwner  && can_submit_for_dept($u)
                       && $ppmp['status'] === 'submitted' && empty($ppmp['budget_reviewed_at']);
    // A past year's PPMP, restored from the Archive, is for viewing only
    $__pastYear = fy_is_past((int)$ppmp['fy']);
    if ($__pastYear) { $canBudgetReview = $canAdminApprove = $canUnsubmit = $waitingBudget = false; }
    ?>
    <?php if ($__pastYear): ?>
      <span class="wf-status" id="pastYearTag" style="background: var(--navy-50); color: var(--ink); border-color: var(--navy-500); border-style: dashed;"
            title="Restored from the Archive. Not counted in FY <?= active_fy() ?> or in any consolidation.">FY <?= (int)$ppmp['fy'] ?> · past year · view only</span>
    <?php endif; ?>

    <?php
    /* Where the paper is, in one chip, in the same five words the PPMP
       Tracking page uses. It is shown only when the two status spans
       further down this toolbar do not already say it, so the page never
       states the status twice; and it stands down when the PPMP has been
       sent back, because the revision banner below announces that along
       with the reviewer's note. */
    /* Colours measured on THIS page's palette, in both themes. The document
       pages (data-nav="ppmps" and its siblings) carry their own lighter
       palette where --navy-700 is #4F7DAE and --orange-600 is a pale yellow,
       so the obvious tokens would have given 3.28:1 and worse. --ink is the
       one text token that is dark on light and light on dark here. */
    $__where = 'With the department';
    $__wbg   = 'var(--navy-50)'; $__wfg = 'var(--ink)'; $__wbd = 'var(--border)';
    if (!empty($revReq)) {
        $__where = 'Sent back';
        $__wbg = 'var(--orange-100)'; $__wfg = 'var(--ink)'; $__wbd = 'var(--orange-500)';
    } elseif ($ppmp['status'] === 'submitted') {
        $__where = 'With Budget Unit';
        $__wbg = 'var(--navy-100)'; $__wfg = 'var(--ink)'; $__wbd = 'var(--navy-500)';
    } elseif ($ppmp['status'] === 'budget_approved') {
        $__where = 'With Procurement Unit';
        $__wbg = 'var(--navy-100)'; $__wfg = 'var(--ink)'; $__wbd = 'var(--navy-500)';
    } elseif ($ppmp['status'] === 'approved') {
        $__where = 'Approved';
        $__wbg = 'var(--green-50)'; $__wfg = '#14532d'; $__wbd = '#86efac';
    }
    $__showWhere = !$waitingBudget && !$fullyApproved && empty($revReq);
    ?>
    <?php if ($__showWhere): ?>
      <span class="wf-status" style="background: <?= $__wbg ?>; color: <?= $__wfg ?>; border-color: <?= $__wbd ?>;">
        <?= e($__where) ?></span>
    <?php endif; ?>

    <?php if ($canUnsubmit): ?>
      <button class="btn btn-ghost" onclick="openUnsubmitModal()" style="padding: 8px 14px; font-size: 13px; color: var(--orange-600); background: var(--orange-50); border-color: var(--orange-100);">
        ↩ Unsubmit
      </button>
    <?php endif; ?>

    <?php if ($canAdminApprove):
      /* With the Procurement Unit: it may edit the PPMP until it gives final
         approval (ppmp-edit.php?id=…) — once the department grants permission
         (config/edit-permission.php). */
      require_once __DIR__ . '/config/edit-permission.php';
      $__pe = pu_edit_state($ppmp, $u); ?>
      <?php if (in_array($__pe['state'], ['own', 'granted'], true)): ?>
      <a href="ppmp-edit.php?id=<?= (int)$ppmp['id'] ?>" class="btn btn-ghost" id="puEditBtn" style="padding: 8px 14px; font-size: 13px; text-decoration: none;"> ✎ Edit PPMP
      </a>
      <?php elseif ($__pe['state'] === 'pending'): ?>
      <span class="wf-status" id="puEditPending" style="background: var(--navy-50); color: var(--ink); border-color: var(--navy-500);">
        Edit request sent · waiting for <?= e($ppmp['dept_code']) ?></span>
      <?php else: ?>
      <button type="button" class="btn btn-ghost" id="puEditRequestBtn" onclick="openEditReqModal()" style="padding: 8px 14px; font-size: 13px;"> ✎ Request edit permission
      </button>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($canBudgetReview || $canAdminApprove): ?>
      <button class="btn btn-ghost" onclick="openRevModal()" style="padding: 8px 14px; font-size: 13px; color: var(--orange-600); background: var(--orange-50); border-color: var(--orange-100);"> Request revision
      </button>
    <?php endif; ?>

    <?php if ($canBudgetReview): ?>
      <button class="btn btn-primary" onclick="openApproveModal('budget')" style="padding: 8px 14px; font-size: 13px; background: #15803d; border-color: #15803d;"> Approve → forward to Admin
      </button>
    <?php elseif ($canAdminApprove): ?>
      <button class="btn btn-primary" onclick="openApproveModal('admin')" style="padding: 8px 14px; font-size: 13px; background: #15803d; border-color: #15803d;"> Approve (final)
      </button>
      <?php /* No "Request edit permission": the Procurement Unit edits the
               PPMP itself with ✎ Edit PPMP (above) while it is with them. */ ?>
    <?php elseif ($waitingBudget): ?>
      <span class="wf-status" style="background: var(--navy-100); color: var(--ink); border-color: var(--navy-500);">With Budget Unit
      </span>
    <?php elseif ($fullyApproved): ?>
      <span class="wf-status" style="background: var(--green-50); color: #14532d; border-color: #86efac;">
        ✓ Approved <?= $ppmp['admin_reviewed_at'] ? '· ' . date('M j, Y', strtotime($ppmp['admin_reviewed_at'])) : '' ?></span>
    <?php endif; ?>

    <?php
      $canArchive = $isAdmin || $isOwner;
      $alreadyArchived = false;
      // A department cannot archive its PPMP while Budget or the PU is reviewing it
      if ($canArchive && !$isAdmin) {
          $__ad = arc_load('ppmp', (int)$ppmp['id']);
          if ($__ad && !$__ad['archived'] && !arc_can($u, $__ad, 'archive')[0]) $canArchive = false;
      }
      try {
          $chk = db()->prepare('SELECT 1 FROM archived_ppmps WHERE ppmp_id = ?');
          $chk->execute([(int)$ppmp['id']]);
          $alreadyArchived = (bool)$chk->fetchColumn();
      } catch (Throwable $t) {}
    ?>
    <?php if ($canArchive && !$alreadyArchived): ?>
      <form method="POST" action="archive.php" style="margin: 0;" data-confirm="This PPMP will move to the Archive, where you can restore it or delete it later." data-confirm-title="Archive this PPMP?" data-confirm-label="Archive" data-confirm-tone="info">
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="ppmp_id" value="<?= (int)$ppmp['id'] ?>">
        <input type="hidden" name="reason" value="Archived by <?= e($u['full_name']) ?>">
        <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; color: #133458;"> Archive
        </button></form>
    <?php elseif ($canArchive && $alreadyArchived): ?>
      <?php
        // Get the archive row id for this PPMP so Restore/Delete can target it
        $__archId = 0;
        try {
            $aq = db()->prepare('SELECT id FROM archived_ppmps WHERE ppmp_id = ?');
            $aq->execute([(int)$ppmp['id']]);
            $__archId = (int)$aq->fetchColumn();
        } catch (Throwable $t) {}
      ?>
      <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; font-size: 12px; color: var(--muted); background: var(--navy-50); border: 1px solid var(--border); border-radius: 8px;"> In archive</span>

      <form method="POST" action="archive.php" style="margin: 0;" data-confirm="This brings the PPMP back out of the archive into the active system." data-confirm-title="Restore this PPMP?" data-confirm-label="Restore" data-confirm-tone="info">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="id" value="<?= $__archId ?>">
        <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; color: #133458;">↩ Restore</button></form>

      <?php if ($isAdmin): ?>
        <form method="POST" action="archive.php" style="margin: 0;" data-confirm="This moves the PPMP to Backup &amp; Restore, where it can still be recovered. It is not permanently deleted yet." data-confirm-title="Delete this PPMP?" data-confirm-label="Delete" data-confirm-tone="danger">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $__archId ?>">
          <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; color: var(--orange-600); background: var(--orange-50); border-color: var(--orange-100);">🗑 Delete</button></form>
      <?php else: ?>
        <form method="POST" action="archive.php" style="margin: 0;" data-confirm="Are you sure you want to delete this PPMP? This will permanently delete it and all its entries. This cannot be undone." data-confirm-title="Permanently delete this PPMP?" data-confirm-label="Delete permanently" data-confirm-tone="danger">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $__archId ?>">
          <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px; color: var(--orange-600); background: var(--orange-50); border-color: var(--orange-100);"> Delete permanently</button></form>
      <?php endif; ?>
    <?php endif; ?></div></div>

<?php if (!empty($ppmp['budget_reviewed_at'])): ?>
  <div style="margin: 16px auto; max-width: 900px; padding: 12px 16px; background: <?= $fullyApproved ? 'var(--green-50)' : 'var(--navy-50)' ?>; border: 1px solid <?= $fullyApproved ? '#86efac' : 'var(--border)' ?>; border-radius: 8px; font-size: 13px;">
    <div class="<?= $fullyApproved ? '' : 'rt-title' ?>" style="font-weight: 600; color: <?= $fullyApproved ? '#14532d' : 'var(--navy-700)' ?>; margin-bottom: 4px;"> Review timeline
    </div>
    <div style="color: var(--muted); line-height: 1.7;">
      <strong>Approved by Budget</strong> · <?= e($ppmp['budget_reviewed_by'] ?? '') ?> on
      <?= e(date('M j, Y g:i a', strtotime($ppmp['budget_reviewed_at']))) ?>
      <?php if (!empty($ppmp['budget_note'])): ?><br><em>"<?= e($ppmp['budget_note']) ?>"</em><?php endif; ?>
      <?php if (!empty($ppmp['pu_edited_at'])): ?>
        <br><strong>Edited by the Procurement Unit</strong> · <?= e($ppmp['pu_edited_by'] ?? '') ?> on
        <?= e(date('M j, Y g:i a', strtotime($ppmp['pu_edited_at']))) ?>
      <?php endif; ?>
      <?php if (!empty($ppmp['admin_reviewed_at'])): ?>
        <br><strong>Approved by the Procurement Unit</strong> · <?= e($ppmp['admin_reviewed_by'] ?? '') ?> on
        <?= e(date('M j, Y g:i a', strtotime($ppmp['admin_reviewed_at']))) ?>
        <?php if (!empty($ppmp['admin_note'])): ?><br><em>"<?= e($ppmp['admin_note']) ?>"</em><?php endif; ?>
      <?php endif; ?></div></div>
<?php endif; ?>




<?php if ($revReq): ?>
<div class="rev-banner">
  <div class="rev-banner-icon">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg></div>
  <div style="flex: 1;">
    <div id="revNoteDisplay">
      <strong style="color: var(--orange-600);">For Revision.</strong>
      <span id="revNoteText"><?= e($revReq['note']) ?></span>
      <?php if (($u['role'] ?? '') === 'budget'): ?>
        <button type="button" onclick="editRevNote()" style="margin-left: 8px; background: none; border: none; color: var(--orange-600); font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: underline;">✎ Edit</button>
      <?php endif; ?></div>
    <?php if (($u['role'] ?? '') === 'budget'): ?>
      <form id="revNoteForm" method="POST" action="api/budget-edit-revision.php" style="display: none; margin-top: 8px;">
        <input type="hidden" name="ppmp_id" value="<?= (int)$ppmp['id'] ?>">
        <textarea name="note" rows="2" required
                  style="width: 100%; padding: 8px 10px; border: 1px solid var(--orange-100); border-radius: 6px; font-family: inherit; font-size: 13px; background: white;"><?= e(preg_replace('/^\[Budget review\]\s*/', '', $revReq['note'])) ?></textarea>
        <div style="display: flex; gap: 8px; margin-top: 6px;">
          <button type="submit" class="btn btn-primary" style="padding: 6px 14px; font-size: 12px;">Save note</button>
          <button type="button" onclick="cancelEditRevNote()" class="btn btn-ghost" style="padding: 6px 14px; font-size: 12px;">Cancel</button></div></form>
      <script> function editRevNote() {
          document.getElementById('revNoteDisplay').style.display = 'none';
          document.getElementById('revNoteForm').style.display = 'block';
        }
        function cancelEditRevNote() {
          document.getElementById('revNoteForm').style.display = 'none';
          document.getElementById('revNoteDisplay').style.display = 'block';
        }
      </script>
    <?php endif; ?></div></div>
<?php endif; ?>

<div class="paper-container">
  <div class="paper" style="padding: 24px 26px; max-width: 100%; width: 100%;">
    <!-- ─── ASCOT Letterhead ─── -->
    <div style="display: grid; grid-template-columns: 90px 1fr 90px; align-items: center; gap: 10px; margin-bottom: 12px;">
      <div style="width: 90px; height: 90px; display: flex; align-items: center; justify-content: center;">
        <img src="<?= e(doc_logo('ppmp', 'left')) ?>" style="width: 82px; height: 82px; object-fit: contain;" alt="ASCOT Seal"></div>
      <div style="text-align: center;">
        <div style="font-size: 11px;"><?= e(doc_fmt('ppmp', 'rep_line')) ?></div>
        <div style="font-size: 18px; font-weight: 700; letter-spacing: 0.02em; margin: 1px 0;"><?= e(doc_fmt('ppmp', 'org_name')) ?></div>
        <div style="font-size: 10px; font-style: italic; margin-bottom: 8px;"><?= e(doc_fmt('ppmp', 'address')) ?></div>
        <div style="font-size: 13px; font-weight: 700; letter-spacing: 0.03em; margin-top: 4px;"> <?= e(doc_fmt('ppmp', 'doc_title')) ?>
          <span style="border-bottom: 1px solid #000; display: inline-block; min-width: 60px; padding: 0 8px; margin-left: 4px;"><?= e(os_ppmp_no($ppmp['ppmp_no'] ?? '', $ppmp['dept_code'] ?? '')) ?></span></div></div>
      <div style="width: 90px; height: 60px; background: url('<?= e(doc_logo('ppmp', 'right')) ?>') center/contain no-repeat;"></div></div>

    <!-- Indicative / Final -->
    <div style="display: flex; justify-content: center; gap: 30px; margin: 8px 0 12px; font-size: 11px; font-weight: 600;">
      <label><span style="display: inline-block; width: 12px; height: 12px; border: 1.5px solid #000; position: relative; vertical-align: middle;"><?= !$ppmp['is_final'] ? '<span style="position:absolute;top:-6px;left:0;font-size:14px;font-weight:bold;">✓</span>' : '' ?></span> INDICATIVE</label>
      <label><span style="display: inline-block; width: 12px; height: 12px; border: 1.5px solid #000; position: relative; vertical-align: middle;"><?= $ppmp['is_final'] ? '<span style="position:absolute;top:-6px;left:0;font-size:14px;font-weight:bold;">✓</span>' : '' ?></span> FINAL</label></div>

    <!-- Meta -->
    <div style="font-size: 11px; margin-bottom: 8px; display: grid; grid-template-columns: max-content 1fr; gap: 4px 18px; align-items: baseline;">
      <div style="font-weight: 700;">Fiscal Year:</div>
      <div style="border-bottom: 1px solid #000; padding: 0 4px;"><?= (int)$ppmp['fy'] ?></div>
      <div style="font-weight: 700;">End-User or Implementing Unit:</div>
      <div style="border-bottom: 1px solid #000; padding: 0 4px;"><?php require_once __DIR__ . '/config/ppmp-unit.php'; ?><?= e(ppmp_unit_label($ppmp, (string)($ppmp['dept_code'] ?? ''), (string)($ppmp['dept_name'] ?? ''))) /* v137 */ ?></div></div>

    <!-- 12-column ASCOT table (horizontally scrollable so long columns aren't crammed) -->
    <div style="overflow-x: auto; border: 1px solid #000; margin-bottom: 14px;">
    <?php /* v94: the columns of this PPMP's year (Document Format) */
      require_once __DIR__ . '/config/ppmp-columns.php';
      $__cols = ppmp_cols((int)$ppmp['fy']); $__hdr = ppmp_cols_header($__cols, (int)$ppmp['fy']);
      $__w = ['desc' => '13%', 'type' => '8%', 'qty' => '8%', 'mode' => '8%', 'pre' => '6%', 'start' => '5%', 'end' => '5%', 'deliv' => '6%', 'fund' => '6%', 'amount' => '8%'];
      $__thG = 'border: 1px solid #000; padding: 6px 4px; font-weight: 700; font-size: 10px; text-align: center; background: white;';
      $__thO = 'border: 1px solid #000; padding: 6px 3px; font-weight: 700; font-size: 9.5px; height: auto; line-height: 1.25; text-align: center;';
      $__thC = 'border: 1px solid #000; padding: 6px 3px; font-weight: 700; font-size: 9.5px; line-height: 1.25; text-align: center;';
      $__td  = 'border: 1px solid #000; padding: 5px 4px; font-size: 10px; line-height: 1.35; word-wrap: break-word; overflow-wrap: anywhere; vertical-align: top;'; ?>
    <table style="min-width: <?= max(900, (int)round(1720 * count($__cols) / 12)) ?>px; width: 100%; border-collapse: collapse; font-size: 10px; font-family: Arial, sans-serif;">
      <thead>
        <tr>
          <?php foreach ($__hdr['top'] as $__t): ?>
            <?php if (isset($__t['col'])): ?><th rowspan="2" style="<?= $__thO ?>"><?= e($__t['col']['label']) ?></th>
            <?php else: ?><th colspan="<?= (int)$__t['span'] ?>" style="<?= $__thG ?>"><?= e($__t['label']) ?></th><?php endif; ?>
          <?php endforeach; ?></tr>
        <tr>
          <?php foreach ($__hdr['second'] as $__c): ?><th style="<?= $__thC ?><?= isset($__w[$__c['id']]) ? ' width: ' . $__w[$__c['id']] . ';' : '' ?>"><?= e($__c['label']) ?></th><?php endforeach; ?></tr>
        <tr>
          <?php foreach ($__cols as $__i => $__c): ?>
            <th style="border: 1px solid #000; padding: 3px; font-weight: 700; font-style: italic; font-size: 10px; text-align: center; background: white;"><?= e(doc_fmt('ppmp', 'col_num_prefix')) ?> <?= $__i + 1 ?></th>
          <?php endforeach; ?></tr></thead>
      <tbody>
        <?php if (!$entries): ?>
          <?php for ($j = 0; $j < 2; $j++): ?>
            <tr>
              <?php foreach ($__cols as $__c): ?>
                <td style="border: 1px solid #000; padding: 8px 4px; text-align: center;">&nbsp;</td>
              <?php endforeach; ?></tr>
          <?php endfor; ?>
        <?php else: foreach ($entries as $en):
          $__x = ppmp_entry_extra($en);
          $__cell = [
            'desc'    => ['left',   e($en['description'])],
            'type'    => ['center', e($en['proj_type'] ?? '')],
            'qty'     => ['center', e($en['quantity'] ?? '')],
            'mode'    => ['center', e($en['mode'])],
            'pre'     => ['center', e($en['pre_conference'] ?? '')],
            'start'   => ['center', e($en['activity_start'])],
            'end'     => ['center', e($en['activity_end'])],
            'deliv'   => ['center', e($en['delivery'])],
            'fund'    => ['center', e($en['fund'])],
            'amount'  => ['right',  '₱' . number_format((float)$en['amount'], 2)],
            'docs'    => ['center', e($en['supporting_docs'] ?? '')],
            'remarks' => ['left',   e($en['remarks'])],
          ]; ?>
          <tr id="entry-<?= (int)$en['id'] ?>">
            <?php foreach ($__cols as $__c):
              [$__al, $__v] = $__c['builtin'] ? $__cell[$__c['id']] : ['center', e($__x[$__c['id']] ?? '')]; ?>
              <td style="<?= $__td ?> text-align: <?= $__al ?>;<?= $__c['id'] === 'amount' ? ' font-family: Arial;' : '' ?>"><?= $__v ?></td>
            <?php endforeach; ?></tr>
        <?php endforeach; endif; ?></tbody>
      <tfoot>
        <?php [$__b, $__a] = ppmp_cols_around_amount($__cols); ?>
        <tr>
          <td colspan="<?= $__b ?>" style="border: 1px solid #000; padding: 8px 10px; font-weight: 700; font-size: 10px; text-align: right;">TOTAL BUDGET:</td>
          <td style="border: 1px solid #000; padding: 8px 4px; font-weight: 700; font-size: 10px; text-align: right; font-family: Arial;">₱<?= number_format($grand, 2) ?></td>
          <?php if ($__a > 0): ?><td colspan="<?= $__a ?>" style="border: 1px solid #000;">&nbsp;</td><?php endif; ?></tr></tfoot></table>
    </div>


    <!-- Signature block -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 24px;">
      <div style="text-align: center; font-size: 10px;">
        <div style="text-align: left; font-weight: 600; margin-bottom: 34px;"><?= e(doc_fmt('ppmp', 'sig_prepared') ?: 'Prepared by:') ?></div>
        <div style="border-bottom: 1px solid #000; height: 1px; margin: 0 auto 2px; width: 70%;"></div>
        <div style="font-weight: 700; font-size: 11px; margin-top: 4px; text-transform: uppercase;"><?php $__ph = department_head($ppmp['dept_code'] ?? null); ?><?= e(($ppmp['sig_name'] ?? '') ?: ($__ph['name'] ?: ($ppmp['preparer'] ?? ''))) ?></div>
        <div style="font-size: 9px; font-style: italic; margin-bottom: 2px;">Signature over Printed Name</div>
        <div style="font-size: 10px; margin-top: 2px;"><?= e($ppmp['sig_position'] ?? '') ?></div>
        <div style="font-size: 8.5px; font-style: italic; color: #333;">[Head of the End-User or Implementing Unit]</div>
        <div style="text-align: left; margin-top: 20px; font-size: 10px;"> Date: <span style="border-bottom: 1px solid #000; display: inline-block; min-width: 120px; padding: 0 4px; margin-left: 4px;"><?= e($ppmp['date_prepared'] ?? '') ?></span></div></div>
      <div style="text-align: center; font-size: 10px;">
        <div style="text-align: left; font-weight: 600; margin-bottom: 34px;"><?= e(doc_fmt('ppmp', 'sig_approved') ?: 'Approved by:') ?></div>
        <div style="border-bottom: 1px solid #000; height: 1px; margin: 0 auto 2px; width: 70%;"></div>
<?php
        /* What is saved on the PPMP wins; a blank one is the department's
           own head, the same person the editor fills in. */
        $__ab = ppmp_approved_by((array)$ppmp);   // the VP, unless typed on purpose
        $__hd = $__ab['vp'];
        $__hdName = $__ab['name'];
        $__hdPos  = $__ab['position'];
      ?>
        <div style="font-weight: 700; font-size: 11px; margin-top: 4px; text-transform: uppercase;"><?= e($__hdName) ?></div>
        <div style="font-size: 9px; font-style: italic; margin-bottom: 2px;">Signature over Printed Name</div>
        <div style="font-size: 10px; margin-top: 2px;"><?= e($__hdPos) ?></div>
        <div style="text-align: left; margin-top: 20px; font-size: 10px;"> Date: <span style="border-bottom: 1px solid #000; display: inline-block; min-width: 120px; padding: 0 4px; margin-left: 4px;"><?= e($ppmp['date_submitted_head'] ?? '') ?></span></div></div></div></div></div>

<!-- Unsubmit modal (department user only, while status = submitted) -->
<?php if ($canUnsubmit): ?>
<div class="modal-overlay" id="unsubmitModal" onclick="if(event.target===this)closeUnsubmitModal()">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Pull PPMP back to draft?</div>
      <button class="modal-close" onclick="closeUnsubmitModal()">×</button></div>
    <p class="modal-sub"> This PPMP will be pulled back from the Budget Unit and returned to <strong>Draft</strong> so you can keep editing.
      The Budget Unit will be notified that you withdrew it.
      This works only because Budget hasn't opened it yet.
    </p>
    <form method="POST" action="api/unsubmit-ppmp.php">
      <input type="hidden" name="ppmp_id" value="<?= (int)$ppmp['id'] ?>">
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeUnsubmitModal()">Never mind</button>
        <button type="submit" class="btn btn-secondary" style="background: var(--accent); color: var(--accent-ink); border-color: var(--accent-hover);">↩ Yes, unsubmit</button></div></form></div></div>
<?php endif; ?>

<!-- Revision modal -->
<div class="modal-overlay" id="revModal" onclick="if(event.target===this)closeRevModal()">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Request revision</div>
      <button class="modal-close" onclick="closeRevModal()">×</button></div>
    <p class="modal-sub">Send this PPMP back to the file owner with notes on what needs to be revised.</p>
    <form method="POST" action="<?= $u['role'] === 'budget' ? 'api/budget-revise.php' : 'api/request-revision.php' ?>">
      <input type="hidden" name="dept_code" value="<?= e($ppmp['dept_code']) ?>">
      <input type="hidden" name="ppmp_id" value="<?= (int)$ppmp['id'] ?>">
      <input type="hidden" name="fy" value="<?= (int)$ppmp['fy'] ?>">
      <label class="form-label" for="revNote">Notes for the department (required)</label>
      <textarea class="form-textarea" id="revNote" name="note" required
        placeholder="e.g. Line items in Q2 exceed the department's allocated ceiling."></textarea>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeRevModal()">Cancel</button>
        <button type="submit" class="btn btn-secondary">Send revision request</button></div></form></div></div>

<?php if (!empty($canAdminApprove) && isset($__pe) && !in_array($__pe['state'], ['own', 'granted', 'pending'], true)): ?>
<!-- Edit permission: the Procurement Unit asks the department first -->
<div class="modal-overlay" id="editReqModal" onclick="if(event.target===this)closeEditReqModal()">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Request edit permission</div>
      <button class="modal-close" onclick="closeEditReqModal()">×</button></div>
    <p class="modal-sub">This PPMP was submitted by <strong><?= e($ppmp['submitted_by'] ?? '') ?></strong> of <strong><?= e($ppmp['dept_code']) ?></strong>.
      You can edit it once they grant permission. Tell them why.</p>
    <?php if ($__pe['state'] === 'declined'): ?>
      <p class="modal-sub" id="editReqDeclined" style="margin-top:-8px;"><strong><?= e($ppmp['dept_code']) ?> declined the last request<?= !empty($__pe['req']['responded_at']) ? ' on ' . e(date('M j, Y', strtotime($__pe['req']['responded_at']))) : '' ?>.</strong> You can ask again.</p>
    <?php endif; ?>
    <form method="POST" action="api/request-edit.php">
      <input type="hidden" name="dept_code" value="<?= e($ppmp['dept_code']) ?>">
      <input type="hidden" name="ppmp_id" value="<?= (int)$ppmp['id'] ?>">
      <input type="hidden" name="fy" value="<?= (int)$ppmp['fy'] ?>">
      <label class="form-label" for="editReason">Reason for editing (required)</label>
      <textarea class="form-textarea" id="editReason" name="reason" required
        placeholder="e.g. Line item under APP-CSE should be split into two entries to match PS-DBM codes."></textarea>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeEditReqModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Send request</button></div></form></div></div>
<script>
  function openEditReqModal(){ document.getElementById('editReqModal').classList.add('active'); document.getElementById('editReason').focus(); }
  function closeEditReqModal(){ document.getElementById('editReqModal').classList.remove('active'); }
  <?php if (isset($_GET['request_edit'])): ?>document.addEventListener('DOMContentLoaded', openEditReqModal);<?php endif; ?>
</script>
<?php endif; ?>

<!-- Approve modal (budget or admin) -->
<div class="modal-overlay" id="approveModal" onclick="if(event.target===this)closeApproveModal()">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title" id="approveTitle">Approve PPMP</div>
      <button class="modal-close" onclick="closeApproveModal()">×</button></div>
    <div class="modal-body" id="approveBody"> You're about to approve this PPMP.
    </div>
    <form method="POST" id="approveForm" action="">
      <input type="hidden" name="ppmp_id" value="<?= (int)$ppmp['id'] ?>">
      <label class="form-label" for="approveNote">Note (optional)</label>
      <textarea class="form-textarea" id="approveNote" name="note"
                rows="3" placeholder="Any comments to attach to your approval…"></textarea>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeApproveModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="background: #15803d; border-color: #15803d;" id="approveSubmitBtn">Approve</button></div></form></div></div>

<script> function openRevModal(){document.getElementById('revModal').classList.add('active');}
  function closeRevModal(){document.getElementById('revModal').classList.remove('active');}
  function openUnsubmitModal(){const m=document.getElementById('unsubmitModal'); if(m) m.classList.add('active');}
  function closeUnsubmitModal(){const m=document.getElementById('unsubmitModal'); if(m) m.classList.remove('active');}

  function openApproveModal(role) {
    const form = document.getElementById('approveForm');
    const title = document.getElementById('approveTitle');
    const body = document.getElementById('approveBody');
    const btn = document.getElementById('approveSubmitBtn');
    if (role === 'budget') {
      form.action = 'api/budget-approve.php';
      title.textContent = 'Approve and forward to Procurement Unit';
      body.textContent = 'The department PPMP will move to the admin queue for final approval. You can add an optional note.';
      btn.textContent = 'Approve → Forward';
    } else {
      form.action = 'api/admin-approve.php';
      title.textContent = 'Grant final approval';
      body.textContent = 'This is the final approval. The Budget Unit will be notified that the PPMP has been fully approved.';
      btn.textContent = 'Approve (final)';
    }
    document.getElementById('approveModal').classList.add('active');
  }
  function closeApproveModal(){document.getElementById('approveModal').classList.remove('active');}

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeEditModal(); closeRevModal(); closeApproveModal(); closeUnsubmitModal(); }
  });

  // Highlight the entry row when arriving via #entry-N anchor from the dept file list
  window.addEventListener('load', () => {
    if (window.location.hash && window.location.hash.startsWith('#entry-')) {
      const row = document.querySelector(window.location.hash);
      if (row) {
        row.style.transition = 'background 0.6s ease';
        row.style.background = '#FFF3B8';
        row.scrollIntoView({behavior: 'smooth', block: 'center'});
        setTimeout(() => { row.style.background = ''; }, 3500);
      }
    }
  });
</script>
<?php /* v111: the office's restored PPMPs are no longer listed under its PPMP — 📂 Restored at the top opens them */ ?>
  </main>
</div>
</body></html>
