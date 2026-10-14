<?php
/* ═══════════════════════════════════════════════════════════════
   ASCOT's administrative units and offices (v82).

   Every office in ASCOT's list of Administrative Units is a department of
   its own: it registers under its code ("Budget Unit (BUDGET-OFC)"), and
   prepares and submits its own documents (PPMP, APP-CSE, Non-PS list,
   Annex, Market Scoping) like every department.

   An office already in the system keeps its code, so the records filed
   under it stay where they are; only its name is set as in the list. A few
   take the code that fits their name (SPMU → SUPPLY, ERDO → EDO,
   QAO → QAMO — none holds records; if one did, they follow the code).
   The Budget Unit is the one the Budget accounts are in (BUDGET-OFC); the
   second "Budget Unit" row (BUDGET) is a duplicate and is deactivated
   (hidden from Registration, kept, and can be reactivated in Departments).
   Offices not in the list (BAC, IGP, LAB, OSS, RDO, RECORDS, SWK) stay —
   v85: except RDO, OSS, SWK and BAC, whose work offices in the list do (ao_retire());
   v86: and LAB, IGP and RECORDS, which are not in the list either.

   Applied once, on ASCOT's database only (config/academic-units.php).
   =============================================================== */
require_once __DIR__ . '/academic-units.php';

const AO_MIGRATION_KEY = 'admin_units_v82';

/* code => [name, group, order, old code that becomes this one] — in the order of the list */
const AO_OFFICES = [
    'PRES'       => ['Office of the President', 'Executive Offices', 10, null],
    'OVPAPF'     => ['Office of the Vice President for Administration, Planning, and Finance', 'Executive Offices', 30, null],
    'OVPAA'      => ['Office of the Vice President for Academic Affairs', 'Executive Offices', 20, null],
    'CAO'        => ['Chief Administrative Office', 'Support Units', 400, null],
    'SAO'        => ['Supervising Administrative Office', 'Support Units', 405, null],
    'BOARDSEC'   => ['Office of Board Secretary', 'Executive Offices', 40, null],
    'IEAS'       => ['International, External, & Alumni Services Office', 'Special Programs', 500, null],
    'LEGAL'      => ['Legal Affairs Unit', 'Support Units', 360, null],
    'REG'        => ['Office of the College Registrar', 'Support Units', 380, null],
    'BUDGET-OFC' => ['Budget Unit', 'Support Units', 71, null],
    'HRMU'       => ['Human Resource Management Unit', 'Support Units', 240, null],
    'GSU'        => ['General Services Unit', 'Support Units', 210, null],
    'PLAN'       => ['Planning Unit', 'Support Units', 320, null],
    'IAU'        => ['Internal Audit Unit', 'Executive Offices', 50, null],
    'ACCTG'      => ['Accounting Unit', 'Support Units', 270, null],
    'CASH'       => ['Cash Unit', 'Support Units', 250, null],
    'PU'         => ['Procurement Unit', 'Support Units', 220, null],
    'PMU'        => ['Project Management Unit', 'Support Units', 300, null],
    'INFO'       => ['Information Unit', 'Support Units', 290, null],
    'ICTU'       => ['Information and Communication Technology Unit', 'Support Units', 280, null],
    'SUPPLY'     => ['Supply Unit', 'Support Units', 310, 'SPMU'],
    'CCC'        => ['Casiguran Campus Coordinator', 'Support Units', 395, null],
    'GCO'        => ['Guidance and Counseling Office', 'Special Programs', 485, null],
    'HSU'        => ['Health Services Unit', 'Support Units', 350, null],
    'LIB'        => ['College Library', 'Support Units', 340, null],
    'EDO'        => ['Extension and Development Office', 'Special Programs', 410, 'ERDO'],
    'QAMO'       => ['Quality Assurance Management Office', 'Support Units', 330, 'QAO'],
    'SCA'        => ['Socio-Cultural Affairs Unit', 'Special Programs', 480, null],
    'NSTP'       => ['National Service Training Program Office', 'Special Programs', 460, null],
    'GAD'        => ['Gender and Development Office', 'Special Programs', 450, null],
    'SDO'        => ['Sports Development Office', 'Special Programs', 475, null],
    'MPO'        => ['Motorpool Office', 'Support Units', 215, null],
    'DPO'        => ['Data Protection Office', 'Support Units', 285, null],
    'DRRMO'      => ['Disaster Risk Reduction Management Office', 'Special Programs', 440, null],
    'CERRDS'     => ['Center for Educational Research and Resources Development Services Office', 'Special Programs', 425, null],
    'TEC'        => ['Testing and Evaluation Center', 'Special Programs', 487, null],
];
/* the duplicate of an office in the list (deactivated, not deleted) */
const AO_DUPLICATES = ['BUDGET' => 'BUDGET-OFC'];

/** Applies the list once (ASCOT's database only). */
function ao_migrate(bool $force = false): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $pdo = db();
    try {
        $s = $pdo->prepare('SELECT value FROM settings WHERE key_name = ?');
        $s->execute([AO_MIGRATION_KEY]);
        if ((string)$s->fetchColumn() === '1' && !$force) return;
    } catch (Throwable $t) {}
    if (!au_is_ascot()) return;

    foreach (AO_OFFICES as $code => [$name, $group, $order, $old]) {
        try {
            // the old code becomes the new one (its records follow), unless the new one is already there
            if ($old !== null) {
                $s = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE code = ?');
                $s->execute([$code]);
                if ((int)$s->fetchColumn() === 0) au_rename_code($old, $code);
            }
            $s = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE code = ?');
            $s->execute([$code]);
            if ((int)$s->fetchColumn() === 0) {
                $pdo->prepare('INSERT INTO departments (code, name, dept_group, display_order, status) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$code, $name, $group, $order, 'active']);
            } else {
                // existing: its name as in the list (its group, order and status are left as they are)
                $pdo->prepare('UPDATE departments SET name = ? WHERE code = ?')->execute([$name, $code]);
            }
        } catch (Throwable $t) { error_log('ao_migrate ' . $code . ': ' . $t->getMessage()); }
    }
    // the duplicate: hidden from Registration, nothing deleted
    foreach (AO_DUPLICATES as $dup => $keep) {
        try {
            $pdo->prepare("UPDATE departments SET status = 'inactive', deactivated_at = NOW(), deactivated_by = ?
                            WHERE code = ? AND COALESCE(status, 'active') = 'active'")
                ->execute(['(system) same as ' . $keep, $dup]);
        } catch (Throwable $t) {}
    }
    try {
        $pdo->prepare('INSERT INTO activity_logs (username, full_name, role, action, entity_type, entity_ref, details, ip_address)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['(system)', '', '', 'Applied the list of administrative units', 'department', '',
                       'Named the offices as in ASCOT\'s list of Administrative Units, added the missing ones, and deactivated the duplicate Budget Unit (BUDGET).', '']);
        $pdo->prepare('DELETE FROM settings WHERE key_name = ?')->execute([AO_MIGRATION_KEY]);
        $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)')->execute([AO_MIGRATION_KEY, '1']);
    } catch (Throwable $t) {}
    au_cache_reset();
}

/**
 * How many departments are registered — exactly the list on Register
 * Account: every active school and office (a school's programs and majors
 * are chosen under it, not counted on their own).
 */
function ao_registered_count(): int {
    $n = 0;
    foreach (au_all() as $r) {
        if (($r['status'] ?? 'active') === 'inactive') continue;
        if (!empty($r['parent_code'])) continue;
        $n++;
    }
    return $n;
}

/**
 * How many prepare and submit their own documents: every registered office
 * and school, except that a school with programs counts as its programs and
 * majors, one by one.
 */
function ao_submitting_count(): int {
    $n = 0;
    foreach (au_all() as $code => $r) {
        if (($r['status'] ?? 'active') === 'inactive') continue;
        if (!empty($r['parent_code'])) {
            $p = au_row((string)$r['parent_code']);
            if ($p && ($p['status'] ?? 'active') !== 'inactive') $n++;
            continue;
        }
        if (!au_has_units((string)$code)) $n++;
    }
    return $n;
}

/* ─── v85: offices whose work is already done by offices in the list ───
   Deactivated (hidden from Registration, kept with anything filed under
   them, and can be reactivated in Departments) — not deleted. */
const AO_RETIRED_KEY = 'admin_units_v85';
const AO_RETIRED = [
    'RDO' => 'CERRDS (Center for Educational Research and Resources Development Services Office)',
    'OSS' => 'GCO, SCA, SDO and HSU',
    'SWK' => 'SCA (Socio-Cultural Affairs Unit)',
    'BAC' => 'PU (the Procurement Unit itself)',
];

/* v86: not in ASCOT's list of Administrative Units (46 units and departments in all).
   The Superadmin can bring any of them back with Reactivate on the Departments page. */
const AO_RETIRED_86_KEY = 'admin_units_v86';
const AO_RETIRED_86 = [
    'LAB'     => 'not in ASCOT\'s list of Administrative Units',
    'IGP'     => 'not in ASCOT\'s list of Administrative Units',
    'RECORDS' => 'not in ASCOT\'s list of Administrative Units',
];

/** Deactivates the offices of v85 and v86, each list once (ASCOT's database only). */
function ao_retire(): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    if (!au_is_ascot()) return;
    ao_retire_list(AO_RETIRED_KEY, AO_RETIRED, 'covered by ');
    ao_retire_list(AO_RETIRED_86_KEY, AO_RETIRED_86, '');
    au_cache_reset();
}

/** One list: [code => why]; skipped once its key is set. */
function ao_retire_list(string $key, array $list, string $prefix): void {
    $pdo = db();
    try {
        $s = $pdo->prepare('SELECT value FROM settings WHERE key_name = ?');
        $s->execute([$key]);
        if ((string)$s->fetchColumn() === '1') return;
    } catch (Throwable $t) {}
    $done = [];
    foreach ($list as $code => $why) {
        try {
            $u = $pdo->prepare("UPDATE departments SET status = 'inactive', deactivated_at = NOW(), deactivated_by = ?
                                 WHERE code = ? AND COALESCE(status, 'active') = 'active'");
            $u->execute(['(system) ' . $prefix . $why, $code]);
            if ($u->rowCount() > 0) $done[] = "$code ($prefix$why)";
        } catch (Throwable $t) {}
    }
    try {
        if ($done) {
            $pdo->prepare('INSERT INTO activity_logs (username, full_name, role, action, entity_type, entity_ref, details, ip_address)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(['(system)', '', '', $prefix !== '' ? 'Deactivated offices covered by others' : 'Deactivated offices not in the list', 'department', '',
                           'Deactivated (kept, hidden from Register Account; Reactivate on the Departments page): ' . implode('; ', $done) . '.', '']);
        }
        $pdo->prepare('DELETE FROM settings WHERE key_name = ?')->execute([$key]);
        $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?)')->execute([$key, '1']);
    } catch (Throwable $t) {}
}
