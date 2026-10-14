<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/notify.php';
require_admin();
ensure_admin_tables();

$activeNav = 'announcement';
$pageTitle = 'Announcement';
$pdo = db();
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'post') {
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body']  ?? '');
        $aud   = in_array($_POST['audience'] ?? 'all', ['all','user','budget'], true) ? $_POST['audience'] : 'all';
        if ($title === '' || $body === '') {
            flash('error', 'Title and message are both required.');
        } else {
            $pdo->prepare('INSERT INTO announcements (title, body, posted_by, audience, is_active) VALUES (?,?,?,?,1)')
                ->execute([$title, $body, $u['full_name'], $aud]);

            // Fan out in-app + email notifications to target audience
            $sent = 0;
            if ($aud === 'all' || $aud === 'user')   $sent += notify_by_role('user',   'announcement', $title, $body, 'user-announcements.php');
            if ($aud === 'all' || $aud === 'budget') $sent += notify_by_role('budget', 'announcement', $title, $body, 'user-announcements.php');
            if ($aud === 'all')                      $sent += notify_by_role('admin',  'announcement', $title, $body, 'announcement.php');

            log_activity('Posted announcement', 'announcement', $title,
                mb_substr($body, 0, 100) . " · Notified $sent user(s).");
            flash('success', "Announcement posted and $sent user(s) notified via bell + email.");
        }
    } elseif ($action === 'toggle' && !empty($_POST['id'])) {
        $pdo->prepare('UPDATE announcements SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('success', 'Announcement visibility updated.');
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([(int)$_POST['id']]);
        log_activity('Deleted announcement', 'announcement', '#' . (int)$_POST['id']);
        flash('success', 'Announcement deleted.');
    }
    header('Location: announcement.php'); exit;
}

$announcements = $pdo->query('SELECT * FROM announcements ORDER BY created_at DESC')->fetchAll();
$activeCount = 0;
foreach ($announcements as $a) if ($a['is_active']) $activeCount++;
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<div class="app">
  <?php require_once __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Announcement</h1>
      </div>
    </div>
    <?php render_flashes(); ?>

    <div class="stats">
      <div class="stat">
        <div class="stat-label">Live announcements</div>
        <div class="stat-value" style="color: var(--ink);"><?= $activeCount ?></div>
        <div class="stat-meta">Visible on user dashboards</div>
      </div>
      <div class="stat">
        <div class="stat-label">All-time posts</div>
        <div class="stat-value"><?= count($announcements) ?></div>
        <div class="stat-meta">Including hidden ones</div>
      </div>
    </div>

    <div class="section-head">
      <div>
        <h2 class="section-title">Post an announcement</h2>
      </div>
    </div>
    <div class="card">
      <form method="POST"
            data-confirm="This announcement will appear on the dashboards of everyone in the audience you picked."
            data-confirm-title="Post this announcement?" data-confirm-label="Post it" data-confirm-tone="info">
        <input type="hidden" name="action" value="post">
        <label class="form-label" for="title">Title</label>
        <input type="text" class="form-input" id="title" name="title" placeholder="e.g. FY 2028 PPMP submission deadline" required style="margin-bottom: 14px;">
        <label class="form-label" for="body">Message</label>
        <textarea class="form-textarea" id="body" name="body" rows="4" placeholder="Explain what changed, what's expected, or a reminder…" required style="margin-bottom: 14px;"></textarea>
        <label class="form-label" for="audience">Audience</label>
        <select id="audience" name="audience" class="form-input" style="margin-bottom: 14px;">
          <option value="all">Everyone (users, budget, admin)</option>
          <option value="user">Department users only</option>
          <option value="budget">Budget Unit only</option>
        </select>
        <button type="submit" class="btn btn-primary">Post announcement</button>
      </form>
    </div>

    <div class="section-head" style="margin-top: 32px;">
      <div>
        <h2 class="section-title">All announcements</h2>
        <p class="section-sub">Toggle visibility to hide or unhide a message. Delete removes it permanently.</p>
      </div>
    </div>

    <?php if (!$announcements): ?>
      <div class="card" style="text-align: center; padding: 60px 24px; color: var(--muted);">
        <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px; display: block; color: var(--border);"><path d="M3 11l18-8v18l-18-8v-2z"/></svg>
        <div style="font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px;">No announcements yet</div>
        <div style="font-size: 13.5px;">The message you post will appear here.</div>
      </div>
    <?php else: ?>
      <?php foreach ($announcements as $a): ?>
        <div class="card" style="margin-bottom: 12px; border-left: 4px solid <?= $a['is_active'] ? '#093c5d' : 'var(--border)' ?>;">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
            <div style="flex: 1;">
              <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <span style="font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; color: var(--ink);"><?= e($a['title']) ?></span>
                <?php if ($a['is_active']): ?>
                  <span style="font-size: 10px; padding: 2px 8px; background: var(--green-50); color: #14532d; border-radius: 999px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase;">Live</span>
                <?php else: ?>
                  <span style="font-size: 10px; padding: 2px 8px; background: var(--navy-50); color: var(--muted); border-radius: 999px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase;">Hidden</span>
                <?php endif; ?>
                <span style="font-size: 10px; padding: 2px 8px; background: var(--navy-100); color: var(--ink); border-radius: 999px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase;"><?= e($a['audience']) ?></span>
              </div>
              <div style="font-size: 13.5px; color: var(--ink); line-height: 1.6; margin-bottom: 8px;"><?= nl2br(e($a['body'])) ?></div>
              <div style="font-size: 11.5px; color: var(--muted);">Posted by <?= e($a['posted_by']) ?> · <?= reltime_span($a['created_at']) ?></div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 6px; align-items: center; justify-content: center;">
              <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn btn-ghost" style="padding: 6px 12px; font-size: 12px; width: 90px;">
                  <?= $a['is_active'] ? 'Hide' : 'Show' ?>
                </button>
              </form>
              <form method="POST" style="margin: 0;" data-confirm="This announcement will be permanently deleted for everyone." data-confirm-title="Delete announcement?" data-confirm-label="Delete" data-confirm-tone="danger">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn btn-ghost" style="padding: 6px 12px; font-size: 12px; color: var(--ink); width: 90px;">
                  Delete
                </button>
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
