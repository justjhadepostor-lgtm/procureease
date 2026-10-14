<?php
/* ═══════════════════════════════════════════════════════════════
   Wrong-password limit (v79).

   After LOGIN_MAX_FAILS wrong passwords in a row, an account is locked for
   LOGIN_LOCK_MINUTES: the sign-in page refuses it, even with the right
   password, until the time is up or the Procurement Unit unlocks it.
   A right password resets the count.

   A locked account is sent straight to Forgot password: setting a new
   password with the emailed code unlocks it at once. The Procurement Unit
   sees every account with wrong passwords on User Management › Sign-in
   lockouts (and can unlock one there), and is told on the bell when an
   account is locked.

   Kept on the users row:
     failed_logins        wrong passwords since the last right one
     last_failed_at/ip    when, and from where, the last wrong one came
     locked_until         locked until then (empty = not locked)
   Times are written and compared by PHP's own clock, never the database's.
   =============================================================== */
require_once __DIR__ . '/db.php';

const LOGIN_MAX_FAILS    = 5;
const LOGIN_LOCK_MINUTES = 15;

/* v111: never locked after wrong passwords — the Procurement Unit (Super Admin), the Department
   Admins and the Budget Unit. Their wrong passwords are still kept in the Activity Log. */
const LOGIN_NO_LOCK_ROLES = ['admin', 'dept_admin', 'budget'];

/** Is this account one that is never locked? */
function login_lock_exempt(?array $row): bool {
    return $row !== null && in_array((string)($row['role'] ?? ''), LOGIN_NO_LOCK_ROLES, true);
}

/** Adds the columns once (never inside a transaction). */
function login_guard_columns(): void {
    static $done = false;
    if ($done) return;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        $have = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!$have) return;
        if (!in_array('failed_logins', $have, true))       db()->exec("ALTER TABLE users ADD COLUMN failed_logins INT NOT NULL DEFAULT 0");
        if (!in_array('last_failed_at', $have, true))      db()->exec("ALTER TABLE users ADD COLUMN last_failed_at DATETIME NULL");
        if (!in_array('last_failed_ip', $have, true))      db()->exec("ALTER TABLE users ADD COLUMN last_failed_ip VARCHAR(45) NULL");
        if (!in_array('locked_until', $have, true))        db()->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME NULL");
    } catch (Throwable $t) {}
}

/** The guard's columns of one account (or null). */
function login_guard_row(int $uid): ?array {
    login_guard_columns();
    try {
        $s = db()->prepare('SELECT id, username, full_name, role, dept_code, failed_logins, last_failed_at, last_failed_ip,
                                   locked_until
                              FROM users WHERE id = ?');
        $s->execute([$uid]);
        return $s->fetch() ?: null;
    } catch (Throwable $t) { return null; }
}

/** Is it locked now? ['locked' => bool, 'until' => unix time|null, 'minutes' => minutes left] */
function login_lock_state(?array $row): array {
    if (login_lock_exempt($row)) return ['locked' => false, 'until' => null, 'minutes' => 0];   // v111
    $until = !empty($row['locked_until']) ? strtotime((string)$row['locked_until']) : false;
    if ($until && $until > time()) {
        return ['locked' => true, 'until' => $until, 'minutes' => max(1, (int)ceil(($until - time()) / 60))];
    }
    return ['locked' => false, 'until' => null, 'minutes' => 0];
}

/** "9:42 PM" — and the date too when it is not today */
function login_time_label(int $ts): string {
    return date('Y-m-d', $ts) === date('Y-m-d') ? date('g:i A', $ts) : date('M j, g:i A', $ts);
}

/** The sign-in page's message for a locked account. */
function login_locked_message(array $state): string {
    return 'This account is locked after ' . LOGIN_MAX_FAILS . ' wrong passwords, until '
         . login_time_label((int)$state['until']) . ' (' . $state['minutes'] . ' minute' . ($state['minutes'] === 1 ? '' : 's') . ' from now).';
}

/** Kept in the Activity Log under the account it happened to. */
function login_guard_log(array $row, string $action, string $details): void {
    try {
        db()->prepare('INSERT INTO activity_logs (username, full_name, role, action, entity_type, entity_ref, details, ip_address)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string)$row['username'], (string)$row['full_name'], (string)$row['role'], $action, 'security',
                       (string)$row['username'], $details, (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
    } catch (Throwable $t) {}
}

/**
 * A wrong password for this account. Returns ['locked' => bool, 'left' => tries left, 'state' => login_lock_state()].
 * A lock that has run out starts the count again.
 */
function login_record_failure(array $row): array {
    // v111: an account that is never locked — no count, no lock; the wrong password is still logged
    if (login_lock_exempt($row)) {
        login_guard_log($row, 'Wrong password', 'Wrong password (this account is not locked after wrong passwords).');
        return ['locked' => false, 'left' => LOGIN_MAX_FAILS, 'state' => login_lock_state(null)];
    }
    $uid  = (int)$row['id'];
    $was  = login_lock_state($row);
    $prev = (int)($row['failed_logins'] ?? 0);
    // the lock ran out: the count starts again
    if (!$was['locked'] && !empty($row['locked_until'])) $prev = 0;
    $n   = $prev + 1;
    $now = date('Y-m-d H:i:s');
    $ip  = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $lockedUntil = null;
    if ($n >= LOGIN_MAX_FAILS) $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
    try {
        db()->prepare('UPDATE users SET failed_logins = ?, last_failed_at = ?, last_failed_ip = ?, locked_until = ? WHERE id = ?')
            ->execute([$n, $now, $ip, $lockedUntil, $uid]);
    } catch (Throwable $t) {}
    if ($lockedUntil) {
        login_guard_log($row, 'Account locked (wrong passwords)', "Locked for " . LOGIN_LOCK_MINUTES . " minutes after $n wrong passwords in a row.");
        try {
            require_once __DIR__ . '/notify.php';
            $s = db()->prepare("SELECT id FROM users WHERE role = 'admin' AND (status IS NULL OR status = 'active')");
            $s->execute();
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                if ((int)$aid === $uid) continue;   // never told about their own lock
                notify_user((int)$aid, 'security', 'Account locked: ' . $row['full_name'],
                    "{$row['full_name']} (@{$row['username']}" . (!empty($row['dept_code']) ? ", {$row['dept_code']}" : '') . ") was locked for "
                    . LOGIN_LOCK_MINUTES . " minutes after $n wrong passwords in a row. You can unlock it on User Management.",
                    'pending-users.php#lockouts');
            }
        } catch (Throwable $t) {}
        $st = login_lock_state(['locked_until' => $lockedUntil]);
        return ['locked' => true, 'left' => 0, 'state' => $st];
    }
    login_guard_log($row, 'Wrong password', "Wrong password ($n of " . LOGIN_MAX_FAILS . ' before the account is locked).');
    return ['locked' => false, 'left' => LOGIN_MAX_FAILS - $n, 'state' => login_lock_state(null)];
}

/** The right password: the count starts again. */
function login_record_success(int $uid): void {
    try {
        db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL
                        WHERE id = ? AND (failed_logins <> 0 OR locked_until IS NOT NULL)')
            ->execute([$uid]);
    } catch (Throwable $t) {}
}

/** Unlocks the account and clears its count (the Procurement Unit, or a password reset). */
function login_unlock(int $uid): void {
    try {
        db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?')
            ->execute([$uid]);
    } catch (Throwable $t) {}
}

/**
 * For User Management: accounts locked now, and accounts with wrong passwords
 * in the last 24 hours — locked ones first, then the latest.
 */
function login_guard_list(): array {
    login_guard_columns();
    $out = [];
    try {
        $since = date('Y-m-d H:i:s', time() - 86400);
        $s = db()->prepare("SELECT u.id, u.username, u.full_name, u.role, u.dept_code, u.failed_logins, u.last_failed_at, u.last_failed_ip,
                                   u.locked_until, d.name AS dept_name
                              FROM users u LEFT JOIN departments d ON d.code = u.dept_code
                             WHERE (u.failed_logins > 0 AND u.last_failed_at >= ?) OR u.locked_until IS NOT NULL");
        $s->execute([$since]);
        foreach ($s->fetchAll() as $r) {
            $r['lock'] = login_lock_state($r);
            // a lock that has run out, with no wrong password in the last day, is not shown
            if (!$r['lock']['locked'] && (empty($r['last_failed_at']) || strtotime((string)$r['last_failed_at']) < time() - 86400)) continue;
            $out[] = $r;
        }
    } catch (Throwable $t) {}
    usort($out, function ($a, $b) {
        $ka = [$a['lock']['locked'] ? 0 : 1, -strtotime((string)($a['last_failed_at'] ?? '1970-01-01'))];
        $kb = [$b['lock']['locked'] ? 0 : 1, -strtotime((string)($b['last_failed_at'] ?? '1970-01-01'))];
        return $ka <=> $kb;
    });
    return $out;
}
