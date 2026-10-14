<?php
/* ═══════════════════════════════════════════════════════════════
   Annex: the unit line and the two totals.

   · The line under the address (it used to say "CASH UNIT" for everyone)
     is the department's or unit's own name — the one the annex belongs
     to — and can be changed on the form (annex_forms.unit_name).
   · Total Estimated Budget = the Allocated Budget of NEP Account #1
     + the Allocated Budget of NEP Account #2. An annex saved before this,
     with neither allocated budget filled in, keeps the total it had.
   · Total Amount = all the item amounts added together.
   =============================================================== */
require_once __DIR__ . '/db.php';

/** Adds annex_forms.unit_name once. */
function annex_ensure_columns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = db()->query('SHOW COLUMNS FROM annex_forms')->fetchAll(PDO::FETCH_COLUMN);
        if ($have && !in_array('unit_name', $have, true)) {
            db()->exec('ALTER TABLE annex_forms ADD COLUMN unit_name VARCHAR(255) NULL');
        }
    } catch (Throwable $t) {}
}

/** The department's / unit's name, as the annex shows it by default. */
function annex_dept_name(string $deptCode): string {
    try {
        $s = db()->prepare('SELECT name FROM departments WHERE code = ?');
        $s->execute([$deptCode]);
        $n = trim((string)$s->fetchColumn());
        return $n !== '' ? $n : $deptCode;
    } catch (Throwable $t) { return $deptCode; }
}

/** The unit line on the annex: what was typed there, else the department's name — in capitals, as the form writes it. */
function annex_unit_line(?array $form, string $deptCode): string {
    $v = trim((string)($form['unit_name'] ?? ''));
    return $v !== '' ? $v : mb_strtoupper(annex_dept_name($deptCode));
}

/** Total Estimated Budget: NEP #1 + NEP #2 (an older annex with neither keeps its own total). */
function annex_estimated(?array $form): float {
    $a = (float)($form['allocated_budget_1'] ?? 0) + (float)($form['allocated_budget_2'] ?? 0);
    if ($a > 0) return round($a, 2);
    return round((float)($form['total_estimated'] ?? 0), 2);
}

/* ─── v99: Annex A–E — one of each letter in a set ─────────────────── */
const ANNEX_LETTERS = ['A', 'B', 'C', 'D', 'E'];

/** The annexes of a department (or set) for the year, not in the Archive, A to E. */
function annex_set_forms(string $dept, int $fy): array {
    try {
        $s = db()->prepare('SELECT id, annex, status, project_name FROM annex_forms
                             WHERE dept_code = ? AND fy = ? AND (archived IS NULL OR archived = 0)
                          ORDER BY annex, id');
        $s->execute([$dept, $fy]);
        return $s->fetchAll();
    } catch (Throwable $t) { return []; }
}

/** The id of another annex of the set that already has this letter (0 when it is free). */
function annex_letter_taken(string $dept, int $fy, string $letter, int $exceptId = 0): int {
    foreach (annex_set_forms($dept, $fy) as $r) {
        if (strtoupper((string)$r['annex']) === $letter && (int)$r['id'] !== $exceptId) return (int)$r['id'];
    }
    return 0;
}
