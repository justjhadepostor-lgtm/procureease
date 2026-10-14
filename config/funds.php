<?php
/* ═══════════════════════════════════════════════════════════════
   Source of Funds — the one list.

   Every place that offers a fund, counts by fund, or checks a fund
   against a pool reads from here: the PPMP entry table, the Budget
   Office's Fund Sources page, the overspend check when Budget approves,
   the Procurement Unit's dashboard and the Fund Allocation report.

   Adding a fund is adding one line below. Nothing else has to be edited,
   and nothing can be offered in one place but ignored in another.

   The key is the value stored in ppmp_entries.fund and in
   fund_pools.fund_source, so it is never renamed — a PPMP filed years ago
   must keep meaning what it meant. 'label' is what a person reads and
   'sub' is the line under it. Those two can be reworded freely.
   =============================================================== */

const FUND_SOURCES = [
    'GAA'                       => ['label' => 'GAA',
                                    'sub'   => 'General Appropriations Act'],
    'Fund 164'                  => ['label' => 'Fund 164',
                                    'sub'   => 'Special Trust Fund'],
    'Income Generating Project' => ['label' => 'Income Generating Project',
                                    'sub'   => 'IGP revenues'],
    'College of Law'            => ['label' => 'College of Law',
                                    'sub'   => 'College of Law fund'],
    'Special Project'           => ['label' => 'Special Project',
                                    'sub'   => 'Special project fund'],
];

/** The stored values, in the order they should be offered. */
function fund_codes(): array {
    return array_keys(FUND_SOURCES);
}

/** What a person reads. An unknown value is shown as itself, never blanked. */
function fund_label(string $code): string {
    return FUND_SOURCES[$code]['label'] ?? $code;
}

/** The line under the label. Empty for a fund not on the list. */
function fund_sub(string $code): string {
    return FUND_SOURCES[$code]['sub'] ?? '';
}

/** A [fund => 0.0] map, for totalling without checking isset() everywhere. */
function fund_zeroes(): array {
    return array_fill_keys(fund_codes(), 0.0);
}

/** Is this a fund the system knows? Used to refuse junk on POST. */
function is_fund(string $code): bool {
    return isset(FUND_SOURCES[$code]);
}

/**
 * The Source of Funds cell in the PPMP entry table.
 *
 * A value already on file that is no longer on the list — a fund that was
 * retired, or one typed before this column became a dropdown — is kept and
 * shown as its own selected option, so opening and saving an old PPMP never
 * rewrites what a department recorded. There is no blank option: a line in
 * a PPMP is always charged to some fund, and GAA is the default.
 */
function fund_select(string $class, ?string $value, string $default = 'GAA'): string {
    $value = trim((string)$value);
    if ($value === '') $value = $default;
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $html = '<select class="cell-input ' . $esc($class) . '" onchange="markDirty()">';
    foreach (FUND_SOURCES as $code => $meta) {
        $html .= '<option value="' . $esc($code) . '"'
               . ($value === $code ? ' selected' : '') . '>'
               . $esc($meta['label']) . '</option>';
    }
    if (!isset(FUND_SOURCES[$value])) {
        $html .= '<option value="' . $esc($value) . '" selected>' . $esc($value) . '</option>';
    }
    return $html . '</select>';
}
