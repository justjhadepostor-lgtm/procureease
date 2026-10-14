<?php
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_admin();
ensure_admin_tables();

$u = current_user();
$activeNav = 'deadlines';
$pageTitle = 'Submission deadlines';
$pdo = db();

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'set') {
        $fy       = (int)($_POST['fy'] ?? 0);
        $deadline = trim($_POST['deadline_date'] ?? '');
        $note     = trim($_POST['note'] ?? '');
        $announce = !empty($_POST['also_announce']);
        // Reminder interval — admin picks 1, 2, 3, 5, or 7 days
        $interval = (int)($_POST['reminder_interval_days'] ?? 2);
        if (!in_array($interval, [1, 2, 3, 5, 7], true)) $interval = 2;

        if ($fy <= 0 || !$deadline) {
            flash('error', 'Fiscal year and deadline date are required.');
        } else {
            $pdo->prepare('INSERT INTO submission_deadlines (fy, deadline_date, note, set_by, reminder_interval_days)
                           VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE
                             deadline_date = VALUES(deadline_date),
                             note = VALUES(note),
                             set_by = VALUES(set_by),
                             reminder_interval_days = VALUES(reminder_interval_days)')
                ->execute([$fy, $deadline, $note, $u['full_name'], $interval]);

            log_activity('Set submission deadline', 'deadline', "FY $fy",
                "Deadline: " . date('F j, Y', strtotime($deadline)) . " · Reminders every $interval day" . ($interval === 1 ? '' : 's') . ($note ? " · Note: $note" : ''));

            $title = "PPMP Submission Deadline for FY $fy";
            $body  = "The Procurement Unit has set the submission deadline for FY $fy PPMPs to "
                   . date('F j, Y', strtotime($deadline)) . ". "
                   . "Please prepare and submit your PPMPs on or before this date."
                   . ($note ? "\n\nNote: $note" : '');

            if ($announce) {
                // Post a matching announcement (optional)
                $pdo->prepare('INSERT INTO announcements (title, body, posted_by, audience, is_active)
                               VALUES (?, ?, ?, ?, 1)')
                    ->execute([$title, $body, $u['full_name'], 'all']);
            }

            // Everyone who has to submit is told about the deadline, by bell and by email
            $told  = notify_by_role('user',   'announcement', $title, $body, 'user-announcements.php');
            $told += notify_by_role('budget', 'announcement', $title, $body, 'user-announcements.php');

            flash('success', "Deadline for FY $fy saved. $told " . ($told == 1 ? 'person was' : 'people were')
                           . " notified by email" . ($announce ? ", and the announcement was posted." : "."));
        }
    } elseif ($action === 'delete') {
        $fy = (int)($_POST['fy'] ?? 0);
        if ($fy > 0) {
            $pdo->prepare('DELETE FROM submission_deadlines WHERE fy = ?')->execute([$fy]);
            log_activity('Removed submission deadline', 'deadline', "FY $fy");
            flash('success', "Deadline for FY $fy removed.");
        }
    }
    header('Location: deadlines.php');
    exit;
}

// Run reminder check whenever the admin visits this page
$sent = check_and_send_reminders();
if ($sent > 0) flash('success', "Sent $sent overdue reminder" . ($sent == 1 ? '' : 's') . " to late departments.");

// Load all deadlines with status + compliance info
$rows = $pdo->query("
    SELECT d.*,
           (SELECT COUNT(*) FROM departments WHERE code <> 'BUDGET-OFC') AS total_depts,
           (SELECT COUNT(DISTINCT p.dept_code) FROM ppmps p
             WHERE p.fy = d.fy AND p.status IN ('submitted','budget_approved','approved')) AS submitted_depts
      FROM submission_deadlines d
  ORDER BY d.fy DESC")->fetchAll();
// v81: a school with programs and majors is counted as its programs and majors, not as itself
require_once __DIR__ . '/config/academic-units.php';
$__multi = 0;
foreach (au_all() as $__c => $__r) if (strcasecmp((string)$__c, 'BUDGET-OFC') !== 0 && au_has_units((string)$__c)) $__multi++;
// v92: nor is a deactivated department counted
foreach (au_all() as $__c => $__r) if (strcasecmp((string)$__c, 'BUDGET-OFC') !== 0 && !au_is_active((string)$__c) && !au_has_units((string)$__c)) $__multi++;
if ($__multi) foreach ($rows as &$__row) $__row['total_depts'] = (int)$__row['total_depts'] - $__multi; unset($__row);

$today = date('Y-m-d');
$nextFY = active_fy();
?>
<?php require_once __DIR__ . '/includes/head.php'; ?>
<div class="app">
  <?php require_once __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Submission deadlines · FY <?= active_fy() ?></h1>
        <p class="page-sub">Submission deadlines and reminders.</p></div></div>
    <?php render_flashes(); ?>

    <!-- Set/update deadline form -->
    <div class="card" style="margin-bottom: 24px;">
      <div style="font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; color: var(--navy-800); margin-bottom: 4px;"> Set a new deadline
      </div>
      <div style="font-size: 12.5px; color: var(--muted); margin-bottom: 16px;"> One deadline per fiscal year. Re-submitting for the same FY updates the existing one.
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="set">
        <div class="deadline-row" style="display: grid; grid-template-columns: 130px 180px 190px auto; justify-content: start; gap: 12px; align-items: end;">
          <div>
            <label class="form-label" for="fy">Fiscal year</label>
            <input type="number" id="fy" name="fy" class="form-input" value="<?= $nextFY ?>" min="2020" max="2100" required></div>
          <div>
            <label class="form-label" for="deadline_date">Deadline date</label>
            <input type="date" id="deadline_date" name="deadline_date" class="form-input" min="<?= $today ?>" required></div>
          <div>
            <label class="form-label" for="reminder_interval_days">Remind late depts</label>
            <select id="reminder_interval_days" name="reminder_interval_days" class="form-input" style="height: 42px;">
              <option value="1">Every 1 day</option>
              <option value="2" selected>Every 2 days</option>
              <option value="3">Every 3 days</option>
              <option value="5">Every 5 days</option>
              <option value="7">Every 7 days</option></select></div>
          <button type="submit" class="btn btn-primary" style="height: 42px; padding: 0 20px;">Save deadline</button></div>
        <label style="display: flex; align-items: center; gap: 8px; margin-top: 14px; font-size: 13px; color: var(--ink); cursor: pointer;">
          <input type="checkbox" name="also_announce" value="1" checked style="width: 16px; height: 16px;"> Also post an announcement to all end-users
        </label></form></div>

    <!-- Existing deadlines -->
    <div class="section-head">
      <div>
        <h2 class="section-title">Active deadlines</h2>
        <p class="section-sub">Each row shows compliance (how many depts submitted vs total) and the current status.</p></div></div>

    <?php if (!$rows): ?>
      <div class="card" style="text-align: center; padding: 60px 24px; color: var(--muted);">
        <div style="font-size: 40px; margin-bottom: 10px;"></div>
        <div style="font-size: 15px; font-weight: 600; color: var(--ink); margin-bottom: 4px;">No deadlines set yet</div>
        <div style="font-size: 13px;">Use the form above to set your first submission deadline.</div></div>
    <?php else: foreach ($rows as $d):
      $isPast  = $d['deadline_date'] < $today;
      $isToday = $d['deadline_date'] === $today;
      $daysLeft = floor((strtotime($d['deadline_date']) - strtotime($today)) / 86400);
      $submitted = (int)$d['submitted_depts'];
      $total     = (int)$d['total_depts'];
      $late      = max(0, $total - $submitted);
      $pct       = $total > 0 ? ($submitted / $total * 100) : 0;

      if ($isPast) {
          $stateLabel = 'Past deadline'; $stateFg = 'var(--orange-600)'; $stateBg = 'var(--orange-50)'; $stateBorder = 'var(--orange-100)';
      } elseif ($isToday) {
          $stateLabel = 'Due today';    $stateFg = 'var(--orange-600)'; $stateBg = 'var(--orange-50)'; $stateBorder = 'var(--orange-100)';
      } else {
          $stateLabel = "$daysLeft " . ($daysLeft == 1 ? 'day' : 'days') . ' left'; $stateFg = '#14532d'; $stateBg = 'var(--green-50)'; $stateBorder = '#86efac';
      }
    ?>
      <div class="card" style="margin-bottom: 14px; padding: 20px 24px; border-left: 4px solid <?= $stateBorder ?>;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 12px;">
          <div>
            <div style="font-family: 'Space Grotesk', sans-serif; font-size: 17px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 10px; flex-wrap: wrap;"> FY <?= (int)$d['fy'] ?>
              <span style="background: <?= $stateBg ?>; color: <?= $stateFg ?>; padding: 3px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; border: 1.5px solid <?= $stateBorder ?>;">
                <?= e($stateLabel) ?></span></div>
            <div style="font-size: 13px; color: var(--muted); margin-top: 4px;"> Deadline: <strong style="color: var(--ink);"><?= e(date('l, F j, Y', strtotime($d['deadline_date']))) ?></strong>
              &nbsp;·&nbsp; Set by <?= e($d['set_by']) ?> · <?= reltime_span($d['updated_at']) ?></div>
            <?php
              $ri = (int)($d['reminder_interval_days'] ?? 2);
              if ($ri < 1) $ri = 2;
            ?>
            <div style="margin-top: 8px; display: inline-flex; align-items: center; gap: 6px; background: var(--navy-50); border: 1px solid var(--navy-100); padding: 4px 10px; border-radius: 999px; font-size: 11.5px; color: var(--ink);">
               <strong>Reminders every <?= $ri ?> day<?= $ri === 1 ? '' : 's' ?></strong></div>
            <?php if (!empty($d['note'])): ?>
              <div style="font-size: 12.5px; color: var(--muted); margin-top: 8px; font-style: italic;">
                <strong style="font-style: normal; color: var(--ink);">Note:</strong> <?= e($d['note']) ?></div>
            <?php endif; ?></div>
          <form method="POST" data-confirm="The deadline for FY <?= (int)$d['fy'] ?> will be removed and reminders will stop." data-confirm-title="Remove deadline?" data-confirm-label="Remove" data-confirm-tone="danger">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="fy" value="<?= (int)$d['fy'] ?>">
            <button type="submit" class="btn btn-ghost" style="padding: 6px 12px; font-size: 12px; color: var(--orange-600);">Remove</button></form></div>

        <!-- Compliance -->
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 5px;">
          <span style="font-family: 'Space Grotesk', sans-serif; font-size: 11px; font-weight: 700; color: var(--ink); letter-spacing: 0.05em; text-transform: uppercase;"> Compliance
          </span>
          <span style="font-family: 'Space Grotesk', sans-serif; font-size: 12.5px; font-weight: 700; color: <?= $submitted === $total ? '#14532d' : 'var(--navy-700)' ?>;">
            <?= $submitted ?> of <?= $total ?> departments submitted
            <?php if ($late > 0 && $isPast): ?>
              &nbsp;·&nbsp; <span style="color: var(--orange-600);"><?= $late ?> late</span>
            <?php endif; ?></span></div>
        <div style="height: 8px; background: var(--navy-50); border-radius: 4px; overflow: hidden;">
          <div style="height: 100%; width: <?= $pct ?>%; background: <?= $submitted === $total ? '#15803d' : ($isPast ? 'var(--orange-500)' : 'var(--navy-500)') ?>; border-radius: 4px; transition: width 0.4s ease;"></div></div></div>
    <?php endforeach; endif; ?></main></div></body></html>
