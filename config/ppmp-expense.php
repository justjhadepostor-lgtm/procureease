<?php
/* ═══════════════════════════════════════════════════════════════
   The budget breakdown under the PPMP table, just before the
   signatories:

       Meals/ Snacks - 77,860
       Honoraria - 8,000
       Accommodation Services -
       Supplies & Materials - 14,140

   The PPMP format stays as it is. No column or row is added to the main
   table. Each line is worked out from the General Description and
   Objective of every row (Column 1): a row whose description names meals,
   snacks, a speaker's honorarium, a hotel, supplies … is counted under
   that line, using its Estimated Budget (Column 10). When a description
   names more than one, the one it names first decides.

   Everything stays editable on the PPMP:
     - an amount typed into a line replaces the computed one (clearing it,
       or ↺, puts the computed one back);
     - a line can be renamed, removed, or added (a line that is added is
       worked out from the words of its own name);
     - with no lines at all, the table is left off the printed PPMP.

   Stored on ppmps.expense_summary as JSON:
       [{"key":"meals","label":"Meals/ Snacks","amount":null}, …]
   amount null = computed. NULL column = the four standard lines.

   The browser uses a copy of the same rules (ppmp-edit.php); both read
   PPMP_EXPENSE_KEYWORDS and PPMP_EXPENSE_STOPWORDS from here.
   =============================================================== */

/** The four lines the printed form carries, in its order. */
const PPMP_EXPENSE_DEFAULT_LINES = [
    ['key' => 'meals',         'label' => 'Meals/ Snacks'],
    ['key' => 'honoraria',     'label' => 'Honoraria'],
    ['key' => 'accommodation', 'label' => 'Accommodation Services'],
    ['key' => 'supplies',      'label' => 'Supplies & Materials'],
];

/** Words in a General Description that put its row under a line. A word
 *  also matches its plural (+s / +es). */
const PPMP_EXPENSE_KEYWORDS = [
    'meals' => ['meal', 'snack', 'food', 'lunch', 'breakfast', 'dinner', 'supper',
                'catering', 'caterer', 'merienda', 'refreshment', 'beverage', 'coffee break'],
    'honoraria' => ['honorarium', 'honoraria', 'resource person', 'resource speaker', 'speaker',
                    'lecturer', 'facilitator', 'talent fee', 'panelist', 'judge'],
    'accommodation' => ['accommodation', 'accomodation', 'lodging', 'hotel'],
    'supplies' => ['supply', 'supplies', 'material', 'kit', 'certificate', 'tarpaulin', 'tarp',
                   'bond paper', 'paper', 'ink', 'toner', 'folder', 'ballpen', 'envelope',
                   'marker', 'notebook', 'token'],
];

/** Words of a line's name that say nothing about what it covers. */
const PPMP_EXPENSE_STOPWORDS = ['and', 'the', 'for', 'with', 'from', 'other', 'others', 'service',
                                'services', 'expense', 'expenses', 'cost', 'costs', 'fee', 'fees',
                                'various', 'misc', 'miscellaneous', 'item', 'items'];

/** Phrases that name how something is bought, not what it is. Taken out
 *  first, so "Supply and Delivery of Meals" counts as meals. */
const PPMP_EXPENSE_LEADINS = ['supply and delivery', 'supply delivery', 'delivery and installation',
                              'procurement of', 'purchase of'];

const PPMP_EXPENSE_MAX_LINES = 20;

function ppmp_expense_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/ +/', ' ', $s));
}

/** The words that put a row under this line. */
function ppmp_expense_keywords(array $line): array {
    $kw = PPMP_EXPENSE_KEYWORDS[$line['key'] ?? ''] ?? [];
    foreach (explode(' ', ppmp_expense_norm((string)($line['label'] ?? ''))) as $w) {
        if (strlen($w) >= 4 && !in_array($w, PPMP_EXPENSE_STOPWORDS, true) && !ctype_digit($w)) $kw[] = $w;
    }
    return array_values(array_unique(array_map('ppmp_expense_norm', $kw)));
}

/** The lines of one PPMP: what was saved, or the four standard ones. */
function ppmp_expense_lines($stored): array {
    $lines = null;
    if (is_string($stored) && trim($stored) !== '') {
        $d = json_decode($stored, true);
        if (is_array($d)) $lines = $d;
    }
    if ($lines === null) {
        return array_map(fn($l) => $l + ['amount' => null], PPMP_EXPENSE_DEFAULT_LINES);
    }
    $out = [];
    foreach ($lines as $l) {
        if (!is_array($l)) continue;
        $key = (string)($l['key'] ?? 'custom');
        if (!isset(PPMP_EXPENSE_KEYWORDS[$key])) $key = 'custom';
        $amt = $l['amount'] ?? null;
        $out[] = ['key' => $key,
                  'label' => mb_substr(trim((string)($l['label'] ?? '')), 0, 150),
                  'amount' => ($amt === null || $amt === '') ? null : round((float)$amt, 2)];
        if (count($out) >= PPMP_EXPENSE_MAX_LINES) break;
    }
    return $out;
}

/** Which line a General Description belongs to (its index), or -1. */
function ppmp_expense_match(string $desc, array $lines): int {
    $t = ' ' . ppmp_expense_norm($desc) . ' ';
    foreach (PPMP_EXPENSE_LEADINS as $p) $t = str_replace(' ' . $p . ' ', ' ', $t);
    $t = trim(preg_replace('/ +/', ' ', $t));
    if ($t === '') return -1;
    $best = -1; $bestPos = PHP_INT_MAX;
    foreach ($lines as $i => $line) {
        foreach (ppmp_expense_keywords($line) as $kw) {
            if ($kw === '') continue;
            if (preg_match('/(?:^| )' . preg_quote($kw, '/') . '(?:s|es)?(?= |$)/', $t, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + (($m[0][0][0] ?? '') === ' ' ? 1 : 0);
                if ($pos < $bestPos) { $bestPos = $pos; $best = $i; }
            }
        }
    }
    return $best;
}

/**
 * The breakdown of one PPMP.
 *   lines     each line with 'auto' (computed), 'amount' (what shows) and 'manual'
 *   total     the TOTAL BUDGET (sum of Column 10)
 *   sum       what the lines add up to
 *   unmatched rows whose description named none of the lines: [row no., description, amount]
 *   matches   the TOTAL BUDGET is what the lines add up to
 */
function ppmp_expense_compute(array $entries, $stored): array {
    $lines = ppmp_expense_lines($stored);
    $auto = array_fill(0, count($lines), 0.0);
    $total = 0.0; $unmatched = [];
    foreach (array_values($entries) as $n => $e) {
        $amt = (float)($e['amount'] ?? 0);
        $total += $amt;
        $i = ppmp_expense_match((string)($e['description'] ?? ''), $lines);
        if ($i >= 0) $auto[$i] += $amt;
        elseif (abs($amt) >= 0.005) $unmatched[] = [$n + 1, (string)($e['description'] ?? ''), $amt];
    }
    $out = []; $sum = 0.0;
    foreach ($lines as $i => $l) {
        $a = round($auto[$i], 2);
        $shown = $l['amount'] === null ? $a : (float)$l['amount'];
        $sum += $shown;
        $out[] = $l + ['auto' => $a, 'shown' => $shown, 'manual' => $l['amount'] !== null];
    }
    $total = round($total, 2); $sum = round($sum, 2);
    return ['lines' => $out, 'total' => $total, 'sum' => $sum, 'unmatched' => $unmatched,
            'matches' => abs($total - $sum) < 0.005];
}

/** 77860 → "77,860"; 77860.5 → "77,860.50"; 0 → "" (the form leaves it blank). */
function ppmp_expense_fmt(float $v): string {
    if (abs($v) < 0.005) return '';
    return abs($v - round($v)) < 0.005 ? number_format(round($v)) : number_format($v, 2);
}

/** What the editor sent, ready to store (JSON), or null to leave it as it is. */
function ppmp_expense_clean($lines): ?string {
    if (!is_array($lines)) return null;
    $out = [];
    foreach ($lines as $l) {
        if (!is_array($l)) continue;
        $key = (string)($l['key'] ?? 'custom');
        if (!isset(PPMP_EXPENSE_KEYWORDS[$key])) $key = 'custom';
        $amt = $l['amount'] ?? null;
        if ($amt !== null && $amt !== '') {
            $amt = str_replace([',', '₱', ' '], '', (string)$amt);
            $amt = is_numeric($amt) ? round(max(0, (float)$amt), 2) : null;
        } else {
            $amt = null;
        }
        $out[] = ['key' => $key, 'label' => mb_substr(trim((string)($l['label'] ?? '')), 0, 150), 'amount' => $amt];
        if (count($out) >= PPMP_EXPENSE_MAX_LINES) break;
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/** The table as it is printed (print page and file view). Empty when there are no lines. */
function ppmp_expense_table_html(array $bd, string $style = ''): string {
    if (!$bd['lines']) return '';
    // Under the Delivery … Supporting Documents columns, where the form has it.
    $h = '<table class="xb-table" style="border-collapse: collapse; width: 32%; min-width: 240px; '
       . 'margin: 6px 0 0 max(0px, min(53%, calc(100% - 250px))); '
       . 'font-family: Arial, sans-serif; font-size: 11px; font-weight: 700; ' . $style . '"><tbody>';
    foreach ($bd['lines'] as $l) {
        $h .= '<tr><td style="border: 1px solid #000; padding: 3px 6px; background: #fff; color: #000; text-align: left;">'
            . htmlspecialchars($l['label'], ENT_QUOTES, 'UTF-8') . ' - ' . ppmp_expense_fmt((float)$l['shown'])
            . '</td></tr>';
    }
    return $h . '</tbody></table>';
}
