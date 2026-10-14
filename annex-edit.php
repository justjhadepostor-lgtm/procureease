<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/companions.php';
require_once __DIR__ . '/config/annex-extra.php';
require_login();
ensure_admin_tables();
annex_ensure_columns();

$u   = current_user();
// Everyone prepares and saves; only the Department Admin submits.
$__canSubmit = can_submit_for_dept($u);
$__headName  = dept_submitter_name($u);

$pdo = db();
$fy  = active_fy();
$deptCode = $u['dept_code'] ?? '';

// Open a specific annex (?id=), start a fresh one (?new=1), or pick up
// wherever this department left off.
$formId = (int)($_GET['id'] ?? 0);
$startNew = isset($_GET['new']);
$form = null;

// v99: "＋ Annex D" — a set has one annex of each letter, A to E. The new one is
// made at once (like the first one is), so it saves as itself from the start.
if ($startNew && $formId === 0 && $deptCode !== '') {
    $__want = strtoupper((string)($_GET['letter'] ?? ''));
    if (!in_array($__want, ANNEX_LETTERS, true)) {
        $__want = '';
        foreach (ANNEX_LETTERS as $__l) if (!annex_letter_taken($deptCode, $fy, $__l)) { $__want = $__l; break; }
    }
    if ($__want === '') { flash('error', 'This set already has Annex A to E — one of each.'); header('Location: annex-edit.php'); exit; }
    if ($__have = annex_letter_taken($deptCode, $fy, $__want)) { header('Location: annex-edit.php?id=' . $__have); exit; }
    try {
        $pdo->prepare("INSERT INTO annex_forms (fy, dept_code, annex, status, created_by) VALUES (?, ?, ?, 'draft', ?)")
            ->execute([$fy, $deptCode, $__want, $u['full_name']]);
        $__nid = (int)$pdo->lastInsertId();
        log_activity("Started Annex $__want", 'annex', "$deptCode · FY $fy");
        header('Location: annex-edit.php?id=' . $__nid); exit;
    } catch (Throwable $t) {
        flash('error', 'The annex could not be started. Please try again.');
        header('Location: annex-edit.php'); exit;
    }
}

if ($formId > 0) {
    try {
        $s = $pdo->prepare('SELECT * FROM annex_forms WHERE id = ? AND dept_code = ?');
        $s->execute([$formId, $deptCode]);
        $form = $s->fetch();
    } catch (Throwable $t) {}
    if (!$form) {
        flash('error', 'That annex could not be found.');
        header('Location: annex-edit.php'); exit;
    }
    arc_redirect_if_archived((int)($form['archived'] ?? 0) === 1, 'Annex', 'annex-print.php?id=' . (int)$form['id']);
    fy_past_stop((int)$form['fy'], 'Annex', 'annex-print.php?id=' . (int)$form['id']);   // a past year's annex is for viewing only
} elseif (!$startNew) {
    // v131: opened from the sidebar (no annex chosen) — Annex A of this set
    $__aId = $deptCode !== '' ? annex_letter_taken($deptCode, $fy, 'A') : 0;
    if ($__aId > 0) {
        try {
            $s = $pdo->prepare('SELECT * FROM annex_forms WHERE id = ? AND dept_code = ?');
            $s->execute([$__aId, $deptCode]);
            $form = $s->fetch() ?: null;
        } catch (Throwable $t) {}
    }
    // Continue the annex still being worked on, so the page is never blank
    if (!$form) try {
        $s = $pdo->prepare("SELECT * FROM annex_forms
                             WHERE dept_code = ? AND fy = ?
                               AND status IN ('draft','revision')
                               AND (archived IS NULL OR archived = 0)
                          ORDER BY updated_at DESC, id DESC LIMIT 1");
        $s->execute([$deptCode, $fy]);
        $form = $s->fetch() ?: null;
    } catch (Throwable $t) {}

    // v78: once the department has submitted its Annex, this page shows the one
    // submitted (or approved) rather than starting a blank one over it. A blank
    // draft opened after the set was sent (no project name, no lines, no amount)
    // is set aside the same way; an annex sent back for revision always comes first.
    $sent = null;
    try {
        $s = $pdo->prepare("SELECT * FROM annex_forms
                             WHERE dept_code = ? AND fy = ?
                               AND status IN ('submitted','approved')
                               AND (archived IS NULL OR archived = 0)
                          ORDER BY submitted_at DESC, id DESC LIMIT 1");
        $s->execute([$deptCode, $fy]);
        $sent = $s->fetch() ?: null;
    } catch (Throwable $t) {}
    if ($sent && $form && $__aId === 0 && ($form['status'] ?? '') === 'draft' && trim((string)($form['project_name'] ?? '')) === ''
        && (float)($form['total_estimated'] ?? 0) == 0) {
        $__lines = 1;
        try {
            $s = $pdo->prepare('SELECT COUNT(*) FROM annex_items WHERE form_id = ?');
            $s->execute([(int)$form['id']]);
            $__lines = (int)$s->fetchColumn();
        } catch (Throwable $t) {}
        if ($__lines === 0) $form = null;
    }
    if (!$form && $sent) $form = $sent;

    // Nothing in progress and nothing submitted — create the department's annex for this year
    // (v131: Annex A — the first of the set)
    if (!$form) {
        try {
            $pdo->prepare("INSERT INTO annex_forms (fy, dept_code, annex, status, created_by)
                           VALUES (?, ?, 'A', 'draft', ?)")
                ->execute([$fy, $deptCode, $u['full_name']]);
            $newId = (int)$pdo->lastInsertId();
            $s = $pdo->prepare('SELECT * FROM annex_forms WHERE id = ?');
            $s->execute([$newId]);
            $form = $s->fetch();
        } catch (Throwable $t) {}
    }
    if ($form) $formId = (int)$form['id'];
}

// What each annex letter is called on the official forms. These fill in
// automatically when the letter is chosen, and stay editable in case a
// unit needs different wording.
$ANNEX_TYPES = [
    'A' => ['subtitle' => 'Goods',                                        'note' => 'Can be used for Item A.1.'],
    'B' => ['subtitle' => 'Training, Seminars and Other Activities',      'note' => 'Can be used for Item B.1.'],
    'C' => ['subtitle' => 'Equipment and Other Capital Outlay',           'note' => 'Can be used for Item C.1.'],
    // v99: Annex D and E — no standard title; the unit types it on the form
    'D' => ['subtitle' => '', 'note' => ''],
    'E' => ['subtitle' => '', 'note' => ''],
];

$annexLetter = $form['annex'] ?? 'B';
if (!isset($ANNEX_TYPES[$annexLetter])) $annexLetter = 'B';

$subtitle = $form['subtitle'] ?? '';
if ($subtitle === '') $subtitle = $ANNEX_TYPES[$annexLetter]['subtitle'];

$itemNote = $form['item_note'] ?? '';
if ($itemNote === '') $itemNote = $ANNEX_TYPES[$annexLetter]['note'];

$status = $form['status'] ?? 'draft';
// Once submitted or approved the department can no longer edit it
$locked = in_array($status, ['submitted','approved'], true);
// Before the PPMP is submitted, the Annex goes with it
[$__solo] = companion_solo_submit($deptCode, (int)($form['fy'] ?? $fy), (string)$status);

$items = [];
if ($form) {
    try {
        $it = $pdo->prepare('SELECT * FROM annex_items WHERE form_id = ? ORDER BY sort_order, id');
        $it->execute([$form['id']]);
        $items = $it->fetchAll();
    } catch (Throwable $t) {}
}

// Defaults for a fresh form
$f = [
    'project_name'        => $form['project_name']        ?? '',
    'general_description' => $form['general_description'] ?? '',
    'nep_account_1'       => $form['nep_account_1']       ?? '',
    'allocated_budget_1'  => $form['allocated_budget_1']  ?? '',
    'nep_account_2'       => $form['nep_account_2']       ?? '',
    'allocated_budget_2'  => $form['allocated_budget_2']  ?? '',
    'inclusive_dates'     => $form['inclusive_dates']     ?? '',
    // NEP Account #1 + #2 (config/annex-extra.php)
    'total_estimated'     => annex_estimated($form) > 0 ? number_format(annex_estimated($form), 2, '.', ',') : '',
];
// The unit line: this department's / unit's own name unless changed on the form
$unitLine = annex_unit_line($form, $deptCode);

// The expenditure groups shown on Annex B (left labels)
$B_GROUPS = ['Supplies and Materials','Honoraria','Venue (Proposed Location)','Meals and Snack','Others','Registration Fee'];

$activeNav = (($u['role'] ?? '') === 'admin') ? 'my-annex' : 'annex';
$pageTitle = 'Annex';
doc_fmt_use_year(active_fy());   // editors work on the active year
require __DIR__ . '/includes/head.php';
?>
<style>
  .annex-tabs { display:flex; gap:8px; flex-wrap:wrap; margin:14px 0 18px; }
  /* v99: the annexes of the set, A–E */
  .anx-letters { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin:12px 0 0; }
  .anx-letters .anx-lbl { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); margin-right:4px; }
  .anx-l { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:9px; font-weight:700; font-size:12.5px;
           text-decoration:none; background:var(--white); color:var(--ink); border:1px solid var(--border); }
  .anx-l small { font-weight:600; font-size:10.5px; color:var(--muted); }
  .anx-l.on { background:var(--accent); color:var(--accent-ink); border-color:var(--accent); }
  .anx-l.on small { color:var(--accent-ink); opacity:.9; }
  .anx-l.anx-add { background:transparent; border:1.5px dashed var(--navy-500); color:var(--ink); }
  .anx-l:hover { border-color:var(--accent); }
  .annex-tab { padding:9px 22px; border-radius:9px; border:1.5px solid var(--border); background:#fff; color:#133458; font-weight:700; font-size:14px; text-decoration:none; font-family:'Space Grotesk',sans-serif; }
  .annex-tab.active { background:#133458; color:#fff; border-color:#133458; }

  /* Sheet mimics an A4/long bond page */
  .annex-sheet { background:#fff; border:1px solid #cfd8e2; border-radius:8px; padding:30px 40px; max-width:820px; margin:0 auto; font-family: Arial, 'Helvetica Neue', sans-serif; color:#000; }
  .annex-head { text-align:center; margin-bottom:14px; }
  .annex-head .rep { font-size:12px; }
  .annex-head .sch { font-weight:bold; font-size:14px; }
  .annex-head .addr { font-size:11px; }
  .annex-head .unit { font-size:12px; font-weight:bold; color:#c00000; margin-top:2px; }
  .annex-head .atitle { font-size:16px; font-weight:bold; letter-spacing:4px; margin-top:12px; }
  .annex-head .asub { font-size:12.5px; }
  .annex-head .afy { font-size:12px; }
  .annex-head .atitle select {
    font-family: inherit; font-size: 16px; font-weight: bold; letter-spacing: 4px;
    border: none; border-bottom: 1px dashed #999; background: transparent;
    padding: 0 2px; cursor: pointer; color: #000;
  }
  .annex-head { position:relative; padding:0 82px; }
  .annex-head .annex-logo { position:absolute; top:0; width:66px; height:66px; object-fit:contain; }
  .annex-head .annex-logo.left { left:0; } .annex-head .annex-logo.right { right:0; }
  @media(max-width:600px){ .annex-head { padding:58px 0 0; } .annex-head .annex-logo { width:48px;height:48px; } }
  .annex-head .atitle select:focus { outline: none; border-bottom-color: #1c4c86; }
  .annex-head .atitle select:disabled { border-bottom: none; cursor: default; appearance: none; -webkit-appearance: none; }
  .annex-head .asub input {
    width: 100%; text-align: center; font-family: inherit; font-size: 12.5px;
    border: none; border-bottom: 1px dashed #bbb; background: transparent; padding: 2px 4px; color: #000;
  }
  .annex-head .asub input:focus { outline: none; border-bottom-color: #1c4c86; }
  .annex-head .asub input:read-only { border-bottom: none; }
  /* the department's / unit's name — the form's red line, typed over in place */
  .annex-head .unit input { width: 60%; max-width: 440px; text-align: center; font: inherit; font-weight: bold; color: #c00000;
    background: transparent; border: none; border-bottom: 1px dashed #d9a3a3; padding: 1px 4px; text-transform: uppercase; }
  .annex-head .unit input:focus { outline: none; border-bottom: 1px solid #c00000; }
  .annex-head .unit input:read-only { border-bottom: none; }
  body[data-theme="dark"] .annex-sheet .annex-head .unit input { color: #c00000 !important; background: transparent !important; }
  #f_total_estimated[readonly] { cursor: default; }
  .annex-note-bar input {
    border: none; background: transparent; font-family: inherit; font-style: italic;
    font-size: 11.5px; color: #000; width: 60%; padding: 0;
  }
  .annex-note-bar input:focus { outline: none; background: rgba(255,255,255,0.55); }

  .annex-note-bar { background:#ffff00; padding:5px 8px; font-style:italic; font-size:11.5px; line-height:1.5; margin-bottom:0; }

  /* Field box grid — bordered like the form */
  table.annex-fields { width:100%; border-collapse:collapse; font-size:12px; }
  table.annex-fields td { border:1px solid #000; padding:4px 7px; vertical-align:middle; }
  table.annex-fields td.lb { width:38%; }
  table.annex-fields td.lb small { color:#333; font-size:10px; font-style:italic; }
  table.annex-fields td.colon { width:16px; text-align:center; }
  table.annex-fields td.nn { width:34px; text-align:left; font-weight:bold; }
  table.annex-fields .section-bar td { background:#808080; -webkit-print-color-adjust:exact; print-color-adjust:exact; height:16px; }
  table.annex-fields td.gray-cell { background:#808080; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  table.annex-fields input { width:100%; box-sizing:border-box; border:none; background:transparent; font-size:12px; font-family:inherit; padding:2px 3px; }
  table.annex-fields input.num { text-align:right; }
  table.annex-fields input:focus { outline:1px solid #4A7AAD; }
  table.annex-fields input::placeholder { color:#aaa; font-style:italic; }
  table.annex-tbl input::placeholder { color:#bbb; font-style:italic; }
  table.annex-fields td.bold { font-weight:bold; font-style:italic; }

  /* Items table */
  table.annex-tbl { width:100%; border-collapse:collapse; margin-top:16px; font-size:12px; }
  table.annex-tbl th, table.annex-tbl td { border:1px solid #000; padding:4px 7px; }
  table.annex-tbl th { text-align:center; font-weight:bold; font-size:12px; }
  table.annex-tbl td.num { text-align:right; }
  table.annex-tbl tr.grp-row td { background:#808080; -webkit-print-color-adjust:exact; print-color-adjust:exact; color:#000; font-style:italic; font-weight:bold; }
  table.annex-tbl td input { width:100%; box-sizing:border-box; border:none; background:transparent; padding:3px 4px; font-size:12px; font-family:inherit; }
  table.annex-tbl td.num input { text-align:right; }
  table.annex-tbl td input:focus { outline:1px solid #4A7AAD; }
  table.annex-tbl tr.total-row td { font-weight:bold; font-style:italic; text-align:center; }
  table.annex-tbl .delrow-a { border:none; background:none; color:#dc2626; font-size:15px; cursor:pointer; }
  table.annex-tbl .addrow-a { border:1px solid #4A7AAD; background:#fff; color:#4A7AAD; border-radius:5px; padding:0 7px; font-size:12px; font-weight:700; cursor:pointer; }
  .annex-instr { margin-top:20px; font-size:10.5px; line-height:1.6; }
  .annex-instr .h { font-weight:bold; }
</style>

<div class="app">
<?php
  // Load the right sidebar for this role
  $__role = $u['role'] ?? '';
  if     ($__role === 'admin')  require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($__role === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
  else                          require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Annex · FY <?= $fy ?></h1>
        <p class="page-sub">
          <?php if ($locked): /* v133: the status is in the ✓ Submitted box below — here, the department */ ?>
            <?= e((string)($u['dept_name'] ?? '')) ?>
          <?php else: ?> Fill out the form, then submit it to the Procurement Unit.
          <?php endif; ?></p><?php require_once __DIR__ . '/includes/unit-switch.php'; echo unit_pick_html($u);   /* v87: which program / major or set */ ?>
          <?php /* v99: the annexes of this set, A to E — open one, or start the next letter */
            $__afy = (int)($form['fy'] ?? $fy);
            $__aforms = annex_set_forms($deptCode, $__afy);
            $__used = array_map(fn($r) => strtoupper((string)$r['annex']), $__aforms);
            require_once __DIR__ . '/config/office-sets.php';
            $__setLbl = os_set_label($deptCode); ?>
          <nav class="anx-letters" id="annexLetters" aria-label="Annexes of this set">
            <span class="anx-lbl">Annexes<?= $__setLbl !== '' ? ' of ' . e($__setLbl) : '' ?></span>
            <?php foreach ($__aforms as $__r):
                $__st = (string)$__r['status'];
                $__state = $__st === 'approved' ? 'approved' : ($__st === 'submitted' ? 'submitted' : ($__st === 'revision' ? 'for revision'
                         : (trim((string)$__r['project_name']) === '' ? 'blank' : 'draft')));
                $__on = (int)$__r['id'] === (int)($form['id'] ?? 0); ?>
              <a href="annex-edit.php?id=<?= (int)$__r['id'] ?>" class="anx-l<?= $__on ? ' on' : '' ?>"<?= $__on ? ' aria-current="page"' : '' ?>>Annex <?= e(strtoupper((string)$__r['annex'])) ?> <small><?= e($__state) ?></small></a>
            <?php endforeach; ?>
            <?php if ($__afy === active_fy()) foreach (ANNEX_LETTERS as $__l): if (in_array($__l, $__used, true)) continue; ?>
              <a href="annex-edit.php?new=1&amp;letter=<?= $__l ?>" class="anx-l anx-add">＋ Annex <?= $__l ?></a>
            <?php endforeach; ?>
          </nav></div>
      <div class="annex-actions" data-dock>
        <?= $form ? arc_button($u, arc_load('annex', (int)$form['id']), 'annex-edit.php?id=' . (int)$form['id']) : '' ?>
        <?php if ($form): ?>
          <?php require_once __DIR__ . '/config/doc-versions.php'; echo doc_history_button('annex', (string)(int)$form['id']);   /* v79 */ ?>
          <?php require_once __DIR__ . '/includes/xl-io-ui.php'; echo xl_io_buttons('annex', 'api/xl-export.php?doc=annex&id=' . (int)$form['id'], !$locked);   /* v105: ANNEX.xlsx */ ?>
          <?php if ($locked): ?>
            <a href="annex-print.php?id=<?= (int)$form['id'] ?>" target="_blank" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">🖨️ Print</a>
          <?php else: ?>
            <button type="button" onclick="printAnnex()" id="printBtn" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">🖨️ Print</button>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!$locked): ?>
          <button type="button" onclick="saveAnnex()" id="saveBtn" class="btn btn-ghost" style="padding:8px 16px; font-size:13px;">💾 Save</button>
          <?php if (!$__canSubmit && !$__solo && (require_once __DIR__ . '/config/head-submit.php') && hs_applies($u)): /* v112: an end user submits to the Department Admin */ ?>
          <?= hs_user_controls($u, (int)($form['fy'] ?? $fy), 'return openSubmitSet(event)') ?>
          <?php elseif (!$__canSubmit): ?>
          <span style="font-size:11.5px; color:var(--muted); align-self:center;">
            <?= $__headName !== '' ? e($__headName) . ' submits for your department.' : 'Only your Department Admin can submit.' ?>
          </span>
          <?php elseif (!$__solo && (require_once __DIR__ . '/config/unit-review.php') && ur_whole_school($u, (int)($form['fy'] ?? $fy))): ?>
          <?= ur_submit_link((int)($form['fy'] ?? $fy), (string)($u['school_code'] ?? '')) ?>
          <?php elseif (!$__solo): ?>
          <a href="ppmp-edit.php?fy=<?= (int)($form['fy'] ?? $fy) ?>" onclick="return openSubmitSet(event)" class="btn btn-primary" id="submitWithPpmp" style="padding:8px 18px; font-size:13px; text-decoration:none;"
             title="The Annex is submitted together with your PPMP">📤 Submit</a>
          <?php else: ?>
          <button type="button" onclick="submitAnnex()" id="submitBtn" class="btn btn-primary" style="padding:8px 18px; font-size:13px;">📤 Submit</button>
          <?php endif; ?>
        <?php else: ?>
          <?php /* v133: no "📤 Submitted / ✓ Approved" here — the ✓ Submitted box below says it once */ ?>
        <?php endif; ?></div></div>
    <?php render_flashes(); ?>
    <?php if (!$locked) { require_once __DIR__ . '/config/unit-review.php'; echo ur_bar($u, (int)($form['fy'] ?? $fy)); }   /* v81: a program / major of a school */ ?>
    <?php if (!empty($form) && empty($msForm ?? 0)): /* "✓ Submitted" — where it is now */ ?>
      <?= submitted_stamp((string)($form['status'] ?? 'draft'), $form['submitted_at'] ?? null, $form['submitted_by'] ?? null,
            ($form['status'] ?? '') === 'approved'
              ? 'Approved by the Procurement Unit' . (!empty($form['reviewed_at']) ? ' on ' . date('M j, Y', strtotime($form['reviewed_at'])) : '') . '.'
              : 'now with the Procurement Unit') ?>
    <?php endif; ?>

    <?php if ($status === 'revision' && !empty($form['review_note'])): ?>
      <div style="background:#FFF4EC; border-left:4px solid #F97316; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:13px; color:#C2410C;">
        <strong>Sent back for revision:</strong> <?= e($form['review_note']) ?></div>
    <?php endif; ?>

    <!-- ─────────────── ANNEX FORM ─────────────── -->
    <div class="annex-sheet" id="annexSheet">
      <div class="annex-head">
        <?php if (doc_logo('annex', 'left')): ?><img class="annex-logo left" src="<?= e(doc_logo('annex', 'left')) ?>" alt="ASCOT logo"><?php endif; ?>
        <?php if (doc_logo('annex', 'right')): ?><img class="annex-logo right" src="<?= e(doc_logo('annex', 'right')) ?>" alt="Bagong Pilipinas logo"><?php endif; ?>
        <div class="rep"><?= e(doc_fmt('annex', 'rep_line')) ?></div>
        <div class="sch"><?= e(doc_fmt('annex', 'org_name')) ?></div>
        <div class="addr"><?= e(doc_fmt('annex', 'address')) ?></div>
        <div class="unit"><input id="f_unit_name" value="<?= e($unitLine) ?>" maxlength="255" aria-label="Department or unit" title="Your department or unit — you can change it" <?= $locked ? 'readonly' : '' ?>></div>
        <div class="atitle"> A N N E X&nbsp;&nbsp;<span id="annexLetterText"><?= e($annexLetter) ?></span><?php /* v130: no drop-down — the Annex buttons above open or start each letter */ ?>
            <input type="hidden" id="f_annex_letter" value="<?= e($annexLetter) ?>"></div>
        <div class="asub">
          <input id="f_subtitle" value="<?= e($subtitle) ?>" placeholder="Title of this annex"
                 <?= $locked ? 'readonly' : '' ?>></div>
        <div class="afy"><?= e(doc_fmt('annex', 'lbl_fy')) ?> <?= $fy ?></div></div>

      <div class="annex-note-bar">
        *Use another sheet for different projects/program/activity<br>
        *<input id="f_item_note" value="<?= e($itemNote) ?>" placeholder="<?= e('Can be used for Item ' . $annexLetter . '.1.') /* v99: the letter of this annex */ ?>"
                <?= $locked ? 'readonly' : '' ?>></div>

      <table class="annex-fields">
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_project')) ?></td>
          <td class="colon">:</td>
          <td colspan="2" style="text-align:center;"><input id="f_project_name" value="<?= e($f['project_name']) ?>" placeholder="e.g. Trainings and Seminars" style="text-align:center;" <?= $locked ? 'readonly' : '' ?>></td></tr>
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_general')) ?><br><small>(As categorized in the PPMP)</small></td>
          <td class="colon">:</td>
          <td colspan="2" style="text-align:center;"><input id="f_general_description" value="<?= e($f['general_description']) /* v102: blank until the unit types it */ ?>" style="text-align:center;"></td></tr>
        <tr><td colspan="2" style="text-align:center;"><?= e(doc_fmt('annex', 'lbl_item')) ?></td><td colspan="2" class="gray-cell">&nbsp;</td></tr>
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_nep')) ?></td>
          <td class="nn">#1</td>
          <td colspan="2"><input id="f_nep_account_1" value="<?= e($f['nep_account_1']) ?>" placeholder="e.g. Training and Seminar"></td></tr>
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_alloc')) ?></td>
          <td class="colon">:</td>
          <td colspan="2"><input id="f_allocated_budget_1" class="num money" inputmode="decimal" value="<?= e($f['allocated_budget_1']) ?>" placeholder="e.g. 20,000.00"></td></tr>
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_nep')) ?><br><small>(If NEP Account #1 allocation is not sufficient)</small></td>
          <td class="nn">#2</td>
          <td colspan="2"><input id="f_nep_account_2" value="<?= e($f['nep_account_2']) ?>" placeholder="Alternative NEP Account"></td></tr>
        <tr>
          <td class="lb"><?= e(doc_fmt('annex', 'lbl_alloc')) ?></td>
          <td class="colon">:</td>
          <td colspan="2"><input id="f_allocated_budget_2" class="num money" inputmode="decimal" value="<?= e($f['allocated_budget_2']) ?>" placeholder="e.g. 20,000.00"></td></tr>
        <tr>
          <td class="lb bold"><?= e(doc_fmt('annex', 'lbl_totbudget')) ?></td>
          <td class="colon">:</td>
          <td colspan="2"><input id="f_total_estimated" class="num money" inputmode="decimal" value="<?= e($f['total_estimated'] ?? '') ?>" placeholder="0.00" readonly tabindex="-1" title="NEP Account #1 + NEP Account #2" style="font-weight:bold; font-style:italic;"></td></tr>
        <tr>
          <td class="lb" style="font-style:italic;"><?= e(doc_fmt('annex', 'lbl_dates')) ?> <small>(tentative)</small></td>
          <td class="colon">:</td>
          <td colspan="2"><input id="f_inclusive_dates" value="<?= e($f['inclusive_dates'] !== '' ? $f['inclusive_dates'] : 'as per schedule') ?>" style="text-align:center;"></td></tr></table>

      <table class="annex-tbl" id="annexTable">
        <thead>
          <tr>
            <th style="width:56%;"><?= e(doc_fmt('annex', 'col_items')) ?></th>
            <th style="width:16%;"><?= e(doc_fmt('annex', 'col_qty')) ?></th>
            <th style="width:24%;"><?= e(doc_fmt('annex', 'col_total')) ?></th>
            <th style="width:30px; border:none;"></th></tr></thead>
        <tbody id="annexBody">
          <?php
            $byGroup = [];
            foreach ($items as $row) {
                $g = $row['item_group'] !== '' ? $row['item_group'] : 'Others';
                $byGroup[$g][] = $row;
            }
            foreach ($B_GROUPS as $g):
              $groupRows = $byGroup[$g] ?? [];
              // Show at least 5 blank rows per group (like the printed form), plus any saved rows
              $blankCount = max(5 - count($groupRows), 1);
          ?>
            <tr class="grp-row" data-group="<?= e($g) ?>">
              <td colspan="3"><?= e($g) ?></td>
              <td style="text-align:center; border:none;"><button type="button" class="addrow-a" title="Add item to <?= e($g) ?>" onclick="addRowA(this)">＋</button></td></tr>
            <?php foreach ($groupRows as $row): ?>
              <tr class="item-row" data-group="<?= e($g) ?>">
                <td><input class="i-spec" value="<?= e($row['specification']) ?>"></td>
                <td class="num"><input class="i-qty" value="<?= $row['quantity']!=0 ? rtrim(rtrim(number_format((float)$row['quantity'],2,'.',''),'0'),'.') : '' ?>" oninput="recalc()"></td>
                <td class="num"><input class="i-amt money" inputmode="decimal" value="<?= $row['total_amount']!=0 ? number_format((float)$row['total_amount'],2,'.','') : '' ?>" oninput="recalc()"></td>
                <td style="text-align:center; border:none;"><button type="button" class="delrow-a" onclick="delRowA(this)">×</button></td></tr>
            <?php endforeach; ?>
            <?php for ($k=0; $k<$blankCount; $k++): ?>
              <tr class="item-row" data-group="<?= e($g) ?>">
                <td><input class="i-spec"></td>
                <td class="num"><input class="i-qty" oninput="recalc()"></td>
                <td class="num"><input class="i-amt money" inputmode="decimal" oninput="recalc()"></td>
                <td style="text-align:center; border:none;"><button type="button" class="delrow-a" onclick="delRowA(this)">×</button></td></tr>
            <?php endfor; ?>
          <?php endforeach; ?>
          <tr class="total-row">
            <td style="text-align:center; font-weight:bold; font-style:italic;"><?= e(doc_fmt('annex', 'col_total')) ?></td>
            <td></td>
            <td class="num" id="annexTotal" style="font-weight:bold; font-style:italic;">0.00</td>
            <td style="border:none;"></td></tr></tbody></table>

      <div class="annex-instr">
        <div class="h"><?= e(doc_fmt('annex', 'instr_title')) ?></div>
        <?= doc_block('annex', 'instr_body') ?></div></div>
      <?php require_once __DIR__ . '/config/past-years.php'; echo past_year_mine_html('annex', (string)$deptCode);   /* v104: restored, earlier years */ ?></main></div>

<script> const FY = <?= $fy ?>;
  const FORM_ID = <?= (int)($form['id'] ?? 0) ?>;
  const LOCKED = <?= $locked ? 'true' : 'false' ?>;

  // ── Annex type ──
  // Choosing a letter fills in the standard wording underneath it.
  // Both fields stay editable afterwards.
  const ANNEX_TYPES = <?= json_encode($ANNEX_TYPES) ?>;


  function applyAnnexType(){
    const sel = document.getElementById('f_annex_letter');
    if (!sel) return;
    const t = ANNEX_TYPES[sel.value];
    if (!t) return;
    const sub  = document.getElementById('f_subtitle');
    const note = document.getElementById('f_item_note');
    if (sub)  sub.value  = t.subtitle;
    if (note) note.value = t.note;
  }

  function peso(n){ return '₱' + (isNaN(n)?0:n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  function recalc(){
    let totalCents = 0;
    document.querySelectorAll('#annexBody tr.item-row').forEach(function(tr){
      const amt = parseFloat((tr.querySelector('.i-amt')||{}).value)||0;
      totalCents += Math.round(amt * 100);
    });
    const el = document.getElementById('annexTotal');
    if (el) el.textContent = peso(totalCents / 100);
    // Total Estimated Budget = Allocated Budget of NEP Account #1 + NEP Account #2
    const teb = document.getElementById('f_total_estimated');
    const nepCents = Math.round((parseFloat((document.getElementById('f_allocated_budget_1')||{}).value)||0)*100)
                   + Math.round((parseFloat((document.getElementById('f_allocated_budget_2')||{}).value)||0)*100);
    const nep = nepCents / 100;
    if (teb && (nep > 0 || !teb.dataset.legacy)) {
      teb.value = nep ? (Math.round(nep * 100) / 100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) : '';
    }
  }

  function makeItemRow(){
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML =
      '<td><input class="i-spec"></td>'+
      '<td class="num"><input class="i-qty" oninput="recalc()"></td>'+
      '<td class="num"><input class="i-amt money" inputmode="decimal" oninput="recalc()"></td>'+
      '<td style="text-align:center;"><button type="button" class="delrow-a" onclick="delRowA(this)">×</button></td>';
    return tr;
  }

  // Add an item row under the group whose ＋ button was clicked
  function addRowA(btn){
    const grpRow = btn.closest('tr.grp-row');
    const group = grpRow.dataset.group;
    // find last item-row in this group; insert after it (else right after group row)
    let ref = grpRow;
    let n = grpRow.nextElementSibling;
    while (n && n.classList.contains('item-row') && n.dataset.group === group){
      ref = n; n = n.nextElementSibling;
    }
    const tr = makeItemRow();
    tr.dataset.group = group;
    ref.after(tr);
    tr.querySelector('.i-spec').focus();
  }

  function delRowA(btn){
    const tr = btn.closest('tr.item-row');
    if (tr){ tr.remove(); recalc(); }
  }

  function collectAnnex(){
    const g = id => (document.getElementById(id)||{}).value || '';
    const rows = [];
    document.querySelectorAll('#annexBody tr.item-row').forEach(function(tr){
      const spec = (tr.querySelector('.i-spec')||{}).value || '';
      const grp  = tr.dataset.group || '';
      const qty  = parseFloat((tr.querySelector('.i-qty')||{}).value)||0;
      const amt  = parseFloat((tr.querySelector('.i-amt')||{}).value)||0;
      if (spec.trim()==='' && qty===0 && amt===0) return;
      rows.push({ item_group: grp, specification: spec, quantity: qty, total_amount: amt });
    });
    return {
      id: FORM_ID, fy: FY,
      annex:      (document.getElementById('f_annex_letter')||{}).value || 'B',
      subtitle:   g('f_subtitle'),
      item_note:  g('f_item_note'),
      project_name: g('f_project_name'),
      general_description: g('f_general_description'),
      nep_account_1: g('f_nep_account_1'),
      allocated_budget_1: parseFloat(g('f_allocated_budget_1'))||0,
      nep_account_2: g('f_nep_account_2'),
      allocated_budget_2: parseFloat(g('f_allocated_budget_2'))||0,
      inclusive_dates: g('f_inclusive_dates'),
      total_estimated: parseFloat((g('f_total_estimated')||'').replace(/,/g,''))||0,
      unit_name: g('f_unit_name'),
      items: rows
    };
  }

  async function postAnnex(payload){
    const res = await fetch('api/save-annex.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    return await res.json();
  }

  async function saveAnnex(){
    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      const data = await postAnnex(collectAnnex());
      if (data.ok){
        btn.textContent='✓ Saved';
        if (data.id && !FORM_ID) {
          // First save — reload so the page is now tied to the saved annex
          setTimeout(function(){ window.location.href = 'annex-edit.php?id=' + data.id; }, 700);
          return;
        }
        setTimeout(()=>{btn.textContent=' Save';btn.disabled=false;},1200);
      }
      else { btn.textContent=' Save'; btn.disabled=false; alert(data.error||'Could not save.'); }
    } catch(e){ btn.textContent=' Save'; btn.disabled=false; alert('Could not save. Check your connection.'); }
  }


  // Print saves first, so the printout always matches what is on screen
  async function printAnnex(){
    const btn = document.getElementById('printBtn');
    const label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Preparing…';
    try {
      const data = await postAnnex(collectAnnex());
      btn.textContent = label; btn.disabled = false;
      if (data.ok) window.open('annex-print.php?id=' + (data.id || window.FORM_ID_LIVE || FORM_ID), '_blank');
      else alert(data.error || 'Could not prepare the printout.');
    } catch(e){
      btn.textContent = label; btn.disabled = false;
      alert('Could not prepare the printout. Check your connection.');
    }
  }


  // ═══════════ Auto-save ═══════════
  // Everything typed is saved on its own a moment after you stop, so the
  // document on screen and the one on file never drift apart.
  let autoTimer = null, autoBusy = false;

  function showSaveState(text, tone){
    // Only a failure is shown; saving on its own goes on quietly (no repeated "Saved")
    if (tone !== 'err') text = '';
    let el = document.getElementById('autoSaveState');
    if (!el) {
      el = document.createElement('span');
      el.id = 'autoSaveState';
      el.style.cssText = 'font-size:12px; margin-right:10px; align-self:center;';
      const bar = document.querySelector('.annex-actions');
      if (bar) bar.insertBefore(el, bar.firstChild);
    }
    el.dataset.state = tone === 'ok' ? 'saved' : (tone === 'busy' ? 'saving' : 'error');   // where saving is (not shown)
    el.textContent = text;
    el.style.color = tone === 'ok' ? '#16a34a' : (tone === 'busy' ? '#5A7896' : '#C2410C');
  }

  async function autoSave(){
    if (LOCKED || autoBusy) return;
    autoBusy = true;
    showSaveState('Saving…', 'busy');
    try {
      const data = await postAnnex(collectAnnex());
      if (data.ok){
        showSaveState('Saved', 'ok');
        // First save gives the record an id; keep it for the next one
        if (data.id && !FORM_ID) {
          history.replaceState(null, '', 'annex-edit.php?id=' + data.id);
          window.FORM_ID_LIVE = data.id;
        }
      } else {
        showSaveState('Not saved', 'err');
      }
    } catch(e){
      showSaveState('Not saved — offline?', 'err');
    }
    autoBusy = false;
  }

  // Submit opens the window for the whole set; save first (includes/submit-set-modal.php)
  window.submitSetSave = async function(){
    clearTimeout(autoTimer);
    while (autoBusy) await new Promise(function(r){ setTimeout(r, 100); });
    try {
      const data = await postAnnex(collectAnnex());
      if (data.ok) {
        showSaveState('Saved', 'ok');
        if (data.id && !FORM_ID) { history.replaceState(null, '', 'annex-edit.php?id=' + data.id); window.FORM_ID_LIVE = data.id; }
        return true;
      }
      alert(data.error || 'Your Annex could not be saved, so it was not submitted.');
    } catch (e) { alert('Could not save. Check your connection.'); }
    return false;
  };

  function queueAutoSave(){
    if (LOCKED) return;
    showSaveState('Unsaved…', 'busy');
    clearTimeout(autoTimer);
    autoTimer = setTimeout(autoSave, window.PE_AUTOSAVE_MS || 1500);
  }

  if (!LOCKED) {
    document.addEventListener('input',  e => { if (e.target.closest('.annex-sheet')) queueAutoSave(); });
    document.addEventListener('change', e => { if (e.target.closest('.annex-sheet')) queueAutoSave(); });
    // Catch anything still pending when the tab closes
    window.addEventListener('beforeunload', function(){ if (autoTimer) { clearTimeout(autoTimer); autoSave(); } });
  }

  function submitAnnex(){
    swiftConfirm({
      title: 'Submit this annex?',
      text: 'This sends your annex to the Procurement Unit for review. You will not be able to edit it afterwards.',
      confirmLabel: 'Submit',
      tone: 'info',
      onConfirm: doSubmitAnnex
    });
  }

  async function doSubmitAnnex(){
    const btn = document.getElementById('submitBtn');
    btn.disabled = true; btn.textContent = 'Submitting…';
    try {
      const payload = collectAnnex();
      payload.submit_now = 1;
      const data = await postAnnex(payload);
      if (data.ok){ window.location.href = 'annex-edit.php?id=' + (data.id || FORM_ID); }
      else { btn.textContent=' Submit'; btn.disabled=false; alert(data.error||'Could not submit.'); }
    } catch(e){ btn.textContent=' Submit'; btn.disabled=false; alert('Could not submit. Check your connection.'); }
  }

  // A submitted or approved annex is read-only for the department
  if (LOCKED) {
    document.querySelectorAll('.annex-sheet input, .annex-sheet textarea').forEach(function(el){
      el.setAttribute('readonly', 'readonly');
    });
    document.querySelectorAll('.annex-sheet .addrow-a, .annex-sheet .delrow-a').forEach(function(el){
      el.style.display = 'none';
    });
  }

  // An annex saved before the total followed NEP #1 + #2, with neither filled in, keeps its own total
  (function(){
    const teb = document.getElementById('f_total_estimated');
    const nep = (parseFloat((document.getElementById('f_allocated_budget_1')||{}).value)||0)
              + (parseFloat((document.getElementById('f_allocated_budget_2')||{}).value)||0);
    if (teb && teb.value.trim() !== '' && nep <= 0) teb.dataset.legacy = '1';
    ['f_allocated_budget_1', 'f_allocated_budget_2'].forEach(function(id){
      const el = document.getElementById(id); if (el) el.addEventListener('input', recalc);
    });
  })();
  recalc();
</script>
<?php if ($form) { require_once __DIR__ . '/includes/xl-io-ui.php'; echo xl_io_modal('annex', ['fy' => (int)($form['fy'] ?? $fy), 'letter' => $annexLetter]); }   /* v105: Import ANNEX.xlsx */ ?>
<?php if (!$locked && $__canSubmit && !$__solo) { $submitSetFy = (int)($form['fy'] ?? $fy); require __DIR__ . '/includes/submit-set-modal.php'; }
      elseif (!$locked && !$__solo && hs_applies($u)) { $submitSetFy = (int)($form['fy'] ?? $fy); $submitSetAction = 'api/submit-to-head.php'; require __DIR__ . '/includes/submit-set-modal.php'; }   /* v112 */ ?>
</body></html>
