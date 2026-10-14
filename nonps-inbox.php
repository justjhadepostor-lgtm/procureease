<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/price-provision.php';   // v80: a list's amount is its GRAND TOTAL
require_admin();
ensure_admin_tables();
$u = current_user();
$activeNav = 'nonps';
$pageTitle = 'Non-PS Inbox';
$pdo = db();

$filter = $_GET['filter'] ?? 'submitted';
$statusFilter = in_array($filter, ['submitted','all','approved','revision'], true) ? $filter : 'submitted';

$sql = "SELECT s.*, d.name AS dept_name,
               (SELECT COUNT(*) FROM nonps_items i WHERE i.submission_id = s.id) AS item_count,
               (SELECT COALESCE(SUM(total_cost),0) FROM nonps_items i WHERE i.submission_id = s.id) AS total_amount
          FROM nonps_submissions s
          LEFT JOIN departments d ON d.code = s.dept_code
         WHERE (s.archived IS NULL OR s.archived = 0)";
$params = [];
if ($statusFilter !== 'all') {
    $sql .= " AND s.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY s.submitted_at DESC, s.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll();

require __DIR__ . '/includes/head.php';
?>
<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Non-PS Inbox</h1>
        <p class="page-sub">Non-PS Supply lists submitted by departments for your review.</p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <div style="display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap;">
      <?php foreach (['submitted'=>'📤 To review','revision'=>'⚠ For revision','approved'=>'✓ Approved','all'=>'All'] as $k => $lbl): ?>
        <a href="?filter=<?= $k ?>" class="btn btn-ghost" style="padding: 7px 14px; font-size: 12.5px;<?= $k === $statusFilter ? ' background: var(--navy-50); color: var(--navy-800); font-weight: 700;' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$submissions): ?>
      <div class="card" style="text-align: center; padding: 40px 24px;">
        <div style="font-size: 40px; margin-bottom: 8px;">📭</div>
        <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; color: var(--ink); margin-bottom: 4px;">
          No submissions <?= $statusFilter === 'all' ? '' : 'to ' . e($statusFilter) ?> right now
        </div>
      </div>
    <?php else: ?>
      <?= sort_bar('nonpsInbox') ?>
      <div id="nonpsInbox">
      <?php foreach ($submissions as $s):
        $st = $s['status'];
        $meta = [
          'submitted' => ['color'=>'#4A7AAD','bg'=>'#EAF4F9','label'=>'📤 Awaiting review'],
          'revision'  => ['color'=>'#4F7DAE','bg'=>'#FFF0E4','label'=>'⚠ For Revision'],
          'approved'  => ['color'=>'#14532d','bg'=>'#f0fdf4','label'=>'✓ Approved'],
          'draft'     => ['color'=>'#64748B','bg'=>'#f3f4f6','label'=>'📝 Draft'],
        ][$st];
      ?>
        <div class="card" data-ts="<?= sort_ts($s['submitted_at'] ?? null) ?>" style="border-left: 4px solid #133458; margin-bottom: 12px;">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 260px;">
              <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px;">
                <span style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; color: var(--ink);">
                  <?= e($s['dept_code']) ?> · <?= e($s['dept_name'] ?? '') ?> · FY <?= (int)$s['fy'] ?>
                </span>
                <span style="background: <?= $meta['bg'] ?>; color: <?= $meta['color'] ?>; padding: 2px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;"><?= $meta['label'] ?></span>
              </div>
              <div style="font-size: 12px; color: var(--muted);">
                <?= (int)$s['item_count'] ?> items · <span title="<?= e(doc_fmt('nonps', 'lbl_grand')) ?> — with VAT, logistics and inflation">₱<?= number_format(nonps_grand_for((float)$s['total_amount'], (int)$s['fy']), 2) ?></span>
                <?php if (!empty($s['submitted_by'])): ?>
                  · Submitted by <strong><?= e($s['submitted_by']) ?></strong>
                  <?php if (!empty($s['submitted_at'])): ?> · <?= date('M j, Y g:i a', strtotime($s['submitted_at'])) ?><?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
              <a href="nonps-print.php?id=<?= (int)$s['id'] ?>" target="_blank" class="btn btn-ghost" style="padding: 7px 14px; font-size: 12.5px;">👁 View / Print</a>
              <?php if ($st === 'submitted'): ?>
                <form method="POST" action="api/nonps-review.php" style="margin: 0; display: inline;"
                      data-confirm="Approve this Non-PS list?"
                      data-confirm-title="Approve list?" data-confirm-label="Approve" data-confirm-tone="info">
                  <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
                  <input type="hidden" name="action" value="approve">
                  <button type="submit" class="btn btn-primary" style="padding: 7px 14px; font-size: 12.5px;">✓ Approve</button>
                </form>
                <button type="button" onclick="document.getElementById('revModal-<?= (int)$s['id'] ?>').classList.add('active')" class="btn btn-ghost" style="padding: 7px 14px; font-size: 12.5px; color: var(--orange-600); background: var(--orange-50); border-color: var(--orange-100);">↩ Send back</button>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($st === 'submitted'): ?>
        <div class="modal-overlay" id="revModal-<?= (int)$s['id'] ?>" onclick="if(event.target===this)this.classList.remove('active')"
             style="position: fixed; inset: 0; z-index: 9999; display: none; align-items: center; justify-content: center; background: rgba(32,39,89,0.45); backdrop-filter: blur(2px);">
          <div style="position: relative; background: var(--white); border-radius: 16px; max-width: 440px; width: calc(100% - 40px); padding: 26px; box-shadow: 0 20px 60px rgba(32,39,89,0.28);">
            <div style="width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; margin-bottom: 16px; font-size: 26px; background: var(--orange-50);">↩</div>
            <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 17px; color: var(--navy-800); margin-bottom: 6px;">Send back for revision?</div>
            <div style="font-size: 13.5px; color: var(--muted); line-height: 1.55; margin-bottom: 18px;">
              Return <strong style="color: var(--ink);"><?= e($s['dept_code']) ?> · FY <?= (int)$s['fy'] ?></strong> to the department with your note.
            </div>
            <form method="POST" action="api/nonps-review.php">
              <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
              <input type="hidden" name="action" value="revision">
              <label style="display: block; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 11.5px; color: var(--navy-800); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Note to department</label>
              <textarea name="note" rows="3" required autofocus placeholder="What needs to be changed?" style="width: 100%; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 13px; resize: vertical; box-sizing: border-box; outline: none;"></textarea>
              <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="document.getElementById('revModal-<?= (int)$s['id'] ?>').classList.remove('active')"
                        style="border: 1.5px solid var(--border); background: var(--white); color: var(--ink); padding: 9px 18px; border-radius: 10px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; cursor: pointer;">Cancel</button>
                <button type="submit" style="border: none; background: #133458; color: #fff; padding: 9px 20px; border-radius: 10px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; cursor: pointer;">Send back</button>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
<script>
  document.querySelectorAll('.modal-overlay[id^="revModal-"]').forEach(m => {
    new MutationObserver(() => {
      m.style.display = m.classList.contains('active') ? 'flex' : 'none';
    }).observe(m, { attributes: true, attributeFilter: ['class'] });
  });
</script>
</body>
</html>
