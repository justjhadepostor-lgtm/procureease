<?php
/* ═══════════════════════════════════════════════════════════════
   v137: "End-User or Implementing Unit" on the PPMP.

   It is the department's name (with its set), as before — unless the
   department types another on its PPMP. What it typed is kept on the PPMP
   (ppmps.unit_name); left blank, the department's name shows again.
   The Fiscal Year stays as the Procurement Unit set it.
   =============================================================== */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/office-sets.php';

/** The column (made once; never inside a transaction). */
function ppmp_unit_column(): void {
    static $done = false;
    if ($done) return;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        $sqlite = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $have = $sqlite
            ? array_column(db()->query('PRAGMA table_info(ppmps)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : db()->query('SHOW COLUMNS FROM ppmps')->fetchAll(PDO::FETCH_COLUMN);
        if ($have && !in_array('unit_name', $have, true)) db()->exec('ALTER TABLE ppmps ADD COLUMN unit_name VARCHAR(255) NULL');
    } catch (Throwable $t) { error_log('ppmp unit_name column: ' . $t->getMessage()); }
}

/** What the PPMP shows: what the department typed, or else its name. */
function ppmp_unit_label(array $ppmp, string $code = '', string $deptName = ''): string {
    $typed = trim((string)($ppmp['unit_name'] ?? ''));
    if ($typed !== '') return $typed;
    return os_unit_name($code !== '' ? $code : (string)($ppmp['dept_code'] ?? ''),
                        $deptName !== '' ? $deptName : (string)($ppmp['dept_name'] ?? ''));
}
