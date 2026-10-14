<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/admin-schema.php';
require_admin();
ensure_admin_tables();
$u = current_user();
$activeNav = 'user-reports';
$pdo = db();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'resolve' && $id > 0) {
        $pdo->prepare("UPDATE user_reports SET status='resolved', resolved_at=NOW(), resolved_by=? WHERE id=?")
            ->execute([$u['full_name'], $id]);
        // Notify the reporter
        $r = $pdo->prepare('SELECT user_id, subject FROM user_reports WHERE id = ?');
        $r->execute([$id]); $rep = $r->fetch();
        if ($rep) {
            notify_user((int)$rep['user_id'], 'report',
                'Your report was resolved',
                "Your report \"{$rep['subject']}\" has been marked as resolved by the administrator.",
                'report-issue.php');
        }
        log_activity('Resolved report', 'report', "#$id");
        flash('success', 'Report marked as resolved.');
    } elseif ($action === 'close' && $id > 0) {
        $pdo->prepare("UPDATE user_reports SET status='closed', resolved_at=NOW(), resolved_by=? WHERE id=?")
            ->execute([$u['full_name'], $id]);
        log_activity('Closed report', 'report', "#$id");
        flash('success', 'Report closed.');
    } elseif ($action === 'reopen' && $id > 0) {
        $pdo->prepare("UPDATE user_reports SET status='open', resolved_at=NULL, resolved_by=NULL WHERE id=?")
            ->execute([$id]);
        log_activity('Reopened report', 'report', "#$id");
        flash('success', 'Report reopened.');
    } elseif ($action === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM user_reports WHERE id = ?')->execute([$id]);
        log_activity('Deleted report', 'report', "#$id");
        flash('success', 'Report deleted.');
    }
    header('Location: admin-reports.php');
    exit;
}

// Filter tabs
$filter = $_GET['filter'] ?? 'open';
if (!in_array($filter, ['open','resolved','closed','all'], true)) $filter = 'open';

$reports = [];
try {
    if ($filter === 'all') {
        $reports = $pdo->query("SELECT * FROM user_reports ORDER BY created_at DESC LIMIT 200")->fetchAll();
    } else {
        $s = $pdo->prepare("SELECT * FROM user_reports WHERE status = ? ORDER BY created_at DESC LIMIT 200");
        $s->execute([$filter]);
        $reports = $s->fetchAll();
    }
} catch (Throwable $t) {}

// Counts per tab
$counts = ['open'=>0,'resolved'=>0,'closed'=>0,'all'=>0];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) AS n FROM user_reports GROUP BY status")->fetchAll() as $c) {
        $counts[$c['status']] = (int)$c['n'];
        $counts['all'] += (int)$c['n'];
    }
} catch (Throwable $t) {}

$catIcon = ['bug'=>'🐞','ui'=>'🎨','account'=>'👤','ppmp'=>'📄','other'=>'💬'];
$pageTitle = 'User reports · ProcureEase';
require __DIR__ . '/includes/head.php';
?>
<div class="app">
  <?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">User reports</h1>
        <p class="page-sub">Bug reports and issues submitted by users and budget officers.</p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <!-- Filter chips -->
    <div style="display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">
      <?php foreach ([['open','⏳ Open','var(--orange-500)'],['resolved','✓ Resolved','var(--green-500)'],['closed','Closed','var(--muted)'],['all','Everything','var(--navy-700)']] as $f):
        $key = $f[0]; $lab = $f[1]; $clr = $f[2];
        $isActive = ($filter === $key);
      ?>
        <a href="?filter=<?= $key ?>" style="text-decoration: none; padding: 8px 16px; border-radius: 999px; font-size: 13px; font-weight: 700; font-family: 'Space Grotesk', sans-serif; background: <?= $isActive ? 'var(--accent)' : 'var(--white)' ?>; color: <?= $isActive ? 'var(--accent-ink)' : 'var(--ink)' ?>; border: 1.5px solid <?= $clr ?>;">
          <?= $lab ?> <span style="opacity: 0.8; font-weight: 600;">(<?= $counts[$key] ?>)</span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (!$reports): ?>
      <div class="card" style="text-align: center; padding: 60px 20px; color: var(--muted);">
        <div style="font-size: 40px; margin-bottom: 10px;">📭</div>
        <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; color: var(--ink); margin-bottom: 4px;">
          No <?= $filter === 'all' ? '' : $filter ?> reports
        </div>
        <div style="font-size: 13px;">Reports from users will appear here.</div>
      </div>
    <?php else: ?>
      <?php foreach ($reports as $r):
        $st = $r['status'] ?? 'open';
        if ($st === 'resolved')      { $sFg = '#14532d'; $sBg = 'var(--green-50)'; $sBd = '#86efac'; $sLabel = '✓ Resolved'; $edge = 'var(--green-500)'; }
        elseif ($st === 'closed')    { $sFg = 'var(--muted)'; $sBg = 'var(--navy-50)'; $sBd = 'var(--border)'; $sLabel = 'Closed'; $edge = 'var(--muted)'; }
        else                          { $sFg = 'var(--orange-600)'; $sBg = 'var(--orange-50)'; $sBd = 'var(--orange-100)'; $sLabel = '⏳ Open'; $edge = 'var(--orange-500)'; }
        $icon = $catIcon[$r['category']] ?? '💬';
      ?>
        <div class="card" style="margin-bottom: 14px; border-left: 4px solid <?= $edge ?>;">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 10px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 240px;">
              <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px; flex-wrap: wrap;">
                <span style="font-size: 20px;"><?= $icon ?></span>
                <span style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; color: var(--ink);"><?= e($r['subject']) ?></span>
                <span style="background: <?= $sBg ?>; color: <?= $sFg ?>; border: 1.5px solid <?= $sBd ?>; padding: 2px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;"><?= $sLabel ?></span>
              </div>
              <div style="font-size: 12px; color: var(--muted);">
                From <strong style="color: var(--ink);"><?= e($r['full_name']) ?></strong>
                <span style="color: var(--muted);"> · @<?= e($r['username']) ?> · <?= e($r['dept_code']) ?></span>
                &nbsp;·&nbsp; <?= reltime_span($r['created_at']) ?>
                &nbsp;·&nbsp; category: <strong><?= e($r['category']) ?></strong>
              </div>
            </div>
          </div>

          <div style="padding: 12px 14px; background: var(--navy-50); border-radius: 8px; font-size: 13.5px; color: var(--ink); line-height: 1.55; margin-bottom: 12px; white-space: pre-wrap;"><?= e($r['description']) ?></div>

          <?php if (!empty($r['resolved_at'])): ?>
            <div style="font-size: 11.5px; color: var(--muted); margin-bottom: 10px;">
              <?= $st === 'closed' ? 'Closed' : 'Resolved' ?> by <?= e($r['resolved_by']) ?> · <?= reltime_span($r['resolved_at']) ?>
            </div>
          <?php endif; ?>

          <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php if ($st === 'open'): ?>
              <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="resolve">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn btn-primary" style="padding: 7px 16px; font-size: 12.5px;">✓ Mark resolved</button>
              </form>
              <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn btn-ghost" style="padding: 7px 16px; font-size: 12.5px;">Close</button>
              </form>
            <?php else: ?>
              <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="reopen">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn btn-ghost" style="padding: 7px 16px; font-size: 12.5px;">↩ Reopen</button>
              </form>
            <?php endif; ?>
            <form method="POST" style="margin: 0;" data-confirm="This report will be permanently deleted." data-confirm-title="Delete report?" data-confirm-label="Delete" data-confirm-tone="danger">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-ghost" style="padding: 7px 16px; font-size: 12.5px; color: var(--orange-600);">🗑 Delete</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
