<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/richtext.php';
require_once __DIR__ . '/config/signatories.php';
require_once __DIR__ . '/config/market-scoping-template.php';
require_once __DIR__ . '/config/archive.php';
require_once __DIR__ . '/config/companions.php';
require_login();
ensure_admin_tables();

$u   = current_user();
// Everyone prepares and saves; only the Department Admin submits.
$__canSubmit = can_submit_for_dept($u);
$__headName  = dept_submitter_name($u);

$pdo = db();
$fy  = active_fy();
$deptCode = $u['dept_code'] ?? '';

/* The Super Admin editing the year's Market Scoping form itself, from the
   Document Format page — the same editor, saving to the format instead of
   to a document (config/market-scoping-template.php). */
$msForm = 0;
if (isset($_GET['form'])) {
    if (($u['role'] ?? '') !== 'admin') { header('Location: market-scoping-edit.php'); exit; }
    $msForm = (int)$_GET['form'];
    if ($msForm !== active_fy()) {
        flash('error', 'Only the active fiscal year\'s form (FY ' . active_fy() . ') can be edited. Earlier years keep their own form.');
        header('Location: document-format.php?type=scoping#ms-form'); exit;
    }
}

$docId    = (int)($_GET['id'] ?? 0);
$startNew = isset($_GET['new']);
$doc = null;

if ($msForm) {
    // no document: the form of FY $msForm is what is edited
} elseif ($docId > 0) {
    try {
        $s = $pdo->prepare('SELECT * FROM market_scoping WHERE id = ? AND dept_code = ?');
        $s->execute([$docId, $deptCode]);
        $doc = $s->fetch();
    } catch (Throwable $t) {}
    if (!$doc) {
        flash('error', 'That document could not be found.');
        header('Location: market-scoping-edit.php'); exit;
    }
    arc_redirect_if_archived((int)($doc['archived'] ?? 0) === 1, 'Market Scoping', 'market-scoping-print.php?id=' . (int)$doc['id']);
    fy_past_stop((int)$doc['fy'], 'Market Scoping', 'market-scoping-print.php?id=' . (int)$doc['id']);   // a past year's document is for viewing only
} elseif (!$startNew) {
    // Pick up wherever this department left off, so the page is never blank
    try {
        $s = $pdo->prepare("SELECT * FROM market_scoping
                             WHERE dept_code = ? AND fy = ?
                               AND status IN ('draft','revision')
                               AND (archived IS NULL OR archived = 0)
                          ORDER BY updated_at DESC, id DESC LIMIT 1");
        $s->execute([$deptCode, $fy]);
        $doc = $s->fetch() ?: null;
    } catch (Throwable $t) {}

    // v78: once the department has submitted its Market Scoping, this page shows
    // the one submitted (or approved) rather than starting a new one over it. A
    // draft opened after the set was sent and never changed (still the form as it
    // was started) is set aside the same way; one sent back for revision always comes first.
    $sent = null;
    try {
        $s = $pdo->prepare("SELECT * FROM market_scoping
                             WHERE dept_code = ? AND fy = ?
                               AND status IN ('submitted','approved')
                               AND (archived IS NULL OR archived = 0)
                          ORDER BY submitted_at DESC, id DESC LIMIT 1");
        $s->execute([$deptCode, $fy]);
        $sent = $s->fetch() ?: null;
    } catch (Throwable $t) {}
    if ($sent && $doc && ($doc['status'] ?? '') === 'draft') {
        $__c = (string)($doc['content'] ?? '');
        if (trim(html_entity_decode(strip_tags($__c), ENT_QUOTES, 'UTF-8')) === '' || $__c === ms_form_for($u, $deptCode, $fy)) $doc = null;
    }
    if (!$doc && $sent) $doc = $sent;

    // Nothing in progress and nothing submitted — start this year's document
    if (!$doc) {
        try {
            // A new document is this year's Market Scoping form (Document
            // Format), with the department's details filled in
            $pdo->prepare("INSERT INTO market_scoping (fy, dept_code, title, content, status, created_by)
                           VALUES (?, ?, '', ?, 'draft', ?)")
                ->execute([$fy, $deptCode, ms_form_for($u, $deptCode, $fy), $u['full_name']]);
            $newId = (int)$pdo->lastInsertId();
            try { $pdo->prepare('UPDATE market_scoping SET from_form = 1 WHERE id = ?')->execute([$newId]); } catch (Throwable $t) {}
            $s = $pdo->prepare('SELECT * FROM market_scoping WHERE id = ?');
            $s->execute([$newId]);
            $doc = $s->fetch();
        } catch (Throwable $t) {}
    }
    if ($doc) $docId = (int)$doc['id'];
}

$status = $doc['status'] ?? 'draft';
$locked = in_array($status, ['submitted', 'approved'], true);
// Before the PPMP is submitted, the Market Scoping goes with it
[$__solo] = companion_solo_submit($deptCode, (int)($doc['fy'] ?? $fy), (string)$status);
$title  = $doc['title'] ?? '';
$body   = $doc ? rt_sanitize((string)$doc['content']) : '';

/* The NGPA Market Scoping Checklist. An empty draft — or a new one not
   saved yet — becomes the checklist; an empty draft is saved as such at
   once, so its printed copy is the checklist too. A draft already written
   in the old free-form layout is left exactly as it is (see the banner). */
$msTemplate = ms_form_for($u, $deptCode, (int)($doc['fy'] ?? $fy));
if (!$msForm && !$locked && trim(strip_tags($body, '<img><table>')) === '') {
    $body = $msTemplate;
    if ($doc) {
        try {
            $pdo->prepare('UPDATE market_scoping SET content = ? WHERE id = ?')->execute([$body, (int)$doc['id']]);
        } catch (Throwable $t) {}
        try { $pdo->prepare('UPDATE market_scoping SET from_form = 1 WHERE id = ?')->execute([(int)$doc['id']]); } catch (Throwable $t) {}
        $doc['from_form'] = 1;
    }
}
if ($msForm) {
    $status = 'draft'; $locked = false; $title = '';
    $body = ms_form_source($msForm);
}
// Started before the form came from Document Format, in the old free-form layout
$msOldLayout = !$msForm && !$locked && $body !== '' && empty($doc['from_form']) && !ms_is_ngpa_format($body);

$activeNav = $msForm ? 'doc-format' : ((($u['role'] ?? '') === 'admin') ? 'my-scoping' : 'scoping');
$pageTitle = 'Market Scoping';
doc_fmt_use_year(active_fy());   // editors work on the active year
require __DIR__ . '/includes/head.php';
?>
<style>
  .ms-shell { max-width: 1000px; margin: 0 auto; }

  /* ── Toolbar ── */
  .ms-toolbar {
    /* var(--white), not a hardcoded #fff: the buttons on it take
       color: var(--ink), which dark mode turns white. On a fixed white
       toolbar that was white on white — every button invisible. */
    background: var(--white); border: 1px solid var(--border); border-radius: 10px 10px 0 0;
    border-bottom: none; padding: 8px 10px;
    display: flex; flex-wrap: wrap; gap: 4px; align-items: center;
    position: sticky; top: 0; z-index: 20;
  }
  .ms-tb-btn {
    min-width: 30px; height: 30px; padding: 0 7px;
    border: 1px solid transparent; border-radius: 6px; background: transparent;
    color: var(--ink); font-size: 13px; cursor: pointer; font-family: inherit;
    display: inline-flex; align-items: center; justify-content: center; gap: 4px;
  }
  .ms-tb-btn:hover { background: var(--navy-50); border-color: var(--border); }
  /* Icons are drawn, not typed, so they cannot come out as an empty box
     on a machine without that symbol in its fonts. currentColor makes
     them follow the theme like the letters beside them. */
  .ms-tb-btn svg { width: 16px; height: 16px; display: block; fill: none;
                   stroke: currentColor; stroke-width: 1.7;
                   stroke-linecap: round; stroke-linejoin: round; }
  .ms-tb-btn.on { background: #1c4c86; color: #fff; border-color: #1c4c86; }
  .ms-tb-sep { width: 1px; height: 22px; background: var(--border); margin: 0 4px; }
  .ms-tb-select {
    height: 30px; border: 1px solid var(--border); border-radius: 6px;
    background: var(--white); color: var(--ink); font-size: 12.5px; font-family: inherit;
    padding: 0 6px; cursor: pointer;
  }
  .ms-tb-color { width: 30px; height: 30px; padding: 2px; border: 1px solid var(--border); border-radius: 6px; background: var(--white); cursor: pointer; }

  /* ── The page ── */
  .ms-paper-wrap {
    background: #eef2f6; border: 1px solid var(--border); border-radius: 0 0 10px 10px;
    padding: 26px; overflow-x: auto;
  }
  /* The NGPA form's own page: Letter, its margins, Arial 11 pt */
  .ms-paper {
    background: #fff; width: 8.5in; min-height: 11in; margin: 0 auto;
    padding: 0.6in 1.06in 1in 1.25in; box-shadow: 0 3px 14px rgba(0,0,0,0.13);
    font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.3; color: #000;
  }
  .ms-doc-title { text-align: center; font-family: Arial, Helvetica, sans-serif; font-size: 14pt; font-weight: 700;
    padding-bottom: 8px; border-bottom: 1px solid #000; margin: 4px 0 6px; letter-spacing: 0.01em; }
  .ms-content p { margin-top: 0; }
  /* Fixed letterhead — same arrangement as the PPMP */
  .ms-letterhead {
    display: grid; grid-template-columns: 90px 1fr 90px;
    align-items: center; gap: 10px; margin-bottom: 12px;
  }
  .ms-letterhead .seal img { width: 82px; height: 82px; object-fit: contain; }
  .ms-letterhead .lh-center { text-align: center; }
  .ms-letterhead .lh-rep  { font-size: 11px; }
  .ms-letterhead .lh-sch  { font-size: 16px; font-weight: 700; letter-spacing: 0; margin: 1px 0; white-space: nowrap; }  /* one line on the Letter page */
  .ms-letterhead .lh-addr { font-size: 10px; font-style: italic; margin-bottom: 8px; }
  .ms-letterhead .lh-doc  { font-size: 13px; font-weight: 700; letter-spacing: 0.03em; margin-top: 4px; }
  .ms-letterhead .flag {
    width: 90px; height: 60px;
    background: url('<?= e(doc_logo('scoping', 'right')) ?>') center/contain no-repeat;
  }

  .ms-content { min-height: 180mm; outline: none; }
  .ms-content:focus { outline: none; }
  /* Market Scoping Results: "Considered?" is picked, not typed */
  .ms-content select.ms-yn { width: 100%; padding: 5px 6px; font: inherit; font-size: 12.5px; border: 1px solid #B4E1EB; border-radius: 5px;
    background: #ffffff; color: #133458; cursor: pointer; }
  body[data-theme="dark"] .ms-content select.ms-yn { background: #ffffff !important; color: #133458 !important; border-color: #7a9bb8 !important; }
  .ms-content img { max-width: 100%; height: auto; }
  /* v99: a ☐ / ☑ is a check box — clicked, not typed */
  .ms-content td.ms-box { cursor: pointer; user-select: none; }
  .ms-content td.ms-box:hover { background: #EAF4F9; }
  .ms-content td.ms-box:focus-visible { outline: 2px solid #1e5285; outline-offset: -2px; }
  .ms-content table { border-collapse: collapse; }
  .ms-content table td, .ms-content table th { border: 1px solid #999; padding: 5px 8px; }
  .ms-content[data-empty="true"]::before {
    content: attr(data-placeholder); color: #9aa8b8; font-style: italic; pointer-events: none;
  }
  /* ── Image editing ── */
  .ms-content img { cursor: pointer; }
  .ms-content img.img-selected { outline: 3px solid #1c4c86; outline-offset: 2px; }
  #imgTools {
    display: none; position: absolute; z-index: 60;
    /* Same fault as the main toolbar: its buttons are var(--ink). */
    background: var(--white); border: 1px solid var(--border); border-radius: 10px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.16); padding: 6px;
    gap: 4px; align-items: center; flex-wrap: wrap;
  }
  #imgTools button {
    border: 1px solid transparent; background: transparent; border-radius: 6px;
    padding: 5px 9px; font-size: 12px; font-family: inherit; color: var(--ink); cursor: pointer;
  }
  #imgTools button:hover { background: var(--navy-50); border-color: var(--border); }
  #imgTools .sep { width: 1px; height: 20px; background: var(--border); margin: 0 2px; }
  #imgTools .danger { color: #dc2626; }

  .crop-overlay {
    display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6);
    z-index: 9999; align-items: center; justify-content: center; padding: 20px;
  }
  .crop-box { background: var(--white); border-radius: 14px; padding: 20px; max-width: 90vw; max-height: 92vh; display: flex; flex-direction: column; }
  .crop-title { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 17px; color: var(--ink); margin-bottom: 4px; }
  .crop-hint { font-size: 12.5px; color: var(--muted); margin-bottom: 12px; }
  .crop-stage { position: relative; overflow: auto; background: #eef2f6; border-radius: 8px; max-height: 62vh; }
  .crop-stage img { display: block; max-width: 100%; user-select: none; -webkit-user-drag: none; }
  .crop-sel {
    position: absolute; border: 2px dashed #fff; box-shadow: 0 0 0 9999px rgba(0,0,0,0.42);
    cursor: move;
  }
  .crop-handle {
    position: absolute; width: 12px; height: 12px; background: #fff;
    border: 2px solid #1c4c86; border-radius: 3px;
  }
  .crop-handle.br { right: -7px; bottom: -7px; cursor: nwse-resize; }
  .crop-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; }

  .ms-status { font-size: 12px; color: var(--muted); margin-top: 10px; text-align: right; }
  @media (max-width: 900px) { .ms-paper { width: 100%; min-height: 60vh; padding: 16px; } .ms-content { min-height: 40vh; } }
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
        <h1 class="page-title"><?= $msForm ? 'Market Scoping form' : 'Market Scoping' ?> · FY <?= $msForm ?: $fy ?></h1>
        <p class="page-sub">
          <?php if ($msForm): ?> Document Format
          <?php elseif ($locked): /* v133: the status is in the ✓ Submitted box below — here, the department */ ?>
            <?= e((string)($u['dept_name'] ?? '')) ?>
          <?php else: ?> Click a ☐ to tick it.
          <?php endif; ?></p><?php if (!$msForm) { require_once __DIR__ . '/includes/unit-switch.php'; echo unit_pick_html($u); }   /* v87: which program / major or set */ ?></div>
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;" data-dock>
        <?php if ($msForm): ?>
          <a href="document-format.php?type=scoping#ms-form" class="btn btn-ghost" style="padding:8px 14px; font-size:13px; text-decoration:none;">← Document Format</a>
          <button type="button" onclick="resetForm()" id="resetBtn" class="btn btn-ghost" style="padding:8px 14px; font-size:13px; color:var(--orange-600);">↺ Reset to the NGPA form</button>
          <button type="button" onclick="saveDoc()" id="saveBtn" class="btn btn-primary" style="padding:8px 16px; font-size:13px;">💾 Save</button>
        <?php else: ?>
        <?= $doc ? arc_button($u, arc_load('scoping', (int)$doc['id']), 'market-scoping-edit.php?id=' . (int)$doc['id']) : '' ?>
        <?php require_once __DIR__ . '/includes/xl-io-ui.php'; echo xl_io_buttons('scoping', $doc ? 'api/xl-export.php?doc=scoping&id=' . (int)$doc['id'] : '', !$locked);   /* v105: MARKET SCOPING.docx */ ?>
        <?php if ($doc): ?>
          <?php require_once __DIR__ . '/config/doc-versions.php'; echo doc_history_button('scoping', (string)(int)$doc['id']);   /* v79 */ ?>
          <?php if ($locked): ?>
            <a href="market-scoping-print.php?id=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">🖨️ Print</a>
          <?php else: ?>
            <button type="button" onclick="printDoc()" id="printBtn" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">🖨️ Print</button>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!$locked): ?>
          <button type="button" onclick="saveDoc()" id="saveBtn" class="btn btn-ghost" style="padding:8px 16px; font-size:13px;">💾 Save</button>
          <?php if (!$__canSubmit && !$__solo && (require_once __DIR__ . '/config/head-submit.php') && hs_applies($u)): /* v112: an end user submits to the Department Admin */ ?>
          <?= hs_user_controls($u, (int)($doc['fy'] ?? $fy), 'return openSubmitSet(event)') ?>
          <?php elseif (!$__canSubmit): ?>
          <span style="font-size:11.5px; color:var(--muted); align-self:center;">
            <?= $__headName !== '' ? e($__headName) . ' submits for your department.' : 'Only your Department Admin can submit.' ?>
          </span>
          <?php elseif (!$__solo && (require_once __DIR__ . '/config/unit-review.php') && ur_whole_school($u, (int)($doc['fy'] ?? $fy))): ?>
          <?= ur_submit_link((int)($doc['fy'] ?? $fy), (string)($u['school_code'] ?? '')) ?>
          <?php elseif (!$__solo): ?>
          <a href="ppmp-edit.php?fy=<?= (int)($doc['fy'] ?? $fy) ?>" onclick="return openSubmitSet(event)" class="btn btn-primary" id="submitWithPpmp" style="padding:8px 18px; font-size:13px; text-decoration:none;"
             title="The Market Scoping is submitted together with your PPMP">📤 Submit</a>
          <?php else: ?>
          <button type="button" onclick="submitDoc()" id="submitBtn" class="btn btn-primary" style="padding:8px 18px; font-size:13px;">📤 Submit</button>
          <?php endif; ?>
        <?php else: ?>
          <?php /* v133: no "📤 Submitted / ✓ Approved" here — the ✓ Submitted box below says it once */ ?>
        <?php endif; ?>
        <?php endif; /* form mode */ ?></div></div>
    <?php render_flashes(); ?>
    <?php if (!$locked) { require_once __DIR__ . '/config/unit-review.php'; echo ur_bar($u, (int)($doc['fy'] ?? $fy)); }   /* v81: a program / major of a school */ ?>
    <?php if (!empty($doc) && empty($msForm ?? 0)): /* "✓ Submitted" — where it is now */ ?>
      <?= submitted_stamp((string)($doc['status'] ?? 'draft'), $doc['submitted_at'] ?? null, $doc['submitted_by'] ?? null,
            ($doc['status'] ?? '') === 'approved'
              ? 'Approved by the Procurement Unit' . (!empty($doc['reviewed_at']) ? ' on ' . date('M j, Y', strtotime($doc['reviewed_at'])) : '') . '.'
              : 'now with the Procurement Unit') ?>
    <?php endif; ?>

    <?php if ($msForm): ?>
      <div id="msFormHelp" style="display:flex; gap:11px; align-items:flex-start; flex-wrap:wrap; padding:13px 17px; margin-bottom:16px;
                  background: var(--navy-50); border:1px solid var(--navy-100); border-radius:12px;
                  font-size:12.5px; color:var(--ink); line-height:1.55;">
        <div style="flex:1 1 420px; min-width:0;">Edit the form the way it should reach the departments. Anything written as a
          <strong>{{field}}</strong> is replaced with that department's own details when a new document is started.
          Saving applies to FY <?= $msForm ?> from now on; earlier years keep their own form.</div>
        <label style="display:flex; gap:6px; align-items:center; font-weight:600;">Insert a field
          <select id="msTokenPick" class="ms-tb-select" aria-label="Insert a field">
            <option value="">Choose…</option>
            <?php foreach (MS_FORM_TOKEN_HELP as $__tok => $__help): ?>
              <option value="<?= e($__tok) ?>"><?= e($__tok) ?> — <?= e($__help) ?></option>
            <?php endforeach; ?></select></label>
      </div>
    <?php endif; ?>

    <?php if ($msOldLayout): ?>
      <div id="msOldLayout" style="display:flex; gap:11px; align-items:flex-start; flex-wrap:wrap; padding:13px 17px; margin-bottom:16px;
                  background: var(--navy-50); border:1px solid var(--navy-100); border-radius:12px;
                  font-size:12.5px; color:var(--ink); line-height:1.55;">
        <div style="flex:1 1 380px; min-width:0;">This document was started in the old free-form layout. Market Scoping now uses the
        <strong>NGPA Market Scoping Checklist</strong>. Put the checklist on top &mdash; everything already written stays below it.</div>
        <button type="button" class="btn btn-primary" style="padding:8px 16px; font-size:13px;" onclick="useNgpaForm()">Use the NGPA checklist</button>
      </div>
    <?php endif; ?>

    <?php if ($status === 'revision' && !empty($doc['review_note'])): ?>
      <div style="background:#FFF4EC; border-left:4px solid #F97316; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:13px; color:#C2410C;">
        <strong>Sent back for revision:</strong> <?= e($doc['review_note']) ?></div>
    <?php endif; ?>

    <div class="ms-shell">
      <?php if (!$locked): ?>
      <div class="ms-toolbar" id="msToolbar">
        <button type="button" class="ms-tb-btn" data-cmd="undo" title="Undo (Ctrl+Z)">↶</button>
        <button type="button" class="ms-tb-btn" data-cmd="redo" title="Redo (Ctrl+Y)">↷</button>
        <span class="ms-tb-sep"></span>

        <select class="ms-tb-select" id="fontFamily" title="Font">
          <option value="Arial" selected>Arial</option>
          <option value="Times New Roman">Times New Roman</option>
          <option value="Calibri">Calibri</option>
          <option value="Cambria">Cambria</option>
          <option value="Georgia">Georgia</option>
          <option value="Verdana">Verdana</option>
          <option value="Courier New">Courier New</option>
          <option value="Tahoma">Tahoma</option></select>

        <select class="ms-tb-select" id="fontSize" title="Font size">
          <option value="8pt">8</option><option value="9pt">9</option><option value="10pt">10</option>
          <option value="11pt" selected>11</option><option value="12pt">12</option><option value="14pt">14</option>
          <option value="16pt">16</option><option value="18pt">18</option><option value="20pt">20</option>
          <option value="24pt">24</option><option value="28pt">28</option><option value="36pt">36</option>
          <option value="48pt">48</option><option value="72pt">72</option></select>

        <select class="ms-tb-select" id="blockStyle" title="Paragraph style">
          <option value="p">Normal</option>
          <option value="h1">Heading 1</option>
          <option value="h2">Heading 2</option>
          <option value="h3">Heading 3</option>
          <option value="h4">Heading 4</option>
          <option value="blockquote">Quote</option>
          <option value="pre">Code</option></select>
        <span class="ms-tb-sep"></span>

        <button type="button" class="ms-tb-btn" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
        <button type="button" class="ms-tb-btn" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
        <button type="button" class="ms-tb-btn" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>
        <button type="button" class="ms-tb-btn" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
        <button type="button" class="ms-tb-btn" data-cmd="superscript" title="Superscript">x²</button>
        <button type="button" class="ms-tb-btn" data-cmd="subscript" title="Subscript">x₂</button>
        <span class="ms-tb-sep"></span>

        <input type="color" class="ms-tb-color" id="foreColor" value="#000000" title="Text colour">
        <input type="color" class="ms-tb-color" id="hiliteColor" value="#ffff00" title="Highlight">
        <span class="ms-tb-sep"></span>

        <button type="button" class="ms-tb-btn" data-cmd="justifyLeft" title="Align left">⯇</button>
        <button type="button" class="ms-tb-btn" data-cmd="justifyCenter" title="Centre">≡</button>
        <button type="button" class="ms-tb-btn" data-cmd="justifyRight" title="Align right">⯈</button>
        <button type="button" class="ms-tb-btn" data-cmd="justifyFull" title="Justify">☰</button>
        <span class="ms-tb-sep"></span>

        <button type="button" class="ms-tb-btn" data-cmd="insertUnorderedList" title="Bullets">• —</button>
        <button type="button" class="ms-tb-btn" data-cmd="insertOrderedList" title="Numbering">1. —</button>
        <button type="button" class="ms-tb-btn" data-cmd="outdent" title="Decrease indent">⇤</button>
        <button type="button" class="ms-tb-btn" data-cmd="indent" title="Increase indent">⇥</button>
        <span class="ms-tb-sep"></span>

        <button type="button" class="ms-tb-btn" onclick="pickImage()" title="Insert image" aria-label="Insert image"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="2.5" y="4" width="15" height="12" rx="1.6"/><circle cx="7" cy="8.2" r="1.3"/><path d="M3 13.5l3.8-3.4a1.2 1.2 0 0 1 1.6 0l3.1 2.8"/><path d="M11.4 11.6l1.9-1.7a1.2 1.2 0 0 1 1.6 0l2.1 1.9"/></svg></button>
        <button type="button" class="ms-tb-btn" onclick="insertTable()" title="Insert table">▦</button>
        <button type="button" class="ms-tb-btn" onclick="insertLink()" title="Insert link" aria-label="Insert link"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M8.4 11.6a3.2 3.2 0 0 0 4.6 0l2.4-2.4a3.2 3.2 0 1 0-4.6-4.6l-1.2 1.2"/><path d="M11.6 8.4a3.2 3.2 0 0 0-4.6 0l-2.4 2.4a3.2 3.2 0 1 0 4.6 4.6l1.2-1.2"/></svg></button>
        <button type="button" class="ms-tb-btn" data-cmd="insertHorizontalRule" title="Horizontal line">—</button>
        <span class="ms-tb-sep"></span>

        <button type="button" class="ms-tb-btn" data-cmd="removeFormat" title="Clear formatting">✕ᶠ</button>
        <input type="file" id="imgPicker" accept="image/*" style="display:none;" onchange="insertImage(this)"></div>
      <?php endif; ?>

      <div class="ms-paper-wrap">
        <div class="ms-paper">
          <!-- Fixed letterhead — part of the page, not of the text people edit -->
          <div class="ms-letterhead">
            <div class="seal"><img src="<?= e(doc_logo('scoping', 'left')) ?>" alt="ASCOT Seal"></div>
            <div class="lh-center">
              <div class="lh-rep"><?= e(doc_fmt('scoping', 'rep_line')) ?></div>
              <div class="lh-sch"><?= e(doc_fmt('scoping', 'org_name')) ?></div>
              <div class="lh-addr"><?= e(doc_fmt('scoping', 'address')) ?></div></div>
            <div class="flag"></div></div>
          <div class="ms-doc-title"><?= e(doc_fmt('scoping', 'doc_title')) ?></div>

          <div class="ms-content" id="msPaper"
               <?= $locked ? '' : 'contenteditable="true"' ?> data-placeholder="Start writing your market scoping here…"><?= $body ?></div></div></div>
      
      <?php /* v104: pictures, each with its words (config/scoping-photos.php) — printed at the end */
        require_once __DIR__ . '/config/scoping-photos.php';
        $__photos = !empty($doc['id']) ? ms_photos((int)$doc['id']) : []; ?>
      <?php if (!$locked && empty($msForm)): ?>
      <div id="msPhotos" style="max-width: 1000px; margin: 20px auto 0; padding: 16px; border: 1px solid var(--border); border-radius: 10px; background: var(--white);">
        <div style="font-size: 13px; font-weight: 700; color: var(--ink);">Pictures <span style="font-weight: 500; color: var(--muted);">(up to <?= MS_PHOTO_MAX ?>)</span></div>
        <div style="font-size: 11.5px; color: var(--muted); margin: 3px 0 12px;">On the printout the pictures come at the end, each with its words under it — on the same page when it fits, otherwise on the next page. JPG, PNG, WEBP or GIF, under 3 MB each.</div>
        <div id="msPhotoList" class="msp-list"></div>
        <div id="msNewList" class="msp-list"></div>
        <label class="btn btn-ghost" for="msPhotoInput" id="msPhotoAdd" style="padding: 7px 14px; font-size: 12.5px; cursor: pointer; margin-top: 6px;">＋ Add pictures</label>
        <input type="file" id="msPhotoInput" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="position:absolute; width:1px; height:1px; opacity:0;">
        <span id="msPhotoCount" style="font-size: 11.5px; color: var(--muted); margin-left: 8px;"></span>
      </div>
      <style>
        .msp-list { display: grid; gap: 10px; }
        .msp { display: flex; gap: 12px; align-items: flex-start; padding: 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--page); }
        .msp img { width: 96px; height: 96px; object-fit: contain; background: var(--navy-50); border-radius: 8px; flex: none; }
        .msp .msp-body { flex: 1; min-width: 0; }
        .msp textarea { width: 100%; max-width: 100%; font: inherit; font-size: 12.5px; padding: 7px 9px; border: 1px solid var(--border); border-radius: 8px; background: var(--white); color: var(--ink); resize: vertical; }
        .msp .msp-tools { display: flex; gap: 10px; align-items: center; margin-top: 6px; font-size: 11.5px; color: var(--muted); }
        .msp.removing { opacity: .5; }
        .msp .msp-new { font-size: 10.5px; font-weight: 700; color: var(--accent); text-transform: uppercase; letter-spacing: .05em; }
      </style>
      <?php endif; ?>
      <script>window.MS_PHOTOS = <?= json_encode($__photos) ?>; window.MS_PHOTO_MAX = <?= (int)MS_PHOTO_MAX ?>;</script>

      <div class="ms-status" id="msStatus"></div></div>
      <?php require_once __DIR__ . '/config/past-years.php'; echo past_year_mine_html('scoping', (string)$deptCode);   /* v104: restored, earlier years */ ?></main></div>

<!-- Floating toolbar for the selected image -->
<div id="imgTools">
  <button type="button" onclick="sizeImg(25)">25%</button>
  <button type="button" onclick="sizeImg(50)">50%</button>
  <button type="button" onclick="sizeImg(75)">75%</button>
  <button type="button" onclick="sizeImg(100)">100%</button>
  <span class="sep"></span>
  <button type="button" onclick="alignImg('left')" title="Align left">⯇</button>
  <button type="button" onclick="alignImg('center')" title="Centre">≡</button>
  <button type="button" onclick="alignImg('right')" title="Align right">⯈</button>
  <span class="sep"></span>
  <button type="button" onclick="openCrop()" title="Crop">✂ Crop</button>
  <button type="button" class="danger" onclick="deleteImg()" title="Remove">🗑 Delete</button></div>

<!-- Crop dialog -->
<div class="crop-overlay" id="cropOverlay">
  <div class="crop-box">
    <div class="crop-title">Crop image</div>
    <div class="crop-hint">Drag inside the box to move it, or pull the corner to resize.</div>
    <div class="crop-stage" id="cropStage">
      <img id="cropImg" alt="">
      <div class="crop-sel" id="cropSel"><div class="crop-handle br" id="cropHandle"></div></div></div>
    <div class="crop-actions">
      <button type="button" class="btn btn-ghost" style="padding:9px 20px; font-size:13px;" onclick="closeCrop()">Cancel</button>
      <button type="button" class="btn btn-primary" style="padding:9px 22px; font-size:13px;" onclick="applyCrop()">Apply crop</button></div></div></div>

<script> const DOC_ID = <?= (int)($doc['id'] ?? 0) ?>;
  const MS_FORM = <?= (int)$msForm ?>;
  const FY     = <?= $fy ?>;
  const LOCKED = <?= $locked ? 'true' : 'false' ?>;

  const paper = document.getElementById('msPaper');
  // Only an old free-form draft needs the checklist in hand (useNgpaForm below)
  const MS_TEMPLATE = <?= $msOldLayout ? json_encode($msTemplate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) : "''" ?>;

  /* Market Scoping Results: the "Considered?" column is a drop-down —
     Yes, No or Not Applicable. It is saved (and printed) as the word itself. */
  const MS_YN = ['Yes', 'No', 'Not Applicable'];
  function msYesNo(){
    if (LOCKED) return;
    paper.querySelectorAll('table').forEach(function(tbl){
      const head = tbl.rows[0]; if (!head) return;
      let col = -1;
      Array.prototype.forEach.call(head.cells, function(c, i){ if (/^\s*Considered\?/i.test(c.textContent)) col = i; });
      if (col < 0) return;
      Array.prototype.slice.call(tbl.rows, 1).forEach(function(tr){
        const td = tr.cells[col];
        if (!td || td.querySelector('select.ms-yn')) return;
        const t = td.textContent.replace(/\s+/g, ' ').trim();
        const v = /^yes$/i.test(t) ? 'Yes' : /^no$/i.test(t) ? 'No' : /^(not applicable|n\/?a)$/i.test(t) ? 'Not Applicable' : t;
        const sel = document.createElement('select');
        sel.className = 'ms-yn'; sel.setAttribute('aria-label', 'Considered?');
        const opts = [''].concat(MS_YN);
        if (v && MS_YN.indexOf(v) < 0) opts.push(v);          // something typed before stays a choice
        opts.forEach(function(o){
          const op = document.createElement('option');
          op.value = o; op.textContent = o || 'Select…'; if (o === v) op.selected = true;
          sel.appendChild(op);
        });
        td.innerHTML = ''; td.appendChild(sel);
        td.setAttribute('contenteditable', 'false');
        sel.addEventListener('change', function(){ markDirty(); });
      });
    });
  }
  /* v99: a ☐ / ☑ cell is a check box — ticked or not by a click (or Space),
     never typed into, deleted or written over like the text around it. */
  function msBoxes(){
    if (LOCKED) return;
    paper.querySelectorAll('td').forEach(function(td){
      const t = td.textContent.trim();
      if (t !== '☐' && t !== '☑') return;
      if (td.classList.contains('ms-box')) return;
      td.classList.add('ms-box');
      td.setAttribute('contenteditable', 'false');
      td.setAttribute('role', 'checkbox');
      td.setAttribute('tabindex', '0');
      td.setAttribute('aria-checked', t === '☑' ? 'true' : 'false');
      td.title = 'Click to tick or untick';
    });
  }
  /* The document as it is saved: each "Considered?" as the word picked */
  function msContent(){
    const live = paper.querySelectorAll('select.ms-yn');
    const box = paper.cloneNode(true);
    // v99: the check boxes are saved as they always were — the ☐ / ☑ character in the cell
    box.querySelectorAll('td.ms-box').forEach(function(td){
      ['contenteditable', 'role', 'tabindex', 'aria-checked', 'title'].forEach(function(a){ td.removeAttribute(a); });
      td.classList.remove('ms-box'); if (!td.className) td.removeAttribute('class');
    });
    box.querySelectorAll('select.ms-yn').forEach(function(sel, i){
      const td = sel.parentNode, v = live[i] ? live[i].value : '';
      td.removeAttribute('contenteditable');
      td.innerHTML = v ? v.replace(/&/g, '&amp;').replace(/</g, '&lt;') : '<br>';
    });
    return box.innerHTML;
  }
  msYesNo();

  /* A ☐ in the checklist is a check box (v99, msBoxes above): a click or Space ticks it (☑)
     and unticks it; it cannot be typed into. It is the character in the cell, so it saves
     and prints like any other text, and a ticked one counts for "Market Scoping ready". */
  if (!LOCKED) {
    const toggleBox = function(td){
      const t = td.textContent.trim();
      if (t !== '☐' && t !== '☑') return;
      td.textContent = (t === '☐') ? '☑' : '☐';
      if (td.classList.contains('ms-box')) td.setAttribute('aria-checked', t === '☐' ? 'true' : 'false');
      if (typeof markDirty === 'function') markDirty();
    };
    paper.addEventListener('click', function(e){
      const td = e.target.closest && e.target.closest('td');
      if (!td || !paper.contains(td)) return;
      toggleBox(td);
    });
    paper.addEventListener('keydown', function(e){
      const td = e.target.closest && e.target.closest('td.ms-box');
      if (!td || (e.key !== ' ' && e.key !== 'Enter')) return;
      e.preventDefault(); toggleBox(td);
    });
    msBoxes();
  }

  /* An old free-form draft: the checklist goes on top, what was written stays below. */
  function useNgpaForm(){
    const go = function(){
      const old = paper.innerHTML;
      paper.innerHTML = MS_TEMPLATE + '<p><br></p><p><b>Earlier notes</b></p>' + old;
      msYesNo(); if (typeof msBoxes === 'function') msBoxes();
      window.__msFromForm = true;   // it now starts with the year's form
      const b = document.getElementById('msOldLayout'); if (b) b.remove();
      if (typeof refreshPlaceholder === 'function') refreshPlaceholder();
      markDirty();
    };
    if (window.swiftConfirm) {
      swiftConfirm({ title: 'Use the NGPA checklist?', text: 'The Market Scoping Checklist is put at the top of this document. Nothing you have written is removed.',
                     confirmLabel: 'Use the checklist', tone: 'info', onConfirm: go });
    } else { go(); }
  }

  function refreshPlaceholder(){
    const empty = paper.innerHTML.trim() === '' || paper.innerHTML.trim() === '<br>';
    paper.setAttribute('data-empty', empty ? 'true' : 'false');
  }
  refreshPlaceholder();

  if (!LOCKED) {
    try { document.execCommand('styleWithCSS', false, true); } catch (e) {}

    // Toolbar buttons
    document.querySelectorAll('#msToolbar .ms-tb-btn[data-cmd]').forEach(function(btn){
      btn.addEventListener('mousedown', function(e){ e.preventDefault(); });
      btn.addEventListener('click', function(){
        document.execCommand(btn.dataset.cmd, false, null);
        paper.focus(); syncToolbar(); markDirty();
      });
    });

    function applyToSelection(fn){
      paper.focus();
      fn();
      markDirty();
    }

    document.getElementById('fontFamily').addEventListener('change', function(){
      applyToSelection(() => document.execCommand('fontName', false, this.value));
    });

    // execCommand only understands sizes 1–7, so wrap the selection ourselves
    document.getElementById('fontSize').addEventListener('change', function(){
      const size = this.value;
      paper.focus();
      document.execCommand('fontSize', false, 7);
      paper.querySelectorAll('font[size="7"]').forEach(function(f){
        const span = document.createElement('span');
        span.style.fontSize = size;
        span.innerHTML = f.innerHTML;
        f.parentNode.replaceChild(span, f);
      });
      markDirty();
    });

    document.getElementById('blockStyle').addEventListener('change', function(){
      applyToSelection(() => document.execCommand('formatBlock', false, this.value));
    });

    document.getElementById('foreColor').addEventListener('input', function(){
      applyToSelection(() => document.execCommand('foreColor', false, this.value));
    });
    document.getElementById('hiliteColor').addEventListener('input', function(){
      const c = this.value;
      applyToSelection(() => {
        if (!document.execCommand('hiliteColor', false, c)) document.execCommand('backColor', false, c);
      });
    });

    // Keep the toolbar in step with the cursor
    function syncToolbar(){
      [['bold','bold'],['italic','italic'],['underline','underline'],['strikeThrough','strikeThrough'],
       ['justifyLeft','justifyLeft'],['justifyCenter','justifyCenter'],['justifyRight','justifyRight'],
       ['justifyFull','justifyFull'],['insertUnorderedList','insertUnorderedList'],
       ['insertOrderedList','insertOrderedList']].forEach(function(pair){
        const b = document.querySelector('#msToolbar .ms-tb-btn[data-cmd="'+pair[0]+'"]');
        if (!b) return;
        let on = false;
        try { on = document.queryCommandState(pair[1]); } catch(e) {}
        b.classList.toggle('on', on);
      });
    }
    paper.addEventListener('keyup', syncToolbar);
    paper.addEventListener('mouseup', syncToolbar);
    paper.addEventListener('input', function(){ refreshPlaceholder(); markDirty(); });

    // Paste as plain text so Word styling doesn't drag rubbish in
    paper.addEventListener('paste', function(e){
      const html = (e.clipboardData || window.clipboardData).getData('text/html');
      if (html) {
        e.preventDefault();
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        tmp.querySelectorAll('script,style,meta,link,o\\\\:p').forEach(n => n.remove());
        document.execCommand('insertHTML', false, tmp.innerHTML);
        markDirty();
      }
    });
  }

  // ── Images ──
  function pickImage(){ document.getElementById('imgPicker').click(); }

  function insertImage(input){
    const file = input.files && input.files[0];
    input.value = '';
    if (!file) return;
    if (file.size > 3 * 1024 * 1024) {
      alert('That image is larger than 3 MB. Please use a smaller one.');
      return;
    }
    const reader = new FileReader();
    reader.onload = function(ev){
      paper.focus();
      document.execCommand('insertHTML', false,
        '<img src="' + ev.target.result + '" alt="" style="max-width:100%; height:auto;"><br>');
      refreshPlaceholder(); markDirty();
    };
    reader.readAsDataURL(file);
  }

  function insertTable(){
    const rows = parseInt(prompt('How many rows?', '3'), 10);
    const cols = parseInt(prompt('How many columns?', '3'), 10);
    if (!rows || !cols || rows < 1 || cols < 1) return;
    let html = '<table style="border-collapse:collapse; width:100%;">';
    for (let r = 0; r < rows; r++) {
      html += '<tr>';
      for (let c = 0; c < cols; c++) html += '<td style="border:1px solid #999; padding:5px 8px;">&nbsp;</td>';
      html += '</tr>';
    }
    html += '</table><br>';
    paper.focus();
    document.execCommand('insertHTML', false, html);
    refreshPlaceholder(); markDirty();
  }

  function insertLink(){
    const url = prompt('Link address:', 'https://');
    if (!url) return;
    paper.focus();
    document.execCommand('createLink', false, url);
    markDirty();
  }


  // ═══════════ Image editing ═══════════
  // Click a picture in the document to resize, align, crop or remove it.
  let selectedImg = null;

  function selectImg(img){
    if (LOCKED) return;
    clearImgSelection();
    selectedImg = img;
    img.classList.add('img-selected');

    const tools = document.getElementById('imgTools');
    const r = img.getBoundingClientRect();
    tools.style.display = 'flex';
    // Sit just above the picture, or below it when there is no room
    const top = r.top + window.scrollY - tools.offsetHeight - 8;
    tools.style.top  = (top > window.scrollY + 4 ? top : r.bottom + window.scrollY + 8) + 'px';
    tools.style.left = (r.left + window.scrollX) + 'px';
  }

  function clearImgSelection(){
    document.querySelectorAll('.ms-content img.img-selected')
            .forEach(i => i.classList.remove('img-selected'));
    document.getElementById('imgTools').style.display = 'none';
    selectedImg = null;
  }

  paper.addEventListener('click', function(e){
    if (e.target.tagName === 'IMG') selectImg(e.target);
    else clearImgSelection();
  });
  document.addEventListener('click', function(e){
    // Clicking outside the picture and its toolbar drops the selection —
    // but not while a dialog is open, since that click belongs to the dialog.
    if (e.target.closest('#imgTools') || e.target.closest('.ms-content img')) return;
    if (e.target.closest('.crop-overlay') || e.target.closest('[class*="confirm"]')) return;
    if (document.getElementById('cropOverlay').style.display === 'flex') return;
    clearImgSelection();
  });

  function sizeImg(pct){
    if (!selectedImg) return;
    selectedImg.style.width = pct + '%';
    selectedImg.style.height = 'auto';
    markDirty();
    selectImg(selectedImg);          // reposition the toolbar
  }

  function alignImg(where){
    if (!selectedImg) return;
    selectedImg.style.float = '';
    if (where === 'center') {
      selectedImg.style.display = 'block';
      selectedImg.style.margin  = '10px auto';
    } else {
      selectedImg.style.display = 'inline';
      selectedImg.style.margin  = where === 'left' ? '10px 14px 10px 0' : '10px 0 10px 14px';
      selectedImg.style.float   = where;
    }
    markDirty();
    selectImg(selectedImg);
  }

  function deleteImg(){
    if (!selectedImg) return;
    // Keep our own reference: opening the dialog counts as a click elsewhere,
    // which clears the selection before the confirm handler ever runs.
    const target = selectedImg;
    swiftConfirm({
      title: 'Remove this image?',
      text: 'It will be taken out of the document.',
      confirmLabel: 'Remove',
      tone: 'danger',
      onConfirm: function(){
        target.remove();
        clearImgSelection();
        refreshPlaceholder();
        markDirty();
      }
    });
  }

  // ── Crop ──
  let cropTarget = null;

  function openCrop(){
    if (!selectedImg) return;
    cropTarget = selectedImg;

    const overlay = document.getElementById('cropOverlay');
    const cimg    = document.getElementById('cropImg');
    cimg.onload = function(){
      const sel = document.getElementById('cropSel');
      // Start with a box covering most of the picture
      const w = cimg.clientWidth, h = cimg.clientHeight;
      sel.style.left   = Math.round(w * 0.08) + 'px';
      sel.style.top    = Math.round(h * 0.08) + 'px';
      sel.style.width  = Math.round(w * 0.84) + 'px';
      sel.style.height = Math.round(h * 0.84) + 'px';
    };
    cimg.src = cropTarget.src;
    overlay.style.display = 'flex';
  }

  function closeCrop(){
    document.getElementById('cropOverlay').style.display = 'none';
    cropTarget = null;
  }
  document.getElementById('cropOverlay').addEventListener('click', function(e){
    if (e.target === this) closeCrop();
  });

  // Dragging the box, and resizing it from the corner
  (function(){
    const sel    = document.getElementById('cropSel');
    const handle = document.getElementById('cropHandle');
    const img    = document.getElementById('cropImg');
    let mode = null, sx = 0, sy = 0, sl = 0, st = 0, sw = 0, sh = 0;

    function start(e, which){
      e.preventDefault(); e.stopPropagation();
      mode = which;
      const p = e.touches ? e.touches[0] : e;
      sx = p.clientX; sy = p.clientY;
      sl = sel.offsetLeft; st = sel.offsetTop;
      sw = sel.offsetWidth; sh = sel.offsetHeight;
    }
    function move(e){
      if (!mode) return;
      e.preventDefault();
      const p  = e.touches ? e.touches[0] : e;
      const dx = p.clientX - sx, dy = p.clientY - sy;
      const maxW = img.clientWidth, maxH = img.clientHeight;

      if (mode === 'move') {
        sel.style.left = Math.min(Math.max(0, sl + dx), maxW - sw) + 'px';
        sel.style.top  = Math.min(Math.max(0, st + dy), maxH - sh) + 'px';
      } else {
        sel.style.width  = Math.min(Math.max(30, sw + dx), maxW - sel.offsetLeft) + 'px';
        sel.style.height = Math.min(Math.max(30, sh + dy), maxH - sel.offsetTop) + 'px';
      }
    }
    function stop(){ mode = null; }

    sel.addEventListener('mousedown',  e => start(e, 'move'));
    sel.addEventListener('touchstart', e => start(e, 'move'), {passive:false});
    handle.addEventListener('mousedown',  e => start(e, 'resize'));
    handle.addEventListener('touchstart', e => start(e, 'resize'), {passive:false});
    document.addEventListener('mousemove', move);
    document.addEventListener('touchmove', move, {passive:false});
    document.addEventListener('mouseup', stop);
    document.addEventListener('touchend', stop);
  })();

  function applyCrop(){
    if (!cropTarget) return;
    const cimg = document.getElementById('cropImg');
    const sel  = document.getElementById('cropSel');

    // The picture on screen is scaled, so convert back to its real pixels
    const scaleX = cimg.naturalWidth  / cimg.clientWidth;
    const scaleY = cimg.naturalHeight / cimg.clientHeight;

    const sxp = Math.round(sel.offsetLeft   * scaleX);
    const syp = Math.round(sel.offsetTop    * scaleY);
    const swp = Math.round(sel.offsetWidth  * scaleX);
    const shp = Math.round(sel.offsetHeight * scaleY);
    if (swp < 5 || shp < 5) { closeCrop(); return; }

    const canvas = document.createElement('canvas');
    canvas.width = swp; canvas.height = shp;
    const ctx = canvas.getContext('2d');

    const src = new Image();
    src.onload = function(){
      ctx.drawImage(src, sxp, syp, swp, shp, 0, 0, swp, shp);
      try {
        cropTarget.src = canvas.toDataURL('image/png');
        cropTarget.removeAttribute('width');
        cropTarget.removeAttribute('height');
        markDirty();
      } catch (err) {
        alert('Could not crop this image.');
      }
      closeCrop();
    };
    src.onerror = function(){ alert('Could not read this image.'); closeCrop(); };
    src.src = cropTarget.src;
  }


  (function(){
    const t = document.getElementById('docTitle');
    if (t) t.addEventListener('input', function(){ if (!LOCKED) markDirty(); });

  })();

  // ═══════════ v104: pictures, each with its words ═══════════
  (function(){
    const list = document.getElementById('msPhotoList'), newList = document.getElementById('msNewList');
    const input = document.getElementById('msPhotoInput'), count = document.getElementById('msPhotoCount');
    if (!list || !input) return;
    let pending = [];   // [{file, row}]
    const esc = function(t){ return String(t).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
    function dirty(){ if (!LOCKED && typeof markDirty === 'function') markDirty(); }
    function total(){ return list.querySelectorAll('.msp:not(.removing)').length + pending.length; }
    function refresh(){
      const n = total();
      count.textContent = n + ' of ' + window.MS_PHOTO_MAX;
      const add = document.getElementById('msPhotoAdd');
      if (add) add.style.display = n >= window.MS_PHOTO_MAX ? 'none' : '';
    }
    function render(photos){
      list.innerHTML = '';
      (photos || []).forEach(function(p, i){
        const row = document.createElement('div');
        row.className = 'msp'; row.dataset.id = p.id;
        row.innerHTML = '<img src="' + esc(p.path) + '" alt="Picture ' + (i + 1) + '">' +
          '<div class="msp-body"><textarea class="msp-cap" rows="2" maxlength="500" placeholder="Words under this picture" aria-label="Words under picture ' + (i + 1) + '">' + esc(p.caption || '') + '</textarea>' +
          '<div class="msp-tools"><span>Picture ' + (i + 1) + '</span><label><input type="checkbox" class="msp-del"> Remove</label></div></div>';
        row.querySelector('.msp-cap').addEventListener('input', dirty);
        row.querySelector('.msp-del').addEventListener('change', function(){ row.classList.toggle('removing', this.checked); refresh(); dirty(); });
        list.appendChild(row);
      });
      refresh();
    }
    input.addEventListener('change', function(){
      Array.prototype.forEach.call(input.files, function(f){
        if (total() >= window.MS_PHOTO_MAX) return;
        const row = document.createElement('div');
        row.className = 'msp';
        row.innerHTML = '<img alt="New picture"><div class="msp-body"><textarea class="msp-cap" rows="2" maxlength="500" placeholder="Words under this picture" aria-label="Words under the new picture"></textarea>' +
          '<div class="msp-tools"><span class="msp-new">New · saved with the document</span><button type="button" class="btn btn-ghost" style="padding:3px 10px; font-size:11.5px;">Remove</button></div></div>';
        row.querySelector('img').src = URL.createObjectURL(f);
        const item = { file: f, row: row };
        row.querySelector('.msp-cap').addEventListener('input', dirty);
        row.querySelector('button').addEventListener('click', function(){ pending = pending.filter(function(x){ return x !== item; }); row.remove(); refresh(); });
        pending.push(item); newList.appendChild(row);
      });
      input.value = ''; refresh(); dirty();
    });
    // what goes with a save
    window.msPhotoState = function(){
      const captions = {}, remove = [];
      list.querySelectorAll('.msp').forEach(function(r){
        captions[r.dataset.id] = r.querySelector('.msp-cap').value;
        if (r.querySelector('.msp-del').checked) remove.push(r.dataset.id);
      });
      return { captions: captions, remove: remove, files: pending.map(function(x){ return x.file; }),
               newCaptions: pending.map(function(x){ return x.row.querySelector('.msp-cap').value; }) };
    };
    // after a save: the list as it is now
    window.msPhotosSaved = function(d){
      if (!d || !d.photos) return;
      pending = []; newList.innerHTML = '';
      render(d.photos);
      if (d.photo_note) alert(d.photo_note);
    };
    render(window.MS_PHOTOS || []);
  })();

  // ═══════════ Auto-save ═══════════
  // The document saves itself a moment after you stop typing.
  let autoTimer = null, autoBusy = false;

  function showSaveState(text, tone){
    // Only a failure is shown; saving on its own goes on quietly (no repeated "Saved")
    if (tone !== 'err') text = '';
    const el = document.getElementById('msStatus');
    if (!el) return;
    el.dataset.state = tone === 'ok' ? 'saved' : (tone === 'busy' ? 'saving' : 'error');   // where saving is (not shown)
    el.textContent = text;
    el.style.color = tone === 'ok' ? '#16a34a' : (tone === 'busy' ? '#5A7896' : '#C2410C');
  }

  async function autoSave(){
    if (LOCKED || autoBusy) return;
    autoBusy = true;
    showSaveState('Saving…', 'busy');
    try {
      const data = await postDoc(false);
      if (data.ok){
        dirty = false;
        showSaveState('All changes saved', 'ok');
        if (data.id && !DOC_ID) {
          history.replaceState(null, '', 'market-scoping-edit.php?id=' + data.id);
          window.DOC_ID_LIVE = data.id;
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
      const data = await postDoc(false);
      if (data.ok) {
        dirty = false;
        showSaveState('All changes saved', 'ok');
        if (data.id && !DOC_ID) { history.replaceState(null, '', 'market-scoping-edit.php?id=' + data.id); window.DOC_ID_LIVE = data.id; }
        return true;
      }
      alert(data.error || 'Your Market Scoping could not be saved, so it was not submitted.');
    } catch (e) { alert('Could not save. Check your connection.'); }
    return false;
  };

  function queueAutoSave(){
    if (LOCKED) return;
    clearTimeout(autoTimer);
    autoTimer = setTimeout(autoSave, window.PE_AUTOSAVE_MS || 1500);
  }

  // ── Saving ──
  let dirty = false;
  function markDirty(){
    dirty = true;
    showSaveState('Unsaved changes…', 'busy');
    queueAutoSave();
  }

  async function postDoc(submitNow){
    if (MS_FORM) {
      // The year's form (Document Format), not a document
      const r = await fetch('api/save-scoping-form.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ fy: MS_FORM, content: msContent() })
      });
      return await r.json();
    }
    // v104: the pictures go with every save — words, removals, and new pictures (a form post when there are new ones)
    const ph = (typeof msPhotoState === 'function') ? msPhotoState() : null;
    if (ph && ph.files.length) {
      const fd = new FormData();
      fd.append('id', DOC_ID); fd.append('fy', FY); fd.append('title', ''); fd.append('content', msContent());
      fd.append('from_form', window.__msFromForm ? 1 : 0); fd.append('submit_now', submitNow ? 1 : 0);
      Object.keys(ph.captions).forEach(function(id){ fd.append('photo_caption[' + id + ']', ph.captions[id]); });
      ph.remove.forEach(function(id){ fd.append('photo_remove[]', id); });
      ph.files.forEach(function(f, i){ fd.append('photos[]', f); fd.append('new_caption[]', ph.newCaptions[i] || ''); });
      const r2 = await fetch('api/save-market-scoping.php', { method: 'POST', body: fd });
      const d2 = await r2.json();
      if (d2.ok && typeof msPhotosSaved === 'function') msPhotosSaved(d2);
      return d2;
    }
    const res = await fetch('api/save-market-scoping.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        id: DOC_ID, fy: FY,
        title: '',
        content: msContent(),
        from_form: window.__msFromForm ? 1 : 0,
        submit_now: submitNow ? 1 : 0,
        // v104: the words under each picture, and pictures taken off
        photo_caption: ph ? ph.captions : {},
        photo_remove: ph ? ph.remove : []
      })
    });
    const d1 = await res.json();
    // the list is redrawn only when pictures were taken off (never while someone is typing their words)
    if (d1.ok && ph && ph.remove.length && typeof msPhotosSaved === 'function') msPhotosSaved(d1);
    return d1;
  }

  async function saveDoc(){
    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      const data = await postDoc(false);   // v104: the pictures go with it (postDoc)
      
      if (data.ok){
        dirty = false;
        document.getElementById('msStatus').textContent = '';
        btn.textContent = '✓ Saved';
        if (data.id && !DOC_ID){
          setTimeout(function(){ window.location.href = 'market-scoping-edit.php?id=' + data.id; }, 600);
          return;
        }
        setTimeout(() => { btn.textContent = ' Save'; btn.disabled = false; }, 1100);
      } else {
        btn.textContent = ' Save'; btn.disabled = false;
        alert(data.error || 'Could not save.');
      }
    } catch(e){
      btn.textContent = ' Save'; btn.disabled = false;
      alert('Could not save. Check your connection.');
    }
  }


  // Print saves first, so the printout always matches what is on screen
  async function printDoc(){
    const btn = document.getElementById('printBtn');
    const label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Preparing…';
    try {
      const data = await postDoc(false);
      btn.textContent = label; btn.disabled = false;
      if (data.ok){ dirty = false; window.open('market-scoping-print.php?id=' + (data.id || window.DOC_ID_LIVE || DOC_ID), '_blank'); }
      else alert(data.error || 'Could not prepare the printout.');
    } catch(e){
      btn.textContent = label; btn.disabled = false;
      alert('Could not prepare the printout. Check your connection.');
    }
  }

  function submitDoc(){
    swiftConfirm({
      title: 'Submit this market scoping?',
      text: 'This sends the document to the Procurement Unit for review. You will not be able to edit it afterwards.',
      confirmLabel: 'Submit',
      tone: 'info',
      onConfirm: doSubmit
    });
  }

  async function doSubmit(){
    const btn = document.getElementById('submitBtn');
    btn.disabled = true; btn.textContent = 'Submitting…';
    try {
      const data = await postDoc(true);
      if (data.ok){ dirty = false; window.location.href = 'market-scoping-edit.php?id=' + (data.id || DOC_ID); }
      else { btn.textContent = ' Submit'; btn.disabled = false; alert(data.error || 'Could not submit.'); }
    } catch(e){
      btn.textContent = ' Submit'; btn.disabled = false;
      alert('Could not submit. Check your connection.');
    }
  }

  window.addEventListener('beforeunload', function(e){
    if (dirty && !LOCKED){ e.preventDefault(); e.returnValue = ''; }
  });

  /* ── The year's form (Document Format) ── */
  if (MS_FORM) {
    const pick = document.getElementById('msTokenPick');
    if (pick) pick.addEventListener('change', function(){
      if (!this.value) return;
      paper.focus();
      document.execCommand('insertText', false, this.value);
      this.value = '';
      markDirty();
    });
  }
  function resetForm(){
    swiftConfirm({
      title: 'Reset to the NGPA form?',
      text: 'The FY ' + MS_FORM + ' form goes back to the NGPA Market Scoping Checklist. Documents already started are not changed.',
      confirmLabel: 'Reset', tone: 'danger',
      onConfirm: async function(){
        try {
          const r = await fetch('api/save-scoping-form.php', { method: 'POST', headers: {'Content-Type': 'application/json'},
                                                               body: JSON.stringify({ fy: MS_FORM, action: 'reset' }) });
          const d = await r.json();
          if (d.ok) { dirty = false; window.location.reload(); } else alert(d.error || 'Could not reset the form.');
        } catch(e){ alert('Could not reset the form. Check your connection.'); }
      }
    });
  }
</script>
<?php if (!$msForm) { require_once __DIR__ . '/includes/xl-io-ui.php'; echo xl_io_modal('scoping', ['fy' => (int)($doc['fy'] ?? $fy)]); }   /* v105: Import MARKET SCOPING.docx */ ?>
<?php if (!$msForm && !$locked && $__canSubmit && !$__solo) { $submitSetFy = (int)($doc['fy'] ?? $fy); require __DIR__ . '/includes/submit-set-modal.php'; }
      elseif (!$msForm && !$locked && !$__solo && hs_applies($u)) { $submitSetFy = (int)($doc['fy'] ?? $fy); $submitSetAction = 'api/submit-to-head.php'; require __DIR__ . '/includes/submit-set-modal.php'; }   /* v112 */ ?>
</body></html>
