<?php
/**
 * Notification helpers — insert an in-app notification and also
 * send it as an email through the configured Gmail account.
 * The DB row is the guaranteed delivery; the email is best-effort.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/smtp.php';

/**
 * Send a notification to one user: rows into notifications table + email attempt.
 */
function notify_user(int $user_id, string $type, string $title, string $body = '', string $link = ''): bool {
    // v104: returns whether the email went (the bell row is made either way)
    try {
        db()->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?,?,?,?,?)')
            ->execute([$user_id, $type, $title, $body, $link]);
    } catch (Throwable $t) { return false; }

    // Look up email and attempt to send
    $stmt = db()->prepare('SELECT email, full_name, role FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();
    if (!$u || empty($u['email'])) return false;
    // Settings > Notifications: whether the Procurement Unit is emailed about
    // submissions and about revisions sent back. The bell always shows them.
    if (function_exists('notify_email_allowed') && !notify_email_allowed($type, (string)($u['role'] ?? ''))) return false;
    $GLOBALS['__pe_mail_tally']['tried'] = ($GLOBALS['__pe_mail_tally']['tried'] ?? 0) + 1;   // v106: notify_mail_note()

    $labelMap = [
        'announcement' => '📢 New announcement',
        'approval'     => '✓ PPMP approved',
        'revision'     => '⚠ Revision requested',
        'submission'   => '📥 New submission',
        'resubmission' => '🔁 Resubmitted after revision',
        'final'        => '✓ Final approval granted',
        'deadline'     => '🗓 Submission deadline',
        'security'     => '🔒 Sign-in security',
        'account'      => '👤 Your account',
    ];
    $prefix = $labelMap[$type] ?? '🔔 Notification';
    $subject = "[ProcureEase] $prefix — $title";

    $emailBody  = "Hi " . ($u['full_name'] ?: 'there') . ",\n\n";
    $emailBody .= "$title\n\n";
    if ($body !== '') $emailBody .= "$body\n\n";
    if ($link !== '') $emailBody .= "Open in ProcureEase: " . app_base_url() . "/$link\n\n";
    $emailBody .= "— ProcureEase\nAurora State College of Technology";

    try {
        $sent = (bool)smtp_send($u['email'], $subject, $emailBody);
        if ($sent) $GLOBALS['__pe_mail_tally']['sent'] = ($GLOBALS['__pe_mail_tally']['sent'] ?? 0) + 1;
        return $sent;
    } catch (Throwable $t) { return false; /* the in-app notification still stands */ }
}

/** v106: start counting the emails of what is about to be sent (notify_mail_note() says how they went). */
function notify_mail_reset(): void { $GLOBALS['__pe_mail_tally'] = ['tried' => 0, 'sent' => 0]; }

/**
 * v106: how the emails since notify_mail_reset() went, for the message the sender sees:
 * all sent, some, none (Mail settings), or no one with an email address (the bell only).
 */
function notify_mail_note(): string {
    $t = $GLOBALS['__pe_mail_tally'] ?? ['tried' => 0, 'sent' => 0];
    $tried = (int)($t['tried'] ?? 0); $sent = (int)($t['sent'] ?? 0);
    if ($tried === 0) return ' No one there has an email address on their account, so only the bell notice was sent.';
    if ($sent === $tried) return ' An email was sent to ' . $sent . ($sent === 1 ? ' person' : ' people') . ' too.';
    if ($sent === 0) return ' The email could not be sent — check Mail settings; the bell notice was sent.';
    return " An email was sent to $sent of $tried people; the rest could not be sent — check Mail settings. The bell notice was sent to all.";
}

/** Notify every user in a specific role (returns count sent). */
function notify_by_role(string $role, string $type, string $title, string $body = '', string $link = ''): int {
    $stmt = db()->prepare('SELECT id FROM users WHERE role = ?');
    $stmt->execute([$role]);
    $count = 0;
    foreach ($stmt->fetchAll() as $u) {
        notify_user((int)$u['id'], $type, $title, $body, $link);
        $count++;
    }
    return $count;
}

/** Notify every user in a specific department. */
function notify_by_dept(string $dept_code, string $type, string $title, string $body = '', string $link = ''): int {
    // v81: a program / major (config/academic-units.php) — the accounts of its school that
    // work on it: those of its program, and those of the whole school
    require_once __DIR__ . '/academic-units.php';
    if (au_is_unit($dept_code)) {
        $r = au_row($dept_code);
        $stmt = db()->prepare("SELECT id FROM users WHERE dept_code = ? AND (sub_unit IS NULL OR sub_unit = '' OR sub_unit = ?)");
        $stmt->execute([(string)$r['parent_code'], (string)$r['program_code']]);
    } elseif (os_is_set($dept_code)) {
        // v83: an office's 2nd / 3rd set — the office's accounts
        $stmt = db()->prepare('SELECT id FROM users WHERE dept_code = ?');
        $stmt->execute([au_school_of($dept_code)]);
    } else {
    $stmt = db()->prepare('SELECT id FROM users WHERE dept_code = ?');
    $stmt->execute([$dept_code]);
    }
    $count = 0;
    foreach ($stmt->fetchAll() as $u) {
        notify_user((int)$u['id'], $type, $title, $body, $link);
        $count++;
    }
    return $count;
}

/** Get recent notifications for a user (default 10 most recent). */
function get_notifications(int $user_id, int $limit = 10): array {
    try {
        $sql = 'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . (int)$limit;
        $stmt = db()->prepare($sql);
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Throwable $t) { return []; }
}

/** Get count of unread notifications for a user. */
function unread_notif_count(int $user_id): int {
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $t) { return 0; }
}

/** Return a short relative-time string (e.g. "5m ago", "2h ago"). */
function notif_reltime(string $timestamp): string {
    $secs = time() - strtotime($timestamp);
    if ($secs < 60)     return 'just now';
    if ($secs < 3600)   return floor($secs / 60)    . 'm ago';
    if ($secs < 86400)  return floor($secs / 3600)  . 'h ago';
    if ($secs < 604800) return floor($secs / 86400) . 'd ago';
    return date('M j', strtotime($timestamp));
}

/**
 * Output a self-updating relative-time span. The JS runner in head.php
 * refreshes every 30s so times stay live without a page reload.
 */
function reltime_span(string $timestamp, string $extraClass = ''): string {
    $iso = date('c', strtotime($timestamp)); // ISO 8601 with tz
    $initial = notif_reltime($timestamp);
    $abs = date('M j, Y g:i a', strtotime($timestamp));
    $cls = 'reltime' . ($extraClass ? ' ' . $extraClass : '');
    return '<span class="' . $cls . '" data-time="' . e($iso) . '" title="' . e($abs) . '">' . e($initial) . '</span>'
         . '<span class="reltime-abs">' . e($abs) . '</span>';
}

/**
 * Check submission deadlines and send reminders to anyone who missed them.
 * A user is "late" if there's an active submission_deadlines row for a FY
 * and they have no submitted PPMP for that FY. Reminders go out at most
 * once every 2 days per user per FY.
 *
 * Idempotent and cheap: safe to call on every admin/budget page load.
 * Returns the number of reminders sent this call.
 */
function check_and_send_reminders(): int {
    try {
        $pdo = db();
        $today = date('Y-m-d');
        $sent = 0;

        // Every deadline for a fiscal year that is still open — reminders run
        // both in the lead-up to the cutoff and after it has passed.
        // v104: the deadlines of the year in effect (and any later one) — an ended year is not chased any more
        $stmt = $pdo->prepare('SELECT fy, deadline_date, reminder_interval_days FROM submission_deadlines WHERE fy >= ?');
        $stmt->execute([function_exists('active_fy') ? active_fy() : 0]);
        $deadlines = $stmt->fetchAll();

        foreach ($deadlines as $d) {
            $fy = (int)$d['fy'];
            $deadlineStr = date('F j, Y', strtotime($d['deadline_date']));
            $daysToGo = (int)floor((strtotime($d['deadline_date']) - strtotime($today)) / 86400);
            $isLate   = $daysToGo < 0;
            $daysLate = $isLate ? abs($daysToGo) : 0;

            // Admin-configured interval; safe default if column missing
            $interval = (int)($d['reminder_interval_days'] ?? 2);
            if ($interval < 1) $interval = 2;

            // Nothing to chase more than 30 days ahead of the cutoff
            if (!$isLate && $daysToGo > 30) continue;

            // Every active user in a real department (skip admin/budget unit end-user duties for now — reminders only go to depts that need to submit)
            $users = $pdo->query("
                SELECT u.id, u.username, u.full_name, u.email, u.dept_code, u.sub_unit, u.role, d.name AS dept_name
                  FROM users u
             LEFT JOIN departments d ON d.code = u.dept_code
                 WHERE u.status = 'active'
                   AND u.role IN ('user','dept_admin','budget')   -- v104: the Department Admin (who submits) is reminded too
                   AND u.dept_code IS NOT NULL")->fetchAll();

            foreach ($users as $u) {
                // Skip if this dept already submitted its PPMP for this FY — v104: once submitted, no
                // more reminders for that year, even while it is sent back for changes (only pulling
                // it back with Unsubmit makes it a plain draft again)
                $q = $pdo->prepare("SELECT COUNT(*) FROM ppmps
                                     WHERE dept_code = ? AND fy = ?
                                       AND (status IN ('submitted','budget_approved','approved') OR submitted_at IS NOT NULL)");
                // v81: an account of a school with programs: done when every unit it works on has submitted
                require_once __DIR__ . '/academic-units.php';
                // v83: …and an account of an office with sets: when each of its sets has
                $__scope = (au_has_units((string)$u['dept_code']) || os_has_sets((string)$u['dept_code'])) ? au_scope($u) : [(string)$u['dept_code']];
                $__left = 0;
                foreach ($__scope as $__c) { $q->execute([$__c, $fy]); if ((int)$q->fetchColumn() === 0) $__left++; }
                if ($__left === 0) continue;

                // Check last reminder — only remind if the admin-configured interval has passed
                $r = $pdo->prepare('SELECT last_reminded_at, reminder_count FROM submission_reminders WHERE user_id = ? AND fy = ?');
                $r->execute([$u['id'], $fy]);
                $rec = $r->fetch();

                if ($rec) {
                    // v104: counted in calendar days ("every 2 days" = Mon, Wed, Fri …), not hours —
                    // a sweep that ran a little earlier in the day than the last one no longer skips a day
                    $daysSince = (int)round((strtotime($today) - strtotime(date('Y-m-d', strtotime($rec['last_reminded_at'])))) / 86400);
                    if ($daysSince < $interval) continue;   // too soon
                }

                // Send the reminder
                $count = $rec ? ((int)$rec['reminder_count'] + 1) : 1;

                if ($isLate) {
                    $title = "⏰ PPMP submission overdue for FY {$fy}";
                    $body  = "Reminder #{$count}: The submission deadline for FY {$fy} PPMPs was {$deadlineStr}, "
                           . "and your department ({$u['dept_name']}) has not yet submitted. "
                           . "It's been {$daysLate} " . ($daysLate == 1 ? 'day' : 'days') . " past the cutoff. "
                           . "Please open your PPMP, mark it Done, and submit it as soon as possible.";
                } else {
                    $when  = $daysToGo === 0
                           ? 'today'
                           : ('in ' . $daysToGo . ' ' . ($daysToGo == 1 ? 'day' : 'days'));
                    $title = "🗓 PPMP submission due {$when} — FY {$fy}";
                    $body  = "Reminder #{$count}: The submission deadline for FY {$fy} PPMPs is {$deadlineStr} ({$when}), "
                           . "and your department ({$u['dept_name']}) has not submitted yet. "
                           . "Please open your PPMP, mark it Done, and submit it on or before the deadline.";
                }
                notify_user((int)$u['id'], 'deadline', $title, $body, 'user-dashboard.php');

                // Update tracker
                if ($rec) {
                    $pdo->prepare('UPDATE submission_reminders SET last_reminded_at = NOW(), reminder_count = ? WHERE user_id = ? AND fy = ?')
                        ->execute([$count, $u['id'], $fy]);
                } else {
                    $pdo->prepare('INSERT INTO submission_reminders (user_id, dept_code, fy, reminder_count) VALUES (?, ?, ?, 1)')
                        ->execute([$u['id'], $u['dept_code'], $fy]);
                }

                $sent++;
            }
        }
        return $sent;
    } catch (Throwable $t) {
        error_log('check_and_send_reminders failed: ' . $t->getMessage());
        return 0;
    }
}

/**
 * Run the deadline reminder sweep at most once a day.
 *
 * XAMPP has no cron, so the sweep piggy-backs on ordinary page loads:
 * the first person to open any page on a new day triggers it, and a
 * marker in `settings` stops it running again until tomorrow.
 */
function maybe_run_daily_reminders(): void {
    try {
        $pdo   = db();
        $today = date('Y-m-d');

        $s = $pdo->prepare("SELECT value FROM settings WHERE key_name = 'reminders_last_run'");
        $s->execute();
        $last = (string)$s->fetchColumn();
        if ($last === $today) return;   // already done today

        // Claim the day first, so two simultaneous visitors don't both sweep
        $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('reminders_last_run', ?)
                       ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([$today]);

        check_and_send_reminders();
    } catch (Throwable $t) { /* never block a page load over a reminder */ }
}
