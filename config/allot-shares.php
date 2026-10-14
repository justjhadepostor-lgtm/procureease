<?php
/* ═══════════════════════════════════════════════════════════════
   One allotment per school, shared by its programs and majors (v81).

   The Budget Unit allots the SCHOOL its budget for the year (dept_budgets,
   on the school's code, as before). The school's Department Admin may set
   aside a share of it for a program (BSED, BEED …). Then:

     a program with a share   its majors share that share
     the other programs       share what is left of the school's allotment
     no shares at all         every unit of the school shares the allotment

   So "kung ilan sa BSED, yun ang paghahatian ng major niya": what the units
   of one pool spend together (APP-CSE + Non-PS, and the PPMPs) may not go
   over what the pool has (config/companions.php, ppmp_budget_match()).
   =============================================================== */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/academic-units.php';

/** The table of shares (made once; never inside a transaction). */
function allot_shares_table(): void {
    static $done = false;
    if ($done) return;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS allot_shares (
            id INT PRIMARY KEY AUTO_INCREMENT,
            school_code VARCHAR(20) NOT NULL,
            program_code VARCHAR(20) NOT NULL,
            fy INT NOT NULL,
            amount DECIMAL(16,2) NOT NULL DEFAULT 0,
            set_by VARCHAR(150) NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_share (school_code, program_code, fy)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $t) {}
}

/** The shares of a school for the year: [program code => amount] (only its programs). */
function allot_shares(string $school, int $fy): array {
    allot_shares_table();
    $out = [];
    $programs = au_programs_of($school);
    try {
        $s = db()->prepare('SELECT program_code, amount FROM allot_shares WHERE school_code = ? AND fy = ?');
        $s->execute([$school, $fy]);
        foreach ($s->fetchAll() as $r) {
            foreach ($programs as $pc => $pn) {
                if (strcasecmp((string)$pc, (string)$r['program_code']) === 0) $out[(string)$pc] = (float)$r['amount'];
            }
        }
    } catch (Throwable $t) {}
    // in the order of the programs
    $sorted = [];
    foreach ($programs as $pc => $pn) if (isset($out[$pc])) $sorted[$pc] = $out[$pc];
    return $sorted;
}

/** The school's allotment for the year from the Budget Unit (null: not set yet). */
function allot_school(string $school, int $fy): ?float {
    try {
        $s = db()->prepare('SELECT alloted_budget FROM dept_budgets WHERE dept_code = ? AND fy = ?');
        $s->execute([$school, $fy]);
        $v = $s->fetchColumn();
        if ($v !== false && $v !== null) return (float)$v;
    } catch (Throwable $t) {}
    return null;
}

/**
 * The pool a unit spends from:
 *   school, program, kind (program | rest | school), label (for sentences),
 *   allot (null: no allotment yet), units (the codes that share it),
 *   school_allot, shares.
 */
function allot_pool(string $unit, int $fy): array {
    // v83: an office's sets share the office's allotment (config/office-sets.php)
    if (!au_is_unit($unit) && os_pooled($unit)) {
        $fam    = os_family($unit);
        $office = $fam[0];
        $A      = allot_school($office, $fy);
        return ['school' => $office, 'program' => '', 'kind' => 'office', 'label' => $office . '\'s allotment', 'noun' => 'sets',
                'allot' => $A, 'units' => $fam, 'school_allot' => $A, 'shares' => []];
    }
    $row    = au_row($unit);
    $school = au_school_of($unit);
    $prog   = (string)($row['program_code'] ?? '');
    $A      = allot_school($school, $fy);
    $shares = allot_shares($school, $fy);
    $sRow   = au_row($school);
    $sName  = (string)($sRow['name'] ?? $school);
    $units  = au_units_of($school);
    if (!$units) {
        return ['school' => $school, 'program' => '', 'kind' => 'school', 'label' => $sName . '\'s allotment',
                'allot' => $A, 'units' => [$school], 'school_allot' => $A, 'shares' => [], 'noun' => 'units'];
    }
    if ($prog !== '' && array_key_exists($prog, $shares)) {
        $codes = array_keys(au_units_of_program($school, $prog));
        return ['school' => $school, 'program' => $prog, 'kind' => 'program', 'label' => 'the ' . $prog . ' share of ' . $school,
                'allot' => $shares[$prog], 'units' => $codes, 'school_allot' => $A, 'shares' => $shares, 'noun' => 'units'];
    }
    if ($shares) {
        $codes = [];
        foreach ($units as $c => $r) if (!array_key_exists((string)$r['program_code'], $shares)) $codes[] = $c;
        $rest = $A === null ? null : round($A - array_sum($shares), 2);
        $names = implode(', ', array_keys(array_diff_key(au_programs_of($school), $shares)));
        return ['school' => $school, 'program' => '', 'kind' => 'rest', 'label' => 'what is left of ' . $school . '\'s allotment (' . $names . ')',
                'allot' => $rest, 'units' => $codes, 'school_allot' => $A, 'shares' => $shares, 'noun' => 'units'];
    }
    return ['school' => $school, 'program' => '', 'kind' => 'school', 'label' => $school . '\'s allotment',
            'allot' => $A, 'units' => array_keys($units), 'school_allot' => $A, 'shares' => [], 'noun' => 'units'];
}

/** The PPMP Total Amount of each unit for the year: [code => amount] (the PPMP not in the Archive). */
function allot_ppmp_totals(array $codes, int $fy): array {
    $out = [];
    foreach ($codes as $c) {
        $out[$c] = 0.0;
        try {
            $s = db()->prepare('SELECT p.id FROM ppmps p WHERE p.dept_code = ? AND p.fy = ?
                                   AND NOT EXISTS (SELECT 1 FROM archived_ppmps a WHERE a.ppmp_id = p.id)
                              ORDER BY p.id DESC');
            $s->execute([$c, $fy]);
            $id = (int)$s->fetchColumn();
            if ($id > 0) {
                $q = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM ppmp_entries WHERE ppmp_id = ?');
                $q->execute([$id]);
                $out[$c] = round((float)$q->fetchColumn(), 2);
            }
        } catch (Throwable $t) {}
    }
    return $out;
}

/**
 * The Department Admin sets the programs' shares for the year. $amounts:
 * [program code => amount or '' (no share)]. The shares together may not
 * go over the school's allotment. Returns [ok, message].
 */
function allot_shares_save(string $school, int $fy, array $amounts, string $who): array {
    allot_shares_table();
    $programs = au_programs_of($school);
    $A = allot_school($school, $fy);
    $clean = [];
    foreach ($programs as $pc => $pn) {
        $raw = trim(str_replace([',', '₱', ' '], '', (string)($amounts[$pc] ?? '')));
        if ($raw === '') continue;
        if (!is_numeric($raw) || (float)$raw < 0) return [false, "The share of $pc must be an amount (₱0 or more), or left blank."];
        $clean[$pc] = round((float)$raw, 2);
    }
    if ($clean && $A === null) return [false, "The Budget Unit has not set the FY $fy allotment of $school yet, so it cannot be divided."];
    $sum = array_sum($clean);
    if ($A !== null && round($sum * 100) > round($A * 100)) {
        return [false, 'The shares add up to ₱' . number_format($sum, 2) . ', more than the ' . $school . ' allotment of ₱' . number_format($A, 2) . '.'];
    }
    try {
        db()->prepare('DELETE FROM allot_shares WHERE school_code = ? AND fy = ?')->execute([$school, $fy]);
        $ins = db()->prepare('INSERT INTO allot_shares (school_code, program_code, fy, amount, set_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW())');
        foreach ($clean as $pc => $amt) $ins->execute([$school, $pc, $fy, $amt, $who]);
    } catch (Throwable $t) { return [false, 'The shares could not be saved. Please try again.']; }
    if (!$clean) return [true, "No shares for FY $fy: every program of $school shares the whole allotment."];
    $parts = [];
    foreach ($clean as $pc => $amt) $parts[] = $pc . ' ₱' . number_format($amt, 2);
    $rest = array_diff_key($programs, $clean);
    return [true, "FY $fy shares saved: " . implode(' · ', $parts)
                . ($rest ? ' · the rest (₱' . number_format(($A ?? 0) - $sum, 2) . ') for ' . implode(', ', array_keys($rest)) : '') . '.'];
}
