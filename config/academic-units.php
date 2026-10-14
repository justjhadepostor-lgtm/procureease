<?php
/* ═══════════════════════════════════════════════════════════════
   ASCOT's schools, their programs (Bachelor / Master) and majors (v81).

   School (what one registers under, e.g. "School of Education (SOED)")
     └ Program — a Bachelor or Master (BEED, BSED, MAEd …)
         └ Major (BSED – Major in English …)

   The unit that prepares a set of documents (PPMP, APP-CSE, Non-PS list,
   Annex, Market Scoping) is the MAJOR — or the program, when it has no
   majors. A school with a single program and no majors (SOAS, SOIT, COL) is
   its own unit. Each unit is a row in `departments`, under its school:
       code        e.g. SOED-BSED-ENG (at most 20 characters, like every department code)
       parent_code the school (SOED)
       program_code / program_name / major_name
   so everything that already works per department works per unit.

   The alloted budget stays with the SCHOOL (one per school); the
   Department Admin of the school may split it among its programs
   (config/allot-shares.php).

   Accounts: users.dept_code is the SCHOOL; users.sub_unit is the PROGRAM
   code (BSED) when the account is for one program, or empty for the whole
   school. current_user() (config/auth.php) works on one unit at a time —
   the account chooses which (au_active_unit()).
   =============================================================== */
require_once __DIR__ . '/db.php';

/* name, programs: [code, name, [major code => major name]] — as in ASCOT's list of academic programs */
const AU_SCHOOLS = [
    'SAS'  => ['School of Agricultural Sciences', [
        ['BSA', 'Bachelor of Science in Agriculture', ['AS' => 'Major in Animal Science', 'CS' => 'Major in Crop Science']],
    ]],
    'SOAS' => ['School of Arts and Sciences', [
        ['ABPS', 'Bachelor of Arts in Political Science', []],
    ]],
    'SOE'  => ['School of Engineering', [
        ['BSCE', 'Bachelor of Science in Civil Engineering', []],
        ['BSEE', 'Bachelor of Science in Electrical Engineering', []],
        ['BSME', 'Bachelor of Science in Mechanical Engineering', []],
    ]],
    'SFOS' => ['School of Fisheries and Ocean Sciences', [
        ['BSFi', 'Bachelor of Science in Fisheries', []],
        ['BSMB', 'Bachelor of Science in Marine Biology', []],
    ]],
    'SFES' => ['School of Forestry and Environmental Sciences', [
        ['BSF',  'Bachelor of Science in Forestry', []],
        ['BSES', 'Bachelor of Science in Environmental Science', []],
    ]],
    'SOED' => ['School of Education', [
        ['BEED',  'Bachelor of Elementary Education', []],
        ['BPED',  'Bachelor of Physical Education', []],
        ['BSED',  'Bachelor of Secondary Education', ['ENG' => 'Major in English', 'FIL' => 'Major in Filipino', 'MATH' => 'Major in Mathematics',
                                                      'SCI' => 'Major in Science', 'SS' => 'Major in Social Studies']],
        ['BTLED', 'Bachelor of Technology and Livelihood Education', ['HE' => 'Major in Home Economics',
                                                                      'ICT' => 'Major in Information and Communication Technology']],
    ]],
    'SIT'  => ['School of Industrial Technology', [
        ['BIT', 'Bachelor in Industrial Technology', ['AET' => 'Major in Automotive Engineering Technology', 'CET' => 'Major in Civil Engineering Technology',
                                                    'EET' => 'Major in Electrical Engineering Technology', 'FT' => 'Major in Food Technology']],
    ]],
    'SOIT' => ['School of Information Technology', [
        ['BSIT', 'Bachelor of Science in Information Technology', []],
    ]],
    'SABM' => ['School of Accountancy and Business Management', [
        ['BSA',  'Bachelor of Science in Accountancy', []],
        ['BSHM', 'Bachelor of Science in Hospitality Management', []],
        ['BSTM', 'Bachelor of Science in Tourism Management', []],
    ]],
    'COL'  => ['College of Law', [
        ['JD', 'Juris Doctor', []],
    ]],
    'GRAD' => ['Graduate Studies', [
        ['MAEd', 'Master of Arts in Education', ['SCI' => 'Major in Science Education', 'FIL' => 'Major in Filipino Language Teaching',
                                                 'GC' => 'Major in Guidance and Counseling', 'EM' => 'Major in Educational Management']],
        ['MM',   'Master in Management', ['EM' => 'Major in Educational Management', 'PM' => 'Major in Public Management',
                                          'BM' => 'Major in Business Management']],
        ['MSA',  'Master of Science in Agriculture', ['AS' => 'Major in Animal Science', 'CS' => 'Major in Crop Science']],
    ]],
];
/* where each school sits in the lists (as before; SIT is new) */
const AU_ORDER = ['SOIT' => 120, 'SOAS' => 130, 'SOED' => 140, 'SABM' => 150, 'SFES' => 160, 'SFOS' => 170, 'GRAD' => 180,
                  'COL' => 190, 'SAS' => 200, 'SOE' => 110, 'SIT' => 115];
const AU_GROUP = 'Schools & Colleges';
const AU_MIGRATION_KEY = 'academic_units_v81';

/** Adds the unit columns to departments once (never inside a transaction). */
function au_columns(): void {
    static $done = false;
    if ($done) return;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        $have = db()->query('SHOW COLUMNS FROM departments')->fetchAll(PDO::FETCH_COLUMN);
        if (!$have) return;
        if (!in_array('parent_code', $have, true))  db()->exec("ALTER TABLE departments ADD COLUMN parent_code VARCHAR(20) NULL");
        if (!in_array('program_code', $have, true)) db()->exec("ALTER TABLE departments ADD COLUMN program_code VARCHAR(20) NULL");
        if (!in_array('program_name', $have, true)) db()->exec("ALTER TABLE departments ADD COLUMN program_name VARCHAR(200) NULL");
        if (!in_array('major_name', $have, true))   db()->exec("ALTER TABLE departments ADD COLUMN major_name VARCHAR(200) NULL");
        // v83: the 2nd / 3rd set of an office (config/office-sets.php)
        if (!in_array('set_no', $have, true))       db()->exec("ALTER TABLE departments ADD COLUMN set_no INT NULL");
    } catch (Throwable $t) {}
}

/** The unit code of a program / major: SOED-BSED-ENG, SOE-BSCE. */
function au_unit_code(string $school, string $program, string $major = ''): string {
    return strtoupper($school . '-' . $program . ($major !== '' ? '-' . $major : ''));
}

/** What the unit is called everywhere: "School of Education – BSED, Major in English". */
function au_unit_name(string $schoolName, string $program, string $major = ''): string {
    return $schoolName . ' – ' . $program . ($major !== '' ? ', ' . $major : '');
}

/** Is this ASCOT's database (it has ASCOT's schools)? The structure is only applied there. */
function au_is_ascot(): bool {
    try {
        $codes = array_keys(AU_SCHOOLS); $codes[] = 'SoIT';
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $s = db()->prepare("SELECT COUNT(*) FROM departments WHERE code IN ($ph)");
        $s->execute($codes);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $t) { return false; }
}

/**
 * Applies the structure once: the schools' names, SIT, SoIT → SOIT, and one
 * row per unit. Then SOE's and SFOS's documents and allotments made for the
 * whole school (before they had units) are deleted, as decided. Safe to run
 * again: it only fills in what is missing.
 */
function au_migrate(bool $force = false): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    au_columns();
    $pdo = db();
    $done = false;
    try {
        $s = $pdo->prepare('SELECT value FROM settings WHERE key_name = ?');
        $s->execute([AU_MIGRATION_KEY]);
        $done = (string)$s->fetchColumn() === '1';
    } catch (Throwable $t) {}
    if ($done && !$force) return;
    if (!au_is_ascot()) return;

    // 1. SoIT becomes SOIT everywhere (the code of the school, in capitals like the others)
    au_rename_code('SoIT', 'SOIT');

    // 2. The schools: their names as in the list, and SIT
    foreach (AU_SCHOOLS as $code => [$name, $programs]) {
        try {
            $s = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE code = ?');
            $s->execute([$code]);
            if ((int)$s->fetchColumn() === 0) {
                $pdo->prepare('INSERT INTO departments (code, name, dept_group, display_order, status) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$code, $name, AU_GROUP, AU_ORDER[$code] ?? 100, 'active']);
            } else {
                $pdo->prepare('UPDATE departments SET name = ?, dept_group = ?, parent_code = NULL WHERE code = ?')->execute([$name, AU_GROUP, $code]);
            }
        } catch (Throwable $t) { error_log('au_migrate school ' . $code . ': ' . $t->getMessage()); }
    }

    // 3. The units under each school (a school with one program and no majors is its own unit)
    foreach (AU_SCHOOLS as $code => [$name, $programs]) {
        $order = AU_ORDER[$code] ?? 100;
        if (count($programs) === 1 && !$programs[0][2]) continue;
        foreach ($programs as [$pc, $pn, $majors]) {
            $rows = $majors ? array_map(null, array_keys($majors), array_values($majors)) : [['', '']];
            foreach ($rows as [$mc, $mn]) {
                $uc = au_unit_code($code, $pc, (string)$mc);
                $un = au_unit_name($name, $pc, (string)$mn);
                try {
                    $s = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE code = ?');
                    $s->execute([$uc]);
                    if ((int)$s->fetchColumn() === 0) {
                        $pdo->prepare('INSERT INTO departments (code, name, dept_group, display_order, status, parent_code, program_code, program_name, major_name)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$uc, $un, AU_GROUP, $order, 'active', $code, $pc, $pn, $mn !== '' ? $mn : null]);
                    } else {
                        $pdo->prepare('UPDATE departments SET name = ?, dept_group = ?, parent_code = ?, program_code = ?, program_name = ?, major_name = ? WHERE code = ?')
                            ->execute([$un, AU_GROUP, $code, $pc, $pn, $mn !== '' ? $mn : null, $uc]);
                    }
                } catch (Throwable $t) { error_log('au_migrate unit ' . $uc . ': ' . $t->getMessage()); }
            }
        }
    }

    // 4. Once only: what SOE and SFOS made as a whole school, before they had units, is deleted
    if (!$done) {
        foreach (['SOE', 'SFOS'] as $school) au_delete_school_documents($school);
    }

    try {
        $pdo->prepare('DELETE FROM settings WHERE key_name = ?')->execute([AU_MIGRATION_KEY]);
        $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)')->execute([AU_MIGRATION_KEY, '1']);
    } catch (Throwable $t) {}
    au_cache_reset();
}

/** Every table with a dept_code column (and the references that carry a code). */
function au_dept_tables(): array {
    $out = [];
    try {
        foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $cols = db()->query('SHOW COLUMNS FROM `' . $t . '`')->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('dept_code', $cols, true)) $out[] = (string)$t;
        }
    } catch (Throwable $t) {}
    return $out;
}

/** Renames a department code everywhere it is used. */
function au_rename_code(string $from, string $to): void {
    $pdo = db();
    try {
        $s = $pdo->prepare('SELECT code FROM departments WHERE code = ?');
        $s->execute([$from]);
        $have = $s->fetchColumn();
        if ($have === false || (string)$have !== $from) return;   // (MySQL compares without case: only the exact old spelling)
    } catch (Throwable $t) { return; }
    // users, ppmps, revision_requests and edit_requests point at departments.code: their
    // checks are paused while the code changes on both sides (MySQL; nothing to pause elsewhere)
    try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 0'); } catch (Throwable $e) {}
    foreach (au_dept_tables() as $t) {
        try { $pdo->prepare("UPDATE `$t` SET dept_code = ? WHERE dept_code = ?")->execute([$to, $from]); } catch (Throwable $e) {}
    }
    try { $pdo->prepare('UPDATE departments SET code = ? WHERE code = ?')->execute([$to, $from]); } catch (Throwable $e) {}
    try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $e) {}
    try { $pdo->prepare('UPDATE departments SET parent_code = ? WHERE parent_code = ?')->execute([$to, $from]); } catch (Throwable $e) {}
    // the APP-CSE is referred to as "FY:DEPT"
    foreach ([['doc_versions', 'doc_ref'], ['edit_requests', 'doc_ref']] as [$t, $c]) {
        try {
            $q = $pdo->query("SELECT DISTINCT $c FROM `$t` WHERE $c LIKE '%:$from'");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $ref) {
                [$fy] = explode(':', (string)$ref, 2);
                $pdo->prepare("UPDATE `$t` SET $c = ? WHERE $c = ?")->execute([$fy . ':' . $to, $ref]);
            }
        } catch (Throwable $e) {}
    }
}

/** Deletes the documents and allotments a school made as a whole (not its units). */
function au_delete_school_documents(string $school): void {
    $pdo = db();
    $n = 0;
    $del = function (string $sql, array $a) use ($pdo, &$n) {
        try { $s = $pdo->prepare($sql); $s->execute($a); $n += $s->rowCount(); } catch (Throwable $t) {}
    };
    try {
        $s = $pdo->prepare('SELECT id FROM ppmps WHERE dept_code = ?'); $s->execute([$school]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $del('DELETE FROM ppmp_entries WHERE ppmp_id = ?', [$pid]);
            $del('DELETE FROM archived_ppmps WHERE ppmp_id = ?', [$pid]);
            $del('DELETE FROM revision_requests WHERE ppmp_id = ?', [$pid]);
        }
    } catch (Throwable $t) {}
    try {
        $s = $pdo->prepare('SELECT id FROM nonps_submissions WHERE dept_code = ?'); $s->execute([$school]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $sid) $del('DELETE FROM nonps_items WHERE submission_id = ?', [$sid]);
    } catch (Throwable $t) {}
    try {
        $s = $pdo->prepare('SELECT id FROM annex_forms WHERE dept_code = ?'); $s->execute([$school]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $aid) $del('DELETE FROM annex_items WHERE form_id = ?', [$aid]);
    } catch (Throwable $t) {}
    foreach (['ppmps', 'nonps_submissions', 'annex_forms', 'market_scoping', 'appcse_items', 'appcse_header', 'dept_budgets',
              'revision_requests', 'edit_requests', 'doc_versions', 'submission_reminders'] as $t) {
        $del("DELETE FROM `$t` WHERE dept_code = ?", [$school]);
    }
    if ($n > 0) {
        try {
            $pdo->prepare('INSERT INTO activity_logs (username, full_name, role, action, entity_type, entity_ref, details, ip_address)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(['(system)', '', '', 'Deleted school-level documents', 'department', $school,
                           "The documents and allotments made for the whole $school, before it had programs, were deleted ($n rows). Its programs prepare their own now.", '']);
        } catch (Throwable $t) {}
    }
}

/* ─── Reading the structure ───────────────────────────────────── */

/** Every department row, by code (cached for the request). */
function au_all(): array {
    static $cache = null;
    if (isset($GLOBALS['__au_reset'])) { $cache = null; unset($GLOBALS['__au_reset']); }
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        au_columns();
        foreach (db()->query('SELECT * FROM departments ORDER BY display_order, name')->fetchAll() as $r) $cache[(string)$r['code']] = $r;
    } catch (Throwable $t) {}
    return $cache;
}
function au_cache_reset(): void { $GLOBALS['__au_reset'] = true; }

/** The department / unit row (case-insensitive code), or null. */
function au_row(?string $code): ?array {
    if ($code === null || $code === '') return null;
    $all = au_all();
    if (isset($all[$code])) return $all[$code];
    foreach ($all as $k => $r) if (strcasecmp((string)$k, $code) === 0) return $r;
    return null;
}

/** Is this code a unit (a program / major under a school)? */
function au_is_unit(?string $code): bool {
    $r = au_row($code);
    return $r !== null && !empty($r['parent_code']) && empty($r['set_no']);   // (an office's set is not a program — v83)
}

/** The school a code belongs to (itself, for a school or an office). */
function au_school_of(?string $code): string {
    $r = au_row($code);
    if ($r && !empty($r['parent_code'])) return (string)$r['parent_code'];
    return (string)($r['code'] ?? $code ?? '');
}

/** The units of a school, in order (empty for a school without units, and for an office). */
function au_units_of(string $school): array {
    $out = [];
    foreach (au_all() as $code => $r) {
        if (strcasecmp((string)($r['parent_code'] ?? ''), $school) === 0 && ($r['status'] ?? 'active') !== 'inactive'
            && empty($r['set_no'])) $out[$code] = $r;   // (an office's sets are not programs — v83)
    }
    return $out;
}

/** Does this school have units? */
function au_has_units(string $school): bool { return (bool)au_units_of($school); }

/** The programs of a school: [code => name], in order (from its units, or from the list). */
function au_programs_of(string $school): array {
    $out = [];
    foreach (au_units_of($school) as $r) $out[(string)$r['program_code']] = (string)$r['program_name'];
    if (!$out && isset(AU_SCHOOLS[strtoupper($school)])) foreach (AU_SCHOOLS[strtoupper($school)][1] as [$pc, $pn]) $out[$pc] = $pn;
    return $out;
}

/** The units of one program of a school. */
function au_units_of_program(string $school, string $program): array {
    return array_filter(au_units_of($school), fn($r) => strcasecmp((string)$r['program_code'], $program) === 0);
}

/** Short label of a unit: "BSED – Major in English", "BSCE"; a school or an office: its name. */
function au_label(?string $code): string {
    $r = au_row($code);
    if (!$r) return (string)$code;
    if (!empty($r['set_no']) || (empty($r['parent_code']) && os_has_sets((string)$r['code']))) {
        return 'Set ' . (int)($r['set_no'] ?: 1);   // v83: an office's sets
    }
    if (empty($r['parent_code'])) return (string)$r['name'];
    return (string)$r['program_code'] . (!empty($r['major_name']) ? ' – ' . $r['major_name'] : '');
}

/** The units an account works on: its program's (sub_unit), or all of its school's; or the school itself. */
function au_scope(array $userRow): array {
    $school = (string)($userRow['dept_code'] ?? '');
    $units = au_units_of($school);
    if (!$units) {
        // v83: an office with a 2nd / 3rd set works on each of them (config/office-sets.php)
        if ($school !== '') { require_once __DIR__ . '/office-sets.php'; return os_family($school); }
        return [];
    }
    $prog = trim((string)($userRow['sub_unit'] ?? ''));
    // the school's Department Admin works on (and submits) every program of the school
    if (($userRow['role'] ?? '') === 'dept_admin') $prog = '';
    if ($prog !== '') {
        $mine = au_units_of_program($school, $prog);
        if ($mine) return array_keys($mine);
    }
    return array_keys($units);
}

/** The unit the account is working on now (chosen with ?unit=…, kept in the session). */
function au_active_unit(array $userRow): string {
    $scope = au_scope($userRow);
    if (!$scope) return (string)($userRow['dept_code'] ?? '');
    $want = (string)($_SESSION['active_unit'][(int)$userRow['id']] ?? '');
    foreach ($scope as $c) if (strcasecmp($c, $want) === 0) return $c;
    return $scope[0];
}

/** The program a stored sub_unit names (code => name), if it is one of the school's programs. */
function au_program_label(string $school, ?string $sub): string {
    $sub = trim((string)$sub);
    if ($sub === '') return '';
    foreach (au_programs_of($school) as $pc => $pn) if (strcasecmp($pc, $sub) === 0) return $pc . ' – ' . $pn;
    return $sub;
}

/** The unit a document opened by its id belongs to ('' when the page or the document is not one of these). */
function au_doc_unit(string $page, array $get): string {
    $map = ['annex-edit.php' => 'annex_forms', 'annex-print.php' => 'annex_forms',
            'market-scoping-edit.php' => 'market_scoping', 'market-scoping-print.php' => 'market_scoping',
            'nonps-edit.php' => 'nonps_submissions', 'nonps-print.php' => 'nonps_submissions',
            'ppmp-edit.php' => 'ppmps', 'ppmp-excel.php' => 'ppmps', 'ppmp-file-view.php' => 'ppmps', 'ppmp-print.php' => 'ppmps'];
    $id = (int)($get['id'] ?? 0);
    if (!isset($map[$page]) || $id <= 0) return '';
    try {
        $s = db()->prepare('SELECT dept_code FROM `' . $map[$page] . '` WHERE id = ?');
        $s->execute([$id]);
        $d = $s->fetchColumn();
        return $d === false ? '' : (string)$d;
    } catch (Throwable $t) { return ''; }
}

/**
 * For the folder pages (v81): a school with programs and majors has no
 * documents of its own — its units are listed instead — so it is left out.
 */
function au_listable(array $rows, string $key = 'code'): array {
    return array_values(array_filter($rows, fn($r) => !au_has_units((string)($r[$key] ?? ''))));
}

/** v92: is this department active (and, for a program or a set, its school / office too)? */
function au_is_active(?string $code): bool {
    $r = au_row($code);
    if (!$r) return true;
    if (($r['status'] ?? 'active') === 'inactive') return false;
    if (!empty($r['parent_code'])) {
        $p = au_row((string)$r['parent_code']);
        if ($p && ($p['status'] ?? 'active') === 'inactive') return false;
    }
    return true;
}

/** v92: leaves deactivated departments out of a list; they come back once reactivated. */
function au_active_rows(array $rows, string $key = 'code'): array {
    return array_values(array_filter($rows, fn($r) => au_is_active((string)($r[$key] ?? ''))));
}

require_once __DIR__ . '/office-sets.php';   // v83: an office's 2nd / 3rd set
