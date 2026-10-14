<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/price-provision.php';   // v80: a list's amount is its GRAND TOTAL
require_login();
ensure_admin_tables();
$u = current_user();
$activeNav = 'nonps';
$pageTitle = 'Non-PS Archive';
$pdo = db();

if (($u['role'] ?? '') === 'admin') { header('Location: nonps-inbox.php'); exit; }

// Safe migrations
try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN archived TINYINT(1) DEFAULT 0"); } catch (Throwable $t) {}
try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN archived_at TIMESTAMP NULL"); } catch (Throwable $t) {}
try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN archived_by VARCHAR(100) NULL"); } catch (Throwable $t) {}

$stmt = $pdo->prepare("SELECT s.*,
                             (SELECT COUNT(*) FROM nonps_items i WHERE i.submission_id = s.id) AS item_count,
                             (SELECT COALESCE(SUM(total_cost),0) FROM nonps_items i WHERE i.submission_id = s.id) AS total_amount
                        FROM nonps_submissions s
                       WHERE s.dept_code = ? AND s.archived = 1
                    ORDER BY s.archived_at DESC, s.id DESC");
$stmt->execute([$u['dept_code']]);
$archived = $stmt->fetchAll();

require __DIR__ . '/includes/head.php';
?>
<div class="app">
<?php if ($u['role'] === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
      else                         require __DIR__ . '/includes/sidebar-user.php'; ?>
  <main class="main">
    <div class="page-head">
      <div style="display: flex; align-items: center; gap: 12px;">
        <a href="nonps-edit.php" style="color: var(--ink); text-decoration: none; font-size: 14px;">← Back</a>
        <div>
          <h1 class="page-title" style="margin: 0;">Non-PS Archive</h1>
          <p class="page-sub" style="margin: 4px 0 0;">Archived Non-PS lists. You can restore them or permanently delete them from here.</p>
        </div>
      </div>
    </div>
    <?php render_flashes(); ?>

    <?php if (!$archived): ?>
      <div class="card" style="text-align: center; padding: 60px 24px;">
        <div style="font-size: 42px; margin-bottom: 10px;">🗄️</div>
        <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; color: var(--ink); margin-bottom: 6px;">
          Your archive is empty
        </div>
        <div style="font-size: 13px; color: var(--muted); max-width: 480px; margin: 0 auto;">
          Non-PS lists you archive from the main list will appear here. You can restore them or permanently delete them anytime.
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($archived as $s): ?>
        <div class="card" style="margin-bottom: 12px; border-left: 4px solid #94a3b8; opacity: 0.9;">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 260px;">
              <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px;">
                <span style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; color: var(--ink);">
                  List of Non-PS Supplies · FY <?= (int)$s['fy'] ?>
                </span>
                <span class="nps-arc-tag" style="background: var(--navy-50); color: var(--muted); padding: 3px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;">🗄️ Archived</span>
              </div>
              <div style="font-size: 12px; color: var(--muted);">
                <?= (int)$s['item_count'] ?> items · <span title="<?= e(doc_fmt('nonps', 'lbl_grand')) ?> — with VAT, logistics and inflation">₱<?= number_format(nonps_grand_for((float)$s['total_amount'], (int)$s['fy']), 2) ?></span>
                <?php if (!empty($s['archived_at'])): ?>
                  · Archived <?= date('M j, Y', strtotime($s['archived_at'])) ?>
                <?php endif; ?>
              </div>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
              <a href="nonps-print.php?id=<?= (int)$s['id'] ?>" target="_blank" class="btn btn-ghost" style="padding: 8px 14px; font-size: 12.5px;">🖨️ Print</a>
              <form method="POST" action="api/nonps-archive.php" style="margin: 0; display: inline;"
                    data-confirm="Restore this Non-PS list back to the main page?"
                    data-confirm-title="Restore list?" data-confirm-label="Restore" data-confirm-tone="info">
                <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="action" value="restore">
                <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 12.5px; color: var(--ink);">↩ Restore</button>
              </form>
              <form method="POST" action="api/nonps-archive.php" style="margin: 0; display: inline;"
                    data-confirm="This permanently deletes the list and all its items. This action cannot be undone."
                    data-confirm-title="Permanently delete?" data-confirm-label="Delete forever" data-confirm-tone="danger">
                <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="action" value="delete_permanently">
                <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 12.5px; color: var(--orange-600);">🗑 Delete permanently</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
