<?php
/* ═══════════════════════════════════════════════════════════════
   Up to 3 sets of documents a year for an office (v83).

   An administrative unit or office (any department that is not a school)
   prepares its documents (PPMP, APP-CSE, Non-PS list, Annex, Market
   Scoping) as Set 1 — the office itself, as before. When it needs more, it
   adds Set 2, then Set 3 (at most 3). Each set is its own row in
   `departments`, under the office:
       code     PU-S2, BUDGET-OFC-S3 …    parent_code  the office
       name     "Procurement Unit – Set 2" set_no       2 / 3
   so each set has its own documents, folders and history, and every page
   shows which set it is.

   • Each set is submitted on its own, by whoever submits for the office
     (its Department Admin), like any department.
   • The office keeps ONE allotment from the Budget Unit: what all its sets
     spend together may not go over it (config/allot-shares.php,
     config/companions.php).
   • Only the office's submitter adds a set; a set that holds nothing yet
     can be removed again.
   =============================================================== */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/academic-units.php';

const OS_MAX = 3;

/** May this department have sets? Every active office — not a school, and not a program or a set itself. */
function os_eligible(?string $code): bool {
    $r = au_row($code);
    if (!$r || !empty($r['parent_code'])) return false;
    if (($r['status'] ?? 'active') === 'inactive') return false;
    if ((string)($r['dept_group'] ?? '') === AU_GROUP) return false;   // a school (Schools & Colleges)
    return !au_has_units((string)$r['code']);
}

/** Is this the 2nd / 3rd set of an office? */
function os_is_set(?string $code): bool {
    $r = au_row($code);
    return $r !== null && !empty($r['parent_code']) && !empty($r['set_no']);
}

/** The 2nd and 3rd sets of an office: [code => row], in order (inactive ones left out). */
function os_sets_of(string $office): array {
    $out = [];
    foreach (au_all() as $code => $r) {
        if (!empty($r['set_no']) && strcasecmp((string)($r['parent_code'] ?? ''), $office) === 0
            && ($r['status'] ?? 'active') !== 'inactive') $out[$code] = $r;
    }
    uasort($out, fn($a, $b) => (int)$a['set_no'] <=> (int)$b['set_no']);
    return $out;
}

function os_has_sets(string $office): bool { return (bool)os_sets_of($office); }

/** The office and its sets ([PU, PU-S2, PU-S3]) — or just the code, for a department without sets. */
function os_family(?string $code): array {
    $code = (string)$code;
    if ($code === '') return [];
    $office = os_is_set($code) ? au_school_of($code) : $code;
    $r = au_row($office);
    $office = (string)($r['code'] ?? $office);
    $sets = os_sets_of($office);
    return $sets ? array_merge([$office], array_keys($sets)) : [$office];
}

/** Does this department share its office's allotment with other sets? */
function os_pooled(?string $code): bool { return count(os_family($code)) > 1; }

/** The next set's number (2 or 3), or 0 when the office has all 3. */
function os_next_no(string $office): int {
    $used = [1 => true];
    foreach (au_all() as $r) {
        if (!empty($r['set_no']) && strcasecmp((string)($r['parent_code'] ?? ''), $office) === 0) $used[(int)$r['set_no']] = true;
    }
    for ($n = 2; $n <= OS_MAX; $n++) if (empty($used[$n])) return $n;
    return 0;
}

/** Adds the next set to an office. Returns [ok, message, code]. */
function os_add(string $office, array $u): array {
    if (!os_eligible($office)) return [false, 'Only an office can have sets.', ''];
    $row = au_row($office);
    $office = (string)$row['code'];
    // a set removed earlier (inactive) comes back first
    foreach (au_all() as $code => $r) {
        if (!empty($r['set_no']) && strcasecmp((string)($r['parent_code'] ?? ''), $office) === 0 && ($r['status'] ?? '') === 'inactive'
            && count(os_family($office)) < OS_MAX) {
            try {
                db()->prepare("UPDATE departments SET status = 'active', deactivated_at = NULL, deactivated_by = NULL WHERE code = ?")->execute([$code]);
            } catch (Throwable $t) { return [false, 'The set could not be added. Please try again.', '']; }
            au_cache_reset();
            log_activity('Added a set', 'department', (string)$code, 'Set ' . (int)$r['set_no'] . ' of ' . $office . ' is back.');
            return [true, 'Set ' . (int)$r['set_no'] . ' of ' . $office . ' added.', (string)$code];
        }
    }
    $n = os_next_no($office);
    if ($n === 0 || count(os_family($office)) >= OS_MAX) return [false, "$office already has " . OS_MAX . ' sets — the most an office can have.', ''];
    $code = $office . '-S' . $n;
    if (strlen($code) > 20) $code = substr($office, 0, 17) . '-S' . $n;
    try {
        db()->prepare('INSERT INTO departments (code, name, dept_group, display_order, status, parent_code, set_no) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$code, $row['name'] . ' – Set ' . $n, $row['dept_group'], (int)$row['display_order'], 'active', $office, $n]);
    } catch (Throwable $t) { return [false, 'The set could not be added. Please try again.', '']; }
    au_cache_reset();
    log_activity('Added a set', 'department', $code, "Set $n of $office ({$row['name']}): its own PPMP, APP-CSE, Non-PS list, Annex and Market Scoping.");
    return [true, "Set $n of $office added. Choose it under “Working on” to prepare its documents.", $code];
}

/** How many records are filed under a code (every table with a dept_code). */
function os_records(string $code): int {
    $n = 0;
    foreach (au_dept_tables() as $t) {
        if ($t === 'departments') continue;
        try {
            $s = db()->prepare("SELECT COUNT(*) FROM `$t` WHERE dept_code = ?");
            $s->execute([$code]);
            $n += (int)$s->fetchColumn();
        } catch (Throwable $e) {}
    }
    return $n;
}

/** Removes a set that holds nothing yet. Returns [ok, message]. */
function os_remove(string $code): array {
    if (!os_is_set($code)) return [false, 'That is not a set of an office.'];
    $r = au_row($code);
    $code = (string)$r['code'];
    $held = os_records($code);
    if ($held > 0) return [false, 'Set ' . (int)$r['set_no'] . " already holds $held record(s) — its documents — so it stays."];
    try { db()->prepare('DELETE FROM departments WHERE code = ?')->execute([$code]); }
    catch (Throwable $t) { return [false, 'The set could not be removed.']; }
    au_cache_reset();
    log_activity('Removed a set', 'department', $code, 'Set ' . (int)$r['set_no'] . ' of ' . $r['parent_code'] . ' held nothing and was removed.');
    return [true, 'Set ' . (int)$r['set_no'] . ' removed.'];
}

/* ─── v90: an office's sets live in the office's ONE folder ─────────── */

/** The folder a document belongs in: its office for a set (PU-S2 → PU), itself otherwise. */
function os_folder_of(?string $code): string {
    $code = (string)$code;
    return os_is_set($code) ? au_school_of($code) : $code;
}

/** Which set a document is, for its title in the folder: "Set 2" — or '' for an office without sets. */
function os_set_label(?string $code): string {
    $code = (string)$code;
    if (os_is_set($code)) return 'Set ' . (int)(au_row($code)['set_no'] ?? 2);
    return os_has_sets($code) ? 'Set 1' : '';
}

/** Leaves an office's 2nd / 3rd sets out of a list of departments (they are inside the office's folder). */
function os_without_sets(array $rows, string $key = 'code'): array {
    return array_values(array_filter($rows, fn($r) => !os_is_set((string)($r[$key] ?? ''))));
}

/* ─── v93: on the PPMP, a set is its office (End-User) and its number (PPMP No.) ─── */

/** "End-User or Implementing Unit": the office's own name for a set; the department's name otherwise. */
function os_unit_name(?string $code, string $fallback = ''): string {
    $code = (string)$code;
    $r = au_row(os_is_set($code) ? os_folder_of($code) : $code);
    return (string)($r['name'] ?? ($fallback !== '' ? $fallback : $code));
}

/** "PPMP No.": the number typed, then the set's number for an office with sets (2027-015-2). */
function os_ppmp_no(?string $typed, ?string $code): string {
    $typed = trim((string)$typed);
    $code  = (string)$code;
    $n = os_is_set($code) ? (int)(au_row($code)['set_no'] ?? 2) : (os_has_sets($code) ? 1 : 0);
    if ($n === 0) return $typed;
    return $typed !== '' ? $typed . '-' . $n : (string)$n;
}

/* ─── v99: the sets are kept from the top of the PPMP ("Working on" · ＋ Add Set · Remove this set) ─── */

/** Has this department's PPMP of the year been submitted (now, or before it was sent back)? */
function os_ppmp_sent(string $code, int $fy): bool {
    try {
        $s = db()->prepare('SELECT status, submitted_at FROM ppmps WHERE dept_code = ? AND fy = ? ORDER BY id DESC');
        $s->execute([$code, $fy]);
        foreach ($s->fetchAll() as $r) {
            if ((string)$r['status'] !== 'draft' || !empty($r['submitted_at'])) return true;
        }
    } catch (Throwable $t) {}
    return false;
}

/**
 * May a set be added to the office now? Only once the office's last set has
 * been submitted for the year (Set 1 submitted → Set 2; Set 2 submitted → Set 3).
 * Returns [ok, reason].
 */
function os_can_add(string $office, int $fy): array {
    if (!os_eligible($office)) return [false, 'Sets are for administrative units and offices.'];
    $fam = os_family($office);
    if (count($fam) >= OS_MAX) return [false, au_row($office)['code'] . ' already has ' . OS_MAX . ' sets — the most an office can have.'];
    $last = (string)end($fam);
    $n = os_is_set($last) ? (int)(au_row($last)['set_no'] ?? 2) : 1;
    if (!os_ppmp_sent($last, $fy)) return [false, "Submit Set $n first."];
    return [true, ''];
}

/** Has anything of this set been submitted (any year)? */
function os_set_sent(string $code): bool {
    $q = ["SELECT COUNT(*) FROM ppmps WHERE dept_code = ? AND (status <> 'draft' OR submitted_at IS NOT NULL)",
          "SELECT COUNT(*) FROM nonps_submissions WHERE dept_code = ? AND status <> 'draft'",
          "SELECT COUNT(*) FROM annex_forms WHERE dept_code = ? AND status <> 'draft'",
          "SELECT COUNT(*) FROM market_scoping WHERE dept_code = ? AND status <> 'draft'",
          "SELECT COUNT(*) FROM appcse_header WHERE dept_code = ? AND COALESCE(status, 'draft') NOT IN ('', 'draft')"];
    foreach ($q as $sql) {
        try { $s = db()->prepare($sql); $s->execute([$code]); if ((int)$s->fetchColumn() > 0) return true; } catch (Throwable $t) {}
    }
    return false;
}

/**
 * Removes Set 2 / 3 from the top of the PPMP. A set with nothing submitted:
 * one that holds nothing is deleted (as before); one with drafts is put away
 * (inactive) with its drafts kept — "＋ Add Set" brings it back with them.
 * A set with anything submitted stays. Returns [ok, message].
 */
function os_remove_set(string $code, string $who): array {
    if (!os_is_set($code)) return [false, 'Set 1 is the office itself, so it cannot be removed.'];
    $r = au_row($code);
    $code = (string)$r['code']; $n = (int)$r['set_no'];
    if (os_set_sent($code)) return [false, "Set $n has documents that were submitted, so it stays."];
    if (os_records($code) === 0) return os_remove($code);
    try {
        db()->prepare("UPDATE departments SET status = 'inactive', deactivated_at = NOW(), deactivated_by = ? WHERE code = ?")->execute([$who, $code]);
    } catch (Throwable $t) { return [false, 'The set could not be removed.']; }
    au_cache_reset();
    log_activity('Removed a set', 'department', $code, "Set $n of {$r['parent_code']} was removed; its drafts are kept and come back if a set is added again.");
    return [true, "Set $n removed. Its drafts are kept — “＋ Add Set” brings them back."];
}

/* ═══════════════════════════════════════════════════════════════
   v104: the folders (PPMP, Non-PS Supplies, Annex, Market Scoping,
   APP-CSE) — a school with programs and majors (SOED, SOE …) is one
   folder, and the documents of its programs and majors are inside it,
   each marked with its program / major ("BSED – Major in English"), the
   way an office's sets are inside the office's folder (v90).
   =============================================================== */

/** The folder a document of this code is in: a program / major → its school; a set → its office. */
function pe_folder_of(?string $code): string {
    $code = (string)$code;
    if ($code !== '' && au_is_unit($code)) return au_school_of($code);
    return os_folder_of($code);
}

/** What a document is marked with in its folder: its program / major, or its set ("Set 2"), or ''. */
function pe_folder_label(?string $code): string {
    $code = (string)$code;
    if ($code !== '' && au_is_unit($code)) return au_label($code);
    return os_set_label($code);
}

/** The departments that have a folder: the schools themselves, not their programs and majors. */
function pe_folder_listable(array $rows, string $key = 'code'): array {
    return array_values(array_filter($rows, fn($r) => !au_is_unit((string)($r[$key] ?? ''))));
}
