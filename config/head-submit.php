<?php
/* ═══════════════════════════════════════════════════════════════
   v112: an end user submits the department's set to its Department Admin.

   In a department with a Department Admin, its end users prepare the FY set
   (PPMP, APP-CSE, Non-PS list, Annex, Market Scoping) and click Submit —
   the same window the Department Admin has (config/submit-set.php), with the
   same ✓ / ✗ for each document. It goes, all at once, to the Department
   Admin (bell + email), not to the Budget Unit or the Procurement Unit.
   The Department Admin then submits the set as before (api/submit-ppmp.php),
   which ends it here.

   Until then the end user can pull it back (Unsubmit). The documents stay
   open for the department to work on, as they always were.

   A program or major of a school keeps its own "Send for review"
   (config/unit-review.php).

   Kept in head_submits: one row per department and fiscal year.
   =============================================================== */
require_once __DIR__ . '/db.php';

/** The table (made once; never inside a transaction). */
function hs_table(): void {
    static $done = false;
    if ($done) return;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        $sqlite = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        db()->exec($sqlite
            ? "CREATE TABLE IF NOT EXISTS head_submits (id INTEGER PRIMARY KEY AUTOINCREMENT, dept_code VARCHAR(20) NOT NULL, fy INTEGER NOT NULL,
                 submitted_by VARCHAR(150) NULL, submitted_by_id INTEGER NULL, submitted_at DATETIME NULL, UNIQUE (dept_code, fy))"
            : "CREATE TABLE IF NOT EXISTS head_submits (
                 id INT PRIMARY KEY AUTO_INCREMENT,
                 dept_code VARCHAR(20) NOT NULL,
                 fy INT NOT NULL,
                 submitted_by VARCHAR(150) NULL,
                 submitted_by_id INT NULL,
                 submitted_at DATETIME NULL,
                 UNIQUE KEY uq_head_submit (dept_code, fy)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $t) { error_log('head submit table: ' . $t->getMessage()); }
}

/** Does this account submit to its Department Admin (an end user of a department that has one)? */
function hs_applies(?array $u): bool {
    if (!$u || ($u['role'] ?? '') !== 'user') return false;
    $dept = (string)($u['dept_code'] ?? '');
    if ($dept === '') return false;
    require_once __DIR__ . '/academic-units.php';
    if (au_is_unit($dept)) return false;                       // a program / major: "Send for review"
    return dept_admin_of($dept) !== null;
}

/** The Department Admin's name ('' when none). */
function hs_head_name(array $u): string {
    $h = dept_admin_of((string)($u['dept_code'] ?? ''));
    return $h ? (string)$h['full_name'] : '';
}

/** The set of this department and year as it was submitted to the Department Admin — null when it was not (or was taken back). */
function hs_get(string $dept, int $fy): ?array {
    hs_table();
    try {
        $s = db()->prepare('SELECT * FROM head_submits WHERE dept_code = ? AND fy = ?');
        $s->execute([$dept, $fy]);
        $r = $s->fetch();
        return $r ?: null;
    } catch (Throwable $t) { return null; }
}

/** Keeps it; false when it could not be kept. */
function hs_put(string $dept, int $fy, array $u): bool {
    hs_table();
    try {
        db()->prepare('DELETE FROM head_submits WHERE dept_code = ? AND fy = ?')->execute([$dept, $fy]);
        db()->prepare('INSERT INTO head_submits (dept_code, fy, submitted_by, submitted_by_id, submitted_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$dept, $fy, (string)($u['full_name'] ?? ''), (int)($u['id'] ?? 0), date('Y-m-d H:i:s')]);
        return true;
    } catch (Throwable $t) { error_log('head submit: ' . $t->getMessage()); return false; }
}

/** Ends it (pulled back, or the Department Admin submitted the set). */
function hs_clear(string $dept, int $fy): void {
    hs_table();
    try { db()->prepare('DELETE FROM head_submits WHERE dept_code = ? AND fy = ?')->execute([$dept, $fy]); } catch (Throwable $t) {}
}

/** The look of the chip and the bar (printed once per page; light and dark). */
function hs_css(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>
      .hs-chip { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:999px; font-size:12px; font-weight:700;
                 background:#ecfdf3; color:#14532d; border:1.5px solid #86efac; }
      .hs-bar { display:flex; gap:11px; align-items:flex-start; padding:13px 17px; margin-bottom:16px; background:#ecfdf3; border:1px solid #86efac;
                border-left:4px solid #15803d; border-radius:12px; font-size:13px; color:#14532d; line-height:1.55; }
      .hs-bar .hs-icon { font-size:17px; line-height:1; }
      body[data-theme="dark"] .hs-chip, body[data-theme="dark"] .hs-bar { background:#123222; color:#c9f2d7; border-color:#2f7a4c; }
      body[data-theme="dark"] .hs-bar { border-left-color:#4ade80; }
    </style>';
}

/** "Oct 10, 2026, 2:30 PM" */
function hs_when(?string $at): string {
    return $at ? date('M j, Y, g:i A', strtotime($at)) : '';
}

/**
 * For an end user, in place of "… submits for your department": the Submit
 * button — or, once submitted, where it is and "↩ Unsubmit".
 *   $onclick — what the page's Submit button runs to open the window.
 */
function hs_user_controls(array $u, int $fy, string $onclick): string {
    $dept = (string)($u['dept_code'] ?? '');
    $head = hs_head_name($u);
    $r = hs_get($dept, $fy);
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    if ($r) {
        $tok = function_exists('csrf_field') ? csrf_field() : '';
        return hs_css() . '<span id="headSubmitted" class="hs-chip" title="Submitted by ' . $h($r['submitted_by']) . ' on ' . $h(hs_when($r['submitted_at'])) . '">'
             . '✓ Submitted to ' . $h($head !== '' ? $head : 'your Department Admin') . '</span>'
             . '<form method="POST" action="api/submit-to-head.php" style="margin:0;" data-confirm="This takes the FY ' . $fy . ' set back from your Department Admin. You can submit it again."'
             . ' data-confirm-title="Unsubmit?" data-confirm-label="Unsubmit" data-confirm-tone="info">' . $tok
             . '<input type="hidden" name="do" value="unsubmit"><input type="hidden" name="fy" value="' . $fy . '">'
             . '<button type="submit" class="btn btn-ghost" id="headUnsubmit" style="padding:8px 14px; font-size:13px;">↩ Unsubmit</button></form>';
    }
    return '<button type="button" class="btn btn-primary" id="submitToHead" onclick="' . $h($onclick) . '" style="padding:8px 18px; font-size:13px;"'
         . ' title="Sends your whole FY ' . $fy . ' set to ' . $h($head !== '' ? $head : 'your Department Admin') . '">📤 Submit</button>';
}

/** For the Department Admin: what an end user submitted to them, waiting for their Submit ('' when nothing). */
function hs_head_bar(array $u, int $fy): string {
    if (($u['role'] ?? '') !== 'dept_admin') return '';
    $dept = (string)($u['dept_code'] ?? '');
    $r = hs_get($dept, $fy);
    if (!$r) return '';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return hs_css() . '<div id="headSubmitBar" class="hs-bar">'
         . '<span class="hs-icon">📥</span><div><b>' . $h($r['submitted_by']) . '</b> submitted the FY ' . (int)$fy
         . ' set to you on ' . $h(hs_when($r['submitted_at'])) . ' — the PPMP, APP-CSE, Non-PS list, Annex and Market Scoping.'
         . ' Review them, then <b>Submit</b> to send them to the Budget Unit and the Procurement Unit.</div></div>';
}
