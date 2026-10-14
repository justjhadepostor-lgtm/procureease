<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/admin-schema.php';
require_login();
ensure_admin_tables();
$u = current_user();
$activeNav = 'report';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject     = trim($_POST['subject'] ?? '');
    $category    = in_array($_POST['category'] ?? '', ['bug','ui','account','ppmp','other'], true) ? $_POST['category'] : 'other';
    $description = trim($_POST['description'] ?? '');

    if ($subject === '' || $description === '') {
        flash('error', 'Please fill in both subject and description.');
    } else {
        try {
            $ins = $pdo->prepare('INSERT INTO user_reports (user_id, username, full_name, dept_code, category, subject, description, status)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, "open")');
            $ins->execute([
                $u['id'], $u['username'], $u['full_name'], ($u['school_code'] ?? $u['dept_code']),
                $category, $subject, $description,
            ]);
            log_activity('Reported a problem', 'report', $subject, "[$category] $subject");

            // Notify all admins
            notify_by_role('admin', 'report',
                "New issue reported by {$u['full_name']}",
                "[$category] $subject — " . mb_substr($description, 0, 140) . (mb_strlen($description) > 140 ? '…' : ''),
                'admin-reports.php');

            flash('success', 'Thanks — your report has been sent to the administrator.');
        } catch (Throwable $t) {
            error_log('report submit failed: ' . $t->getMessage());
            flash('error', 'Could not submit your report. Please try again.');
        }
    }
    header('Location: report-issue.php');
    exit;
}

// My past reports
$myReports = [];
try {
    $s = $pdo->prepare('SELECT * FROM user_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $s->execute([(int)$u['id']]);
    $myReports = $s->fetchAll();
} catch (Throwable $t) {}
$pageTitle = 'Report a problem · ProcureEase';
require __DIR__ . '/includes/head.php';
?>
<div class="app">
  <?php if ($u['role'] === 'budget') require __DIR__ . '/includes/sidebar-budget.php';
        else                         require __DIR__ . '/includes/sidebar-user.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Report a problem</h1>
        <p class="page-sub">Found a bug or something not working right? Let the administrator know.</p>
      </div>
    </div>
    <?php render_flashes(); ?>

    <div class="card">
      <div class="card-title">Send a report</div>
      <div class="card-sub">Describe what happened. The Procurement Unit will be notified.</div>

      <form method="POST"
            data-confirm="This report will be sent to the Procurement Unit."
            data-confirm-title="Send this report?" data-confirm-label="Send report" data-confirm-tone="info">
        <div style="margin-bottom: 14px;">
          <label class="form-label" for="category">Category</label>
          <select id="category" name="category" class="form-select" required>
            <option value="bug">🐞 Bug — something broke or gave an error</option>
            <option value="ui">🎨 UI — something looks wrong or is confusing</option>
            <option value="account">👤 Account — sign-in or profile problem</option>
            <option value="ppmp">📄 PPMP — issue with a PPMP or its workflow</option>
            <option value="other">💬 Other</option>
          </select>
        </div>
        <div style="margin-bottom: 14px;">
          <label class="form-label" for="subject">Subject</label>
          <input type="text" id="subject" name="subject" class="form-input" placeholder="Short summary" required maxlength="200">
        </div>
        <div style="margin-bottom: 16px;">
          <label class="form-label" for="description">What happened?</label>
          <textarea id="description" name="description" class="form-textarea" rows="5"
                    placeholder="Steps to reproduce, what you expected, what actually happened…" required></textarea>
        </div>
        <div style="text-align: center; margin-top: 8px;">
          <button type="submit" class="btn btn-primary">Send report</button>
        </div>
      </form>
    </div>

    <?php if ($myReports): ?>
      <div class="section-head" style="margin-top: 32px;">
        <div>
          <h2 class="section-title">My past reports</h2>
          <p class="section-sub">Reports you've submitted, with their current status.</p>
        </div>
      </div>
      <div class="card" style="padding: 0; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
          <thead>
            <tr style="background: var(--navy-50); border-bottom: 1.5px solid var(--border);">
              <th style="text-align: left; padding: 11px 16px; font-size: 10.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em;">Subject</th>
              <th style="text-align: left; padding: 11px 14px; font-size: 10.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em;">Category</th>
              <th style="text-align: left; padding: 11px 14px; font-size: 10.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em;">Status</th>
              <th style="text-align: left; padding: 11px 16px; font-size: 10.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em;">Sent</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($myReports as $r):
              $st = $r['status'] ?? 'open';
              if ($st === 'resolved') { $sBg = 'var(--green-50)'; $sFg = '#14532d'; $sBd = '#86efac'; $sLabel = '✓ Resolved'; }
              elseif ($st === 'closed') { $sBg = 'var(--navy-50)'; $sFg = 'var(--muted)'; $sBd = 'var(--border)'; $sLabel = 'Closed'; }
              else { $sBg = 'var(--orange-50)'; $sFg = 'var(--orange-600)'; $sBd = 'var(--orange-100)'; $sLabel = '⏳ Open'; }
            ?>
              <tr style="border-bottom: 1px solid var(--border);">
                <td style="padding: 12px 16px; color: var(--ink); font-weight: 600;"><?= e($r['subject']) ?></td>
                <td style="padding: 12px 14px; color: var(--muted);"><?= e($r['category']) ?></td>
                <td style="padding: 12px 14px;">
                  <span style="background: <?= $sBg ?>; color: <?= $sFg ?>; border: 1.5px solid <?= $sBd ?>; padding: 3px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;"><?= $sLabel ?></span>
                </td>
                <td style="padding: 12px 16px; color: var(--muted); font-size: 12px;"><?= reltime_span($r['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
