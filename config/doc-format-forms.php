<?php
/* ═══════════════════════════════════════════════════════════════
   v104: Document Format › Export form — the form itself, as a Word file.

   The PPMP, APP-CSE, Non-PS list, Annex and Market Scoping come out the
   way the departments fill them in and print them: the same letterhead
   and logos, headings, tables, columns, totals and signatory blocks, on
   the same paper (A4 landscape, Letter, 8.5" × 13"), with the wording of
   the active fiscal year — blank, ready to be filled in.

   Every wording the Document Format page keeps sits in a Word content
   control tagged with its key (DocxW::ctl), so the file can be edited in
   Word and imported back: the import reads the controls
   (config/doc-format-io.php).
   =============================================================== */
require_once __DIR__ . '/doc-format.php';
require_once __DIR__ . '/docx-builder.php';

const FMT_FORM_MARK = 'ProcureEase Document Format';

/** What the printed form shows for a key: its wording, with {FY} and the like filled in. */
function fmt_show(string $t, string $k, int $fy): string {
    $v = doc_fmt($t, $k, $fy);
    if ($t === 'appcse' && preg_match('/^sig([123])_(name|position)$/', $k, $m)) {
        // the three signatures: a name or position not set here is the account's / the office's (includes/appcse-summary.php)
        require_once __DIR__ . '/signatories.php';
        $live = [1 => ['name' => '', 'position' => 'Property/Supply Officer'],
                 2 => budget_officer(),
                 3 => ['name' => '', 'position' => 'Head of Office/Agency']][(int)$m[1]];
        if ((int)$m[1] === 2 && ($live['position'] ?? '') === '') $live['position'] = 'Accountant / Budget Officer';
        $v = doc_signatory('appcse', $k, $live, $m[2]);
    }
    return str_replace(['{FY-1}', '{FY}'], [(string)($fy - 1), (string)$fy], $v);
}

/** The forms' builder state: which keys already have their control (each key once). */
final class FmtForm {
    public static $tagged = [];
    public static $type = '';
    public static $fy = 0;

    /** The wording of a key — in its control the first time it appears, plain after that. */
    public static function w(string $k, array $o = []): string {
        $text = fmt_show(self::$type, $k, self::$fy);
        if (isset(self::$tagged[$k])) return DocxW::r($text, $o);
        self::$tagged[$k] = true;
        $label = doc_fmt_fields(self::$type)[$k][0] ?? $k;
        return DocxW::ctl($k, $label, $text, $o);
    }

    /** A logo (left / right) as a picture run, or ''. */
    public static function logo(DocxW $d, string $side, float $w, float $h): string {
        $path = doc_fmt(self::$type, $side === 'right' ? 'logo_right' : 'logo_left', self::$fy);
        if (trim($path) === '') return '';
        return $d->img(__DIR__ . '/../' . ltrim(strtok($path, '?'), '/'), $w, $h);
    }
}

/** The Export (bytes of the .docx). */
function fmt_form_docx(string $t, int $fy): string {
    FmtForm::$tagged = []; FmtForm::$type = $t; FmtForm::$fy = $fy;
    doc_fmt_use_year($fy);
    $d = new DocxW();
    $d->subject = FMT_FORM_MARK . ' (' . $t . ')';
    switch ($t) {
        case 'ppmp':    [$body, $page] = fmt_form_ppmp($d, $fy); break;
        case 'appcse':  [$body, $page] = fmt_form_appcse($d, $fy); break;
        case 'nonps':   [$body, $page] = fmt_form_nonps($d, $fy); break;
        case 'annex':   [$body, $page] = fmt_form_annex($d, $fy); break;
        default:        [$body, $page] = fmt_form_scoping($d, $fy); break;
    }
    return $d->build($body, $page, (DOC_FORMAT_TYPES[$t] ?? $t) . ' form — FY ' . $fy);
}

const FMT_A4_LANDSCAPE = ['w' => 16838, 'h' => 11906];
const FMT_LETTER       = ['w' => 12240, 'h' => 15840];
const FMT_LONG         = ['w' => 12240, 'h' => 18720];   // 8.5" × 13"

/* ═══════════════════ PPMP (ppmp-print.php) ═══════════════════ */
function fmt_form_ppmp(DocxW $d, int $fy): array {
    require_once __DIR__ . '/ppmp-columns.php';
    $W = FmtForm::class;
    $page = FMT_A4_LANDSCAPE + ['top' => 567, 'bottom' => 567, 'left' => 454, 'right' => 454];
    $width = $page['w'] - $page['left'] - $page['right'];
    $b = '';

    // Letterhead: seal · the four lines · the right logo
    $center = DocxW::p($W::w('rep_line', ['sz' => 8.5]), ['align' => 'center'])
            . DocxW::p($W::w('org_name', ['sz' => 13.5, 'b' => true]), ['align' => 'center'])
            . DocxW::p($W::w('address', ['sz' => 7.5, 'i' => true]), ['align' => 'center', 'after' => 120])
            . DocxW::p($W::w('doc_title', ['sz' => 10, 'b' => true]) . DocxW::r(' ', ['sz' => 10]) . DocxW::r('__________', ['sz' => 10, 'b' => true]), ['align' => 'center']);
    $b .= DocxW::table([[
        ['p' => DocxW::p($W::logo($d, 'left', 60, 60), ['align' => 'center']), 'nob' => true],
        ['p' => $center, 'nob' => true],
        ['p' => DocxW::p($W::logo($d, 'right', 60, 45), ['align' => 'center']), 'nob' => true],
    ]], [1700, $width - 3400, 1700], ['borders' => false]);

    // Indicative / Final
    $b .= DocxW::p(DocxW::r('☐ ', ['sz' => 11, 'font' => 'Segoe UI Symbol']) . DocxW::r('INDICATIVE', ['sz' => 8, 'b' => true])
                 . DocxW::r("          ☐ ", ['sz' => 11, 'font' => 'Segoe UI Symbol']) . DocxW::r('FINAL', ['sz' => 8, 'b' => true]),
                   ['align' => 'center', 'before' => 120, 'after' => 120]);

    // Fiscal Year · End-User or Implementing Unit
    $line = ['bd' => ['bottom' => 'single', 'top' => 'nil', 'left' => 'nil', 'right' => 'nil']];
    $b .= DocxW::table([
        [['t' => 'Fiscal Year:', 'o' => ['sz' => 8, 'b' => true], 'nob' => true], ['t' => (string)$fy, 'o' => ['sz' => 8]] + $line],
        [['t' => 'End-User or Implementing Unit:', 'o' => ['sz' => 8, 'b' => true], 'nob' => true], ['t' => '', 'o' => ['sz' => 8]] + $line],
    ], [2600, 7400], ['borders' => false, 'mar' => [20, 40, 20, 40]]);
    $b .= DocxW::pt('', ['sz' => 4]);

    // The table: the columns of this year (Document Format › Columns of the table)
    $cols = ppmp_cols($fy);
    $hdr  = ppmp_cols_header($cols, $fy);
    $weight = ['desc' => 3.2, 'type' => 2.0, 'qty' => 1.6, 'mode' => 1.6, 'pre' => 1.5, 'start' => 1.2, 'end' => 1.2,
               'deliv' => 1.8, 'fund' => 1.2, 'amount' => 1.7, 'docs' => 1.7, 'remarks' => 1.6];
    $sum = 0; foreach ($cols as $c) $sum += $weight[$c['id']] ?? 1.4;
    $widths = []; foreach ($cols as $c) $widths[] = (int)floor($width * ($weight[$c['id']] ?? 1.4) / $sum);
    $th = ['sz' => 6.5, 'b' => true];
    $colText = function (array $c) use ($W, $th): string {
        return $c['builtin'] ? $W::w('col_' . $c['id'], $th) : DocxW::r($c['label'], $th);
    };
    $r1 = []; $r2 = []; $r3 = [];
    foreach ($hdr['top'] as $top) {
        if (isset($top['col'])) $r1[] = ['p' => DocxW::p($colText($top['col']), ['align' => 'center']), 'vm' => 'restart'];
        else $r1[] = ['p' => DocxW::p($W::w(PPMP_COL_GROUPS[$top['group']], ['sz' => 7, 'b' => true]), ['align' => 'center']), 'span' => (int)$top['span']];
    }
    foreach ($cols as $c) {
        $r2[] = $c['group'] === '' ? ['p' => DocxW::p(''), 'vm' => 'cont'] : ['p' => DocxW::p($colText($c), ['align' => 'center'])];
    }
    foreach ($cols as $i => $c) {
        $r3[] = ['p' => DocxW::p($W::w('col_num_prefix', ['sz' => 6.5, 'b' => true, 'i' => true]) . DocxW::r(' ' . ($i + 1), ['sz' => 6.5, 'b' => true, 'i' => true]), ['align' => 'center'])];
    }
    $rows = [['cells' => $r1, 'header' => true], ['cells' => $r2, 'header' => true], ['cells' => $r3, 'header' => true]];
    for ($k = 0; $k < 8; $k++) {
        $cells = []; foreach ($cols as $c) $cells[] = ['t' => '', 'o' => ['sz' => 7.5]];
        $rows[] = ['cells' => $cells, 'h' => 340];
    }
    [$before, $after] = ppmp_cols_around_amount($cols);
    $tot = [];
    if ($before > 0) $tot[] = ['t' => 'TOTAL BUDGET:', 'o' => ['sz' => 7.5, 'b' => true], 'po' => ['align' => 'right'], 'span' => $before];
    $tot[] = ['t' => '', 'o' => ['sz' => 7.5, 'b' => true], 'po' => ['align' => 'right']];
    if ($after > 0) $tot[] = ['t' => '', 'span' => $after];
    $rows[] = ['cells' => $tot, 'h' => 300];
    $b .= DocxW::table($rows, $widths, ['mar' => [30, 50, 30, 50]]);

    // Signatories
    $sigLine = DocxW::p('', ['border' => ['bottom'], 'left' => 900, 'right' => 900, 'before' => 600]);
    $cap = fn($s, $o = []) => DocxW::p(DocxW::r($s, ['sz' => 7, 'i' => true] + $o), ['align' => 'center']);
    $left  = DocxW::p($W::w('sig_prepared', ['sz' => 8, 'b' => true]), ['before' => 300])
           . $sigLine
           . $cap('Signature over Printed Name') . DocxW::p('', ['sz' => 7]) . $cap('[Head of the End-User or Implementing Unit]')
           . DocxW::p(DocxW::r('Date: ', ['sz' => 8]) . DocxW::r('______________________', ['sz' => 8]), ['before' => 200]);
    $right = DocxW::p($W::w('sig_approved', ['sz' => 8, 'b' => true]), ['before' => 300])
           . $sigLine
           . $cap('Signature over Printed Name') . DocxW::p('', ['sz' => 7]) . DocxW::p('', ['sz' => 7])
           . DocxW::p(DocxW::r('Date: ', ['sz' => 8]) . DocxW::r('______________________', ['sz' => 8]), ['before' => 200]);
    $b .= DocxW::table([[['p' => $left, 'nob' => true, 'valign' => 'top'], ['p' => $right, 'nob' => true, 'valign' => 'top']]],
                       [(int)($width / 2), $width - (int)($width / 2)], ['borders' => false]);
    return [$b, $page];
}

/* ═══════════════════ APP-CSE (includes/appcse-form.php) ═══════════════════ */
function fmt_form_appcse(DocxW $d, int $fy): array {
    require_once __DIR__ . '/appcse-items.php';
    $W = FmtForm::class;
    $page = FMT_A4_LANDSCAPE + ['top' => 454, 'bottom' => 454, 'left' => 340, 'right' => 340];
    $width = $page['w'] - $page['left'] - $page['right'];
    $b = '';

    $b .= DocxW::p($W::w('form_title', ['sz' => 9, 'b' => true]), ['align' => 'center', 'before' => 60, 'after' => 80]);
    $b .= DocxW::p($W::w('intro_label', ['sz' => 6.5, 'b' => true]) . DocxW::r(' ', ['sz' => 6.5]) . $W::w('intro_body', ['sz' => 6.5]),
                   ['align' => 'center', 'left' => 1600, 'right' => 1600, 'after' => 120, 'line' => 276]);
    $b .= DocxW::p($W::w('reminders_label', ['sz' => 6, 'i' => true]), ['before' => 120, 'after' => 40]);
    // the reminders, numbered as on the form — one control around all of them
    $rem = '';
    foreach (preg_split('/\r?\n/', fmt_show('appcse', 'reminders_body', $fy)) as $line) {
        $line = trim($line); if ($line === '') continue;
        if (preg_match('/^(\d+(?:\.\d+)?)\.?\s+(.*)$/s', $line, $m)) $rem .= DocxW::p(DocxW::r($m[1] . "\t" . $m[2], ['sz' => 6, 'b' => true]), ['left' => 400, 'hanging' => 400, 'tabs' => [400], 'after' => 20]);
        else $rem .= DocxW::p(DocxW::r($line, ['sz' => 6, 'b' => true]), ['left' => 400, 'after' => 20]);
    }
    FmtForm::$tagged['reminders_body'] = true;
    $b .= DocxW::ctlBlock('reminders_body', doc_fmt_fields('appcse')['reminders_body'][0] ?? 'Reminders text', $rem);
    $b .= DocxW::p($W::w('note_text', ['sz' => 6.5, 'i' => true]), ['before' => 120, 'after' => 120]);

    // The agency block: three columns of label + box
    $lb = fn($s) => ['t' => $s, 'o' => ['sz' => 6.5], 'po' => ['align' => 'right'], 'nob' => true];
    $bx = ['t' => '', 'o' => ['sz' => 6.5], 'fill' => 'F0F0F0', 'bd' => ['top' => 'single', 'left' => 'single', 'bottom' => 'single', 'right' => 'single'], 'bcolor' => '999999'];
    $no = ['t' => '', 'nob' => true];
    $gap = ['t' => '', 'nob' => true];
    $agency = [
        [$lb('Department/Bureau/Office:'), $bx, $gap, $lb('Agency Code/UACS:'), $bx, $gap, $lb('Contact Person:'), $bx],
        [$lb('Region:'), $bx, $gap, $lb('Organization Type:'), $bx, $gap, $lb('Position:'), $bx],
        [$lb('Address:'), $bx, $gap, $no, $no, $gap, $lb('E-mail :'), $bx],
        [$no, $no, $gap, $no, $no, $gap, $lb('Telephone/Mobile Nos:'), $bx],
    ];
    $g = (int)(($width - 3 * 2100 - 2 * 300) / 3);
    $b .= DocxW::table(array_map(fn($r) => ['cells' => $r, 'h' => 220], $agency), [2100, $g, 300, 2100, $g, 300, 2100, $g], ['borders' => false, 'mar' => [10, 40, 10, 40]]);
    $b .= DocxW::pt('', ['sz' => 5]);

    // The items table — the list of categories and items of the year (Document Format › APP-CSE list)
    $fixed = [1250, 2650, 650];
    $tail  = [800, 850, 1000];
    $mw = (int)floor(($width - array_sum($fixed) - array_sum($tail)) / 20);
    $widths = array_merge($fixed, array_fill(0, 20, $mw), $tail);
    $th = ['sz' => 5.5, 'b' => true];
    $hc = fn($k, $o = []) => ['p' => DocxW::p($W::w($k, $th), ['align' => 'center']), 'fill' => 'E8E8E8'] + $o;
    $r1 = [$hc('col_code', ['vm' => 'restart']), $hc('col_item', ['vm' => 'restart']), $hc('col_uom', ['vm' => 'restart']),
           $hc('col_months', ['span' => 20]),
           $hc('col_totqty', ['vm' => 'restart']), $hc('col_price', ['vm' => 'restart']), $hc('col_amount', ['vm' => 'restart'])];
    $cont = ['p' => DocxW::p(''), 'vm' => 'cont', 'fill' => 'E8E8E8'];
    $r2 = [$cont, $cont, $cont];
    for ($q = 1; $q <= 4; $q++) {
        for ($k = ($q - 1) * 3 + 1; $k <= $q * 3; $k++) $r2[] = $hc('mon_' . $k);
        $r2[] = $hc('qtr_' . $q); $r2[] = $hc('qamt_' . $q);
    }
    $r2 = array_merge($r2, [$cont, $cont, $cont]);
    $rows = [['cells' => $r1, 'header' => true], ['cells' => $r2, 'header' => true]];
    $grouped = appcse_catalogue_grouped(db(), $fy);
    foreach (['I'  => 'PART I. AVAILABLE AT PS-DBM (MAIN WAREHOUSE AND DEPOTS)',
              'II' => 'PART II. OTHER ITEMS NOT AVAILABLE AT PS-DBM BUT ARE REGULARLY PURCHASED FROM OTHER SOURCES'] as $pk => $pl) {
        $rows[] = [['t' => $pl, 'o' => ['sz' => 7, 'b' => true, 'color' => 'FFFFFF'], 'fill' => '133458', 'span' => 26]];
        foreach (($grouped[$pk] ?? []) as $cat => $items) {
            $rows[] = [['t' => (string)$cat, 'o' => ['sz' => 6, 'b' => true], 'fill' => 'DCE7F0', 'span' => 26]];
            foreach ($items as $it) {
                $cells = [['t' => (string)$it['code'], 'o' => ['sz' => 5, 'font' => 'Consolas']],
                          ['t' => (string)$it['description'], 'o' => ['sz' => 6]],
                          ['t' => (string)$it['unit'], 'o' => ['sz' => 6], 'po' => ['align' => 'center']]];
                for ($k = 0; $k < 20; $k++) $cells[] = ['t' => '', 'o' => ['sz' => 6]];
                $cells[] = ['t' => '', 'o' => ['sz' => 6]];
                $cells[] = ['t' => number_format((float)$it['unit_price'], 2), 'o' => ['sz' => 6], 'po' => ['align' => 'center']];
                $cells[] = ['t' => '', 'o' => ['sz' => 6]];
                $rows[] = $cells;
            }
        }
    }
    $b .= DocxW::table($rows, $widths, ['mar' => [15, 30, 15, 30]]);
    $b .= DocxW::pt('', ['sz' => 6]);

    // A to E of each Part (includes/appcse-summary.php)
    $sw = [(int)($width * 0.30), (int)($width * 0.48)]; $sw[] = $width - $sw[0] - $sw[1];
    $fill = '8EA9DB';
    foreach (['I' => ['sum_part1_title', ''], 'II' => ['sum_part2_title', 'sum_part2_note']] as $pk => [$tKey, $nKey]) {
        $hd = DocxW::p($W::w($tKey, ['sz' => 7, 'b' => true]));
        if ($nKey !== '') $hd .= DocxW::p($W::w($nKey, ['sz' => 6]), ['before' => 40]);
        $peso = ['p' => DocxW::p(DocxW::r("₱", ['sz' => 7, 'b' => true])), 'fill' => $fill];
        $rows = [[['p' => $hd, 'fill' => 'FFE699', 'span' => 3]]];
        foreach (['sum_a', 'sum_b', 'sum_c', 'sum_d'] as $k) {
            $rows[] = [['p' => DocxW::p($W::w($k, ['sz' => 7, 'b' => true])), 'fill' => $fill], ['t' => '', 'fill' => $fill], $peso];
        }
        $rows[] = [['p' => DocxW::p($W::w('sum_e', ['sz' => 7, 'b' => true])), 'fill' => $fill], ['t' => '', 'fill' => $fill, 'span' => 2]];
        $b .= DocxW::table(array_map(fn($r) => ['cells' => $r, 'h' => 300], $rows), $sw, ['mar' => [40, 80, 40, 80]]);
        $b .= DocxW::pt('', ['sz' => 6]);
    }
    $b .= DocxW::p($W::w('sum_warrant', ['sz' => 7, 'b' => true]), ['before' => 60, 'after' => 200]);

    // The three signatures
    $sig = function (int $n, bool $date) use ($W): string {
        $x  = DocxW::p($W::w('sig' . $n . '_label', ['sz' => 7, 'b' => true]), ['after' => 80]);
        $x .= DocxW::table([['cells' => [['p' => DocxW::p($W::w('sig' . $n . '_name', ['sz' => 7, 'b' => true]), ['align' => 'center']),
                                          'fill' => 'F2F2F2', 'valign' => 'bottom', 'bd' => ['top' => 'nil', 'left' => 'nil', 'right' => 'nil', 'bottom' => 'single']]], 'h' => 600]],
                            [4600], ['borders' => false]);
        $x .= DocxW::p($W::w('sig' . $n . '_position', ['sz' => 7, 'b' => true]), ['align' => 'center', 'before' => 40]);
        if ($date) $x .= DocxW::p($W::w('sig_date_label', ['sz' => 7, 'b' => true]) . DocxW::r('  ______________________________________', ['sz' => 7, 'b' => true]), ['before' => 240]);
        return $x;
    };
    $third = (int)($width / 3);
    $b .= DocxW::table([[['p' => $sig(1, true), 'nob' => true, 'valign' => 'top'], ['p' => $sig(2, false), 'nob' => true, 'valign' => 'top'],
                         ['p' => $sig(3, false), 'nob' => true, 'valign' => 'top']]],
                       [$third, $third, $width - 2 * $third], ['borders' => false]);
    return [$b, $page];
}

/* ═══════════════════ Non-PS Supplies (nonps-print.php) ═══════════════════ */
function fmt_form_nonps(DocxW $d, int $fy): array {
    require_once __DIR__ . '/price-provision.php';
    $W = FmtForm::class;
    $page = FMT_LETTER + ['top' => 720, 'bottom' => 720, 'left' => 720, 'right' => 720];
    $width = $page['w'] - $page['left'] - $page['right'];
    $b = '';
    $logos = $W::logo($d, 'left', 54, 54);
    $right = $W::logo($d, 'right', 54, 54);
    if ($right !== '') $logos .= DocxW::r('      ') . $right;
    if ($logos !== '') $b .= DocxW::p($logos, ['align' => 'center', 'after' => 60]);
    $b .= DocxW::p($W::w('rep_line', ['sz' => 10]), ['align' => 'center']);
    $b .= DocxW::p($W::w('org_name', ['sz' => 14, 'b' => true]), ['align' => 'center', 'before' => 30, 'after' => 30]);
    $b .= DocxW::p($W::w('address', ['sz' => 10]), ['align' => 'center']);
    $b .= DocxW::p($W::w('unit_line', ['sz' => 11, 'b' => true]), ['align' => 'center', 'before' => 120]);
    $b .= DocxW::p($W::w('doc_title', ['sz' => 11, 'b' => true]), ['align' => 'center', 'before' => 120]);
    $b .= DocxW::p(DocxW::r('FY ' . $fy, ['sz' => 10]), ['align' => 'center', 'after' => 180]);

    $widths = [660, 0, 975, 1050, 1350, 1500];
    $widths[1] = $width - array_sum($widths);
    $th = ['sz' => 10, 'b' => true];
    $rows = [['cells' => array_map(fn($k) => ['p' => DocxW::p($W::w($k, $th), ['align' => 'center'])],
                                   ['col_no', 'col_part', 'col_unit', 'col_qty', 'col_cost', 'col_total']), 'header' => true]];
    for ($k = 0; $k < 10; $k++) $rows[] = ['cells' => array_fill(0, 6, ['t' => '', 'o' => ['sz' => 10]]), 'h' => 320];
    $rows[] = [['t' => 'TOTAL', 'o' => ['sz' => 10, 'b' => true], 'po' => ['align' => 'center'], 'span' => 5],
               ['t' => '₱', 'o' => ['sz' => 10, 'b' => true]]];
    // + VAT, logistics and inflation — each "LABEL (rate%)"
    foreach (NONPS_CHARGES as [$k, $lblKey, $rateKey, $def]) {
        $rate = nonps_rate_value(doc_fmt('nonps', $rateKey, $fy));
        $rateTxt = nonps_rate_text($rate ?? (float)$def);
        FmtForm::$tagged[$rateKey] = true;
        $rows[] = [['p' => DocxW::p($W::w($lblKey, ['sz' => 10, 'b' => true]) . DocxW::r(' (', ['sz' => 10, 'b' => true])
                                    . DocxW::ctl($rateKey, doc_fmt_fields('nonps')[$rateKey][0] ?? $rateKey, $rateTxt, ['sz' => 10, 'b' => true])
                                    . DocxW::r('%)', ['sz' => 10, 'b' => true]), ['align' => 'center']), 'span' => 5],
                   ['t' => '₱', 'o' => ['sz' => 10, 'b' => true]]];
    }
    $rows[] = [['p' => DocxW::p($W::w('lbl_grand', ['sz' => 10, 'b' => true]), ['align' => 'center']), 'span' => 5],
               ['t' => '₱', 'o' => ['sz' => 10, 'b' => true]]];
    $b .= DocxW::table($rows, $widths, ['mar' => [60, 90, 60, 90]]);

    $b .= DocxW::p($W::w('sig_prepared', ['sz' => 10]), ['before' => 300, 'after' => 630]);
    $b .= DocxW::p(DocxW::r('__________________________________', ['sz' => 10]));
    $b .= DocxW::p(DocxW::r('Name and Designation', ['sz' => 9, 'i' => true]));
    $b .= DocxW::p(DocxW::r('End-User or Implementing Unit', ['sz' => 9, 'i' => true]));
    return [$b, $page];
}

/* ═══════════════════ Annex A to E (annex-print.php) ═══════════════════ */
function fmt_form_annex(DocxW $d, int $fy): array {
    $W = FmtForm::class;
    $page = FMT_LONG + ['top' => 680, 'bottom' => 680, 'left' => 680, 'right' => 680];
    $width = $page['w'] - $page['left'] - $page['right'];
    $types = [
        'A' => ['Goods', 'Can be used for Item A.1.'],
        'B' => ['Training, Seminars and Other Activities', 'Can be used for Item B.1.'],
        'C' => ['Equipment and Other Capital Outlay', 'Can be used for Item C.1.'],
        'D' => ['', ''],
        'E' => ['', ''],
    ];
    $groups = ['Supplies and Materials', 'Honoraria', 'Venue (Proposed Location)', 'Meals and Snack', 'Others', 'Registration Fee'];
    $b = '';
    $first = true;
    foreach ($types as $letter => [$subtitle, $note]) {
        $po = $first ? ['align' => 'center'] : ['align' => 'center', 'page' => true];
        $first = false;
        $b .= DocxW::p($W::w('rep_line', ['sz' => 8.5]), $po);
        $b .= DocxW::p($W::w('org_name', ['sz' => 10.5, 'b' => true]), ['align' => 'center']);
        $b .= DocxW::p($W::w('address', ['sz' => 7.5]), ['align' => 'center']);
        $b .= DocxW::p(DocxW::r('[END-USER OR IMPLEMENTING UNIT]', ['sz' => 8.5, 'b' => true, 'color' => 'C00000']), ['align' => 'center', 'before' => 30]);
        $b .= DocxW::p(DocxW::r('ANNEX  ' . $letter, ['sz' => 12, 'b' => true, 'spacing' => 60]), ['align' => 'center', 'before' => 150]);
        $b .= DocxW::p(DocxW::r($subtitle !== '' ? $subtitle : '______________________________', ['sz' => 9]), ['align' => 'center']);
        $b .= DocxW::p($W::w('lbl_fy', ['sz' => 8.5]) . DocxW::r(' ' . $fy, ['sz' => 8.5]), ['align' => 'center', 'after' => 180]);
        $b .= DocxW::p(DocxW::r("*Use another sheet for different projects/program/activity\n*" . ($note !== '' ? $note : ''), ['sz' => 8, 'i' => true]),
                       ['fill' => 'FFFF00', 'line' => 300, 'left' => 0]);
        // the fields
        $f8 = ['sz' => 8.5];
        $lbl = fn($k, $small = '', $o = []) => ['p' => DocxW::p($W::w($k, $f8 + $o) . ($small !== '' ? DocxW::r("\n" . $small, ['sz' => 7, 'i' => true]) : ''))];
        $c = fn($s, $o = []) => ['t' => $s, 'o' => $f8 + $o, 'po' => ['align' => 'center']];
        $val = ['t' => '', 'o' => $f8, 'span' => 2];
        $fw = [(int)($width * 0.38), 460, 0, 0]; $fw[2] = (int)(($width - $fw[0] - $fw[1]) / 2); $fw[3] = $width - $fw[0] - $fw[1] - $fw[2];
        $rows = [
            [$lbl('lbl_project'), $c(':'), $val],
            [$lbl('lbl_general', '(As categorized in the PPMP)'), $c(':'), $val],
            [['p' => DocxW::p($W::w('lbl_item', $f8), ['align' => 'center']), 'span' => 2], ['t' => '', 'fill' => '808080', 'span' => 2]],
            [$lbl('lbl_nep'), $c('#1', ['b' => true]), $val],
            [$lbl('lbl_alloc'), $c(':'), $val],
            [$lbl('lbl_nep', '(If NEP Account #1 allocation is not sufficient)'), $c('#2', ['b' => true]), $val],
            [$lbl('lbl_alloc'), $c(':'), $val],
            [$lbl('lbl_totbudget', '', ['b' => true, 'i' => true]), $c(':'), ['t' => '(                    )', 'o' => $f8 + ['b' => true, 'i' => true], 'span' => 2]],
            [['p' => DocxW::p($W::w('lbl_dates', $f8 + ['i' => true]) . DocxW::r(' (tentative)', ['sz' => 7, 'i' => true]))], $c(':'), $val],
        ];
        $b .= DocxW::table(array_map(fn($r) => ['cells' => $r, 'h' => 300], $rows), $fw, ['mar' => [30, 90, 30, 90]]);
        $b .= DocxW::pt('', ['sz' => 8]);
        // the items
        $iw = [(int)($width * 0.56), (int)($width * 0.16)]; $iw[] = $width - $iw[0] - $iw[1];
        $th = ['sz' => 8.5, 'b' => true];
        $rows = [['cells' => [['p' => DocxW::p($W::w('col_items', $th), ['align' => 'center'])], ['p' => DocxW::p($W::w('col_qty', $th), ['align' => 'center'])],
                              ['p' => DocxW::p($W::w('col_total', $th), ['align' => 'center'])]], 'header' => true]];
        foreach ($groups as $gname) {
            $rows[] = [['t' => $gname, 'o' => ['sz' => 8.5, 'b' => true, 'i' => true], 'fill' => '808080', 'span' => 3]];
            for ($k = 0; $k < 5; $k++) $rows[] = ['cells' => [['t' => ''], ['t' => ''], ['t' => '']], 'h' => 300];
        }
        $rows[] = [['p' => DocxW::p($W::w('col_total', ['sz' => 8.5, 'b' => true, 'i' => true]), ['align' => 'center'])], ['t' => ''],
                   ['t' => '(                    )', 'o' => ['sz' => 8.5, 'b' => true, 'i' => true], 'po' => ['align' => 'right']]];
        $b .= DocxW::table($rows, $iw, ['mar' => [40, 100, 40, 100]]);
        // the instructions
        $b .= DocxW::p($W::w('instr_title', ['sz' => 7, 'b' => true]), ['before' => 240, 'after' => 40]);
        $lines = '';
        foreach (preg_split('/\r?\n/', fmt_show('annex', 'instr_body', $fy)) as $line) {
            if (trim($line) === '') continue;
            $lines .= DocxW::p(DocxW::r(trim($line), ['sz' => 7]), ['line' => 300]);
        }
        if (!isset(FmtForm::$tagged['instr_body'])) {
            FmtForm::$tagged['instr_body'] = true;
            $b .= DocxW::ctlBlock('instr_body', doc_fmt_fields('annex')['instr_body'][0] ?? 'Instructions text', $lines);
        } else {
            $b .= $lines;
        }
    }
    return [$b, $page];
}

/* ═══════════════════ Market Scoping (market-scoping-print.php) ═══════════════════ */
function fmt_form_scoping(DocxW $d, int $fy): array {
    require_once __DIR__ . '/market-scoping-template.php';
    $W = FmtForm::class;
    $page = FMT_LETTER + ['top' => 864, 'right' => 1526, 'bottom' => 1440, 'left' => 1800];
    $width = $page['w'] - $page['left'] - $page['right'];
    $b = '';
    $center = DocxW::p($W::w('rep_line', ['sz' => 8.5]), ['align' => 'center'])
            . DocxW::p($W::w('org_name', ['sz' => 12, 'b' => true]), ['align' => 'center'])
            . DocxW::p($W::w('address', ['sz' => 7.5, 'i' => true]), ['align' => 'center']);
    $b .= DocxW::table([[
        ['p' => DocxW::p($W::logo($d, 'left', 60, 60), ['align' => 'center']), 'nob' => true],
        ['p' => $center, 'nob' => true],
        ['p' => DocxW::p($W::logo($d, 'right', 66, 45), ['align' => 'center']), 'nob' => true],
    ]], [1350, $width - 2700, 1350], ['borders' => false]);
    $b .= DocxW::p($W::w('doc_title', ['sz' => 14, 'b' => true]), ['align' => 'center', 'before' => 60, 'after' => 120, 'border' => ['bottom']]);
    // the checklist of the year (Document Format › Market Scoping), blank
    $html = ms_form_fill(ms_form_source($fy), [], $fy);
    $b .= fmt_html_docx($d, $html, $width, 11);
    return [$b, $page];
}

/**
 * HTML (the rich-text editor's, config/richtext.php) as Word paragraphs and
 * tables: p / div / h1–h6 / li, br, b / strong, i / em, u, s, sub / sup,
 * span and td styles (bold, italic, size, alignment, background, width,
 * margin-left), tables with colspan / rowspan, and pictures.
 */
function fmt_html_docx(DocxW $d, string $html, int $width, float $baseSize, bool $dataImages = false): string {
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = $dom->getElementById('__root');
    if (!$root) return '';
    $style = function (DOMNode $n): array {
        $s = ($n instanceof DOMElement) ? (string)$n->getAttribute('style') : '';
        $out = [];
        foreach (explode(';', $s) as $decl) {
            if (strpos($decl, ':') === false) continue;
            [$k, $v] = array_map('trim', explode(':', $decl, 2));
            $out[strtolower($k)] = strtolower($v);
        }
        return $out;
    };
    $px = function (string $v, float $base) {
        if (preg_match('/^([\d.]+)\s*pt$/', $v, $m)) return (float)$m[1];
        if (preg_match('/^([\d.]+)\s*px$/', $v, $m)) return (float)$m[1] * 0.75;
        if (preg_match('/^([\d.]+)\s*em$/', $v, $m)) return (float)$m[1] * $base;
        return null;
    };
    // inline content → runs
    $runs = function (DOMNode $n, array $o) use (&$runs, $style, $px, $d, $width, $dataImages): string {
        $x = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) {
                $t = preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $c->nodeValue));
                if ($t !== '') $x .= DocxW::r($t, $o + ((mb_strpos($c->nodeValue, '☐') !== false || mb_strpos($c->nodeValue, '☑') !== false) ? ['font' => 'Segoe UI Symbol'] : []));
                continue;
            }
            if (!($c instanceof DOMElement)) continue;
            $tag = strtolower($c->tagName);
            $o2 = $o;
            $st = $style($c);
            if (in_array($tag, ['b', 'strong'], true) || in_array($st['font-weight'] ?? '', ['bold', '700', '600'], true)) $o2['b'] = true;
            if (($st['font-weight'] ?? '') === 'normal') unset($o2['b']);
            if (in_array($tag, ['i', 'em'], true) || ($st['font-style'] ?? '') === 'italic') $o2['i'] = true;
            if (($st['font-style'] ?? '') === 'normal') unset($o2['i']);
            if ($tag === 'u' || strpos($st['text-decoration'] ?? '', 'underline') !== false) $o2['u'] = 'single';
            if (isset($st['font-size']) && ($sz = $px($st['font-size'], (float)($o['sz'] ?? 11)))) $o2['sz'] = $sz;
            if (isset($st['color']) && preg_match('/^#([0-9a-f]{6})$/', $st['color'], $m)) $o2['color'] = strtoupper($m[1]);
            if ($tag === 'br') { $x .= DocxW::r("\n", $o); continue; }
            if ($tag === 'img') {
                $src = (string)$c->getAttribute('src');
                if ($src !== '' && strpos($src, '://') === false && strpos($src, 'data:') !== 0) $x .= $d->img(__DIR__ . '/../' . ltrim(strtok($src, '?'), '/'), $width / 20, 400);
                // v105: a picture written into the document itself (Market Scoping export), at the width it was given
                elseif ($dataImages && preg_match('#^data:image/[a-z+]+;base64,(.+)$#is', $src, $dm)) {
                    $wpx = (int)$c->getAttribute('width');
                    $x .= $d->imgBytes((string)base64_decode($dm[1]), $wpx > 0 ? min($width / 20, $wpx * 0.75) : $width / 20, 600);
                }
                continue;
            }
            $x .= $runs($c, $o2);
        }
        return $x;
    };
    $parOpts = function (DOMElement $e, array $base) use ($style, $width): array {
        $st = $style($e);
        $o = $base;
        if (isset($st['text-align'])) $o['align'] = ['justify' => 'both', 'center' => 'center', 'right' => 'right', 'left' => 'left'][$st['text-align']] ?? null;
        if (isset($st['margin-left']) && preg_match('/^([\d.]+)%$/', $st['margin-left'], $m)) $o['left'] = (int)($width * $m[1] / 100);
        if (isset($st['margin-left']) && preg_match('/^([\d.]+)px$/', $st['margin-left'], $m)) $o['left'] = (int)($m[1] * 15);
        if (isset($st['margin']) && preg_match('/^([\d.]+)(px|pt)?\s+[\d.]+\w*\s+([\d.]+)(px|pt)?(?:\s+([\d.]+)(%|px))?/', $st['margin'], $m)) {
            $o['before'] = (int)((float)$m[1] * (($m[2] ?? 'px') === 'pt' ? 20 : 15));
            $o['after']  = (int)((float)$m[3] * (($m[4] ?? 'px') === 'pt' ? 20 : 15));
            if (!empty($m[5])) $o['left'] = $m[6] === '%' ? (int)($width * $m[5] / 100) : (int)($m[5] * 15);
        }
        return array_filter($o, fn($v) => $v !== null);
    };
    $base = ['sz' => $baseSize];
    $block = function (DOMNode $n, array $o, int $avail) use (&$block, $runs, $parOpts, $style, $px, $base): string {
        $x = ''; $inline = '';
        $flush = function () use (&$inline, &$x, $o) {
            if (trim(strip_tags($inline)) !== '' || strpos($inline, '<w:drawing') !== false) $x .= DocxW::p($inline, ['after' => 120, 'sz' => $o['sz'] ?? 11]);
            $inline = '';
        };
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) { $inline .= $runs((function () use ($c) { $f = $c->ownerDocument->createElement('span'); $f->appendChild($c->cloneNode()); return $f; })(), $o); continue; }
            if (!($c instanceof DOMElement)) continue;
            $tag = strtolower($c->tagName);
            if (in_array($tag, ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'pre'], true)) {
                $flush();
                $ro = $o;
                if ($tag[0] === 'h') { $ro['b'] = true; $ro['sz'] = [1 => 16, 2 => 14, 3 => 12.5, 4 => 11.5, 5 => 11, 6 => 10][(int)$tag[1]] ?? 11; }
                $st = $style($c);
                if (in_array($st['font-weight'] ?? '', ['bold', '700'], true)) $ro['b'] = true;
                if (($st['font-style'] ?? '') === 'italic') $ro['i'] = true;
                $po = $parOpts($c, ['after' => 120, 'sz' => $ro['sz'] ?? 11]);
                $prefix = $tag === 'li' ? DocxW::r('•  ', $ro) : '';
                if ($tag === 'li') $po['left'] = ($po['left'] ?? 0) + 360;
                $x .= DocxW::p($prefix . $runs($c, $ro), $po);
                continue;
            }
            if (in_array($tag, ['div', 'ul', 'ol', 'tbody', 'section'], true)) { $flush(); $x .= $block($c, $o, $avail); continue; }
            if ($tag === 'hr') { $flush(); $x .= DocxW::p('', ['border' => ['bottom'], 'after' => 120]); continue; }
            if ($tag === 'table') {
                $flush();
                $st = $style($c);
                $tw = $avail; $ind = 0;
                if (isset($st['width']) && preg_match('/^([\d.]+)%$/', $st['width'], $m)) $tw = (int)($avail * min(100, (float)$m[1]) / 100);
                if (isset($st['margin-left']) && preg_match('/^([\d.]+)%$/', $st['margin-left'], $m)) $ind = (int)($avail * (float)$m[1] / 100);
                $trs = [];
                foreach ($c->getElementsByTagName('tr') as $tr) $trs[] = $tr;
                // the widths of the columns: from the first row's cells (style width %), the rest shared
                $ncol = 0; $pct = [];
                foreach ($trs as $tr) {
                    $n2 = 0; $i = 0;
                    foreach ($tr->childNodes as $td) {
                        if (!($td instanceof DOMElement) || !in_array(strtolower($td->tagName), ['td', 'th'], true)) continue;
                        $span = max(1, (int)$td->getAttribute('colspan'));
                        $tst = $style($td);
                        if ($span === 1 && !isset($pct[$n2]) && isset($tst['width']) && preg_match('/^([\d.]+)%$/', $tst['width'], $m)) $pct[$n2] = (float)$m[1];
                        $n2 += $span;
                    }
                    $ncol = max($ncol, $n2);
                }
                if ($ncol === 0) continue;
                $known = array_sum($pct); $rest = $ncol - count($pct);
                $widths = [];
                for ($i = 0; $i < $ncol; $i++) $widths[] = isset($pct[$i]) ? $pct[$i] : ($rest > 0 ? max(0, 100 - $known) / $rest : 0);
                $tot = array_sum($widths) ?: 1;
                $widths = array_map(fn($w) => max(200, (int)($tw * $w / $tot)), $widths);
                // rows, with rowspans carried down as merged cells
                $rows = []; $carry = [];
                foreach ($trs as $ri => $tr) {
                    $cells = []; $col = 0;
                    $header = strtolower($tr->parentNode->nodeName) === 'thead';
                    $tds = [];
                    foreach ($tr->childNodes as $td) if ($td instanceof DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) $tds[] = $td;
                    $k = 0;
                    while ($col < $ncol) {
                        if (!empty($carry[$col])) {
                            $carry[$col]['left']--;
                            $cells[] = ['p' => DocxW::p(''), 'vm' => 'cont', 'span' => $carry[$col]['span']];
                            $sp = $carry[$col]['span'];
                            if ($carry[$col]['left'] <= 0) unset($carry[$col]);
                            $col += $sp; continue;
                        }
                        if (!isset($tds[$k])) { $cells[] = ['t' => '']; $col++; continue; }
                        $td = $tds[$k++];
                        $span = max(1, (int)$td->getAttribute('colspan'));
                        $rs = max(1, (int)$td->getAttribute('rowspan'));
                        $tst = $style($td);
                        $co = $o;
                        if (strtolower($td->tagName) === 'th' || in_array($tst['font-weight'] ?? '', ['bold', '700'], true)) $co['b'] = true;
                        if (($tst['font-style'] ?? '') === 'italic') $co['i'] = true;
                        if (isset($tst['font-size']) && ($sz = $px($tst['font-size'], (float)($o['sz'] ?? 11)))) $co['sz'] = $sz;
                        $cw = array_sum(array_slice($widths, $col, $span));
                        $inner = str_replace('w:after="120"', 'w:after="40"', $block($td, $co, max(400, $cw - 160)));   // tighter inside a cell
                        if (trim($inner) === '') $inner = DocxW::p('', ['sz' => $co['sz'] ?? 11]);
                        $al = $tst['text-align'] ?? '';
                        if ($al !== '') $inner = str_replace('<w:pPr>', '<w:pPr><w:jc w:val="' . (['center' => 'center', 'right' => 'right', 'justify' => 'both'][$al] ?? 'left') . '"/>', $inner);
                        $cell = ['p' => $inner, 'span' => $span, 'valign' => ['middle' => 'center', 'top' => 'top', 'bottom' => 'bottom'][$tst['vertical-align'] ?? 'top'] ?? 'top'];
                        if (isset($tst['background-color']) && preg_match('/^#([0-9a-f]{6})$/', $tst['background-color'], $m)) $cell['fill'] = strtoupper($m[1]);
                        if (strpos($tst['border'] ?? '', 'none') !== false) $cell['nob'] = true;
                        if ($rs > 1) { $cell['vm'] = 'restart'; $carry[$col] = ['left' => $rs - 1, 'span' => $span]; }
                        $cells[] = $cell;
                        $col += $span;
                    }
                    $h = 0;
                    foreach ($tds as $td) { $tst = $style($td); if (isset($tst['height']) && preg_match('/^([\d.]+)px$/', $tst['height'], $m)) $h = max($h, (int)($m[1] * 15)); }
                    $rows[] = ['cells' => $cells, 'header' => $header, 'h' => $h ?: 0];
                }
                $allNone = true;
                foreach ($rows as $r) foreach ($r['cells'] as $cc) if (empty($cc['nob'])) $allNone = false;
                $x .= DocxW::table($rows, $widths, ['borders' => !$allNone, 'indent' => $ind, 'mar' => [50, 100, 50, 100]]);
                $x .= DocxW::p('', ['sz' => 6, 'after' => 120]);
                continue;
            }
            // inline element
            $wrap = $c->ownerDocument->createElement('span');
            $wrap->appendChild($c->cloneNode(true));
            $inline .= $runs($wrap, $o);
        }
        $flush();
        return $x;
    };
    return $block($root, $base, $width);
}
