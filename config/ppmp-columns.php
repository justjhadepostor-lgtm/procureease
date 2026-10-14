<?php
/* ═══════════════════════════════════════════════════════════════
   v94: the columns of the PPMP table, kept per fiscal year on the
   Document Format page (key "columns" of the PPMP format).

   The twelve columns of the form are built in. The Superadmin can
   take a built-in column off the form (except the description and the
   estimated budget, which the totals and the budget checks are made
   from) and add columns of their own under any of the three headings,
   or with a heading of their own. A department types into an added
   column like any other; what it types is kept on the line
   (ppmp_entries.extra), so taking a column off never loses anything.

   Stored as JSON: {"off": ["pre", …], "custom": [{"id": "x…", "label": "…", "group": "details"}, …]}
   A year with nothing stored has the built-in twelve. Like every other
   format value it belongs to its year: a change applies to the active
   year, and earlier years keep the columns they were filed under.
   =============================================================== */
require_once __DIR__ . '/doc-format.php';

/** The built-in columns, in their order: id => [heading key, group, entry field]. */
const PPMP_COLS = [
    'desc'    => ['col_desc',    'details',  'description'],
    'type'    => ['col_type',    'details',  'proj_type'],
    'qty'     => ['col_qty',     'details',  'quantity'],
    'mode'    => ['col_mode',    'details',  'mode'],
    'pre'     => ['col_pre',     'details',  'pre_conference'],
    'start'   => ['col_start',   'timeline', 'activity_start'],
    'end'     => ['col_end',     'timeline', 'activity_end'],
    'deliv'   => ['col_deliv',   'timeline', 'delivery'],
    'fund'    => ['col_fund',    'funding',  'fund'],
    'amount'  => ['col_amount',  'funding',  'amount'],
    'docs'    => ['col_docs',    '',         'supporting_docs'],
    'remarks' => ['col_remarks', '',         'remarks'],
];

/** These stay on the form: the totals and the budget checks are made from them. */
const PPMP_COLS_REQUIRED = ['desc', 'amount'];

/** The three headings over the columns: group => format key. '' = a column with a heading of its own. */
const PPMP_COL_GROUPS = ['details' => 'grp_details', 'timeline' => 'grp_timeline', 'funding' => 'grp_funding'];

const PPMP_COLS_MAX_CUSTOM = 10;

/** The stored layout of a year: ['off' => [ids], 'custom' => [[id, label, group], …]]. */
function ppmp_layout(?int $fy = null): array {
    $raw = trim(doc_fmt_raw('ppmp', 'columns', $fy ?? doc_fmt_year()));
    $j = $raw !== '' ? json_decode($raw, true) : null;
    $off = [];
    foreach ((array)($j['off'] ?? []) as $id) {
        if (isset(PPMP_COLS[$id]) && !in_array($id, PPMP_COLS_REQUIRED, true)) $off[] = (string)$id;
    }
    $custom = [];
    foreach ((array)($j['custom'] ?? []) as $c) {
        $id = (string)($c['id'] ?? '');
        $label = trim((string)($c['label'] ?? ''));
        $group = (string)($c['group'] ?? '');
        if (!preg_match('/^x[a-z0-9]{1,16}$/', $id) || $label === '') continue;
        if ($group !== '' && !isset(PPMP_COL_GROUPS[$group])) $group = '';
        $custom[] = ['id' => $id, 'label' => $label, 'group' => $group];
    }
    return ['off' => array_values(array_unique($off)), 'custom' => $custom];
}

/** The layout as it is stored. */
function ppmp_layout_json(array $layout): string {
    return json_encode(['off' => array_values($layout['off']), 'custom' => array_values($layout['custom'])], JSON_UNESCAPED_UNICODE);
}

/**
 * The columns of a year's PPMP, in order:
 *   [['id', 'label', 'group', 'builtin' => bool, 'field' => entry field or '', 'on' => bool], …]
 * Each heading's own columns, its added columns after them; then the columns
 * with headings of their own (supporting documents, remarks, then added ones).
 * $all: the columns taken off the form too (marked 'on' => false).
 */
function ppmp_cols(?int $fy = null, bool $all = false): array {
    $fy = $fy ?? doc_fmt_year();
    $L = ppmp_layout($fy);
    $byGroup = [];
    foreach (PPMP_COLS as $id => [$key, $group, $field]) {
        $byGroup[$group][] = ['id' => $id, 'label' => doc_fmt('ppmp', $key, $fy), 'group' => $group, 'builtin' => true,
                              'field' => $field, 'on' => !in_array($id, $L['off'], true)];
    }
    foreach ($L['custom'] as $c) {
        $byGroup[$c['group']][] = ['id' => $c['id'], 'label' => $c['label'], 'group' => $c['group'], 'builtin' => false,
                                   'field' => '', 'on' => true];
    }
    $out = [];
    foreach (array_merge(array_keys(PPMP_COL_GROUPS), ['']) as $g) {
        foreach ($byGroup[$g] ?? [] as $c) if ($all || $c['on']) $out[] = $c;
    }
    return $out;
}

/**
 * The two heading rows over a list of columns:
 *   ['top' => [['label' => heading, 'span' => n] | ['col' => column]], 'second' => [columns under a heading]]
 * A column with a heading of its own takes both rows (rowspan 2).
 */
function ppmp_cols_header(array $cols, ?int $fy = null): array {
    $top = []; $second = [];
    foreach ($cols as $c) {
        if ($c['group'] === '') { $top[] = ['col' => $c]; continue; }
        $n = count($top);
        if ($n && isset($top[$n - 1]['group']) && $top[$n - 1]['group'] === $c['group']) $top[$n - 1]['span']++;
        else $top[] = ['group' => $c['group'], 'label' => doc_fmt('ppmp', PPMP_COL_GROUPS[$c['group']], $fy), 'span' => 1];
        $second[] = $c;
    }
    return ['top' => $top, 'second' => $second];
}

/** How many columns come before / after the estimated budget (for the TOTAL row). */
function ppmp_cols_around_amount(array $cols): array {
    $before = 0; $after = 0; $seen = false;
    foreach ($cols as $c) {
        if ($c['id'] === 'amount') { $seen = true; continue; }
        if ($seen) $after++; else $before++;
    }
    return [$before, $after];
}

/** What a line holds in the added columns: [column id => text]. */
function ppmp_entry_extra($entry): array {
    $v = is_array($entry) ? ($entry['extra'] ?? '') : '';
    $j = is_string($v) && $v !== '' ? json_decode($v, true) : null;
    $out = [];
    foreach ((array)$j as $k => $x) if (is_string($k) && preg_match('/^x[a-z0-9]{1,16}$/', $k)) $out[$k] = (string)$x;
    return $out;
}

/** Adds ppmp_entries.extra once (what the added columns hold). */
function ppmp_extra_column(): void {
    static $done = false;
    if ($done) return;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        $have = db()->query('SHOW COLUMNS FROM ppmp_entries')->fetchAll(PDO::FETCH_COLUMN);
        if ($have && !in_array('extra', $have, true)) db()->exec('ALTER TABLE ppmp_entries ADD COLUMN extra TEXT NULL');
    } catch (Throwable $t) {}
}

/** A new id for an added column. */
function ppmp_col_new_id(): string {
    return 'x' . substr(bin2hex(random_bytes(6)), 0, 10);
}
