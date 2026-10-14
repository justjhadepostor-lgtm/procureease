<?php
/* ═══════════════════════════════════════════════════════════════
   Wrong-code limit (v80) for the 6-digit codes of "Sign in with email" and
   Forgot password.

   After OTP_MAX_FAILS wrong codes, the code being guessed stops working
   (as if it were used): the person has to get a new code. Without this a
   code could be guessed over and over for its 10 minutes.

   Kept on the code's own row: email_otps.failed_attempts. The codes
   themselves are still made and checked by config/otp.php, unchanged.
   =============================================================== */
require_once __DIR__ . '/db.php';

const OTP_MAX_FAILS = 5;

/** Adds email_otps.failed_attempts once (never inside a transaction). */
function otp_guard_columns(): void {
    static $done = false;
    if ($done) return;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        $have = db()->query('SHOW COLUMNS FROM email_otps')->fetchAll(PDO::FETCH_COLUMN);
        if ($have && !in_array('failed_attempts', $have, true)) {
            db()->exec("ALTER TABLE email_otps ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0");
        }
    } catch (Throwable $t) {}
    // v110: before a wrong code is counted on the newest code's row — expires_at must not be
    // "ON UPDATE CURRENT_TIMESTAMP" (see config/admin-schema.php), or that count expires the code
    try {
        $c = db()->query("SHOW COLUMNS FROM email_otps LIKE 'expires_at'")->fetch(PDO::FETCH_ASSOC);
        if ($c && stripos((string)($c['Type'] ?? ''), 'datetime') === false) {
            db()->exec("ALTER TABLE email_otps MODIFY expires_at DATETIME NOT NULL");
        }
    } catch (Throwable $t) {}
}

/**
 * A wrong code for $email / $purpose. Counts it against the code now in use
 * (the newest one not used and not expired) and stops that code at the
 * OTP_MAX_FAILS-th wrong try. Returns the message to show.
 */
function otp_guard_fail(string $email, string $purpose): string {
    otp_guard_columns();
    try {
        $s = db()->prepare('SELECT id, failed_attempts FROM email_otps
                             WHERE email = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
                          ORDER BY id DESC LIMIT 1');
        $s->execute([$email, $purpose]);
        $row = $s->fetch();
    } catch (Throwable $t) { $row = null; }
    if (!$row) return 'That code is invalid or has expired. Get a new code and try again.';
    $n = (int)($row['failed_attempts'] ?? 0) + 1;
    try {
        if ($n >= OTP_MAX_FAILS) {
            db()->prepare('UPDATE email_otps SET failed_attempts = ?, used_at = NOW() WHERE id = ?')->execute([$n, (int)$row['id']]);
        } else {
            db()->prepare('UPDATE email_otps SET failed_attempts = ? WHERE id = ?')->execute([$n, (int)$row['id']]);
        }
    } catch (Throwable $t) {}
    if ($n >= OTP_MAX_FAILS) {
        return 'Too many wrong codes (' . OTP_MAX_FAILS . '). That code no longer works — get a new code and try again.';
    }
    $left = OTP_MAX_FAILS - $n;
    return 'That code is not right. ' . $left . ' more ' . ($left === 1 ? 'try' : 'tries') . ' before this code stops working.';
}
