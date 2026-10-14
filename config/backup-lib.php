<?php
/* ═══════════════════════════════════════════════════════════════
   Backups — used by Backup & Restore (by hand) and by the automatic
   schedule set on Settings > Backup schedule (maybe_run_scheduled_backup,
   called on page loads, since XAMPP has no scheduler of its own).
   =============================================================== */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/app-settings.php';

/**
 * Pure-PHP ZIP writer with a traditional ZIP password (ZipCrypto) — works even
 * when the ZipArchive extension is OFF, and the resulting .zip opens in Windows
 * Explorer / 7-Zip / WinRAR with a password prompt. $files = [name => data].
 */
function pe_zip_write_encrypted(string $path, array $files, string $password): bool {
    static $tab = null;
    if ($tab === null) {
        $tab = [];
        for ($i = 0; $i < 256; $i++) {
            $c = $i;
            for ($j = 0; $j < 8; $j++) $c = ($c & 1) ? (0xEDB88320 ^ (($c >> 1) & 0x7FFFFFFF)) : (($c >> 1) & 0x7FFFFFFF);
            $tab[$i] = $c & 0xFFFFFFFF;
        }
    }
    $crcb = fn($crc, $b) => ((($crc >> 8) & 0x00FFFFFF) ^ $tab[($crc ^ $b) & 0xFF]) & 0xFFFFFFFF;
    $fh = @fopen($path, 'wb');
    if (!$fh) return false;
    $central = ''; $offset = 0; $cnt = 0; $pwlen = strlen($password);
    foreach ($files as $name => $data) {
        $crc  = crc32($data) & 0xFFFFFFFF;
        $ulen = strlen($data);
        $k0 = 305419896; $k1 = 591751049; $k2 = 878082192;
        $upd = function ($b) use (&$k0, &$k1, &$k2, $crcb) {
            $k0 = $crcb($k0, $b);
            $k1 = ($k1 + ($k0 & 0xFF)) & 0xFFFFFFFF;
            $k1 = ($k1 * 134775813 + 1) & 0xFFFFFFFF;
            $k2 = $crcb($k2, ($k1 >> 24) & 0xFF);
        };
        $decb = function () use (&$k2) {
            $t = ($k2 | 2) & 0xFFFF;
            return (($t * ($t ^ 1)) >> 8) & 0xFF;
        };
        for ($i = 0; $i < $pwlen; $i++) $upd(ord($password[$i]));
        $header = random_bytes(11) . chr(($crc >> 24) & 0xFF);
        $enc = '';
        for ($i = 0; $i < 12; $i++)    { $p = ord($header[$i]); $enc .= chr($p ^ $decb()); $upd($p); }
        for ($i = 0; $i < $ulen; $i++) { $p = ord($data[$i]);   $enc .= chr($p ^ $decb()); $upd($p); }
        $clen = strlen($enc);
        $lfh = "\x50\x4b\x03\x04" . "\x14\x00" . "\x01\x00" . "\x00\x00"
             . "\x00\x00\x00\x00" . pack('V', $crc) . pack('V', $clen) . pack('V', $ulen)
             . pack('v', strlen($name)) . "\x00\x00" . $name;
        fwrite($fh, $lfh); fwrite($fh, $enc);
        $central .= "\x50\x4b\x01\x02" . "\x14\x00" . "\x14\x00" . "\x01\x00" . "\x00\x00"
                  . "\x00\x00\x00\x00" . pack('V', $crc) . pack('V', $clen) . pack('V', $ulen)
                  . pack('v', strlen($name)) . "\x00\x00" . "\x00\x00" . "\x00\x00" . "\x00\x00"
                  . "\x00\x00\x00\x00" . pack('V', $offset) . $name;
        $offset += strlen($lfh) + $clen; $cnt++;
    }
    fwrite($fh, $central);
    fwrite($fh, "\x50\x4b\x05\x06" . "\x00\x00" . "\x00\x00" . pack('v', $cnt) . pack('v', $cnt)
              . pack('V', strlen($central)) . pack('V', $offset) . "\x00\x00");
    fclose($fh);
    return is_file($path);
}

/* ─── v96: the password the backup .zip asks for — changed on Backup & Restore ─── */
const BACKUP_PW_DEFAULT = 'Password123';
const BACKUP_PW_MIN = 8;
const BACKUP_PW_MAX = 64;

function backup_setting(string $key): ?string {
    try {
        $s = db()->prepare('SELECT value FROM settings WHERE key_name = ?');
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? null : (string)$v;
    } catch (Throwable $t) { return null; }
}

/** The password new backups are made with. */
function backup_password(): string {
    $v = backup_setting('backup_password');
    return ($v === null || $v === '') ? BACKUP_PW_DEFAULT : $v;
}

/** When the password was last changed ('' = still the first one). */
function backup_password_changed_at(): string {
    return (string)(backup_setting('backup_password_changed_at') ?? '');
}

/** Changes it. Returns [ok, message]. */
function backup_password_change(string $current, string $new, string $confirm, string $who): array {
    if (!hash_equals(backup_password(), $current)) return [false, 'The current backup password is not right.'];
    if ($new !== trim($new))                        return [false, 'The new password cannot start or end with a space.'];
    $len = mb_strlen($new);
    if ($len < BACKUP_PW_MIN || $len > BACKUP_PW_MAX) return [false, 'The new password must have ' . BACKUP_PW_MIN . ' to ' . BACKUP_PW_MAX . ' characters.'];
    if (!hash_equals($new, $confirm))               return [false, 'The new password and its confirmation are not the same.'];
    if (hash_equals($new, $current))                return [false, 'The new password is the same as the current one.'];
    try {
        $pdo = db();
        foreach (['backup_password' => $new, 'backup_password_changed_at' => date('Y-m-d H:i:s')] as $k => $v) {
            $pdo->prepare('DELETE FROM settings WHERE key_name = ?')->execute([$k]);
            $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)')->execute([$k, $v]);
        }
    } catch (Throwable $t) { return [false, 'The password could not be changed. Please try again.']; }
    if (function_exists('log_activity')) {
        log_activity('Changed the backup password', 'backup', '', 'New backups ask for the new password. Backups made before keep the password they were made with.');
    }
    return [true, 'Backup password changed. Backups made from now on ask for the new password; backups made before keep the old one.'];
}

/**
 * Write one encrypted backup of the whole database into backups/ and record
 * it. Returns [name, path, size, tables, rows]. Throws on failure.
 */
function pe_make_backup(PDO $pdo, string $by, string $notes): array {
    $backupDir = __DIR__ . '/../backups';
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
        throw new Exception("Cannot create backups/ directory. Check folder permissions in XAMPP.");
    }
    if (!is_writable($backupDir)) {
        throw new Exception("The backups/ directory is not writable. Check folder permissions in XAMPP.");
    }
    $u = ['username' => $by];
    // v96: the password set on Backup & Restore (Password123 until it is changed)
    $password = backup_password();
    $stamp   = date('Y-m-d-His');
    $sqlName = 'procureease-' . $stamp . '.sql';
    $zipName = 'procureease-' . $stamp . '.zip';
    $zipPath = $backupDir . '/' . $zipName;

    $out = "-- ProcureEase database backup\n-- Generated: "
         . date('Y-m-d H:i:s') . "\n-- By: {$u['username']}\n\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $totalRows = 0;
    foreach ($tables as $t) {
        $out .= "\n-- ─── Table: $t ─────────\n";
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        $out .= "DROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n\n";
        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        $totalRows += count($rows);
        foreach ($rows as $row) {
            $cols = array_keys($row);
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
            $out .= "INSERT INTO `$t` (`" . implode('`, `', $cols) . "`) VALUES ("
                  . implode(', ', $vals) . ");\n";
        }
    }

    $encrypted = false;
    // Preferred (php_zip ON + libzip AES): native AES-256 password ZIP.
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString($sqlName, $out);
            if (method_exists($zip, 'setEncryptionName') && defined('ZipArchive::EM_AES_256')) {
                $encrypted = $zip->setEncryptionName($sqlName, ZipArchive::EM_AES_256, $password);
            }
            $zip->close();
            if (!$encrypted) @unlink($zipPath);
        }
    }
    // Fallback (works even when php_zip is OFF): a password-protected ZIP
    // (traditional ZipCrypto) written in pure PHP. It holds the plain .sql
    // and opens in Windows Explorer with a password prompt — double-click
    // the .zip, type the password, and out comes the .sql.
    if (!$encrypted) {
        @unlink($zipPath);
        if (!pe_zip_write_encrypted($zipPath, [$sqlName => $out], $password)) {
            throw new Exception('Could not write the backup ZIP. Check the backups/ folder permissions.');
        }
        $encrypted = true;
    }
    if (!is_file($zipPath)) {
        throw new Exception('Failed to write the backup ZIP to disk.');
    }
    $size = filesize($zipPath);


    $pdo->prepare('INSERT INTO backups (filename, size_bytes, tables_count, rows_count, created_by, notes) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$zipName, $size, count($tables), $totalRows, $by, $notes]);
    return ['name' => $zipName, 'path' => $zipPath, 'size' => $size, 'tables' => count($tables), 'rows' => $totalRows];
}

/** How many days apart the automatic backups are (0 = by hand only). */
function backup_schedule_days(?string $sched = null): int {
    return ['daily' => 1, 'weekly' => 7, 'monthly' => 30][$sched ?? app_setting('backup_schedule')] ?? 0;
}

/** When the last backup (automatic or by hand) was made, or null. */
function backup_last_at(): ?string {
    try {
        $v = db()->query('SELECT MAX(created_at) FROM backups')->fetchColumn();
        return $v ? (string)$v : null;
    } catch (Throwable $t) { return null; }
}

/**
 * Run the scheduled backup when one is due. Tried at most once an hour, so a
 * backup that cannot be written never slows every page down.
 */
function maybe_run_scheduled_backup(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $days = backup_schedule_days();
    if ($days === 0) return;
    $last = backup_last_at();
    if ($last && strtotime($last) > time() - $days * 86400) return;
    $tried = (int)app_setting('backup_auto_tried_at', '0');
    if ($tried > 0 && time() - $tried < 3600) return;
    try {
        $pdo = db();
        $pdo->prepare("DELETE FROM settings WHERE key_name = 'backup_auto_tried_at'")->execute();
        $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('backup_auto_tried_at', ?)")->execute([(string)time()]);
        app_settings_all(true);
        $sched = app_setting('backup_schedule');
        $r = pe_make_backup($pdo, 'system', "Automatic $sched backup (Settings > Backup schedule)");
        log_activity('Automatic backup', 'backup', $r['name'],
            "Scheduled ($sched): {$r['tables']} tables, {$r['rows']} rows, " . round($r['size'] / 1024, 1) . ' KB.');
    } catch (Throwable $t) {
        error_log('scheduled backup failed: ' . $t->getMessage());
    }
}
