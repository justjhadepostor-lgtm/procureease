<?php
/* ═══════════════════════════════════════════════════════════════
   Who signs what — read from the accounts, not written into the code.

   The names on the printed documents used to be typed into the source:
   GEORGE WILSON UY on the Non-PS list, three more on the APP-CSE, and
   "Vice President for Academic Affairs" on every PPMP. When one of those
   people changes post, every document in the system still carries the old
   name until someone edits the PHP.

   These look the person up instead, from the account that actually holds
   the role today:

       the Procurement Unit head   the account with the 'admin' role
       the Budget Officer          the account with the 'budget' role
       a department's head         that department's Department Admin

   Where an office has no account in the system — the Property/Supply
   Officer, the Head of the Agency — the name is set on the Document Format
   page instead, per fiscal year, like every other wording on those forms.

   The order is always the same: a name set on the Document Format page
   wins, because the Procurement Unit put it there on purpose; otherwise
   the live account is used. So a document that needs a particular name for
   a particular year can still have one, and nothing else has to be edited
   when someone new takes over.
   =============================================================== */

/**
 * The name and position on the account holding a role, or empty strings.
 *
 * There is at most one Procurement Unit account and one Budget Unit
 * account; if a system somehow has more, the lowest id wins so the answer
 * is at least stable.
 */
function signatory_for_role(string $role): array {
    static $cache = [];
    if (array_key_exists($role, $cache)) return $cache[$role];
    $cache[$role] = ['name' => '', 'position' => ''];
    try {
        $s = db()->prepare("SELECT full_name, position FROM users
                             WHERE role = ? AND COALESCE(status, 'active') = 'active'
                          ORDER BY id LIMIT 1");
        $s->execute([$role]);
        if ($r = $s->fetch()) {
            $cache[$role] = ['name'     => trim((string)($r['full_name'] ?? '')),
                             'position' => trim((string)($r['position'] ?? ''))];
        }
    } catch (Throwable $t) { /* leave empty */ }
    return $cache[$role];
}

/** The Procurement Unit head — the account that approves at the end. */
function procurement_head(): array {
    return signatory_for_role('admin');
}

/** The Budget Officer — the account that reviews before the Procurement Unit. */
function budget_officer(): array {
    return signatory_for_role('budget');
}

/**
 * The head of a department — its Department Admin, the one who submits.
 * Falls back to empty strings for a department that has none yet.
 */
function department_head(?string $deptCode): array {
    $head = function_exists('dept_admin_of') ? dept_admin_of($deptCode) : null;
    if (!$head) return ['name' => '', 'position' => ''];
    $pos = '';
    try {
        $s = db()->prepare('SELECT position FROM users WHERE id = ?');
        $s->execute([(int)$head['id']]);
        $pos = trim((string)$s->fetchColumn());
    } catch (Throwable $t) {}
    return ['name' => trim((string)($head['full_name'] ?? '')), 'position' => $pos];
}

/**
 * The Vice President who approves this department's PPMP.
 *
 * Which one depends on the group the department sits in: an academic unit
 * answers to the Vice President for Academic Affairs, an administrative
 * one to the Vice President for Administration, Planning and Finance.
 *
 * The names live on the Document Format page, per fiscal year, so a change
 * of officer is typed once rather than into every department's PPMP. What
 * is returned here is only the starting value: the box on the PPMP stays
 * editable, and a name typed there wins.
 */
function ppmp_approver(?string $deptCode): array {
    static $cache = [];
    $key = (string)$deptCode;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $group = '';
    try {
        $s = db()->prepare('SELECT dept_group FROM departments WHERE code = ?');
        $s->execute([$key]);
        $group = trim((string)$s->fetchColumn());
    } catch (Throwable $t) {}

    // Anything that is not academic is treated as administrative, so a
    // department in a group nobody has named yet still gets a signatory.
    $academic = stripos($group, 'academ') !== false
             || stripos($group, 'school') !== false
             || stripos($group, 'college') !== false;
    $which = $academic ? 'acad' : 'admin';

    $name = trim((string)doc_fmt('ppmp', "appr_{$which}_name"));
    $pos  = trim((string)doc_fmt('ppmp', "appr_{$which}_position"));
    $cache[$key] = ['name' => $name, 'position' => $pos, 'group' => $group];
    return $cache[$key];
}

/**
 * The "Approved by" block of one PPMP, as it should read.
 *
 * The Vice President the department answers to (ppmp_approver()), unless a
 * different name was typed into the block on purpose (head_custom = 1).
 * A name that was only ever filled in by the system — including the
 * Academic VP an old backfill wrote into every PPMP — is not "on purpose",
 * so it no longer wins.
 */
function ppmp_approved_by(array $ppmp): array {
    $vp = ppmp_approver($ppmp['dept_code'] ?? null);
    if (!empty($ppmp['head_custom'])) {
        $name = trim((string)($ppmp['head_name'] ?? ''));
        $pos  = trim((string)($ppmp['head_position'] ?? ''));
        if ($name !== '' || $pos !== '') {
            return ['name' => $name !== '' ? $name : $vp['name'],
                    'position' => $pos !== '' ? $pos : $vp['position'],
                    'custom' => true, 'vp' => $vp];
        }
    }
    return ['name' => $vp['name'], 'position' => $vp['position'], 'custom' => false, 'vp' => $vp];
}

/**
 * A signatory line for a document: what the Document Format page says for
 * this fiscal year, and when that is blank, the live account.
 *
 * $fallback is ['name' => .., 'position' => ..]; $part is 'name' or
 * 'position'.
 */
function doc_signatory(string $docType, string $key, array $fallback, string $part): string {
    $set = trim((string)doc_fmt($docType, $key));
    if ($set !== '') return $set;
    return trim((string)($fallback[$part] ?? ''));
}
