<?php
/* ═══════════════════════════════════════════════════════════════
   setup.php only while ProcureEase is being installed (v80).

   setup.php puts every seed account's password back to the default and
   makes missing seed accounts. Anyone could open it, so once the system is
   in use it is turned off: the first time anyone is signed in (any page with
   the top bar), the setting "setup_locked" is set, for good.

   To run it again on purpose (for example, no one can sign in any more), the
   person in charge of the server creates an empty file named
   setup-unlock.txt in this config folder, opens setup.php, then deletes the
   file. That needs access to the server's files, which a visitor does not have.
   =============================================================== */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app-settings.php';

const SETUP_LOCK_KEY  = 'setup_locked';
const SETUP_UNLOCK_FILE = __DIR__ . '/setup-unlock.txt';

/** Is setup.php turned off? */
function setup_is_locked(): bool {
    if (is_file(SETUP_UNLOCK_FILE)) return false;
    try {
        $s = db()->prepare('SELECT value FROM settings WHERE key_name = ?');
        $s->execute([SETUP_LOCK_KEY]);
        return (string)$s->fetchColumn() === '1';
    } catch (Throwable $t) { return false; }
}

/** Turns setup.php off (once; called when someone is signed in). */
function setup_lock_now(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if ((app_settings_all()[SETUP_LOCK_KEY] ?? '') === '1') return;
    try {
        db()->prepare('DELETE FROM settings WHERE key_name = ?')->execute([SETUP_LOCK_KEY]);
        db()->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)')->execute([SETUP_LOCK_KEY, '1']);
        app_settings_all(true);
    } catch (Throwable $t) {}
}
