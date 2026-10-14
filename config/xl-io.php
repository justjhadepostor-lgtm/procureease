<?php
/* ═══════════════════════════════════════════════════════════════
   v105: Excel files (.xlsx) of the documents — pure PHP.

   Reading: xl_read() gives every sheet of a workbook a department sends
   in — the text and numbers of its cells (a formula gives the value it
   was last worked out to), which cells are dates, the merged cells, and
   the shapes drawn on it.

   Writing: the Procurement Unit's own forms are kept as templates
   (assets/xl-templates). XlSheet copies their rows — the top of the form
   once, an item row once for every item, the bottom once — keeping every
   look (fonts, borders, fills, widths, heights, merged cells, logos), and
   puts the document's data in. XlBook makes the workbook of one or more
   such sheets.
   =============================================================== */
require_once __DIR__ . '/docx-lib.php';

/** Every file of a ZIP's bytes: [name => bytes]. null when it is not a ZIP that can be read. */
function xl_zip_entries(string $zip): ?array {
    $eocd = strrpos($zip, "PK\x05\x06");
    if ($eocd === false || strlen($zip) < $eocd + 22) return null;
    $e = unpack('Vsig/vdisk/vcd/vthis/vtotal/Vsize/Voffset', substr($zip, $eocd, 20));
    $p = (int)$e['offset'];
    $out = [];
    for ($i = 0; $i < (int)$e['total'] && $p + 46 <= strlen($zip); $i++) {
        $c = unpack('Vsig/vmade/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voff', substr($zip, $p, 46));
        if ($c['sig'] !== 0x02014b50) return null;
        $name = substr($zip, $p + 46, $c['nlen']);
        $p += 46 + $c['nlen'] + $c['xlen'] + $c['clen'];
        $lo = (int)$c['off'];
        if ($lo + 30 > strlen($zip)) continue;
        $l = unpack('Vsig/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen', substr($zip, $lo, 30));
        if ($l['sig'] !== 0x04034b50) continue;
        $raw = substr($zip, $lo + 30 + $l['nlen'] + $l['xlen'], (int)$c['csize']);
        if ((int)$c['method'] === 0) $out[$name] = $raw;
        elseif ((int)$c['method'] === 8) { $x = @gzinflate($raw); if ($x !== false) $out[$name] = $x; }
    }
    return $out;
}

/** "A" → 1, "AA" → 27 */
function xl_col(string $letters): int {
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n;
}
/** 1 → "A", 27 → "AA" */
function xl_letters(int $n): string {
    $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
    return $s;
}
function xl_unxml(string $s): string { return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/** The text of a <si> / <is>: its runs, without the phonetic guide. */
function xl_rich_text(string $x): string {
    $x = preg_replace('#<rPh\b.*?</rPh>#s', '', $x);
    preg_match_all('#<t(?:\s[^>]*)?>(.*?)</t>|<t(?:\s[^>]*)?/>#s', $x, $m);
    return xl_unxml(implode('', $m[1]));
}

/** A path inside the package, from a relationship target relative to $base ("xl/worksheets/"). */
function xl_path(string $base, string $target): string {
    if ($target !== '' && $target[0] === '/') return ltrim($target, '/');
    $parts = explode('/', rtrim($base, '/') . '/' . $target);
    $out = [];
    foreach ($parts as $p) { if ($p === '..') array_pop($out); elseif ($p !== '.' && $p !== '') $out[] = $p; }
    return implode('/', $out);
}
function xl_rels(array $files, string $relsPath): array {
    $out = [];
    if (!isset($files[$relsPath])) return $out;
    preg_match_all('#<Relationship\b([^>]*)/?>#', $files[$relsPath], $m);
    foreach ($m[1] as $a) {
        if (preg_match('#\bId="([^"]+)"#', $a, $i) && preg_match('#\bTarget="([^"]+)"#', $a, $t)) $out[$i[1]] = xl_unxml($t[1]);
    }
    return $out;
}

/**
 * A workbook's sheets:
 *   [['name', 'hidden', 'cells' => [row => [col => text|float]], 'dates' => [row => [col => true]],
 *     'merges' => [[c1, r1, c2, r2], …], 'shapes' => [['col', 'row', 'kind' => 'sp'|'cxn'|'pic', 'text'], …]], …]
 * null when the bytes are not an .xlsx.
 */
function xl_read(string $bytes): ?array {
    if (strncmp($bytes, 'PK', 2) !== 0) return null;
    $f = xl_zip_entries($bytes);
    if (!$f || !isset($f['xl/workbook.xml'])) return null;
    // shared strings
    $ss = [];
    $wbRels = xl_rels($f, 'xl/_rels/workbook.xml.rels');
    $ssPath = 'xl/sharedStrings.xml';
    foreach ($wbRels as $t) if (preg_match('#sharedStrings\.xml$#i', $t)) $ssPath = xl_path('xl/', $t);
    if (isset($f[$ssPath])) {
        preg_match_all('#<si>(.*?)</si>|<si/>#s', $f[$ssPath], $m);
        foreach ($m[1] as $x) $ss[] = xl_rich_text($x);
    }
    // which styles are dates
    $dateXf = [];
    if (isset($f['xl/styles.xml'])) {
        $st = $f['xl/styles.xml'];
        $fmts = [];
        if (preg_match_all('#<numFmt\b[^>]*numFmtId="(\d+)"[^>]*formatCode="([^"]*)"#', $st, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $fmts[(int)$x[1]] = xl_unxml($x[2]);
        }
        if (preg_match('#<cellXfs[^>]*>(.*?)</cellXfs>#s', $st, $cx)) {
            preg_match_all('#<xf\b([^>]*)(?:/>|>)#', $cx[1], $xm);
            foreach ($xm[1] as $i => $a) {
                $id = preg_match('#numFmtId="(\d+)"#', $a, $n) ? (int)$n[1] : 0;
                $isDate = ($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47);
                if (!$isDate && isset($fmts[$id])) {
                    $code = preg_replace('#"[^"]*"|\[[^\]]*\]|\\\\.#', '', $fmts[$id]);
                    $isDate = (bool)preg_match('#[dmyhs]#i', $code) && !preg_match('/[0#?]/', $code);
                }
                if ($isDate) $dateXf[$i] = true;
            }
        }
    }
    $sheets = [];
    preg_match_all('#<sheet\b([^>]*)/?>#', $f['xl/workbook.xml'], $sm);
    foreach ($sm[1] as $a) {
        $name = preg_match('#\bname="([^"]*)"#', $a, $x) ? xl_unxml($x[1]) : '';
        $rid  = preg_match('#\br:id="([^"]*)"#', $a, $x) ? $x[1] : '';
        $hidden = (bool)preg_match('#\bstate="(hidden|veryHidden)"#', $a);
        $path = isset($wbRels[$rid]) ? xl_path('xl/', $wbRels[$rid]) : '';
        if ($path === '' || !isset($f[$path])) continue;
        $x = $f[$path];
        $cells = []; $dates = []; $hiddenRows = [];
        if (preg_match_all('#<row\b[^>]*?\br="(\d+)"[^>]*?\bhidden="(?:1|true)"#', $x, $hm)) foreach ($hm[1] as $hr) $hiddenRows[(int)$hr] = true;
        if (preg_match('#<sheetData>(.*)</sheetData>#s', $x, $sd)) {
            preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $sd[1], $cm, PREG_SET_ORDER);
            foreach ($cm as $c) {
                if (!preg_match('#\br="([A-Z]+)(\d+)"#', $c[1], $ref)) continue;
                $inner = $c[2] ?? '';
                $t = preg_match('#\bt="(\w+)"#', $c[1], $tm) ? $tm[1] : 'n';
                $s = preg_match('#\bs="(\d+)"#', $c[1], $sx) ? (int)$sx[1] : 0;
                $v = preg_match('#<v>(.*?)</v>#s', $inner, $vm) ? xl_unxml($vm[1]) : null;
                if ($t === 's') $val = ($v !== null && isset($ss[(int)$v])) ? $ss[(int)$v] : null;
                elseif ($t === 'inlineStr') $val = preg_match('#<is>(.*?)</is>#s', $inner, $im) ? xl_rich_text($im[1]) : null;
                elseif ($t === 'str' || $t === 'e') $val = $v;
                elseif ($t === 'b') $val = $v === null ? null : ($v === '1' ? 'TRUE' : 'FALSE');
                else $val = ($v === null || $v === '' || !is_numeric($v)) ? $v : (float)$v;
                if ($val === null || $val === '') continue;
                $r = (int)$ref[2]; $col = xl_col($ref[1]);
                $cells[$r][$col] = $val;
                if (is_float($val) && isset($dateXf[$s])) $dates[$r][$col] = true;
            }
        }
        ksort($cells);
        $merges = [];
        if (preg_match_all('#<mergeCell ref="([A-Z]+)(\d+):([A-Z]+)(\d+)"#', $x, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $q) $merges[] = [xl_col($q[1]), (int)$q[2], xl_col($q[3]), (int)$q[4]];
        }
        // shapes drawn on the sheet (e.g. the tick in the INDICATIVE / FINAL box of the PPMP)
        $shapes = [];
        $base = substr($path, 0, strrpos($path, '/') + 1);
        $srels = xl_rels($f, $base . '_rels/' . basename($path) . '.rels');
        if (preg_match('#<drawing r:id="([^"]+)"#', $x, $dm) && isset($srels[$dm[1]])) {
            $dp = xl_path($base, $srels[$dm[1]]);
            if (isset($f[$dp]) && preg_match_all('#<xdr:(oneCellAnchor|twoCellAnchor)\b[^>]*>(.*?)</xdr:\1>#s', $f[$dp], $am, PREG_SET_ORDER)) {
                foreach ($am as $an) {
                    $b = $an[2];
                    if (!preg_match('#<xdr:from><xdr:col>(\d+)</xdr:col>.*?<xdr:row>(\d+)</xdr:row>#s', $b, $fr)) continue;
                    $kind = strpos($b, '<xdr:pic') !== false ? 'pic' : (strpos($b, 'cxnSp') !== false || preg_match('#prst="(straightConnector1|line)"#', $b) ? 'cxn' : 'sp');
                    preg_match_all('#<a:t>(.*?)</a:t>#s', $b, $tx);
                    $shapes[] = ['col' => (int)$fr[1] + 1, 'row' => (int)$fr[2] + 1, 'kind' => $kind, 'text' => trim(xl_unxml(implode('', $tx[1])))];
                }
            }
        }
        $sheets[] = ['name' => $name, 'hidden' => $hidden, 'cells' => $cells, 'dates' => $dates, 'merges' => $merges, 'shapes' => $shapes, 'hidden_rows' => $hiddenRows];
    }
    return ['sheets' => $sheets];
}

/** An Excel date number → 'Y-m-d' (the 1900 system). */
function xl_date(float $serial): string {
    $days = (int)floor($serial);
    return gmdate('Y-m-d', ($days - 25569) * 86400);
}

/** A cell's text, trimmed, with runs of spaces made one (a number as it would print plainly). */
function xl_text(array $sheet, int $r, int $c): string {
    $v = $sheet['cells'][$r][$c] ?? '';
    if (is_float($v)) {
        if (!empty($sheet['dates'][$r][$c])) return xl_date($v);
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
    $v = str_replace("\xC2\xA0", ' ', (string)$v);
    return trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/ *\r?\n */u", "\n", $v)));
}

/** A money / number cell: 27000, "27,000.00", "P27,000.00", "₱ 1,234.5" → float (null when it is not a number). */
function xl_num($v): ?float {
    if (is_float($v) || is_int($v)) return (float)$v;
    $s = trim(str_replace(["\xC2\xA0", ',', '₱', ' '], ['', '', '', ''], (string)$v));
    $s = preg_replace('/^(PHP|Php|P)\s*/', '', $s);
    if ($s === '' || !is_numeric($s)) return null;
    return (float)$s;
}

/* ─── writing: a sheet made from a template's rows ───────────────── */

final class XlTplSheet {
    public string $head = '';
    public string $tail = '';
    /** @var array<int, array{a:string, c:array<int, array{a:string, i:string}>}> */
    public array $rows = [];
    /** @var array<int, array{0:int,1:int,2:int,3:int}> */
    public array $merges = [];

    public static function parse(string $xml): self {
        $t = new self();
        $p = strpos($xml, '<sheetData');
        $q = strpos($xml, '</sheetData>');
        $t->head = substr($xml, 0, $p);
        $tail = substr($xml, $q + strlen('</sheetData>'));
        if (preg_match('#<mergeCells[^>]*>(.*?)</mergeCells>#s', $tail, $mm)) {
            preg_match_all('#ref="([A-Z]+)(\d+):([A-Z]+)(\d+)"#', $mm[1], $m, PREG_SET_ORDER);
            foreach ($m as $x) $t->merges[] = [xl_col($x[1]), (int)$x[2], xl_col($x[3]), (int)$x[4]];
            $tail = str_replace($mm[0], '', $tail);
        }
        $t->tail = $tail;
        $body = substr($xml, $p, $q - $p);
        preg_match_all('#<row\b([^>]*?)(?:/>|>(.*?)</row>)#s', $body, $rm, PREG_SET_ORDER);
        foreach ($rm as $r) {
            if (!preg_match('#\br="(\d+)"#', $r[1], $n)) continue;
            $cells = [];
            preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $r[2] ?? '', $cm, PREG_SET_ORDER);
            foreach ($cm as $c) {
                if (!preg_match('#\br="([A-Z]+)\d+"#', $c[1], $ref)) continue;
                $cells[xl_col($ref[1])] = ['a' => trim(preg_replace('#\s*\br="[^"]*"#', '', $c[1])), 'i' => $c[2] ?? ''];
            }
            $t->rows[(int)$n[1]] = ['a' => trim(preg_replace('#\s*\br="[^"]*"#', '', $r[1])), 'c' => $cells];
        }
        return $t;
    }
}

final class XlSheet {
    public string $name;
    private XlTplSheet $tpl;
    private array $rows = [];
    private array $merges = [];
    public int $last = 0;

    public function __construct(XlTplSheet $tpl, string $name) { $this->tpl = $tpl; $this->name = $name; }

    /** Puts template rows $r1…$r2 next (with their merged cells); the new number of $r1. */
    public function copy(int $r1, int $r2 = 0): int {
        if ($r2 < $r1) $r2 = $r1;
        $start = $this->last + 1;
        $off = $start - $r1;
        for ($r = $r1; $r <= $r2; $r++) {
            $this->rows[$r + $off] = $this->tpl->rows[$r] ?? ['a' => '', 'c' => []];
        }
        foreach ($this->tpl->merges as [$c1, $m1, $c2, $m2]) {
            if ($m1 >= $r1 && $m2 <= $r2) $this->merges[] = [$c1, $m1 + $off, $c2, $m2 + $off];
        }
        $this->last = $r2 + $off;
        return $start;
    }

    /** A merged range over new rows. */
    public function merge(int $c1, int $r1, int $c2, int $r2): void { $this->merges[] = [$c1, $r1, $c2, $r2]; }

    /** Takes a merged range off (the one starting at $c1/$r1). */
    public function unmerge(int $c1, int $r1): void {
        $this->merges = array_values(array_filter($this->merges, fn($m) => !($m[0] === $c1 && $m[1] === $r1)));
    }

    /**
     * What a cell holds: text, a number, null (empty), or ['f' => formula, 'v' => its value].
     * The cell keeps the template's look unless $style is given.
     */
    public function set(int $r, $col, $v, ?int $style = null): void {
        $c = is_int($col) ? $col : xl_col($col);
        if (!isset($this->rows[$r])) $this->rows[$r] = ['a' => '', 'c' => []];
        $a = $this->rows[$r]['c'][$c]['a'] ?? '';
        $a = trim(preg_replace('#\s*\bt="[^"]*"#', '', $a));
        if ($style !== null) $a = trim(preg_replace('#\s*\bs="\d+"#', '', $a) . ' s="' . $style . '"');
        $inner = '';
        if (is_array($v)) {
            $inner = '<f>' . self::x((string)$v['f']) . '</f>' . (isset($v['v']) ? '<v>' . self::num((float)$v['v']) . '</v>' : '');
        } elseif (is_int($v) || is_float($v)) {
            $inner = '<v>' . self::num((float)$v) . '</v>';
        } elseif ($v !== null && (string)$v !== '') {
            $a = trim($a . ' t="inlineStr"');
            $inner = '<is><t xml:space="preserve">' . self::x((string)$v) . '</t></is>';
        }
        $this->rows[$r]['c'][$c] = ['a' => $a, 'i' => $inner];
    }

    /** The style a cell of the template row has (for a cell put in another row). */
    public function styleOf(int $tplRow, $col): ?int {
        $c = is_int($col) ? $col : xl_col($col);
        $a = $this->tpl->rows[$tplRow]['c'][$c]['a'] ?? '';
        return preg_match('#\bs="(\d+)"#', $a, $m) ? (int)$m[1] : null;
    }

    /** A row's height in points (taller only: never lower than the template's). */
    public function height(int $r, float $pt, bool $exact = false): void {
        if (!isset($this->rows[$r])) return;
        $a = $this->rows[$r]['a'];
        $cur = preg_match('#\bht="([\d.]+)"#', $a, $m) ? (float)$m[1] : 15.0;
        if (!$exact && $pt <= $cur) return;
        $a = trim(preg_replace('#\s*\b(ht|customHeight)="[^"]*"#', '', $a));
        $this->rows[$r]['a'] = trim($a . ' ht="' . round(min(409, $pt), 2) . '" customHeight="1"');
    }

    /** Shows or hides a row. */
    public function hide(int $r, bool $hide = true): void {
        if (!isset($this->rows[$r])) return;
        $a = trim(preg_replace('#\s*\bhidden="[^"]*"#', '', $this->rows[$r]['a']));
        $this->rows[$r]['a'] = $hide ? trim($a . ' hidden="1"') : $a;
    }

    public static function x(string $s): string {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
    private static function num(float $v): string {
        if (!is_finite($v)) return '0';
        if (floor($v) == $v && abs($v) < 1e15) return (string)(int)$v;
        return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
    }

    public function xml(): string {
        ksort($this->rows);
        $rows = '';
        $maxC = 1;
        foreach ($this->rows as $r => $row) {
            ksort($row['c']);
            $cells = '';
            foreach ($row['c'] as $c => $cell) {
                $maxC = max($maxC, $c);
                $ref = xl_letters($c) . $r;
                $a = $cell['a'] !== '' ? ' ' . $cell['a'] : '';
                $cells .= $cell['i'] === '' ? '<c r="' . $ref . '"' . $a . '/>' : '<c r="' . $ref . '"' . $a . '>' . $cell['i'] . '</c>';
            }
            $ra = $row['a'] !== '' ? ' ' . $row['a'] : '';
            $rows .= '<row r="' . $r . '"' . $ra . '>' . $cells . '</row>';
        }
        $mc = '';
        if ($this->merges) {
            $mc = '<mergeCells count="' . count($this->merges) . '">';
            foreach ($this->merges as [$c1, $r1, $c2, $r2]) $mc .= '<mergeCell ref="' . xl_letters($c1) . $r1 . ':' . xl_letters($c2) . $r2 . '"/>';
            $mc .= '</mergeCells>';
        }
        $head = $this->tpl->head;
        $dim = '<dimension ref="A1:' . xl_letters($maxC) . max(1, $this->last) . '"/>';
        $head = preg_replace('#(<worksheet\b[^>]*>(?:\s*<sheetPr\b.*?(?:</sheetPr>|/>))?)#s', '$1' . $dim, $head, 1);
        return $head . '<sheetData>' . $rows . '</sheetData>' . $mc . $this->tpl->tail;
    }
}

/** A workbook made from a template file (assets/xl-templates/NAME.xlsx), one or more sheets. */
final class XlBook {
    private array $files;
    public XlTplSheet $tpl;
    /** @var XlSheet[] */
    private array $sheets = [];
    private string $drawing = '';

    public function __construct(string $templateName) {
        $bytes = (string)@file_get_contents(__DIR__ . '/../assets/xl-templates/' . $templateName);
        $files = xl_zip_entries($bytes);
        if (!$files || !isset($files['xl/worksheets/sheet1.xml'])) throw new RuntimeException('The ' . $templateName . ' form is missing.');
        $this->files = $files;
        $this->tpl = XlTplSheet::parse($files['xl/worksheets/sheet1.xml']);
        $this->drawing = $files['xl/drawings/drawing1.xml'] ?? '';
    }

    public function sheet(string $name): XlSheet {
        $s = new XlSheet($this->tpl, $name);
        $this->sheets[] = $s;
        return $s;
    }

    /** The drawing of the form (shapes, logos), to change something on it before the file is made. */
    public function setDrawing(string $xml): void { $this->drawing = $xml; }
    public function drawing(): string { return $this->drawing; }

    /** The .xlsx (bytes). */
    public function bytes(string $title, string $subject): string {
        $f = $this->files;
        unset($f['xl/worksheets/sheet1.xml'], $f['xl/worksheets/_rels/sheet1.xml.rels'], $f['xl/drawings/drawing1.xml'], $f['xl/drawings/_rels/drawing1.xml.rels']);
        $hasDrawing = $this->drawing !== '';
        $drawRels = $this->files['xl/drawings/_rels/drawing1.xml.rels'] ?? '';
        $sheetsXml = ''; $wbRels = ''; $ct = '';
        $used = [];
        foreach ($this->sheets as $i => $s) {
            $n = $i + 1;
            $xml = $s->xml();
            if ($i > 0) $xml = str_replace(' tabSelected="1"', '', $xml);
            if ($hasDrawing) {
                $f["xl/worksheets/_rels/sheet$n.xml.rels"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing' . $n . '.xml"/></Relationships>';
                $f["xl/drawings/drawing$n.xml"] = $this->drawing;
                if ($drawRels !== '') $f["xl/drawings/_rels/drawing$n.xml.rels"] = $drawRels;
                $ct .= '<Override PartName="/xl/drawings/drawing' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
            } else {
                $xml = preg_replace('#<drawing r:id="[^"]*"/>#', '', $xml);
            }
            $f["xl/worksheets/sheet$n.xml"] = $xml;
            // a sheet name: 31 characters, none of : \ / ? * [ ], unique
            $nm = trim(preg_replace('#[:\\\\/?*\[\]]#', ' ', $s->name)) ?: 'Sheet' . $n;
            $nm = mb_substr($nm, 0, 31);
            $base = $nm; $k = 2;
            while (isset($used[mb_strtolower($nm)])) { $nm = mb_substr($base, 0, 28) . ' (' . $k++ . ')'; }
            $used[mb_strtolower($nm)] = 1;
            $sheetsXml .= '<sheet name="' . XlSheet::x($nm) . '" sheetId="' . $n . '" r:id="rIdS' . $n . '"/>';
            $wbRels .= '<Relationship Id="rIdS' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $f['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<workbookPr/><bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $sheetsXml . '</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
        $f['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels
            . '<Relationship Id="rIdSt" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdTh" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/></Relationships>';
        $media = '';
        foreach (array_keys($f) as $k) {
            if (strpos($k, 'xl/media/') === 0) {
                $e = strtolower(pathinfo($k, PATHINFO_EXTENSION));
                if (strpos($media, 'Extension="' . $e . '"') === false) $media .= '<Default Extension="' . $e . '" ContentType="image/' . ($e === 'jpg' ? 'jpeg' : $e) . '"/>';
            }
        }
        $x = fn($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $f['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $x($title) . '</dc:title><dc:subject>' . $x($subject) . '</dc:subject><dc:creator>ProcureEase</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>';
        $f['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>';
        $f['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' . $media
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $ct
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>';
        // the package parts first, as Excel likes them
        $order = ['[Content_Types].xml' => 1, '_rels/.rels' => 2, 'xl/workbook.xml' => 3, 'xl/_rels/workbook.xml.rels' => 4];
        uksort($f, fn($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9) ?: strcmp($a, $b));
        return docx_zip($f);
    }
}

/**
 * How tall a row needs to be for its wrapped text: [[column width (Excel units), text, font pt], …].
 * Never lower than $min.
 */
function xl_fit(array $cells, float $min): float {
    $h = $min;
    foreach ($cells as [$w, $text, $pt]) {
        $text = (string)$text;
        if ($text === '' || $w <= 0) continue;
        $per = max(1, (int)floor($w * 1.15 * 10 / max(6, $pt)));    // characters on one line
        $lines = 0;
        foreach (preg_split('/\r?\n/', $text) as $ln) $lines += max(1, (int)ceil(mb_strlen($ln) / $per));
        $h = max($h, $lines * $pt * 1.3 + 6);
    }
    return $h;
}

/** Sends a file to download and stops. */
function xl_send(string $bytes, string $filename, string $type): void {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}
