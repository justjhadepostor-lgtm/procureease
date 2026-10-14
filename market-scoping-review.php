<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/richtext.php';
require_once __DIR__ . '/config/archive.php';
require_admin();
ensure_admin_tables();

$u   = current_user();
$pdo = db();

$docId = (int)($_GET['id'] ?? 0);
$doc = null;
try {
    $s = $pdo->prepare("SELECT m.*, d.name AS dept_name, d.dept_group
                          FROM market_scoping m
                     LEFT JOIN departments d ON d.code = m.dept_code
                         WHERE m.id = ?");
    $s->execute([$docId]);
    $doc = $s->fetch();
} catch (Throwable $t) {}

if (!$doc) {
    flash('error', 'That document could not be found.');
    header('Location: market-scoping.php'); exit;
}

// A past year's document (restored from the Archive) is for viewing only: open it itself
if (fy_is_past((int)$doc['fy'])) { header('Location: market-scoping-print.php?id=' . (int)$doc['id']); exit; }
$status = $doc['status'];
$group  = dept_group_folder($doc['dept_code'], $doc['dept_group'] ?? null);
$body   = rt_sanitize((string)$doc['content']);

$activeNav = 'scoping';
$pageTitle = 'Market Scoping Review';
// This document renders with the format of its own fiscal year,
// not today's, so a filed document keeps the form it was filed under.
doc_fmt_use_year((int)($doc['fy'] ?? 0));
require __DIR__ . '/includes/head.php';
?>
<style>
  .ms-paper-wrap { background:#eef2f6; border:1px solid var(--border); border-radius:10px; padding:26px; overflow-x:auto; }
  .ms-paper {
    background:var(--white); width:8.5in; min-height:11in; margin:0 auto;
    padding:0.6in 1.06in 1in 1.25in; box-shadow:0 3px 14px rgba(0,0,0,0.13);
    font-family:Arial, Helvetica, sans-serif; font-size:11pt; line-height:1.3; color:var(--ink);
  }
  .ms-doc-title { text-align:center; font-family:Arial, Helvetica, sans-serif; font-size:14pt; font-weight:700;
    padding-bottom:8px; border-bottom:1px solid #000; margin:4px 0 6px; }
  .ms-paper p { margin-top:0; }
  .ms-letterhead {
    display:grid; grid-template-columns:90px 1fr 90px;
    align-items:center; gap:10px; margin-bottom:12px;
  }
  .ms-letterhead .seal img { width:82px; height:82px; object-fit:contain; }
  .ms-letterhead .lh-center { text-align:center; }
  .ms-letterhead .lh-rep  { font-size:11px; }
  .ms-letterhead .lh-sch  { font-size:16px; font-weight:700; letter-spacing:0; margin:1px 0; white-space:nowrap; }
  .ms-letterhead .lh-addr { font-size:10px; font-style:italic; margin-bottom:8px; }
  .ms-letterhead .lh-doc  { font-size:13px; font-weight:700; letter-spacing:0.03em; margin-top:4px; }
  .ms-letterhead .flag {
    width:90px; height:60px;
    background:url('<?= e(doc_logo('scoping', 'right')) ?>') center/contain no-repeat;
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
  }
  .ms-paper img { max-width:100%; height:auto; }
  .ms-paper table { border-collapse:collapse; }
  .ms-paper table td, .ms-paper table th { border:1px solid #999; padding:5px 8px; }
  @media (max-width:900px){ .ms-paper { width:100%; min-height:60vh; padding:16px; } }
</style>

<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title"><?= e($doc['dept_name'] ?: $doc['dept_code']) ?> · Market Scoping</h1>
        <p class="page-sub"><?= e($group) ?> · FY <?= (int)$doc['fy'] ?><?php if (!empty($doc['submitted_by'])): ?> · Submitted by <?= e($doc['submitted_by']) ?><?php endif; ?></p>
      </div>
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;" data-dock>
        <a href="market-scoping.php?group=<?= urlencode($group) ?>" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">← Back</a>
        <?php require_once __DIR__ . '/config/doc-versions.php'; echo doc_history_button('scoping', (string)$docId);   /* v79 */ ?>
        <a href="market-scoping-print.php?id=<?= $docId ?>" target="_blank" class="btn btn-ghost" style="padding:8px 14px; font-size:13px;">🖨️ Print</a>
        <?= arc_button(current_user(), arc_load('scoping', $docId), 'market-scoping-review.php?id=' . $docId) ?>
        <?php if ($status !== 'approved'): ?>
          <button type="button" onclick="askRevision()" class="btn btn-ghost" style="padding:8px 16px; font-size:13px; color:#C2410C;">↩ Send back for revision</button>
          <button type="button" onclick="askApprove()" class="btn btn-primary" style="padding:8px 18px; font-size:13px;">✓ Approve</button>
        <?php else: ?>
          <span style="display:inline-flex; align-items:center; gap:6px; background:#f0fdf4; color:#14532d; border:1.5px solid #86efac; padding:8px 18px; border-radius:10px; font-size:13px; font-weight:700;">✓ Approved</span>
        <?php endif; ?>
      </div>
    </div>
    <?php render_flashes(); ?>

    <?php
      $badge = [
        'submitted' => ['#1c4c86', '#EAF4F9', '📤 Awaiting review'],
        'approved'  => ['#14532d', '#f0fdf4', '✓ Approved'],
        'revision'  => ['#C2410C', '#FFF4EC', '↩ Sent back for revision'],
      ][$status] ?? ['#5A7896', '#f3f4f6', $status];
    ?>
    <div style="margin-bottom:14px;">
      <span style="background:<?= $badge[1] ?>; color:<?= $badge[0] ?>; padding:5px 14px; border-radius:999px; font-size:12px; font-weight:700;"><?= $badge[2] ?></span>
    </div>

    <?php if (!empty($doc['title'])): ?>
      <div style="font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:17px; color:var(--ink); margin-bottom:12px;">
        <?= e($doc['title']) ?>
      </div>
    <?php endif; ?>

    <div class="ms-paper-wrap">
      <div class="ms-paper">
        <div class="ms-letterhead">
          <div class="seal"><img src="<?= e(doc_logo('scoping', 'left')) ?>" alt="ASCOT Seal"></div>
          <div class="lh-center">
            <div class="lh-rep"><?= e(doc_fmt('scoping', 'rep_line')) ?></div>
            <div class="lh-sch"><?= e(doc_fmt('scoping', 'org_name')) ?></div>
            <div class="lh-addr"><?= e(doc_fmt('scoping', 'address')) ?></div>
          </div>
          <div class="flag"></div>
        </div>
        <div class="ms-doc-title"><?= e(doc_fmt('scoping', 'doc_title')) ?></div>
        <?= $body !== '' ? $body : '<p style="color:#6b6b6b; font-style:italic;">This document is empty.</p>' ?>
      </div>
    </div>
  </main>
</div>

<form method="post" action="api/market-scoping-action.php" id="approveForm" style="display:none;">
  <input type="hidden" name="id" value="<?= $docId ?>">
  <input type="hidden" name="do" value="approve">
</form>
<form method="post" action="api/market-scoping-action.php" id="revisionForm" style="display:none;">
  <input type="hidden" name="id" value="<?= $docId ?>">
  <input type="hidden" name="do" value="revision">
  <input type="hidden" name="note" id="revisionNote" value="">
</form>

<script>
  function askApprove(){
    swiftConfirm({
      title: 'Approve this market scoping?',
      text: 'This marks the department\u2019s market scoping as approved. It can no longer be approved again afterwards.',
      confirmLabel: 'Yes, approve',
      tone: 'info',
      onConfirm: function(){ document.getElementById('approveForm').submit(); }
    });
  }
  function askRevision(){
    swiftConfirm({
      title: 'Send back for revision?',
      text: 'The department will be able to edit and resubmit their market scoping.',
      confirmLabel: 'Send back',
      tone: 'danger',
      onConfirm: function(){
        const note = prompt('Note to the department (optional):') || '';
        document.getElementById('revisionNote').value = note;
        document.getElementById('revisionForm').submit();
      }
    });
  }
</script>
</body>
</html>
