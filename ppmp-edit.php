<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/ppmp-options.php';
require_once __DIR__ . '/config/ppmp-expense.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/companions.php';
require_login();  // Admin (Procurement Unit) edits its own PPMP with the same template
ensure_admin_tables();

$u = current_user();
// Everyone prepares and saves; only the Department Admin submits.
$__canSubmit = can_submit_for_dept($u);
$__headName  = dept_submitter_name($u);

/* The Procurement Unit editing a department's PPMP.
   Once the Budget Unit has approved a PPMP it is with the Procurement
   Unit, which may change its content — with the department's permission —
   until it gives final approval (ppmp_with_pu(), config/edit-permission.php).
   It opens here as ppmp-edit.php?id=N. Nothing about the PPMP's status
   changes; after final approval it is locked again. */
$__puEdit  = false;
$__puEditP = null;
if ((int)($_GET['id'] ?? 0) > 0 && ($u['role'] ?? '') === 'admin') {
    $__s = db()->prepare('SELECT * FROM ppmps WHERE id = ?');
    $__s->execute([(int)$_GET['id']]);
    $__puEditP = $__s->fetch() ?: null;
    if (!$__puEditP) {
        flash('error', 'PPMP not found.');
        header('Location: admin-dashboard.php'); exit;
    }
    if (!ppmp_with_pu($__puEditP)) {
        flash('error', ($__puEditP['status'] ?? '') === 'approved'
            ? 'This PPMP has final approval, so it can no longer be edited.'
            : 'The Procurement Unit can edit a PPMP once the Budget Unit has approved it, until it is given final approval.');
        header('Location: ppmp-file-view.php?id=' . (int)$__puEditP['id']); exit;
    }
    // …and only with the department's permission (config/edit-permission.php)
    require_once __DIR__ . '/config/edit-permission.php';
    if (!pu_edit_allowed($__puEditP, $u)) {
        $__es = pu_edit_state($__puEditP, $u)['state'];
        flash('error', $__es === 'pending'
            ? 'Your edit request is still waiting for ' . $__puEditP['dept_code'] . ' to answer. You can edit once they grant it.'
            : 'Ask ' . $__puEditP['dept_code'] . ' for permission first — use "Request edit permission" on the PPMP.');
        header('Location: ppmp-file-view.php?id=' . (int)$__puEditP['id']); exit;
    }
    $__puEdit    = true;
    $__canSubmit = false;
}

$deptCode = $__puEdit ? (string)$__puEditP['dept_code'] : $u['dept_code'];
$activeFY = active_fy();
$fy = $__puEdit ? (int)$__puEditP['fy'] : (int)($_GET['fy'] ?? $activeFY);

// The department whose PPMP this is, by name
$__deptName = (string)($u['dept_name'] ?? '');
if ($__puEdit) {
    try {
        $__dn = db()->prepare('SELECT name FROM departments WHERE code = ?');
        $__dn->execute([$deptCode]);
        $__deptName = (string)($__dn->fetchColumn() ?: $deptCode);
    } catch (Throwable $t) { $__deptName = $deptCode; }
}

// v137: "End-User or Implementing Unit" can be typed (kept on the PPMP)
require_once __DIR__ . '/config/ppmp-unit.php';
ppmp_unit_column();

// Find PPMP for this dept + FY
$stmt = db()->prepare('SELECT * FROM ppmps WHERE dept_code = ? AND fy = ?');
$stmt->execute([$deptCode, $fy]);
$ppmp = $stmt->fetch();

// The fiscal year is locked to what the Procurement Unit set.
// A new PPMP can only be created for the active FY — never a past/future year via URL.
if (!$ppmp && $fy !== $activeFY) {
    flash('error', 'The fiscal year is set by the Procurement Unit. You can only create a PPMP for FY ' . $activeFY . '.');
    header('Location: ppmp-edit.php?fy=' . $activeFY); exit;
}

if (!$ppmp) {
    $__newHead = department_head($deptCode);
    $cert = "I hereby certify that the above Project Procurement Management Plan is prepared in accordance with the approved Work and Financial Plan of the {$u['dept_name']} for FY $fy, in compliance with Republic Act No. 12009 (New Government Procurement Act) and its implementing rules and regulations.";
    $stmt = db()->prepare('INSERT INTO ppmps (dept_code, fy, preparer, date_prepared, primary_fund, certification, sig_name, sig_position, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $deptCode, $fy,
        // who created the record — an audit field, always the live account
        $u['full_name'] . ', ' . $u['position'],
        date('F j, Y'), 'GAA', $cert,
        // but "Prepared by" on the form is the department's own head, its
        // Dean or Department Admin, not whoever opened it first
        $__newHead['name'], $__newHead['position'], 'draft',
    ]);
    $stmt = db()->prepare('SELECT * FROM ppmps WHERE dept_code = ? AND fy = ?');
    $stmt->execute([$deptCode, $fy]);
    $ppmp = $stmt->fetch();
}

// An archived PPMP is read-only until it is restored
arc_redirect_if_archived(arc_ppmp_is_archived((int)$ppmp['id']), 'PPMP', 'ppmp-file-view.php?id=' . (int)$ppmp['id']);
// A past year's PPMP (restored from the Archive) is for viewing only
if (fy_is_past((int)$ppmp['fy'])) {
    flash('error', fy_past_msg('PPMP', (int)$ppmp['fy']));
    header('Location: ppmp-file-view.php?id=' . (int)$ppmp['id']); exit;
}

// Block editing if PPMP is beyond draft state (unless there's a pending revision)
$hasRevision = false;
$revStmt = db()->prepare('SELECT COUNT(*) FROM revision_requests WHERE dept_code = ? AND fy = ? AND resolved = 0');
$revStmt->execute([$deptCode, $fy]);
$hasRevision = (int)$revStmt->fetchColumn() > 0;

if (!$__puEdit && !in_array($ppmp['status'], ['draft'], true) && !$hasRevision) {
    // Already submitted / approved — send to view mode
    header('Location: ppmp-file-view.php?id=' . (int)$ppmp['id']);
    exit;
}

// Load entries
$stmt = db()->prepare('SELECT * FROM ppmp_entries WHERE ppmp_id = ? ORDER BY id');
$stmt->execute([$ppmp['id']]);
$entries = $stmt->fetchAll();

$activeNav = $__puEdit ? 'ppmps' : ((($u['role'] ?? '') === 'admin') ? 'my-ppmp' : 'ppmps');
$pageTitle = 'Edit PPMP FY ' . $fy;
doc_fmt_use_year(active_fy());   // editors work on the active year
require_once __DIR__ . '/includes/head.php';
?>
<style>
  /* Match APP-CSE: keep the topbar/sidebar still while this page's wide table
     scrolls. Without min-width:0 the whole .main column stretches to fit the
     table and the entire page scrolls sideways with it. */
  .main { min-width: 0; }

  .save-status { font-size: 12px; color: var(--muted); }
  .save-status.dirty { color: #C2410C; }
  .save-status.saved { color: var(--green-500); }

  /* Summary cards — mirrors APP-CSE .grand-box */
  .grand-box { display:flex; gap:16px; flex-wrap:wrap; margin:0 0 16px; }
  .grand-box .gb { flex:1 1 0; min-width:200px; background:#fff; border:1px solid var(--border); border-left:4px solid #133458; border-radius:10px; padding:16px 22px; }
  .grand-box .gb.accent { border-left-color:#E8DA8F; }
  .grand-box .gb .lbl { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }
  .grand-box .gb .val { font-family:'Space Grotesk',sans-serif; font-size:22px; font-weight:700; color:#133458; margin-top:4px; }
  .grand-box .gb .sub { font-size:11.5px; color:var(--muted); margin-top:4px; }
  .grand-box .gb.remain { border-left-color:#16a34a; }
  .grand-box .gb.remain.over { border-left-color:#b42318; }
  .grand-box .gb.remain.over .val,
  body[data-theme="dark"] .grand-box .gb.remain.over .val { color:#b42318; }

  /* Official header + signature cards — mirrors APP-CSE .ac-official */
  .pp-official { background:#fff; border:1px solid var(--border); border-radius:10px; padding:20px 24px; margin-bottom:18px; }
  .pp-official .letterhead { display:grid; grid-template-columns:82px 1fr 90px; align-items:center; gap:12px; margin-bottom:12px; }
  .pp-official .seal img { width:78px; height:78px; object-fit:contain; }
  .pp-official .letterhead-center { text-align:center; }
  .pp-official .rep-line { font-size:11px; color:var(--ink); }
  .pp-official .school-name { font-family:'Space Grotesk',sans-serif; font-size:17px; font-weight:700; color:#133458; margin:1px 0; }
  .pp-official .school-addr { font-size:10px; font-style:italic; color:var(--muted); margin-bottom:6px; }
  .pp-official .doc-title { font-size:13px; font-weight:700; color:#133458; margin-top:4px; }
  .pp-official .doc-title input { border:none; border-bottom:1px solid #999; text-align:center; font:inherit; width:90px; padding:0 4px; margin-left:4px; background:transparent; color:#133458; }
  .pp-official .doc-title input:focus { outline:none; border-bottom-color:#4A7AAD; }
  .pp-official .flag { width:90px; height:58px; background:url('<?= e(doc_logo('ppmp', 'right')) ?>') center/contain no-repeat; }

  .status-boxes { display:flex; justify-content:center; gap:40px; margin:8px 0 14px; font-size:11px; font-weight:600; color:var(--ink); }
  .status-boxes label { display:inline-flex; align-items:center; gap:8px; cursor:pointer; }
  .status-boxes input[type=radio] { width:14px; height:14px; }

  .meta-block { font-size:11px; display:grid; grid-template-columns:max-content 1fr; gap:6px 18px; align-items:baseline; }
  .meta-label { font-weight:700; color:var(--ink); }
  .meta-block .meta-value { border-bottom:1px solid #cbd5e1; padding:0 4px; color:#133458; }
  .meta-block input.meta-input { font:inherit; width:100%; min-width:0; background:transparent; border:none; border-bottom:1px solid #cbd5e1; border-radius:0; }
  .meta-block input.meta-input:focus { outline:none; border-bottom-color:#1c4c86; background:#f5f9fc; }

  /* Scrollable long-column table — mirrors APP-CSE .ac-wrap / table.appcse */
  .pp-wrap { overflow-x:auto; border:1px solid var(--border); border-radius:10px; background:#fff; margin-bottom:12px; }
  table.ppmp-table { border-collapse:collapse; font-size:10.5px; min-width:1720px; width:100%; }
  .ppmp-table th, .ppmp-table td { border:1px solid #d7dee6; padding:4px 5px; vertical-align:middle; text-align:center; }
  .ppmp-table td { word-wrap:break-word; overflow-wrap:anywhere; }
  .ppmp-table thead th { background:var(--navy-50); color:var(--ink); }
  .ppmp-table thead .group-header { font-weight:700; font-size:11px; }
  .ppmp-table thead .col-label { font-weight:700; font-size:10px; line-height:1.3; }
  .ppmp-table thead .col-num { font-weight:700; font-style:italic; font-size:10px; color:var(--muted); }
  .ppmp-table tbody td { padding:3px; vertical-align:top; }

  /* Editable inputs — mirrors APP-CSE transparent-with-focus style */
  .cell-input, .cell-select, .cell-textarea {
    border:1px solid transparent; background:transparent; width:100%;
    font:inherit; font-size:10.5px; color:var(--ink); padding:4px 5px;
    outline:none; resize:vertical; box-sizing:border-box; border-radius:4px;
  }
  .cell-textarea { min-height:52px; text-align:left; }
  /* Type of Project / Mode of Procurement: the floating search list */
  .pp-combo-list { position:fixed; z-index:150; margin:0; padding:4px; list-style:none; max-height:260px; overflow-y:auto;
    background:#fff; color:#133458; border:1px solid #b4e1eb; border-radius:10px; box-shadow:0 12px 30px rgba(15,43,74,.18);
    font-family:'Inter',sans-serif; font-size:12.5px; }
  .pp-combo-opt { padding:7px 10px; border-radius:7px; cursor:pointer; line-height:1.35; }
  .pp-combo-opt.is-active, .pp-combo-opt:hover { background:#eaf4f9; }
  .pp-combo-opt[aria-selected="true"] { font-weight:700; }
  .pp-combo-opt mark { background:#fde68a; color:inherit; padding:0 1px; border-radius:2px; }
  .pp-combo-opt.pp-na em { color:#516678; font-style:normal; }
  .pp-combo-opt.pp-other { border-top:1px solid #e5eaf0; margin-top:2px; }
  .pp-combo-opt.pp-other span { display:block; font-size:11px; color:#516678; margin-top:1px; }
  .pp-combo-empty { padding:6px 10px 2px; font-size:11px; color:#516678; }
  .pp-combo::placeholder { color:#6b7f95; }
  .cell-input:focus, .cell-select:focus, .cell-textarea:focus {
    border-color:#4A7AAD; background:#fff; box-shadow:0 0 0 2px rgba(74,122,173,0.12);
  }
  .cell-select { cursor:pointer; }
  .cell-num { text-align:right; font-variant-numeric:tabular-nums; }
  .cell-center { text-align:center; }

  .del-row-btn { background:transparent; border:none; color:#B3312C; font-size:15px; line-height:1; cursor:pointer; padding:4px 6px; border-radius:4px; }
  .del-row-btn:hover { background:#FBE7E6; }

  .add-row-btn { display:inline-block; margin:0 0 18px; background:#fff; color:#133458; border:1px dashed #9FB4C7; border-radius:6px; padding:8px 16px; font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; }
  .add-row-btn:hover { background:#EAF1F7; }

  .ppmp-table tfoot .total-row { background:#eef4f9; font-weight:700; font-size:11px; color:#133458; text-align:right; padding:8px 6px; }

  /* Signatures */
  .sig-row { display:grid; grid-template-columns:1fr 1fr; gap:40px; }
  .sig-block { position:relative; text-align:center; font-size:10px; color:var(--ink); }
  .sig-label { text-align:left; font-weight:700; margin-bottom:34px; }
  .sig-line { border-bottom:1px solid #333; height:1px; margin:0 auto 2px; width:70%; }
  .sig-name-input, .sig-pos-input, .sig-date-input { border:1px solid transparent; background:transparent; text-align:center; font:inherit; padding:3px 6px; width:90%; outline:none; border-radius:4px; }
  .sig-name-input:focus, .sig-pos-input:focus, .sig-date-input:focus { border-color:#4A7AAD; background:#fff; }
  .sig-name-input { font-weight:700; font-size:11px; text-transform:uppercase; margin-top:4px; }
  .sig-caption { font-size:9px; font-style:italic; margin:2px 0; color:var(--muted); }
  .sig-pos-input { font-size:10px; margin-top:2px; }
  .sig-bracket { font-size:8.5px; font-style:italic; color:#666; }
  .sig-date-wrap { text-align:left; margin-top:20px; font-size:10px; }
  .sig-date-input { text-align:left; width:60%; margin-left:4px; }


  /* One sheet of paper: the letterhead, the table and
     the signatures, in the order they print. The parts keep their own
     classes (and so their dark-mode paper colours); only their separate
     card edges are taken off. The table still scrolls sideways inside. */
  .pp-sheet { background:#fff; border:1px solid var(--border); border-radius:10px; padding:22px 24px 26px;
              margin-bottom:18px; max-width:100%; min-width:0; box-shadow:0 1px 3px rgba(15,43,74,.06); }
  .pp-sheet > .pp-official,
  .pp-sheet > .pp-wrap { border:none; border-radius:0; background:transparent; box-shadow:none; }
  .pp-sheet > .pp-official { padding:0; margin:0 0 16px; }
  .pp-sheet > .pp-wrap { margin:0 0 10px; }
  .pp-sheet > .add-row-btn { margin:0 0 22px; }
  .pp-sheet > :last-child { margin-bottom:0; }
  body[data-theme="dark"] .pp-sheet {
    --ink:#133458; --muted:#516678; --border:#B4E1EB; --white:#FFFFFF; --navy-50:#EAF4F9; --navy-100:#CFE4F0;
    background:#FFFFFF !important; border-color:#B4E1EB !important; color:#133458;
    box-shadow:0 10px 34px rgba(0,0,0,.35) !important;
  }
  body[data-theme="dark"] .pp-sheet > .pp-official,
  body[data-theme="dark"] .pp-sheet > .pp-wrap { box-shadow:none !important; }
  @media (max-width: 720px) { .pp-sheet { padding:16px 14px 18px; } }

<?php require __DIR__ . '/includes/submit-set-css.php'; ?>
</style>

<div class="app">
<?php
  $__role = $u['role'] ?? '';
  if     ($__role === 'admin')  require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($__role === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
  else                          require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title" style="margin: 0;">Project Procurement Management Plan · FY <?= $fy ?></h1>
        <p class="page-sub" style="margin: 4px 0 0;">
          <?= e($__deptName) ?> · <span id="status-label"><?= $__puEdit ? 'Approved by the Budget Unit · with the Procurement Unit' : 'Draft' ?></span>
          <span class="save-status" id="saveStatus" style="margin-left:10px;" role="status" aria-live="polite" data-state="saved"></span>
        </p><?php if (!$__puEdit) { require_once __DIR__ . '/includes/unit-switch.php'; echo set_bar_html($u, (int)$fy); }   /* v87: which program / major or set — v99: with ＋ Add Set */ ?></div>
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;" id="ppmpActions" data-dock data-dock-id="ppmpDock" data-dock-status="saveStatus">
        <?php if ($__puEdit): ?>
          <a href="ppmp-file-view.php?id=<?= (int)$ppmp['id'] ?>" class="btn btn-ghost" style="padding:8px 16px; font-size:13px; text-decoration:none;">← Back to the file</a>
        <?php endif; ?>
        <?php if (!$__puEdit) {   /* v111: this office's restored PPMPs — in their own folder, not under the PPMP */
            require_once __DIR__ . '/config/past-years.php';
            $__rest = past_year_ppmps_of((string)($u['dept_code'] ?? ''));
            if ($__rest) echo '<a href="user-dashboard.php?restored=1" id="restoredBtn" data-no-dock class="btn btn-ghost" style="padding:8px 16px; font-size:13px; text-decoration:none;" title="PPMPs of earlier years restored from the Archive (view only)">📂 Restored (' . count($__rest) . ')</a>';
          } ?>
        <?php require_once __DIR__ . '/includes/xl-io-ui.php'; echo xl_io_buttons('ppmp', 'api/xl-export.php?doc=ppmp&id=' . (int)$ppmp['id'], true);   /* v105: PPMP.xlsx */ ?>
        <button type="button" class="btn btn-ghost" style="padding:8px 16px; font-size:13px;" onclick="printPPMP()">🖨️ Print</button>
        <button type="button" class="btn btn-ghost" style="padding:8px 16px; font-size:13px;" onclick="saveAndStay()">💾 Save</button>
        <?php if ($__puEdit): ?>
          <?php /* no Submit: it has been submitted; final approval is given from the file */ ?>
        <?php elseif (!$__canSubmit && (require_once __DIR__ . '/config/head-submit.php') && hs_applies($u)): /* v112: an end user submits to the Department Admin */ ?>
          <?= hs_user_controls($u, (int)$fy, 'openSubmitModal()') ?>
        <?php elseif (!$__canSubmit): ?>
          <span style="font-size:11.5px; color:var(--muted); align-self:center;">
            <?= $__headName !== '' ? e($__headName) . ' submits for your department.' : 'Only your Department Admin can submit.' ?>
          </span>
          <?php elseif ((require_once __DIR__ . '/config/unit-review.php') && ur_whole_school($u, (int)$fy)): ?>
          <?= ur_submit_link((int)$fy, (string)($u['school_code'] ?? '')) ?>
          <?php else: ?>
          <button type="button" class="btn btn-primary" style="padding:8px 18px; font-size:13px;" onclick="openSubmitModal()">📤 Submit</button>
          <?php endif; ?></div>
    </div>

<?php render_flashes(); /* for everyone — a Submit that is not ready yet comes back here */ ?>
<?php /* v104: My PPMPs opens here — its deadline banner and the Procurement Unit's edit requests come along */
  if (!$__puEdit && (int)$fy === active_fy()) { require_once __DIR__ . '/includes/dept-notices.php'; pe_dept_notices($u); } ?>
<?php /* v99 → v103: the floating Print · Save · Submit is the shared one (assets/dock.js, data-dock above) */ ?>
<?php if (!$__puEdit) { require_once __DIR__ . '/config/unit-review.php'; echo ur_bar($u, (int)$fy); }   /* v81: a program / major of a school */ ?>
<?php if (!$__puEdit) { require_once __DIR__ . '/config/head-submit.php'; echo hs_head_bar($u, (int)$fy); }   /* v112: an end user submitted the set to the Department Admin */ ?>
<?php if (!$__puEdit && $hasRevision):
  $__rn = null;
  try {
      $__rq = db()->prepare('SELECT note, requested_by, requested_at FROM revision_requests WHERE dept_code = ? AND fy = ? AND resolved = 0 ORDER BY id DESC LIMIT 1');
      $__rq->execute([$deptCode, $fy]);
      $__rn = $__rq->fetch() ?: null;
  } catch (Throwable $t) {}
?>
    <div id="revisionNote" style="display:flex; gap:11px; align-items:flex-start; padding:13px 17px; margin-bottom:16px;
                background:#FFF4EC; border:1px solid #fed7aa; border-left:4px solid #F97316; border-radius:12px;
                font-size:12.5px; color:#7c2d12; line-height:1.55;">
      <span style="font-size:16px; line-height:1;">&#8617;</span>
      <div><strong>Sent back for revision<?= $__rn && !empty($__rn['requested_by']) ? ' by ' . e($__rn['requested_by']) : '' ?>.</strong>
        <?= $__rn ? e(preg_replace('/^\[Budget review\]\s*/', '', (string)$__rn['note'])) : '' ?><br>
        Make the changes, then <strong>Submit</strong>: only this PPMP goes back.</div>
    </div>
<?php endif; ?>
<?php if ($__puEdit): ?>
    <div style="display:flex; gap:11px; align-items:flex-start; padding:13px 17px; margin-bottom:16px;
                background: var(--navy-50); border:1px solid var(--navy-100); border-radius:12px;
                font-size:12.5px; color:var(--ink); line-height:1.55;">
      <span style="font-size:16px; line-height:1;">&#9998;</span>
      <div>You are editing <strong><?= e($deptCode) ?></strong>'s FY <?= $fy ?> PPMP as the Procurement Unit.
      The Budget Unit approved it<?= !empty($ppmp['budget_reviewed_at']) ? ' on ' . e(date('M j, Y', strtotime($ppmp['budget_reviewed_at']))) : '' ?>.
      Changes save as you type, are recorded on the PPMP's review timeline, and the department is notified.
      When you are done, give final approval from the file &mdash; after that the PPMP can no longer be edited.</div>
    </div>
<?php endif; ?>
<?php
// Check whether the user has a Non-PS list for this FY (still used for submit gating)
$hasNonpsList = false;
try {
    $__ns = db()->prepare('SELECT COUNT(*) FROM nonps_submissions WHERE dept_code = ? AND fy = ?');
    $__ns->execute([$u['dept_code'], $fy]);
    $hasNonpsList = ((int)$__ns->fetchColumn()) > 0;
} catch (Throwable $t) { /* older install — treat as OK */ $hasNonpsList = true; }
?>

<?php
// The department's allotted budget for this FY, set by the Budget Unit (dept_budgets)
$__allot = null;
try {
    $__ab = db()->prepare('SELECT alloted_budget FROM dept_budgets WHERE dept_code = ? AND fy = ?');
    $__ab->execute([$deptCode, $fy]);
    $__abv = $__ab->fetchColumn();
    if ($__abv !== false && $__abv !== null) $__allot = (float)$__abv;
} catch (Throwable $t) { $__allot = null; }
// v81: a unit of a school shares the school's allotment (or its program's share) with the other units
$__pool = null; $__poolLeft = null; $__poolOthers = 0.0;
require_once __DIR__ . '/config/academic-units.php';
if (au_is_unit((string)$deptCode) || os_pooled((string)$deptCode)) {   // v83: an office's sets too
    require_once __DIR__ . '/config/allot-shares.php';
    $__pool  = allot_pool((string)$deptCode, (int)$fy);
    $__allot = $__pool['allot'];
    foreach (allot_ppmp_totals($__pool['units'], (int)$fy) as $__c => $__v) if (strcasecmp($__c, (string)$deptCode) !== 0) $__poolOthers += $__v;
    if ($__allot !== null) $__poolLeft = round($__allot - $__poolOthers, 2);
}
?>
    <div class="grand-box">
      <div class="gb"><div class="lbl">Procurement Items</div><div class="val" id="gtItems">0</div></div>
      <div class="gb accent" id="gbBudget"<?= $__allot !== null ? ' data-allot="' . number_format($__pool ? $__poolLeft : $__allot, 2, '.', '') . '"' : '' ?><?= $__pool ? ' data-pool="' . e(rtrim($__pool['noun'] ?? 'units', 's')) . '"' : '' ?>>
        <div class="lbl">Total Budget</div>
        <?php if ($__allot !== null && $__pool): ?>
          <div class="val" id="gtBudget">₱<?= number_format($__allot, 2) ?></div>
          <div class="sub"><?= e(ucfirst($__pool['label'])) ?> · FY <?= (int)$fy ?> · shared by <?= count($__pool['units']) ?> <?= e(count($__pool['units']) === 1 ? rtrim($__pool['noun'] ?? 'units', 's') : ($__pool['noun'] ?? 'units')) ?><?= $__poolOthers > 0 ? ' · the others\' PPMPs: ₱' . number_format($__poolOthers, 2) : '' ?></div>
        <?php elseif ($__allot !== null): ?>
          <div class="val" id="gtBudget">₱<?= number_format($__allot, 2) ?></div>
          <div class="sub">Allotted by the Budget Unit · FY <?= (int)$fy ?></div>
        <?php else: ?>
          <div class="val" id="gtBudget">N/A</div>
          <div class="sub">The Budget Unit hasn't set the FY <?= (int)$fy ?> allotment yet</div>
        <?php endif; ?></div>
      <div class="gb"><div class="lbl">Total Amount</div><div class="val" id="gtTotal">₱0.00</div>
        <div class="sub">Sum of the items in this PPMP</div></div>
      <div class="gb remain" id="gbRemain"><div class="lbl">Remaining Amount</div><div class="val" id="gtRemain"><?= $__allot !== null ? '₱' . number_format($__pool ? $__poolLeft : $__allot, 2) : 'N/A' ?></div>
        <div class="sub" id="gtRemainNote"><?= $__allot !== null ? ($__pool ? 'Total Budget less every ' . e(rtrim($__pool['noun'] ?? 'units', 's')) . '\'s PPMP' : 'Total Budget less Total Amount') : 'Shown once the allotment is set' ?></div></div>
    </div>

<form id="ppmpForm" onsubmit="return false;">
<input type="hidden" name="ppmp_id" id="ppmpId" value="<?= (int)$ppmp['id'] ?>">

  <!-- The PPMP on one sheet: letterhead, table, signatures -->
  <div class="pp-sheet" id="ppSheet">
  <!-- Official PPMP header -->
  <div class="pp-official">
    <div class="letterhead">
      <div class="seal"><img src="<?= e(doc_logo('ppmp', 'left')) ?>" alt="ASCOT Seal"></div>
      <div class="letterhead-center">
        <div class="rep-line"><?= e(doc_fmt('ppmp', 'rep_line')) ?></div>
        <div class="school-name"><?= e(doc_fmt('ppmp', 'org_name')) ?></div>
        <div class="school-addr"><?= e(doc_fmt('ppmp', 'address')) ?></div>
        <div class="doc-title"> <?= e(doc_fmt('ppmp', 'doc_title')) ?>
          <input type="text" id="ppmpNo" value="<?= e($ppmp['ppmp_no'] ?? '') ?>" oninput="markDirty()"><?php $__setNo = os_ppmp_no('', $deptCode); if ($__setNo !== ''): ?><span id="ppmpSetNo" title="Set <?= e($__setNo) ?>">-<?= e($__setNo) ?></span><?php endif; ?></div></div>
      <div class="flag"></div></div>

    <div class="status-boxes">
      <label><input type="radio" name="isFinal" value="0" <?= !$ppmp['is_final'] ? 'checked' : '' ?> onchange="markDirty()"> INDICATIVE</label>
      <label><input type="radio" name="isFinal" value="1" <?= $ppmp['is_final'] ? 'checked' : '' ?> onchange="markDirty()"> FINAL</label></div>

    <div class="meta-block">
      <div class="meta-label">Fiscal Year:</div>
      <div class="meta-value"><?= $fy ?></div>
      <div class="meta-label">End-User or Implementing Unit:</div>
      <?php /* v137: the department's name unless it types another; blank = its name again */ ?>
      <input type="text" id="unitName" class="meta-value meta-input" maxlength="255" oninput="markDirty()"
             value="<?= e(ppmp_unit_label($ppmp, $deptCode, $__deptName)) ?>" data-default="<?= e(os_unit_name($deptCode, $__deptName)) ?>"
             placeholder="<?= e(os_unit_name($deptCode, $__deptName)) ?>" aria-label="End-User or Implementing Unit"
             title="Your department or unit — you can change it"></div>
  </div>

  <!-- Scrollable long-column table -->
  <div class="pp-wrap">
    <?php /* v94: the columns of the year (Document Format) */
      require_once __DIR__ . '/config/ppmp-columns.php';
      $__ppCols = ppmp_cols((int)$fy, true);
      $__ppVis  = array_values(array_filter($__ppCols, fn($c) => $c['on']));
      $__ppHdr  = ppmp_cols_header($__ppVis, (int)$fy);
      $__ppMin  = ['desc' => 220, 'type' => 130, 'qty' => 110, 'mode' => 140, 'pre' => 100, 'start' => 90, 'end' => 90, 'deliv' => 120,
                   'fund' => 110, 'amount' => 140, 'docs' => 160, 'remarks' => 190]; ?>
    <table class="ppmp-table" id="ppmpTable">
      <thead>
        <tr>
          <?php $__first = true; foreach ($__ppHdr['top'] as $__t): ?>
            <?php if (isset($__t['col'])): ?><th rowspan="2" class="col-label" style="min-width:<?= (int)($__ppMin[$__t['col']['id']] ?? 160) ?>px;"><?= e($__t['col']['label']) ?></th>
            <?php else: ?><th colspan="<?= (int)$__t['span'] ?>" class="group-header"><?= e($__t['label']) ?></th><?php endif; ?>
          <?php endforeach; ?>
          <th rowspan="3" style="min-width:34px; width:34px; background:var(--navy-50);"></th></tr>
        <tr>
          <?php foreach ($__ppHdr['second'] as $__c): ?><th class="col-label" style="min-width:<?= (int)($__ppMin[$__c['id']] ?? 160) ?>px;"><?= e($__c['label']) ?></th><?php endforeach; ?></tr>
        <tr>
          <?php foreach ($__ppVis as $__i => $__c): ?><th class="col-num"><?= e(doc_fmt('ppmp', 'col_num_prefix')) ?> <?= $__i + 1 ?></th><?php endforeach; ?></tr></thead>
      <tbody id="entriesBody">
        <?php foreach ($entries as $r): ?>
          <?php include __DIR__ . '/includes/ppmp-edit-row.php'; ?>
        <?php endforeach; ?></tbody>
      <tfoot>
        <?php [$__ppB, $__ppA] = ppmp_cols_around_amount($__ppVis); ?>
        <tr>
          <td colspan="<?= $__ppB ?>" class="total-row">TOTAL BUDGET:</td>
          <td class="total-row" id="totalCell">₱0.00</td>
          <td colspan="<?= $__ppA + 1 ?>"></td></tr></tfoot></table>
  </div>

  <button type="button" class="add-row-btn" onclick="addRow()">＋ Add a procurement item</button>

  <!-- Signatures -->
  <div class="pp-official">
    <?php
      /* Same rule as the Non-PS list: a stored value wins, a blank fills
         itself in. This page is only ever reached on a draft or a PPMP sent
         back for revision, so filling in from the person working on it is
         always the preparer, never a reader of a filed document. */
      /* "Prepared by" is the department's own head — its Dean, or its
         Department Admin. It used to fill with whoever had the form open,
         which on a shared account is not the name that belongs on it.
         Anything saved on the PPMP still wins, so a copy that needs a
         different name keeps it. */
      $__dh = department_head($deptCode);
      $__sigName = trim((string)($ppmp['sig_name'] ?? ''));
      if ($__sigName === '') $__sigName = $__dh['name'];
      $__sigPos  = trim((string)($ppmp['sig_position'] ?? ''));
      if ($__sigPos === '') $__sigPos = $__dh['position'];
      $__datePrepared = trim((string)($ppmp['date_prepared'] ?? ''));
      if ($__datePrepared === '') $__datePrepared = date('F j, Y');
      /* "Approved by" is the Vice President the department answers to:
         Academic Affairs for an academic unit, Administration, Planning
         and Finance for an administrative one. Both names are set on the
         Document Format page. A name typed here on purpose still wins
         (ppmp_approved_by()). */
      $__ab     = ppmp_approved_by(array_merge((array)$ppmp, ['dept_code' => $deptCode]));
      $__hd     = $__ab['vp'];
      $__hdName = $__ab['name'];
      $__hdPos  = $__ab['position'];
    ?>
    <div class="sig-row">
      <div class="sig-block" id="sigPreparedBlock">
        <button type="button" onclick="removeSigBlock('sigPreparedBlock')" title="Remove this signature block"
                style="position: absolute; top: 6px; right: 6px; background: none; border: none; color: var(--row-x); cursor: pointer; font-size: 14px; padding: 2px 6px; line-height: 1;">✕</button>
        <div class="sig-label"><?= e(doc_fmt('ppmp', 'sig_prepared') ?: 'Prepared by:') ?></div>
        <div class="sig-line"></div>
        <input type="text" class="sig-name-input" id="sigName" value="<?= e($__sigName) ?>" oninput="markDirty()" placeholder="PREPARER FULL NAME">
        <div class="sig-caption">Signature over Printed Name</div>
        <input type="text" class="sig-pos-input" id="sigPos" value="<?= e($__sigPos) ?>" oninput="markDirty()" placeholder="Position">
        <div class="sig-bracket">[Head of the End-User or Implementing Unit]</div>
        <div class="sig-date-wrap"> Date: <input type="text" class="sig-date-input" id="datePrepared" value="<?= e($__datePrepared) ?>" oninput="markDirty()" placeholder="e.g. April 7, 2026"></div></div>
      <div class="sig-block" id="sigSubmittedBlock">
        <button type="button" onclick="removeSigBlock('sigSubmittedBlock')" title="Remove this signature block"
                style="position: absolute; top: 6px; right: 6px; background: none; border: none; color: var(--row-x); cursor: pointer; font-size: 14px; padding: 2px 6px; line-height: 1;">✕</button>
        <div class="sig-label"><?= e(doc_fmt('ppmp', 'sig_approved') ?: 'Approved by:') ?></div>
        <div class="sig-line"></div>
        <input type="text" class="sig-name-input" id="headName" value="<?= e($__hdName) ?>" oninput="markDirty()" placeholder="APPROVING OFFICIAL"
               data-vp="<?= e($__hd['name']) ?>">
        <div class="sig-caption">Signature over Printed Name</div>
        <input type="text" class="sig-pos-input" id="headPos" value="<?= e($__hdPos) ?>" oninput="markDirty()" placeholder="Position"
               data-vp="<?= e($__hd['position']) ?>">
        <div class="sig-date-wrap"> Date: <input type="text" class="sig-date-input" id="dateSubmitted" value="<?= e($ppmp['date_submitted_head'] ?? '') ?>" oninput="markDirty()" placeholder="e.g. April 7, 2026"></div></div></div>
  </div>
  </div><!-- /.pp-sheet -->
</form>

<script>
  // Remove/restore a signature block; block state persists in localStorage per PPMP
  const PPMP_ID = <?= (int)($ppmp['id'] ?? 0) ?>;
  const STORAGE_KEY = 'sigBlocks_ppmp_' + PPMP_ID;
  function loadSigBlockState() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const removed = JSON.parse(raw);
      removed.forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });
    } catch (e) {}
  }
  function saveSigBlockState() {
    const removed = [];
    ['sigPreparedBlock','sigSubmittedBlock'].forEach(id => {
      const el = document.getElementById(id);
      if (el && el.style.display === 'none') removed.push(id);
    });
    localStorage.setItem(STORAGE_KEY, JSON.stringify(removed));
  }
  function removeSigBlock(id) {
    const el = document.getElementById(id);
    if (!el) return;
    if (window.swiftConfirm) {
      swiftConfirm({ title: 'Remove this signature block?', text: 'You can restore it by refreshing the page and clicking Restore.', confirmLabel: 'Remove', tone: 'danger',
        onConfirm: function(){ el.style.display = 'none'; saveSigBlockState(); showRestoreBar(); }});
    } else {
      el.style.display = 'none'; saveSigBlockState(); showRestoreBar();
    }
  }
  function showRestoreBar() {
    let bar = document.getElementById('restoreSigBar');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'restoreSigBar';
      bar.style.cssText = 'text-align: center; margin: 12px 0; padding: 8px 12px; background: var(--navy-50); border-radius: 8px; font-size: 12px;';
      const sigRow = document.querySelector('.sig-row');
      if (sigRow && sigRow.parentNode) sigRow.parentNode.insertBefore(bar, sigRow);
    }
    const hiddenBlocks = ['sigPreparedBlock','sigSubmittedBlock'].filter(id => {
      const el = document.getElementById(id); return el && el.style.display === 'none';
    });
    if (hiddenBlocks.length === 0) { bar.remove(); return; }
    bar.innerHTML = '<span style="color: var(--muted);">Hidden signature block' + (hiddenBlocks.length > 1 ? 's' : '') + ':</span> ' +
      hiddenBlocks.map(id => `<button type="button" onclick="restoreSigBlock('${id}')" style="background: none; border: 1px solid #133458; color: #133458; padding: 4px 10px; border-radius: 6px; margin: 0 4px; font-size: 11.5px; cursor: pointer;">↩ Restore ${id === 'sigPreparedBlock' ? '&quot;Prepared by&quot;' : '&quot;Submitted by&quot;'}</button>`).join('');
  }
  function restoreSigBlock(id) {
    const el = document.getElementById(id);
    if (el) { el.style.display = ''; saveSigBlockState(); showRestoreBar(); markDirty && markDirty(); }
  }
  document.addEventListener('DOMContentLoaded', function() { loadSigBlockState(); showRestoreBar(); });
</script>

<!-- Submit confirmation modal -->
<?php
  // Everything that goes with this PPMP (config/companions.php)
  // The whole set is checked on a resubmission too (the same list as a first submission)
  // v112: an end user's window is the same, and sends the set to the Department Admin (config/head-submit.php)
  require_once __DIR__ . '/config/head-submit.php';
  $__toHead = (!$__puEdit && !$__canSubmit && hs_applies($u)) ? (dept_submitter_name($u) ?: 'your Department Admin') : '';
  $__set = (!$__puEdit && ($__canSubmit || $__toHead !== '')) ? ppmp_companions((string)$deptCode, (int)$fy) : null;
  $__toPU = ppmp_skips_budget((string)$deptCode);
  // Within the allotment: APP-CSE (with its 10%) + Non-PS (with VAT, logistics, inflation) against the Budget Unit's allotment
  $__bm = (!$__puEdit && ($__canSubmit || $__toHead !== '')) ? ppmp_budget_match((string)$deptCode, (int)$fy, (int)$ppmp['id']) : null;
  // The same window opens from Submit on the APP-CSE, Non-PS list, Annex and Market Scoping (config/submit-set.php)
  require_once __DIR__ . '/config/submit-set.php';
?>
<div class="modal-overlay" id="submitModal" onclick="if(event.target===this)closeSubmitModal()">
  <div class="modal-box" style="max-width:560px;">
<?= submit_set_body((int)$fy, $hasRevision, (string)($ppmp['status'] ?? 'draft'), $__toPU, $__set, $__bm,
                    '<li class="ok"><span class="set-mark">✓</span><span class="set-name">PPMP</span><span class="set-note">This plan</span></li>', $__toHead) ?>
    <div class="modal-actions">
      <button type="button" class="modal-btn modal-btn-cancel" onclick="closeSubmitModal()">Not yet</button>
      <button type="button" class="modal-btn modal-btn-confirm" id="submitConfirm" onclick="submitForReview()"
              <?= (($__set && !$__set['ok']) || ($__bm && !$__bm['ok'])) ? 'disabled' : '' ?>>✓ Submit</button></div></div></div>

<script>
let dirty = false;
let saveTimer = null;
const statusEl = document.getElementById('saveStatus');

function markDirty() {
  dirty = true;
  statusEl.textContent = '';                      // saving on its own goes on quietly
  statusEl.className = 'save-status'; statusEl.dataset.state = 'dirty';
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => saveNow(true), window.PE_AUTOSAVE_MS || 1500);
}

function collectData() {
  const rows = document.querySelectorAll('#entriesBody tr');
  const entries = [];
  rows.forEach(row => {
    entries.push({
      id: row.dataset.id || null,
      category: row.querySelector('.f-cat').value,
      budget_category: row.querySelector('.f-budget-cat').value,
      description: row.querySelector('.f-desc').value,
      proj_type: row.querySelector('.f-type').value,
      quantity: row.querySelector('.f-qty').value,
      mode: row.querySelector('.f-mode').value,
      pre_conference: row.querySelector('.f-pre').value,
      activity_start: row.querySelector('.f-start').value,
      activity_end: row.querySelector('.f-end').value,
      delivery: row.querySelector('.f-deliv').value,
      fund: row.querySelector('.f-fund').value,
      amount: parseFloat(row.querySelector('.f-amt').value) || 0,
      supporting_docs: row.querySelector('.f-docs').value,
      remarks: row.querySelector('.f-rem').value,
      extra: (function () {   // v94: the added columns (Document Format), with what earlier ones held
        var x = {}; try { x = JSON.parse((row.querySelector('.f-xraw') || {}).value || '{}') || {}; } catch (e) { x = {}; }
        row.querySelectorAll('.f-x').forEach(function (el) { x[el.dataset.col] = el.value; });
        return x;
      })(),
    });
  });
  return {
    ppmp_id: parseInt(document.getElementById('ppmpId').value),
    ppmp_no: document.getElementById('ppmpNo').value,
    // v137: the End-User typed — blank (or the department's own name) shows the department's name
    unit_name: (function () { var el = document.getElementById('unitName'); if (!el) return undefined;
      var v = el.value.trim(); return v === (el.dataset.default || '') ? '' : v; })(),
    is_final: parseInt(document.querySelector('input[name="isFinal"]:checked')?.value || 0),
    sig_name: document.getElementById('sigName').value,
    sig_position: document.getElementById('sigPos').value,
    date_prepared: document.getElementById('datePrepared').value,
    head_name: document.getElementById('headName').value,
    head_position: document.getElementById('headPos').value,
    // typed on purpose: anything other than the Vice President it would show
    // anyway (blank counts as "show the Vice President")
    head_custom: (function(){
      const n = document.getElementById('headName'), p = document.getElementById('headPos');
      const nv = n.value.trim(), pv = p.value.trim();
      if (nv === '' && pv === '') return 0;
      return (nv !== (n.dataset.vp || '').trim() || pv !== (p.dataset.vp || '').trim()) ? 1 : 0;
    })(),
    date_submitted_head: document.getElementById('dateSubmitted').value,
    entries,
  };
}

async function saveNow(silent) {
  if (!silent) { statusEl.textContent = ''; statusEl.className = 'save-status'; statusEl.dataset.state = 'saving'; }
  try {
    const r = await fetch('api/save-ppmp.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(collectData())
    });
    const d = await r.json();
    if (!d.ok) throw new Error(d.error || 'Save failed');
    dirty = false;
    statusEl.textContent = '';                    // only a failure is shown (no repeated "Draft saved")
    statusEl.className = 'save-status'; statusEl.dataset.state = 'saved';
    // Update row ids so future saves keep the same entry ids
    if (d.entries) {
      document.querySelectorAll('#entriesBody tr').forEach((row, i) => {
        if (d.entries[i]?.id) row.dataset.id = d.entries[i].id;
      });
    }
  } catch (e) {
    statusEl.textContent = 'Save failed — try again';
    statusEl.className = 'save-status dirty'; statusEl.dataset.state = 'error';
  }
}

function updateTotal() {
  let total = 0;
  document.querySelectorAll('.f-amt').forEach(el => total += (parseFloat(el.value) || 0));
  const peso = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const tc = document.getElementById('totalCell'); if (tc) tc.textContent = peso;
  const gt = document.getElementById('gtTotal'); if (gt) gt.textContent = peso;
  const gi = document.getElementById('gtItems'); if (gi) gi.textContent = document.querySelectorAll('#entriesBody tr').length;
  // Remaining Amount = the allotted Total Budget less the Total Amount
  const gb = document.getElementById('gbBudget'), gr = document.getElementById('gtRemain');
  if (gb && gr && gb.dataset.allot !== undefined) {
    const rem = Math.round((parseFloat(gb.dataset.allot) - total) * 100) / 100;
    const fmt = v => '₱' + v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    gr.textContent = rem < 0 ? '−' + fmt(-rem) : fmt(rem);
    const box = document.getElementById('gbRemain'); if (box) box.classList.toggle('over', rem < 0);
    const nt = document.getElementById('gtRemainNote');
    if (nt) nt.textContent = rem < 0 ? 'Over the Total Budget by ' + fmt(-rem) : (gb.dataset.pool ? 'Total Budget less every ' + gb.dataset.pool + '\'s PPMP' : 'Total Budget less Total Amount');
  }
}

function addRow() {
  const tbody = document.getElementById('entriesBody');
  const tr = document.createElement('tr');
  tr.innerHTML = rowHTML({category:'training', description:'', proj_type:'', quantity:'',
    mode:'', pre_conference:'',
    activity_start:'', activity_end:'', delivery:'',
    fund:'', amount:'', supporting_docs:'', remarks:''});
  tbody.appendChild(tr);
  markDirty();
  updateTotal();
}

function delRow(btn) {
  const tr = btn.closest('tr');
  if (window.swiftConfirm) {
    swiftConfirm({
      title: 'Remove this row?',
      text: 'This procurement entry will be removed from the PPMP.',
      confirmLabel: 'Remove',
      tone: 'danger',
      onConfirm: function(){ tr.remove(); markDirty(); updateTotal(); }
    });
  } else {
    tr.remove(); markDirty(); updateTotal();
  }
}

/* The same predefined lists the saved rows use, handed to the browser so a
   newly added row offers exactly the same choices. */
const PPMP_CHOICES = {
  projectTypes: <?= json_encode(PPMP_PROJECT_TYPES, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  modes:        <?= json_encode(PPMP_MODES,         JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  quantities:   <?= json_encode(PPMP_QUANTITIES,    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  yesNo:        <?= json_encode(PPMP_YES_NO,        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  months:       <?= json_encode(PPMP_MONTHS,        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  funds:        <?= json_encode(array_map(fn($c) => ['value' => $c, 'label' => fund_label($c)], fund_codes()),
                                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};

/* Mirror of fund_select() in config/funds.php. A new row is always charged
   to some fund, so there is no blank option and GAA is the default. */
function fundHTML(value) {
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var v = (value == null || String(value).trim() === '') ? 'GAA' : String(value).trim();
  var html = '<select class="cell-input f-fund" onchange="markDirty()">';
  var known = false;
  PPMP_CHOICES.funds.forEach(function (f) {
    if (f.value === v) known = true;
    html += '<option value="' + esc(f.value) + '"' + (f.value === v ? ' selected' : '')
          + '>' + esc(f.label) + '</option>';
  });
  if (!known) html += '<option value="' + esc(v) + '" selected>' + esc(v) + '</option>';
  return html + '</select>';
}

/* Mirror of ppmp_choice() in config/ppmp-options.php: a value that is not on
   the list is kept as its own option rather than being thrown away. */
function choiceHTML(cls, value, options) {
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var v = (value == null ? '' : String(value)).trim();
  var h = '<select class="cell-input ' + esc(cls) + '" onchange="markDirty()">';
  h += '<option value=""' + (v === '' ? ' selected' : '') + '>N/A</option>';
  options.forEach(function (o) {
    h += '<option value="' + esc(o) + '"' + (v === o ? ' selected' : '') + '>' + esc(o) + '</option>';
  });
  if (v !== '' && options.indexOf(v) === -1) {
    h += '<option value="' + esc(v) + '" selected>' + esc(v) + '</option>';
  }
  return h + '</select>';
}

/* Mirror of ppmp_combo() in config/ppmp-options.php. */
function comboHTML(cls, value, list) {
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var v = (value == null ? '' : String(value)).trim();
  return '<input class="cell-input pp-combo ' + esc(cls) + '" data-list="' + esc(list) + '" value="' + esc(v) + '"'
       + ' placeholder="' + (list === 'quantities' ? 'As per schedule' : 'N/A') + '" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false"'
       + ' aria-controls="ppComboList" oninput="markDirty()">';
}

/* v94: the columns of the year, in order ({id, builtin, on}) — the same as the saved rows */
const PPMP_COLS = <?= json_encode(array_map(fn($c) => ['id' => $c['id'], 'builtin' => $c['builtin'], 'on' => $c['on']], $__ppCols), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function rowHTML(e) {
  const cell = {
    desc: `<textarea class="cell-textarea f-desc" oninput="markDirty()">${e.description||''}</textarea>
      <label style="display:block;font-size:10px;color:var(--muted);margin-top:4px">Budget category <select class="cell-input f-budget-cat" onchange="markDirty()"><option value="travel" ${(e.budget_category||'training')==='travel'?'selected':''}>Travel</option><option value="training" ${(e.budget_category||'training')==='training'?'selected':''}>Training</option><option value="supply" ${(e.budget_category||'training')==='supply'?'selected':''}>Supply</option><option value="equipment" ${(e.budget_category||'training')==='equipment'?'selected':''}>Equipment</option></select></label>
      <input type="hidden" class="f-cat" value="${e.category||'training'}"><input type="hidden" class="f-xraw" value="{}">`,
    type: comboHTML('f-type', e.proj_type, 'projectTypes'),
    qty: comboHTML('f-qty', e.quantity, 'quantities'),
    mode: comboHTML('f-mode', e.mode, 'modes'),
    pre: choiceHTML('f-pre', e.pre_conference, PPMP_CHOICES.yesNo),
    start: choiceHTML('f-start', e.activity_start, PPMP_CHOICES.months),
    end: choiceHTML('f-end', e.activity_end, PPMP_CHOICES.months),
    deliv: choiceHTML('f-deliv', e.delivery, PPMP_CHOICES.months),
    fund: fundHTML(e.fund),
    amount: `<input class="cell-input cell-num f-amt money" type="text" inputmode="decimal" data-num-only value="${e.amount||''}" oninput="markDirty(); updateTotal();" placeholder="0.00">`,
    docs: `<input class="cell-input f-docs" value="${e.supporting_docs||''}" placeholder="e.g. Training Proposal, RIS" oninput="markDirty()">`,
    remarks: `<textarea class="cell-textarea f-rem" oninput="markDirty()" placeholder="Remarks">${e.remarks||''}</textarea>`
  };
  let h = '';
  PPMP_COLS.forEach(function (c) {
    if (!c.on) return;
    h += '<td>' + (c.builtin ? cell[c.id] : '<textarea class="cell-textarea f-x" data-col="' + c.id + '" oninput="markDirty()"></textarea>') + '</td>';
  });
  PPMP_COLS.forEach(function (c) { if (!c.on && c.builtin) h += '<td class="pp-off" hidden>' + cell[c.id] + '</td>'; });
  return h + '<td class="cell-center"><button type="button" class="del-row-btn" onclick="delRow(this)" title="Remove">×</button></td>';
}

/* ─── Type of Project / Mode of Procurement: search while typing ──────
   One list for every row (it floats above the table so the table's own
   scrolling cannot cut it off). "N/A" clears the cell, the choices follow,
   and "Others" is always last: it empties the cell so anything can be typed.
   Text that is not on the list is kept as typed — nothing is ever thrown away. */
(function () {
  var list = document.createElement('ul');
  list.id = 'ppComboList'; list.className = 'pp-combo-list'; list.setAttribute('role', 'listbox'); list.hidden = true;
  document.body.appendChild(list);
  var cur = null, items = [], active = -1;
  var OTHER_HINT = { projectTypes: 'Type the type of project', modes: 'Type the mode of procurement', quantities: 'Type the quantity and size' };
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function mark(t, q) {
    if (!q) return esc(t);
    var i = t.toLowerCase().indexOf(q.toLowerCase());
    return i < 0 ? esc(t) : esc(t.slice(0, i)) + '<mark>' + esc(t.slice(i, i + q.length)) + '</mark>' + esc(t.slice(i + q.length));
  }
  function opts(el) { return (PPMP_CHOICES[el.dataset.list] || []); }
  /* A choice with a number in it ("Estimated 2 seminars"): the number is the
     department's to change, so "Estimated 5 seminars" is still that choice. */
  function numbered(o) { return /\d/.test(o); }
  function shape(o) {
    return new RegExp('^' + o.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\d+/, '(\\d+)').replace(/\s+/g, '\\s+') + '$', 'i');
  }
  function canonical(el) {
    var v = el.value.trim(), lv = v.toLowerCase();
    var hit = opts(el).filter(function (o) { return o.toLowerCase() === lv; })[0];
    if (hit) return hit;
    for (var i = 0, all = opts(el); i < all.length; i++) {
      if (!numbered(all[i])) continue;
      var m = v.match(shape(all[i]));
      if (m) return all[i].replace(/\d+/, String(parseInt(m[1], 10)));
    }
    return undefined;
  }
  // what an empty cell means (an empty Quantity prints "as per schedule")
  function emptyText(el) { return el.dataset.list === 'quantities' ? 'As per schedule' : 'N/A'; }
  function sameChoice(o, c) { return !!c && (o === c || (numbered(o) && shape(o).test(c))); }
  function place() {
    if (!cur) return;
    var r = cur.getBoundingClientRect(), w = Math.max(r.width, 250);
    var left = Math.min(r.left, window.innerWidth - w - 8); if (left < 8) left = 8;
    list.style.left = left + 'px'; list.style.width = w + 'px';
    var below = window.innerHeight - r.bottom;
    if (below < 220 && r.top > below) { list.style.top = ''; list.style.bottom = (window.innerHeight - r.top + 2) + 'px'; }
    else { list.style.bottom = ''; list.style.top = (r.bottom + 2) + 'px'; }
  }
  function render(typing) {
    var q = cur.value.trim(), all = opts(cur);
    var exact = canonical(cur);
    var term = (!typing || q === '' || exact) ? '' : q;    // opening the cell shows the whole list; typing narrows it
    var hits = term ? all.filter(function (o) { return o.toLowerCase().indexOf(term.toLowerCase()) > -1; }) : all.slice();
    items = [];
    if (!term && all.indexOf('N/A') < 0) items.push({ v: '', label: 'N/A', kind: 'na' });   // (a list with its own N/A writes it in)
    hits.forEach(function (o) { items.push({ v: o, label: o, kind: 'opt' }); });
    items.push({ v: null, label: 'Others', kind: 'other', typed: term && !hits.length ? q : '' });
    list.innerHTML = '';
    if (term && !hits.length) {
      var e = document.createElement('li'); e.className = 'pp-combo-empty'; e.textContent = 'Not on the list';
      list.appendChild(e);
    }
    items.forEach(function (it, ix) {
      var li = document.createElement('li');
      li.id = 'pp-opt-' + ix; li.setAttribute('role', 'option'); li.className = 'pp-combo-opt pp-' + it.kind;
      li.setAttribute('aria-selected', it.kind === 'opt' && sameChoice(it.v, exact) ? 'true' : 'false');
      li.innerHTML = it.kind === 'other'
        ? '<strong>Others</strong><span>' + (it.typed ? 'Keep &ldquo;' + esc(it.typed) + '&rdquo; as typed' : 'Not on the list — type your own') + '</span>'
        : it.kind === 'na' ? '<em>N/A</em>' : mark(it.label, term);
      li.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(ix); });
      li.addEventListener('mousemove', function () { if (active !== ix) setActive(ix, false); });   // only a moving mouse highlights
      list.appendChild(li);
    });
    active = -1;
  }
  function show(el, typing) {
    cur = el; render(!!typing); list.hidden = false; place();
    el.setAttribute('aria-expanded', 'true');
  }
  function hide() {
    list.hidden = true;
    if (cur) { cur.setAttribute('aria-expanded', 'false'); cur.removeAttribute('aria-activedescendant'); }
    active = -1;
  }
  function setActive(ix, scroll) {
    var rows = list.querySelectorAll('.pp-combo-opt');
    rows.forEach(function (r) { r.classList.remove('is-active'); });
    active = ix;
    if (ix > -1 && rows[ix]) {
      rows[ix].classList.add('is-active'); cur.setAttribute('aria-activedescendant', rows[ix].id);
      if (scroll !== false) rows[ix].scrollIntoView({ block: 'nearest' });
    }
  }
  function pick(ix) {
    var it = items[ix], el = cur; if (!it || !el) return;
    if (it.kind === 'other') {
      if (canonical(el)) el.value = '';                       // replacing a listed choice: start empty; anything typed is kept
      el.dataset.free = '1';                                  // typing now is free text: don't reopen the list
      el.placeholder = OTHER_HINT[el.dataset.list] || 'Type it';
      hide(); el.focus(); markDirty();
      return;
    }
    // "Estimated 2 seminars" chosen again keeps the number already there
    var keep = sameChoice(it.v, canonical(el)) ? canonical(el) : it.v;
    el.value = keep; delete el.dataset.free; el.placeholder = emptyText(el);
    hide(); markDirty();
    if (it.v && numbered(it.v)) {
      // the number is selected, ready to be typed over
      var m = keep.match(/\d+/);
      el.dataset.free = '1'; el.focus();
      try { el.setSelectionRange(m.index, m.index + m[0].length); } catch (e) {}
    }
  }
  document.addEventListener('focusin', function (ev) {
    var el = ev.target;
    if (el.classList && el.classList.contains('pp-combo') && !el.readOnly && !el.disabled && !el.dataset.free) show(el);
  });
  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (!el.classList || !el.classList.contains('pp-combo') || el.dataset.free) return;
    show(el, true);
  });
  // Clicking the cell again after a choice opens the list again
  document.addEventListener('click', function (ev) {
    var el = ev.target;
    if (el.classList && el.classList.contains('pp-combo')) {
      if (list.hidden && !el.readOnly && !el.disabled && !el.dataset.free) show(el);
    } else if (!list.contains(el)) hide();
  });
  document.addEventListener('keydown', function (ev) {
    var el = ev.target;
    if (!el.classList || !el.classList.contains('pp-combo')) return;
    if (list.hidden) { if (ev.key === 'ArrowDown' && !el.dataset.free) { ev.preventDefault(); show(el); setActive(0); } return; }
    if (ev.key === 'ArrowDown') { ev.preventDefault(); setActive(active + 1 >= items.length ? 0 : active + 1); }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); setActive(active - 1 < 0 ? items.length - 1 : active - 1); }
    else if (ev.key === 'Enter') { if (active > -1) { ev.preventDefault(); pick(active); } else { ev.preventDefault(); hide(); } }
    else if (ev.key === 'Escape') { ev.preventDefault(); hide(); }
    else if (ev.key === 'Tab') { hide(); }
  });
  document.addEventListener('focusout', function (ev) {
    var el = ev.target;
    if (!el.classList || !el.classList.contains('pp-combo')) return;
    setTimeout(function () {
      if (document.activeElement === el) return;
      if (cur === el) hide();
      // Typed the name of a choice? Store it exactly as the list spells it
      var c = canonical(el);
      if (c && c !== el.value) { el.value = c; markDirty(); }
      delete el.dataset.free; el.placeholder = emptyText(el);
    }, 120);
  });
  window.addEventListener('resize', place);
  document.addEventListener('scroll', function () { if (!list.hidden) place(); }, true);
  // A long Quantity choice is wider than its cell: pointing at the cell shows it whole
  function tip(el) { if (el && el.dataset && el.dataset.list === 'quantities') el.title = el.value; }
  document.querySelectorAll('.pp-combo[data-list="quantities"]').forEach(tip);
  ['input', 'change', 'focusout', 'mouseover'].forEach(function (t) {
    document.addEventListener(t, function (ev) { tip(ev.target); }, true);
  });
})();

function openSubmitModal() {
  // Save first, then open modal
  saveNow(true).then(() => { budgetCheck(); document.getElementById('submitModal').classList.add('active'); });
}

// "Within the allotment" is worked out on the server (config/companions.php):
// APP-CSE (with its 10%) + Non-PS (with VAT, logistics, inflation), against the Budget Unit's allotment —
// it does not change with the PPMP's own items. Only ✗ (over) stops Submit.
function budgetCheck() {
  // The PPMP's own Total Amount, as it is on the screen now, against the allotment (v77)
  const pl = document.getElementById('submitPpmpAllot');
  if (pl && pl.dataset.allot !== undefined) {
    const c = v => Math.round((parseFloat(v) || 0) * 100);
    let t = 0; document.querySelectorAll('#entriesBody .f-amt').forEach(el => t += c(el.value));
    const a = c(pl.dataset.allot), over = t - a;
    const peso = v => '₱' + (v / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    pl.className = over > 0 ? 'miss' : 'ok';
    pl.querySelector('.set-mark').textContent = over > 0 ? '✗' : '✓';
    const pool = pl.dataset.pool;   // v81: a unit shares its school's (or program's) allotment
    pl.querySelector('.pp-text').textContent = pool
      ? (over > 0
        ? 'PPMP Total Amount ' + peso(t) + ' is ' + peso(over) + ' over the ' + peso(a) + ' left of ' + pool + ' (after the other ' + (pl.dataset.noun || 'units') + '\' PPMPs). Lower the amounts on the PPMP so it is within the allotment.'
        : 'PPMP Total Amount ' + peso(t) + ' of the ' + peso(a) + ' left of ' + pool + ' (after the other ' + (pl.dataset.noun || 'units') + '\' PPMPs)' + (over < 0 ? ' · ' + peso(-over) + ' remaining.' : ' · all of it used.'))
      : (over > 0
      ? 'PPMP Total Amount ' + peso(t) + ' is ' + peso(over) + ' over your allotment of ' + peso(a) + '. Lower the amounts on the PPMP so it is within the allotment.'
      : 'PPMP Total Amount ' + peso(t) + ' of your ' + peso(a) + ' allotment' + (over < 0 ? ' · ' + peso(-over) + ' remaining.' : ' · all of it used.'));
  }
  const bad = document.querySelectorAll('#submitSet li.miss').length > 0;
  const go = document.getElementById('submitConfirm'); if (go) go.disabled = bad;
  const blk = document.getElementById('submitBlocked'); if (blk) blk.hidden = !bad;
}

function printPPMP() {
  // Open the print tab immediately (counts as a user gesture, so it isn't
  // blocked), save the latest edits, then point the tab at the print page.
  const w = window.open('', '_blank');
  const url = 'ppmp-print.php?id=' + document.getElementById('ppmpId').value;
  saveNow(true).finally(() => {
    if (w) { w.location = url; } else { window.open(url, '_blank'); }
  });
}
function closeSubmitModal() { document.getElementById('submitModal').classList.remove('active'); }

async function saveAndStay() {
  await saveNow(false);
}
async function saveAndClose() {
  await saveNow(false);
  // Small delay before leaving the page
  setTimeout(() => {
    window.location.href = 'user-dashboard.php?saved=1';
  }, 350);
}

async function submitForReview() {
  closeSubmitModal();
  statusEl.textContent = 'Submitting…';
  <?php if (($__toHead ?? '') !== ''): /* v112: an end user — a plain form post, so the page that follows says what happened */ ?>
  const hf = document.createElement('form');
  hf.method = 'POST'; hf.action = 'api/submit-to-head.php'; hf.style.display = 'none';
  const tk = document.querySelector('meta[name="csrf-token"]');
  [['fy', '<?= (int)$fy ?>'], ['_csrf', tk ? tk.content : '']].forEach(([n, v]) => {
    const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; hf.appendChild(i);
  });
  document.body.appendChild(hf); hf.submit();
  return;
  <?php endif; ?>
  const fd = new FormData();
  fd.append('ppmp_id', document.getElementById('ppmpId').value);
  fd.append('fy', <?= $fy ?>);
  try {
    const r = await fetch('api/submit-ppmp.php', { method: 'POST', body: fd });
    if (r.redirected) { window.location.href = r.url; return; }
    const text = await r.text();
    // If it went through we get a redirect; otherwise show something happened
    window.location.href = 'user-dashboard.php';
  } catch (e) {
    statusEl.textContent = 'Submit failed';
  }
}

document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); saveNow(false); }
});
window.addEventListener('beforeunload', e => {
  if (dirty) { e.preventDefault(); e.returnValue = ''; }
});

// Trigger initial recalculation
updateTotal();
</script>
<?= xl_io_modal('ppmp', ['fy' => (int)$fy]) /* v105: Import PPMP.xlsx */ ?>
<?php /* v111: this office's restored PPMPs are no longer listed under the PPMP — they are in the
         "Restored" folder (the 📂 Restored button at the top opens it: user-dashboard.php?restored=1) */ ?>
  </main>
</div>
</body></html>
