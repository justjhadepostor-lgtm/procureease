<?php
/* ═══════════════════════════════════════════════════════════════
   v104: Suffix — optional, like the middle initial (Register and My Profile).

   Written the usual way: Jr. / Sr. with a full stop, and II, III, IV … in
   capitals. It goes at the end of the full name:
       Juan D. Dela Cruz Jr.
   =============================================================== */
require_once __DIR__ . '/db.php';

/** users.suffix, once. */
function name_suffix_column(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if ($have && !in_array('suffix', $have, true)) db()->exec("ALTER TABLE users ADD COLUMN suffix VARCHAR(10) NULL");
    } catch (Throwable $t) {}
}

/**
 * The suffix as it is written: '' when none, null when it is not a suffix.
 *   "jr" / "JR." → "Jr."   "sr" → "Sr."   "iii" → "III"
 */
function name_suffix_norm(string $v): ?string {
    $v = trim(preg_replace('/\s+/u', '', $v));
    if ($v === '') return '';
    if (preg_match('/^(jr|sr)\.?$/i', $v, $m)) return ucfirst(strtolower($m[1])) . '.';
    if (preg_match('/^(i{1,3}|iv|v|vi{1,3}|ix|x)\.?$/i', $v, $m)) return strtoupper($m[1]);
    return null;
}

/** First M. Last Suffix — the display name. */
function name_full(string $first, string $mi, string $last, string $suffix = ''): string {
    $mi = trim($mi);
    if ($mi !== '' && substr($mi, -1) !== '.') $mi .= '.';
    return trim(preg_replace('/\s+/', ' ', $first . ' ' . $mi . ' ' . $last . ' ' . trim($suffix)));
}

const NAME_SUFFIX_MSG = 'The suffix can only be Jr., Sr., or II, III, IV and so on.';
