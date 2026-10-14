<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/richtext.php';
require_login();
ensure_admin_tables();

$u   = current_user();
$pdo = db();
$docId = (int)($_GET['id'] ?? 0);

$doc = null;
try {
    $s = $pdo->prepare("SELECT m.*, d.name AS dept_name
                          FROM market_scoping m
                     LEFT JOIN departments d ON d.code = m.dept_code
                         WHERE m.id = ?");
    $s->execute([$docId]);
    $doc = $s->fetch();
} catch (Throwable $t) {}

// Departments may only print their own; the Procurement Unit may print any
if (!$doc || (($u['role'] ?? '') !== 'admin' && $doc['dept_code'] !== ($u['dept_code'] ?? ''))) {
    header('Location: market-scoping.php'); exit;
}

$body = rt_sanitize((string)$doc['content']);
// This document renders with the format of its own fiscal year,
// not today's, so a filed document keeps the form it was filed under.
doc_fmt_use_year((int)($doc['fy'] ?? 0));
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<title><?= e($doc['title'] ?: 'Market Scoping') ?> — ProcureEase</title>
<style>
  /* The NGPA form's own page: Letter, its margins, Arial 11 pt */
  @page { size: letter portrait; margin: 0.6in 1.06in 1in 1.25in; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.3; color: #000; margin: 0; padding: 24px; background: #eef2f6; }
  .sheet { background:#fff; max-width: 8.5in; margin: 0 auto; padding: 0.6in 1.06in 1in 1.25in; box-shadow: 0 3px 14px rgba(0,0,0,0.12); }
  .doc-title { text-align:center; font-size:14pt; font-weight:700; padding-bottom:8px; border-bottom:1px solid #000; margin:4px 0 6px; }
  .body p { margin-top: 0; }
  /* As the form prints: a row is never split across two pages, and a
     table's heading row is repeated on the next page. */
  .body tr { page-break-inside: avoid; break-inside: avoid; }
  .body thead { display: table-header-group; }
  .head {
    display:grid; grid-template-columns:90px 1fr 90px;
    align-items:center; gap:10px; margin-bottom:12px;
  }
  .head .seal img { width:82px; height:82px; object-fit:contain; }
  .head .lh-center { text-align:center; }
  .head .lh-rep  { font-size:11px; }
  .head .lh-sch  { font-size:16px; font-weight:700; letter-spacing:0; margin:1px 0; white-space:nowrap; }
  .head .lh-addr { font-size:10px; font-style:italic; margin-bottom:8px; }
  .head .lh-doc  { font-size:13px; font-weight:700; letter-spacing:0.03em; margin-top:4px; }
  .head .flag {
    width:90px; height:60px;
    background:url('<?= e(doc_logo('scoping', 'right')) ?>') center/contain no-repeat;
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
  }
  .head { margin-bottom:16px; }
  .head { margin-bottom: 6px; }
  .head .rep { font-size: 11pt; }
  .head .sch { font-size: 13pt; font-weight: bold; }
  .head .addr { font-size: 10pt; font-style: italic; }
  .head .doc { font-size: 12pt; font-weight: bold; margin-top: 12px; text-transform: uppercase; letter-spacing: 1px; }
  .head .meta { font-size: 10pt; margin-top: 4px; }
  .title { font-size: 13pt; font-weight: bold; text-align: center; margin: 16px 0 20px; }
  .body img { max-width: 100%; height: auto; }
  /* v103: the attached picture — at the end, never split from its words across two pages */
  .ms-photo { margin: 30px 0 0; text-align: center; break-inside: avoid; page-break-inside: avoid; }
  .ms-photo img { max-width: 100%; max-height: 7in; object-fit: contain; display: block; margin: 0 auto; }
  .ms-photo figcaption { margin-top: 8px; font-size: 11pt; line-height: 1.4; text-align: center; }
  .body table { border-collapse: collapse; }
  .body table td, .body table th { border: 1px solid #999; padding: 5px 8px; }
  .toolbar { max-width: 8.5in; margin: 0 auto 14px; }
  .toolbar a, .toolbar button { padding: 8px 18px; border: none; border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; text-decoration: none; color: #fff; font-family: Arial, sans-serif; margin-right: 6px; }
  @media print { .toolbar { display: none !important; } body { padding: 0; background: var(--white); } .sheet { box-shadow: none; max-width: none; padding: 0; } }
</style></head>
<body>
  <div class="toolbar">
    <a href="<?= ($u['role'] ?? '') === 'admin' ? 'market-scoping-review.php?id=' . $docId : 'market-scoping-edit.php?id=' . $docId ?>" style="background:#64748B;">← Back</a>
    <button onclick="window.print()" style="background:#1c4c86;">🖨️ Print</button>
  </div>

  <div class="sheet">
    <div class="head">
      <div class="seal"><img src="<?= e(doc_logo('scoping', 'left')) ?>" alt="ASCOT Seal"></div>
      <div class="lh-center">
        <div class="lh-rep"><?= e(doc_fmt('scoping', 'rep_line')) ?></div>
        <div class="lh-sch"><?= e(doc_fmt('scoping', 'org_name')) ?></div>
        <div class="lh-addr"><?= e(doc_fmt('scoping', 'address')) ?></div>
      </div>
      <div class="flag"></div>
    </div>
    <div class="doc-title"><?= e(doc_fmt('scoping', 'doc_title')) ?></div>

    <?php if (!empty($doc['title'])): ?>
      <div class="title"><?= e($doc['title']) ?></div>
    <?php endif; ?>

    <div class="body"><?= $body ?></div>
    
    <?php /* v103 → v104: the pictures go at the end of the document, each with its words under it.
         Each is kept whole: when it still fits on the page it stays there; when it does not, it
         moves to the next page together with its words (config/scoping-photos.php). */
      require_once __DIR__ . '/config/scoping-photos.php';
      foreach (ms_photos((int)$doc['id']) as $__ph): ?>
    <figure class="ms-photo">
      <img src="<?= e($__ph['path']) ?>" alt="Picture">
      <?php if (trim($__ph['caption']) !== ''): ?><figcaption><?= nl2br(e(trim($__ph['caption']))) ?></figcaption><?php endif; ?>
    </figure>
    <?php endforeach; ?>
  </div>
<?php $__pu = current_user(); if (!empty($__pu['id'])): /* Back stays in your section (assets/back-nav.js) */ ?>
<script>window.PE_UID = <?= (int)$__pu['id'] ?>;</script><script src="assets/back-nav.js"></script>
<?php endif; ?>
</body></html>
