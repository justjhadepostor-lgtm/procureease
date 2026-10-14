<?php
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_login();
ensure_admin_tables();

$u = current_user();
$activeNav = 'announcements';
$pageTitle = 'Announcements';
$pdo = db();

// Mark all announcement notifications as read for this user
try {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND type = 'announcement' AND is_read = 0")
        ->execute([$u['id']]);
} catch (Throwable $t) { /* ignore */ }

// Which audiences apply to this user?
$aud = 'user';
if ($u['role'] === 'budget') $aud = 'budget';
elseif ($u['role'] === 'admin') $aud = 'admin';

// Active announcements for this user's audience (or 'all')
$stmt = $pdo->prepare("SELECT * FROM announcements
                        WHERE is_active = 1
                          AND (audience = 'all' OR audience = ?)
                     ORDER BY created_at DESC");
$stmt->execute([$aud]);
$active = $stmt->fetchAll();
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<div class="app">
<?php
  if ($u['role'] === 'admin')      require __DIR__ . '/includes/sidebar-admin.php';
  elseif ($u['role'] === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
  else                             require __DIR__ . '/includes/sidebar-user.php';
?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Announcements</h1>
        <p class="page-sub">Messages from the Procurement Unit. Newest first.</p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <?php if (!$active): ?>
      <div class="card" style="text-align: center; padding: 60px 24px; color: var(--muted);">
        <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px; display: block; color: var(--border);"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
        <div style="font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px;">No announcements right now</div>
        <div style="font-size: 13.5px;">When the Procurement Unit posts one, it will appear here.</div>
      </div>
    <?php else: foreach ($active as $a): ?>
      <div class="card" style="margin-bottom: 12px; border-left: 4px solid #093c5d;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="#093c5d"><path d="M3 11l18-5v12L3 14v-3z"/></svg>
          <span style="font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 700; color: var(--ink);"><?= e($a['title']) ?></span>
        </div>
        <div style="font-size: 14px; color: var(--ink); line-height: 1.65; margin-bottom: 10px;">
          <?= nl2br(e($a['body'])) ?>
        </div>
        <div style="font-size: 11.5px; color: var(--muted);">
          Posted by <strong><?= e($a['posted_by']) ?></strong>
          &nbsp;·&nbsp;
          <?= reltime_span($a['created_at']) ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </main>
</div>
</body>
</html>
