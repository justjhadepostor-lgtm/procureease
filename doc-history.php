<?php
/* ═══════════════════════════════════════════════════════════════
   Version history of one document (v79, config/doc-versions.php).

   Every time the document was submitted, resubmitted, approved, sent back
   or pulled back, what it said then — newest first — and what changed from
   the version before: lines added or removed, amounts and fields changed,
   the total before and after. Read-only.

   ?type=ppmp|appcse|nonps|annex|scoping  &ref=the document
   Seen by whoever can open the document: its department, the Procurement
   Unit, and the Budget Unit for PPMPs.
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/doc-versions.php';
require_login();

$u    = current_user();
$type = (string)($_GET['type'] ?? '');
$ref  = trim((string)($_GET['ref'] ?? ''));
if (!isset(DOC_VERSION_TYPES[$type]) || $ref === '' || !preg_match('/^[0-9]+(:[A-Za-z0-9_-]+)?$/', $ref)) {
    flash('error', 'That document could not be found.');
    header('Location: index.php'); exit;
}

$versions = doc_versions($type, $ref);
$now      = doc_version_read($type, $ref);
$dept     = (string)($versions ? end($versions)['dept_code'] : ($now['dept'] ?? ''));
$fy       = (int)($versions ? end($versions)['fy'] : ($now['fy'] ?? 0));
if ((!$versions && !$now) || !doc_history_can_view($u, $type, $dept)) {
    flash('error', 'That document could not be found.');
    header('Location: index.php'); exit;
}
$deptName = $dept;
try { $s = db()->prepare('SELECT name FROM departments WHERE code = ?'); $s->execute([$dept]); $deptName = (string)($s->fetchColumn() ?: $dept); } catch (Throwable $t) {}

// Back to the document, the way this person opens it
$isAdmin = ($u['role'] ?? '') === 'admin';
$own     = (string)($u['dept_code'] ?? '') === $dept;
switch ($type) {
    case 'ppmp':    $back = 'ppmp-file-view.php?id=' . (int)$ref; break;
    case 'appcse':  $back = ($isAdmin && !$own) ? 'appcse-review.php?fy=' . $fy . '&dept=' . urlencode($dept) : 'appcse-edit.php'; break;
    case 'nonps':   $back = ($isAdmin && !$own) ? 'nonps-review.php?sub=' . (int)$ref : 'nonps-edit.php?id=' . (int)$ref; break;
    case 'annex':   $back = ($isAdmin && !$own) ? 'annex-review.php?id=' . (int)$ref : 'annex-edit.php?id=' . (int)$ref; break;
    default:        $back = ($isAdmin && !$own) ? 'market-scoping-review.php?id=' . (int)$ref : 'market-scoping-edit.php?id=' . (int)$ref;
}

$docLabel  = DOC_VERSION_TYPES[$type];
$activeNav = '';
$pageTitle = 'Version history · ' . $docLabel;

function dh_signed(float $v): string { return ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . '₱' . number_format(abs($v), 2); }
/** A line's figures in one short row: "Amount ₱10,000.00 · Mode Small Value Procurement …" */
function dh_cols(array $cols, int $max = 6): string {
    $keep = [];
    foreach ($cols as $c => $v) {
        if ((string)$v === '' || preg_match('/^(0|₱0\.00)$/', (string)$v)) continue;
        $keep[] = $c . ' ' . $v;
        if (count($keep) >= $max) break;
    }
    return implode(' · ', $keep);
}

require __DIR__ . '/includes/head.php';
?>
<style>
  .dh-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px 20px; flex-wrap: wrap; }
  .dh-ver { padding: 0; overflow: hidden; margin-bottom: 16px; }
  .dh-ver-top { display: flex; align-items: flex-start; gap: 14px; padding: 16px 20px; flex-wrap: wrap; }
  .dh-no { flex: none; display: grid; place-items: center; min-width: 44px; height: 44px; padding: 0 8px; border-radius: 12px; background: var(--navy-50);
    border: 1px solid var(--border); font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; color: var(--ink); }
  .dh-meta { flex: 1 1 260px; min-width: 0; }
  .dh-event { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; color: var(--ink); display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .dh-tag { font-family: 'Inter', sans-serif; font-size: 10.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; text-transform: uppercase; letter-spacing: .04em;
    background: var(--navy-50); color: var(--ink); border: 1px solid var(--border); }
  .dh-tag.latest { background: var(--accent); color: var(--accent-ink); border-color: transparent; }
  .dh-sub { font-size: 12.5px; color: var(--muted); margin-top: 3px; }
  .dh-note { margin-top: 8px; font-size: 12.5px; color: var(--ink); padding: 8px 12px; border-radius: 8px; background: var(--orange-50); border: 1px solid var(--orange-100); }
  .dh-total { text-align: right; font-size: 12px; color: var(--muted); }
  .dh-total b { display: block; font-family: 'Space Grotesk', sans-serif; font-size: 16px; color: var(--ink); }
  .dh-body { border-top: 1px solid var(--border); padding: 14px 20px 16px; }
  .dh-body h4 { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
  .dh-none { font-size: 13px; color: var(--muted); }
  .dh-list { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 6px; }
  .dh-item { display: grid; grid-template-columns: 22px minmax(0, 1fr); gap: 8px; align-items: start; padding: 8px 10px; border-radius: 8px; font-size: 12.5px;
    color: var(--ink); border: 1px solid var(--border); background: var(--white); line-height: 1.5; }
  .dh-item .sym { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 6px; font-weight: 800; font-size: 13px; }
  .dh-item.add .sym { background: #dcfce7; color: #14532d; } .dh-item.del .sym { background: #fee2e2; color: #7f1d1d; } .dh-item.chg .sym { background: #e0ecf8; color: #133458; }
  .dh-item b { font-weight: 700; }
  .dh-item .was { color: var(--muted); text-decoration: line-through; text-decoration-thickness: 1px; }
  .dh-item .arrow { color: var(--muted); padding: 0 4px; }
  .dh-item .figs { color: var(--muted); font-size: 12px; }
  .dh-chg { margin: 4px 0 0; padding: 0; list-style: none; display: grid; gap: 2px; }
  details.dh-full { border-top: 1px solid var(--border); padding: 10px 20px 14px; }
  details.dh-full > summary { cursor: pointer; font-size: 12px; font-weight: 700; color: var(--navy-700); width: max-content; }
  .dh-tablewrap { overflow-x: auto; margin-top: 10px; }
  .dh-table { width: 100%; border-collapse: collapse; font-size: 12px; color: var(--ink); }
  .dh-table th, .dh-table td { padding: 6px 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
  .dh-table th { font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); white-space: nowrap; }
  .dh-fields { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 4px 14px; font-size: 12.5px; color: var(--ink); margin-top: 10px; }
  .dh-fields dt { color: var(--muted); } .dh-fields dd { margin: 0; }
  body[data-theme="dark"] .dh-item.add .sym { background: #14532d; color: #dcfce7; }
  body[data-theme="dark"] .dh-item.del .sym { background: #7f1d1d; color: #fee2e2; }
  body[data-theme="dark"] .dh-item.chg .sym { background: #16456e; color: #ffffff; }
  body[data-theme="dark"] .dh-note { color: #ffffff; }
  @media print { .topbar, .sidebar, .dh-actions { display: none !important; } details.dh-full { display: block; } }
</style>
<div class="app">
  <?php
    $__role = (string)($u['role'] ?? '');
    if     ($__role === 'admin')  require __DIR__ . '/includes/sidebar-admin.php';
    elseif ($__role === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
    else                          require __DIR__ . '/includes/sidebar-user.php';
  ?>
  <main class="main">
    <div class="page-head dh-head">
      <div>
        <h1 class="page-title">Version history · <?= e($docLabel) ?></h1>
        <p class="page-sub"><?= e($deptName) ?> · FY <?= $fy ?><?= $now && $type === 'annex' && ($now['fields']['Project name'] ?? '') !== '' ? ' · ' . e($now['fields']['Project name']) : '' ?>
          — a copy is kept each time it is submitted, approved, sent back or pulled back.</p></div>
      <div class="dh-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
        <a class="btn btn-ghost" href="<?= e($back) ?>" style="padding: 8px 16px; font-size: 13px; text-decoration: none;">← Back to the <?= e($docLabel) ?></a>
        <button type="button" class="btn btn-ghost" onclick="window.print()" style="padding: 8px 16px; font-size: 13px;">🖨️ Print</button></div></div>
    <?php render_flashes(); ?>

    <?php if (!$versions): ?>
      <div class="card" style="text-align:center; padding: 44px 24px; color: var(--muted);" id="dhEmpty">
        <div style="font-size: 15px; font-weight: 600; color: var(--ink); margin-bottom: 6px;">No versions yet</div>
        A version is kept each time this <?= e($docLabel) ?> is submitted, approved, sent back or pulled back.</div>
    <?php else:
      $list = array_reverse($versions);
      foreach ($list as $i => $v):
        $doc  = $v['doc'];
        $prev = null;
        foreach ($versions as $p) if ((int)$p['version_no'] === (int)$v['version_no'] - 1) $prev = $p;
        $diff = $prev ? doc_version_diff($prev['doc'], $doc) : null;
        $evLabel = DOC_VERSION_EVENTS[$v['event']] ?? $v['event'];
    ?>
      <div class="card dh-ver" id="v<?= (int)$v['version_no'] ?>" data-event="<?= e($v['event']) ?>">
        <div class="dh-ver-top">
          <div class="dh-no">v<?= (int)$v['version_no'] ?></div>
          <div class="dh-meta">
            <div class="dh-event"><?= e($evLabel) ?><?php if ($i === 0): ?> <span class="dh-tag latest">Latest</span><?php endif; ?></div>
            <div class="dh-sub"><?= e(date('M j, Y · g:i A', strtotime((string)$v['created_at']))) ?>
              <?php if (!empty($v['created_by'])): ?> · by <?= e($v['created_by']) ?><?= !empty($v['created_role']) ? ' (' . e($v['created_role']) . ')' : '' ?><?php endif; ?></div>
            <?php if (!empty($v['note'])): ?><div class="dh-note"><b>Note:</b> <?= e($v['note']) ?></div><?php endif; ?>
          </div>
          <?php if ($v['total'] !== null && ($doc['total_label'] ?? '') !== ''): ?>
            <div class="dh-total"><?= e($doc['total_label']) ?><b>₱<?= number_format((float)$v['total'], 2) ?></b></div>
          <?php endif; ?>
        </div>

        <div class="dh-body">
          <?php if (!$diff): ?>
            <h4>First version</h4>
            <div class="dh-none"><?= count($doc['rows'] ?? []) ?> line<?= count($doc['rows'] ?? []) === 1 ? '' : 's' ?><?= $type === 'scoping' ? ' · ' . count($doc['lines'] ?? []) . ' lines of text' : '' ?>
              <?= isset($doc['total']) && $doc['total'] !== null ? ' · ' . e($doc['total_label']) . ' ₱' . number_format((float)$doc['total'], 2) : '' ?>. Open &ldquo;What it said&rdquo; below to see all of it.</div>
          <?php elseif ($diff['none']): ?>
            <h4>What changed from v<?= (int)$prev['version_no'] ?></h4>
            <div class="dh-none">No changes from v<?= (int)$prev['version_no'] ?>.</div>
          <?php else: ?>
            <h4>What changed from v<?= (int)$prev['version_no'] ?></h4>
            <ul class="dh-list">
              <?php if ($diff['total']): ?>
                <li class="dh-item chg"><span class="sym">~</span><span><b><?= e($doc['total_label']) ?></b>:
                  <span class="was">₱<?= number_format($diff['total'][0], 2) ?></span><span class="arrow">→</span><b>₱<?= number_format($diff['total'][1], 2) ?></b>
                  <span class="figs">(<?= e(dh_signed($diff['total'][1] - $diff['total'][0])) ?>)</span></span></li>
              <?php endif; ?>
              <?php foreach ($diff['fields'] as [$f, $a, $b]): ?>
                <li class="dh-item chg"><span class="sym">~</span><span><b><?= e($f) ?></b>:
                  <span class="was"><?= $a !== '' ? e($a) : '(empty)' ?></span><span class="arrow">→</span><?= $b !== '' ? e($b) : '(empty)' ?></span></li>
              <?php endforeach; ?>
              <?php foreach ($diff['changed'] as [$label, $cols]): ?>
                <li class="dh-item chg"><span class="sym">~</span><span><b><?= e($label) ?></b>
                  <ul class="dh-chg"><?php foreach ($cols as [$c, $a, $b]): ?>
                    <li><?= e($c) ?>: <span class="was"><?= $a !== '' ? e($a) : '(empty)' ?></span><span class="arrow">→</span><?= $b !== '' ? e($b) : '(empty)' ?></li>
                  <?php endforeach; ?></ul></span></li>
              <?php endforeach; ?>
              <?php foreach ($diff['added'] as $r): ?>
                <li class="dh-item add"><span class="sym">+</span><span><b>Added:</b> <?= e($r['label']) ?>
                  <?php if (($fig = dh_cols($r['cols'])) !== ''): ?><br><span class="figs"><?= e($fig) ?></span><?php endif; ?></span></li>
              <?php endforeach; ?>
              <?php foreach ($diff['removed'] as $r): ?>
                <li class="dh-item del"><span class="sym">−</span><span><b>Removed:</b> <?= e($r['label']) ?>
                  <?php if (($fig = dh_cols($r['cols'])) !== ''): ?><br><span class="figs"><?= e($fig) ?></span><?php endif; ?></span></li>
              <?php endforeach; ?>
              <?php foreach ($diff['lines_added'] as $l): ?>
                <li class="dh-item add"><span class="sym">+</span><span><?= e($l) ?></span></li>
              <?php endforeach; ?>
              <?php foreach ($diff['lines_removed'] as $l): ?>
                <li class="dh-item del"><span class="sym">−</span><span><span class="was"><?= e($l) ?></span></span></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <details class="dh-full">
          <summary>What it said (v<?= (int)$v['version_no'] ?>)</summary>
          <?php $__fs = array_filter($doc['fields'] ?? [], fn($x) => (string)$x !== ''); if ($__fs): ?>
            <dl class="dh-fields"><?php foreach ($__fs as $f => $val): ?><dt><?= e($f) ?></dt><dd><?= e($val) ?></dd><?php endforeach; ?></dl>
          <?php endif; ?>
          <?php if (!empty($doc['rows'])):
            $__cols = []; foreach ($doc['rows'] as $r) foreach ($r['cols'] as $c => $val) if ((string)$val !== '' && !in_array($c, $__cols, true)) $__cols[] = $c;
          ?>
            <div class="dh-tablewrap"><table class="dh-table">
              <thead><tr><th>Line</th><?php foreach ($__cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
              <tbody><?php foreach ($doc['rows'] as $r): ?>
                <tr><td><?= e($r['label']) ?></td><?php foreach ($__cols as $c): ?><td><?= e((string)($r['cols'][$c] ?? '')) ?></td><?php endforeach; ?></tr>
              <?php endforeach; ?></tbody></table></div>
          <?php endif; ?>
          <?php if (!empty($doc['lines'])): ?>
            <div style="margin-top: 10px; font-size: 12.5px; color: var(--ink); line-height: 1.6;">
              <?php foreach ($doc['lines'] as $l): ?><div><?= e($l) ?></div><?php endforeach; ?></div>
          <?php endif; ?>
          <?php if (empty($doc['rows']) && empty($doc['lines']) && !$__fs): ?><div class="dh-none" style="margin-top:8px;">(empty)</div><?php endif; ?>
        </details>
      </div>
    <?php endforeach; endif; ?>
  </main>
</div>
</body>
</html>
