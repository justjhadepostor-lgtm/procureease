<?php
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_admin();
$activeNav = 'activity';
$pageTitle = 'Activity Logs';

// Auto-create the activity_logs / settings / backups tables if they don't exist yet
ensure_admin_tables();

// ─── Handle POST actions ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_old') {
    $days = max(1, (int)($_POST['days'] ?? 30));
    $stmt = db()->prepare('DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$days]);
    $deleted = $stmt->rowCount();
    log_activity('Cleared activity logs', 'activity', "older than $days days", "Removed $deleted event(s) older than $days days.");
    flash('success', "Cleared $deleted activity event(s) older than $days days.");
    header('Location: activity-logs.php');
    exit;
}

// ─── Read filters ────────────────────────────────────────────
$user_filter   = trim($_GET['user']   ?? '');
$action_filter = trim($_GET['action'] ?? '');
$search        = trim($_GET['q']      ?? '');
$search        = preg_replace('/[0-9]/', '', $search); // search is characters only — no numbers

$sql = 'SELECT * FROM activity_logs WHERE 1=1';
$params = [];
if ($user_filter)   { $sql .= ' AND username = ?'; $params[] = $user_filter; }
if ($action_filter) { $sql .= ' AND action = ?';   $params[] = $action_filter; }
if ($search)        {
    $sql .= ' AND (details LIKE ? OR entity_ref LIKE ? OR full_name LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
// ── Pagination — 20 events per page ────────────────────────
$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$cst = db()->prepare(preg_replace('/^SELECT \*/', 'SELECT COUNT(*)', $sql));
$cst->execute($params);
$totalLogs  = (int)$cst->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare($sql . ' ORDER BY created_at DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Shared pagination helpers for the stepper (top + bottom)
$qs = function ($p) use ($user_filter, $action_filter, $search) {
    return '?' . http_build_query(array_filter([
        'user' => $user_filter ?: null, 'action' => $action_filter ?: null,
        'q' => $search ?: null, 'page' => $p,
    ]));
};
$pgStart = max(1, $page - 2);
$pgEnd   = min($totalPages, $page + 2);

// Renders the pagination stepper (used above and below the table)
function render_log_pager(int $page, int $totalPages, int $totalLogs, callable $qs, int $pgStart, int $pgEnd, string $align = 'center'): void {
    if ($totalPages <= 1) return;
    echo '<nav class="log-pager no-print" style="justify-content:' . $align . ';" aria-label="Activity log pages">';
    echo '<a class="pg ' . ($page <= 1 ? 'disabled' : '') . '" href="' . ($page > 1 ? e($qs($page - 1)) : '#') . '">‹ Prev</a>';
    if ($pgStart > 1) {
        echo '<a class="pg" href="' . e($qs(1)) . '">1</a>';
        if ($pgStart > 2) echo '<span class="pg-gap">…</span>';
    }
    for ($i = $pgStart; $i <= $pgEnd; $i++) {
        echo '<a class="pg ' . ($i === $page ? 'active' : '') . '" href="' . e($qs($i)) . '">' . $i . '</a>';
    }
    if ($pgEnd < $totalPages) {
        if ($pgEnd < $totalPages - 1) echo '<span class="pg-gap">…</span>';
        echo '<a class="pg" href="' . e($qs($totalPages)) . '">' . $totalPages . '</a>';
    }
    echo '<a class="pg ' . ($page >= $totalPages ? 'disabled' : '') . '" href="' . ($page < $totalPages ? e($qs($page + 1)) : '#') . '">Next ›</a>';
    echo '<span class="pg-info">' . number_format($totalLogs) . ' events · ' . (int)$totalPages . ' page' . ($totalPages === 1 ? '' : 's') . ' · page ' . (int)$page . ' of ' . (int)$totalPages . '</span>';
    echo '</nav>';
}

$users   = db()->query('SELECT DISTINCT username, full_name FROM activity_logs ORDER BY username')->fetchAll();
$actions = db()->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$today  = (int)db()->query('SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn();
$week   = (int)db()->query('SELECT COUNT(*) FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
$total  = (int)db()->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$logins = (int)db()->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Signed in' AND DATE(created_at) = CURDATE()")->fetchColumn();

function relTime(string $t): string {
    $secs = time() - strtotime($t);
    if ($secs < 60)     return $secs . 's ago';
    if ($secs < 3600)   return floor($secs / 60)    . 'm ago';
    if ($secs < 86400)  return floor($secs / 3600)  . 'h ago';
    if ($secs < 604800) return floor($secs / 86400) . 'd ago';
    return date('M j, Y', strtotime($t));
}
$actionColor = [
    'Signed in'             => 'navy',
    'Signed out'            => 'muted',
    'Submitted PPMP'        => 'green',
    'Saved draft'           => 'navy',
    'Requested edit'        => 'orange',
    'Requested revision'    => 'orange',
    'Granted edit access'   => 'green',
    'Declined edit'         => 'muted',
    'Generated report'      => 'navy',
    'Backed up database'    => 'navy',
    'Downloaded backup'     => 'navy',
    'Deleted backup'        => 'orange',
    'Cleared activity logs' => 'orange',
    'Updated settings'      => 'navy',
];
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<style>
  @media print {
    /* Hide everything not part of the report */
    header, .sidebar, .no-print, .page-head .page-sub,
    .stats, .filter-row, .flash,
    button, form, .notif-wrap { display: none !important; }

    /* Reveal the print-only report header */
    .print-header { display: block !important; }

    /* Layout */
    html, body, .app, .main {
      background: white !important; color: black !important;
      margin: 0; padding: 0;
      font-family: Arial, 'Helvetica Neue', sans-serif;
    }
    .main { padding: 12mm 10mm !important; max-width: 100% !important; }
    .page-head { border: none !important; box-shadow: none !important; padding: 0 !important; display: none !important; }

    /* Card wrapping the table */
    .card {
      background: white !important; border: 1px solid #000 !important;
      border-radius: 0 !important; box-shadow: none !important;
      padding: 0 !important; overflow: visible !important;
    }

    /* The log table */
    table { width: 100% !important; border-collapse: collapse !important; font-size: 10px !important; }
    thead tr { background: var(--navy-50) !important; }
    thead th {
      background: var(--navy-50) !important;
      color: var(--ink) !important; font-size: 9.5px !important;
      padding: 6px 8px !important; border-bottom: 1.5px solid #000 !important;
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    tbody td {
      color: var(--ink) !important; padding: 5px 8px !important;
      border-bottom: 1px solid var(--border) !important;
      font-size: 9.5px !important;
      vertical-align: top !important;
    }
    tbody td * { color: var(--ink) !important; }
    /* Tag pills flatten to plain text so they print */
    span[style*="background"] {
      background: none !important;
      color: var(--ink) !important;
      padding: 0 !important;
      font-weight: 700 !important;
    }

    /* Print pagination hints */
    @page { size: A4 portrait; margin: 15mm 12mm; }
    tr { page-break-inside: avoid; }
    thead { display: table-header-group; }
    .print-header { page-break-after: avoid; }
  }
  /* Suppress the print header entirely on screen */
  .print-header { display: none; }
</style>
<div class="app">
  <?php require_once __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Activity logs</h1>
        <p class="page-sub">Audit trail of every user action across ProcureEase.</p>
      </div>
      <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;" class="no-print">
        <span style="font-family: 'Space Grotesk', sans-serif; font-size: 14px; font-weight: 700; color: var(--ink); padding: 6px 14px; background: var(--white); border-radius: 999px; border: 1.5px solid var(--navy-500); display: flex; align-items: center; gap: 8px;" title="Current server time — updates every second">
          🕐 <span id="liveClock"><?= e(date('g:i:s a')) ?></span>
        </span>
        <span style="font-size: 11.5px; color: var(--muted); display: flex; align-items: center; gap: 6px; padding: 4px 12px; background: var(--navy-50); border-radius: 999px; border: 1px solid var(--border);" title="Page auto-refreshes every 60 seconds so new activity appears">
          <span style="width: 8px; height: 8px; background: #15803d; border-radius: 50%; animation: pulse 2s ease-in-out infinite;"></span>
          <span>Live · refreshes in <span id="refreshCountdown">60</span>s</span>
        </span>
        <button type="button" onclick="window.print()" class="btn btn-ghost" style="color: var(--ink);">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Print logs
        </button>
        <a href="activity-logs.php" class="btn btn-ghost">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Reset filters
        </a>
        <form method="POST" style="margin: 0;"
              data-confirm="Activity older than 30 days will be permanently deleted. This cannot be undone." data-confirm-title="Clear old logs?" data-confirm-label="Clear logs" data-confirm-tone="danger">
          <input type="hidden" name="action" value="clear_old">
          <input type="hidden" name="days" value="30">
          <button type="submit" class="btn btn-ghost" style="color: var(--orange-600);">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
            Clear old logs
          </button>
        </form>
      </div>
    </div>

    <!-- Print-only report header (visible only when printing) -->
    <div class="print-header" style="display: none;">
      <div style="text-align: center; padding-bottom: 12px; border-bottom: 2px solid #000; margin-bottom: 16px;">
        <div style="display: flex; justify-content: center; align-items: center; gap: 14px; margin-bottom: 8px;">
          <img src="assets/ascot-logo.png" width="64" height="64" alt="ASCOT" style="object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
          <div>
            <div style="font-size: 11px;">Republic of the Philippines</div>
            <div style="font-size: 15px; font-weight: bold;">AURORA STATE COLLEGE OF TECHNOLOGY</div>
            <div style="font-size: 10px; font-style: italic;">Brgy. Zabali, 3200 Baler, Aurora, Philippines</div>
          </div>
          <img src="assets/procurement-logo.png" width="64" height="64" alt="Procurement" style="object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
        </div>
        <div style="font-size: 14px; font-weight: bold; margin-top: 8px;">
          PROCUREEASE ACTIVITY LOG REPORT
        </div>
      </div>
      <?php
        $activeFilters = [];
        if ($search)        $activeFilters[] = "Search: <strong>" . e($search) . "</strong>";
        if ($user_filter)   $activeFilters[] = "User: <strong>" . e($user_filter) . "</strong>";
        if ($action_filter) $activeFilters[] = "Action: <strong>" . e($action_filter) . "</strong>";
      ?>
      <div style="font-size: 11px; margin-bottom: 8px;">
        <strong>Printed:</strong> <?= e(date('F j, Y \a\t g:i a')) ?> &nbsp; · &nbsp;
        <strong>Printed by:</strong> <?= e($u['full_name']) ?> &nbsp; · &nbsp;
        <strong>Events on this page:</strong> <?= count($logs) ?> &nbsp; · &nbsp;
        <strong>Page:</strong> <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalLogs) ?> total)
      </div>
      <?php if ($activeFilters): ?>
        <div style="font-size: 11px; padding: 6px 10px; background: var(--navy-50); border-left: 3px solid #121358; margin-bottom: 8px;">
          <strong>Active filters:</strong> <?= implode(' &nbsp;·&nbsp; ', $activeFilters) ?>
        </div>
      <?php else: ?>
        <div style="font-size: 11px; padding: 6px 10px; background: var(--navy-50); border-left: 3px solid #121358; margin-bottom: 8px;">
          <strong>Filters:</strong> <em>None (showing all events)</em>
        </div>
      <?php endif; ?>
    </div>
    <?php render_flashes(); ?>

    <div class="stats">
      <div class="stat">
        <div class="stat-label">Events today</div>
        <div class="stat-value"><?= $today ?></div>
        <div class="stat-meta">Actions in last 24h</div>
      </div>
      <div class="stat">
        <div class="stat-label">Logins today</div>
        <div class="stat-value"><?= $logins ?></div>
        <div class="stat-meta">Sign-in events</div>
      </div>
      <div class="stat">
        <div class="stat-label">Past 7 days</div>
        <div class="stat-value"><?= $week ?></div>
        <div class="stat-meta">Rolling weekly total</div>
      </div>
      <div class="stat">
        <div class="stat-label">All-time total</div>
        <div class="stat-value"><?= $total ?></div>
        <div class="stat-meta">Recorded events</div>
      </div>
    </div>

    <form method="GET" action="activity-logs.php" class="filter-row" role="search" style="align-items: center; margin-bottom: 20px;">
      <?= search_field(['name' => 'q', 'value' => $search, 'placeholder' => 'Search details, department, or name…',
                        'label' => 'Search the activity log', 'attrs' => SEARCH_NO_DIGITS]) ?>
      <select name="user" class="filter-select" onchange="this.form.submit()">
        <option value="">All users</option>
        <?php foreach ($users as $usr): ?>
          <option value="<?= e($usr['username']) ?>" <?= $user_filter === $usr['username'] ? 'selected' : '' ?>>
            <?= e($usr['full_name'] ?: $usr['username']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="action" class="filter-select" onchange="this.form.submit()">
        <option value="">All actions</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= e($a) ?>" <?= $action_filter === $a ? 'selected' : '' ?>><?= e($a) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-ghost" style="padding: 8px 14px; font-size: 13px;">Apply</button>
    </form>

    <div class="card" style="padding: 0; overflow: hidden;">
      <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
        <thead>
          <tr style="background: var(--navy-50); border-bottom: 1px solid var(--border);">
            <th style="text-align: left; padding: 12px 16px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">When</th>
            <th style="text-align: left; padding: 12px 16px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">User</th>
            <th style="text-align: left; padding: 12px 16px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Action</th>
            <th style="text-align: left; padding: 12px 16px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Target</th>
            <th style="text-align: left; padding: 12px 16px; font-weight: 700; color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em;">Details</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$logs): ?>
            <tr><td colspan="5" style="padding: 40px; text-align: center; color: var(--muted); font-style: italic;">No matching activity.</td></tr>
          <?php else: foreach ($logs as $log):
              $color = $actionColor[$log['action']] ?? 'muted';
              $tagBg = ['navy'=>'var(--navy-100)', 'green'=>'var(--green-50)', 'orange'=>'var(--orange-100)', 'muted'=>'var(--border)'][$color];
              $tagFg = ['navy'=>'var(--navy-700)', 'green'=>'var(--green-500)', 'orange'=>'var(--orange-600)', 'muted'=>'var(--muted)'][$color];
          ?>
            <tr style="border-bottom: 1px solid var(--border);">
              <td style="padding: 14px 16px; color: var(--muted); white-space: nowrap;">
                <?php
                  $ts = strtotime($log['created_at']);
                  $today = date('Y-m-d', $ts) === date('Y-m-d');
                  $yesterday = date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'));
                ?>
                <div style="font-weight: 700; color: var(--ink); font-size: 14px; font-family: 'Space Grotesk', sans-serif;">
                  <?= e(date('g:i a', $ts)) ?>
                </div>
                <div style="font-size: 11.5px; color: var(--muted); margin-top: 1px;">
                  <?php if ($today): ?>Today<?php elseif ($yesterday): ?>Yesterday<?php else: ?><?= e(date('M j, Y', $ts)) ?><?php endif; ?>
                </div>
                <div style="font-size: 10.5px; color: var(--muted); margin-top: 3px; font-style: italic;">
                  <?= reltime_span($log['created_at']) ?>
                </div>
              </td>
              <td style="padding: 14px 16px;">
                <div style="font-weight: 600;"><?= e($log['full_name'] ?: $log['username']) ?></div>
                <div style="font-size: 11px; color: var(--muted);"><?= e(role_label($log['role'] ?? 'user')) ?> · <?= e($log['username']) ?></div>
              </td>
              <td style="padding: 14px 16px;">
                <span style="display: inline-block; background: <?= $tagBg ?>; color: <?= $tagFg ?>; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.06em;">
                  <?= e($log['action']) ?>
                </span>
              </td>
              <td style="padding: 14px 16px; color: var(--ink); font-weight: 500;"><?= e($log['entity_ref'] ?: '—') ?></td>
              <td style="padding: 14px 16px; color: var(--muted); max-width: 300px;"><?= e($log['details']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php render_log_pager($page, $totalPages, $totalLogs, $qs, $pgStart, $pgEnd, 'center'); ?>
  </main>
</div>
<style>
  .log-pager { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: center; margin-top: 18px; }
  .log-pager .pg { min-width: 34px; height: 34px; padding: 0 10px; display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid var(--border); border-radius: 8px; background: var(--white); color: var(--ink);
    font-size: 13px; font-weight: 600; text-decoration: none; transition: background .12s, color .12s, border-color .12s; }
  .log-pager .pg:hover { background: var(--navy-50); border-color: var(--navy-500); color: var(--navy-800); }
  .log-pager .pg.active { background: var(--navy-800); color: #fff; border-color: var(--navy-800); }
  .log-pager .pg.disabled { opacity: .45; pointer-events: none; }
  .log-pager .pg-gap { color: var(--muted); padding: 0 4px; }
  .log-pager .pg-info { color: var(--muted); font-size: 12px; margin-left: 8px; }
</style>
<style>
  @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }
</style>
<script>
  (function() {
    // Live wall-clock — updates every second
    function fmtClock(d) {
      let h = d.getHours(), m = d.getMinutes(), s = d.getSeconds();
      const ampm = h >= 12 ? 'pm' : 'am';
      h = h % 12 || 12;
      const pad = n => String(n).padStart(2, '0');
      return h + ':' + pad(m) + ':' + pad(s) + ' ' + ampm;
    }
    const clockEl = document.getElementById('liveClock');
    function tickClock() { if (clockEl) clockEl.textContent = fmtClock(new Date()); }
    tickClock();
    setInterval(tickClock, 1000);

    // Auto-refresh countdown (unchanged)
    const INTERVAL = 60;
    let secs = INTERVAL;
    const el = document.getElementById('refreshCountdown');
    setInterval(function tick() {
      secs -= 1;
      if (el) el.textContent = Math.max(0, secs);
      if (secs <= 0) {
        const active = document.activeElement;
        const typing = active && ['INPUT','TEXTAREA','SELECT'].includes(active.tagName);
        if (typing) { secs = INTERVAL; return; }
        window.location.reload();
      }
    }, 1000);
    window.addEventListener('beforeprint', function() { secs = INTERVAL + 999; });
  })();
</script>
</body>
</html>
