<?php
/* ═══════════════════════════════════════════════════════════════
   v104: Document Format — Export form (.docx) and Import form.

   Export: a Word file with every wording of the form in a table —
           Field | Value | Key — grouped as on the page. Edit the Value
           column in Word and import it back.
   Import: any .docx (the rows of its tables — a field's name, then its
           value — and paragraphs written "Field: value"), a .json (the
           file the earlier Export gave), or a .txt ("Field: value", one
           per line; the lines after one belong to it until the next field).
           A field is found by its Key or by its name on the page. What
           the file does not have is left as it is. Logos and the PPMP's
           columns are not part of the file.
   =============================================================== */
require_once __DIR__ . '/doc-format.php';
require_once __DIR__ . '/docx-lib.php';

const FMT_IO_KINDS = ['text', 'html', 'percent'];
const FMT_IO_MARK  = 'ProcureEase Document Format';

/** The fields that go into the file: [key => [label, kind, group]]. */
function fmt_io_fields(string $t): array {
    $out = [];
    foreach (DOC_FORMAT_SCHEMA[$t] ?? [] as $group => $fields) {
        foreach ($fields as $k => $meta) {
            if (in_array($meta[1], FMT_IO_KINDS, true)) $out[$k] = [$meta[0], $meta[1], $group];
        }
    }
    return $out;
}

/** The Export (.docx bytes). */
function fmt_export_docx(string $t, int $fy, string $who): string {
    // v104: the form itself — the PPMP, APP-CSE, Non-PS list, Annex or Market Scoping as it is filled in and printed
    require_once __DIR__ . '/doc-format-forms.php';
    return fmt_form_docx($t, $fy);
}

/** (before v104: the wording as a Field | Value | Key table — kept for reference, not used) */
function fmt_export_docx_table(string $t, int $fy, string $who): string {
    $label = DOC_FORMAT_TYPES[$t] ?? $t;
    $body  = docx_p($label . ' form — FY ' . $fy, ['bold' => true, 'size' => 30, 'color' => '1C3A5E', 'after' => 60]);
    $body .= docx_p('Exported ' . date('F j, Y g:i A') . ' by ' . $who . '.', ['color' => '6B7C8F', 'size' => 18, 'after' => 60]);
    $body .= docx_p('Change the words in the Value column, save, then use Import form on Document Format (' . $label . ' tab). '
                  . 'Leave the Key column as it is. {FY}, {FY-1} and the like are filled in with the year on the printout. '
                  . 'Logos and the PPMP\'s columns are not in this file; importing leaves them as they are.', ['size' => 18, 'after' => 60]);
    $body .= docx_p(FMT_IO_MARK . ' (' . $t . ')', ['color' => '9AA8B8', 'size' => 14, 'after' => 160]);
    $rows = [['Field', 'Value', 'Key']];
    $group = null;
    foreach (fmt_io_fields($t) as $k => [$lab, $kind, $g]) {
        if ($g !== $group) { $rows[] = ['section' => $g]; $group = $g; }
        $rows[] = [$lab, doc_fmt_raw($t, $k, $fy), $k];
    }
    $body .= docx_table($rows, [3000, 5900, 1900]);
    return docx_build($body, $label . ' form — FY ' . $fy);
}

/** Lower-case letters and digits only — "Form title" / "form_title" → "formtitle". */
function fmt_io_norm(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim($s)));
}

/**
 * Read an uploaded file: ['ok' => bool, 'error' => '', 'values' => [key => value], 'kind' => 'docx'|'json'|'txt'].
 */
function fmt_import_read(string $t, string $name, string $bytes): array {
    $fail = fn(string $m) => ['ok' => false, 'error' => $m, 'values' => [], 'kind' => ''];
    $label = DOC_FORMAT_TYPES[$t] ?? $t;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($bytes === '') return $fail('The file is empty.');
    if ($ext === 'pdf' || strncmp($bytes, '%PDF', 4) === 0) {
        return $fail('A PDF cannot be read back into the form. Open it in Word and save it as .docx, then import the .docx (a .json or .txt works too).');
    }
    if ($ext === 'doc' || strncmp($bytes, "\xD0\xCF\x11\xE0", 4) === 0) {
        return $fail('This is an old Word file (.doc). Open it in Word and save it as .docx (Word Document), then import the .docx.');
    }
    $fields = fmt_io_fields($t);

    // Which form a file says it is for, when it says
    $wrongForm = function (string $other) use ($t, $label): ?string {
        if ($other === '' || $other === $t) return null;
        $o = DOC_FORMAT_TYPES[$other] ?? $other;
        return "This file is the $o form, not the $label form. Open the $o tab to import it.";
    };

    // .docx
    if ($ext === 'docx' || strncmp($bytes, 'PK', 2) === 0) {
        $d = docx_read($bytes);
        if ($d === null) return $fail('This file could not be read as a Word document (.docx). Save it again in Word as .docx and import it.');
        foreach (array_merge($d['paras'], [$d['subject'] ?? '']) as $p) {
            if (preg_match('/' . preg_quote(FMT_IO_MARK, '/') . '\s*\(([a-z]+)\)/', $p, $m) && ($e = $wrongForm($m[1]))) return $fail($e);
        }
        // v104: a form exported from here (or a copy of it): its wording is in tagged content controls
        $tagged = array_intersect_key($d['tags'] ?? [], $fields);
        if ($tagged) return ['ok' => true, 'error' => '', 'values' => $tagged, 'kind' => 'form'];
        $vals = [];
        foreach ($d['tables'] as $rows) foreach ($rows as $cells) fmt_io_row($fields, $cells, $vals);
        $lines = [];
        foreach ($d['paras'] as $p) $lines[] = $p;
        fmt_io_lines($fields, $lines, $vals, false);
        return ['ok' => true, 'error' => '', 'values' => $vals, 'kind' => 'docx'];
    }

    // text: drop a byte-order mark, read UTF-16 and Windows text as UTF-8
    if (strncmp($bytes, "\xFF\xFE", 2) === 0)      $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
    elseif (strncmp($bytes, "\xFE\xFF", 2) === 0)  $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
    elseif (strncmp($bytes, "\xEF\xBB\xBF", 3) === 0) $bytes = substr($bytes, 3);
    if (!mb_check_encoding($bytes, 'UTF-8')) $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    if (strpos($bytes, "\0") !== false) {
        return $fail('This file cannot be read as a form. Import a Word file (.docx), or a .json or .txt file.');
    }

    // .json
    $trim = ltrim($bytes);
    if ($ext === 'json' || ($trim !== '' && $trim[0] === '{')) {
        $j = json_decode($bytes, true);
        if (!is_array($j)) {
            if ($ext === 'json') return $fail('This .json file could not be read (it is not valid JSON).');
        } else {
            if (isset($j['document_type']) && ($e = $wrongForm((string)$j['document_type']))) return $fail($e);
            $src = (isset($j['data']) && is_array($j['data'])) ? $j['data'] : $j;
            $vals = [];
            $map = fmt_io_map($fields);
            foreach ($src as $k => $v) {
                if (!is_scalar($v)) continue;
                $key = isset($fields[$k]) ? $k : fmt_io_take($map, (string)$k, $vals);
                if ($key !== null && !array_key_exists($key, $vals)) $vals[$key] = (string)$v;
            }
            return ['ok' => true, 'error' => '', 'values' => $vals, 'kind' => 'json'];
        }
    }

    // .txt (or any other plain text)
    if (preg_match('/' . preg_quote(FMT_IO_MARK, '/') . '\s*\(([a-z]+)\)/', $bytes, $m) && ($e = $wrongForm($m[1]))) return $fail($e);
    $vals = [];
    fmt_io_lines($fields, preg_split("/\r\n|\r|\n/", $bytes), $vals, true);
    return ['ok' => true, 'error' => '', 'values' => $vals, 'kind' => 'txt'];
}

/** [norm(key) => [key], norm(label) => [key, key …]] — a name two fields share lists both, in order. */
function fmt_io_map(array $fields): array {
    $m = ['key' => [], 'label' => []];
    foreach ($fields as $k => [$lab]) {
        $m['key'][fmt_io_norm($k)] = $k;
        $m['label'][fmt_io_norm($lab)][] = $k;
    }
    return $m;
}

/** The field a name stands for (key first, then the field's name; a shared name: the next not yet found). */
function fmt_io_take(array $map, string $name, array $have): ?string {
    $n = fmt_io_norm($name);
    if ($n === '') return null;
    if (isset($map['key'][$n]) && !isset($map['label'][$n])) return $map['key'][$n];
    if (isset($map['label'][$n])) {
        foreach ($map['label'][$n] as $k) if (!array_key_exists($k, $have)) return $k;
        return null;
    }
    return $map['key'][$n] ?? null;
}

/** One table row: a cell naming a field (its Key, or its name), and the first other cell is its value. */
function fmt_io_row(array $fields, array $cells, array &$vals): void {
    if (count($cells) < 2) return;
    $map = fmt_io_map($fields);
    $key = null; $used = [];
    // the Key column, when there is one, decides
    foreach ($cells as $i => $c) {
        $c = trim($c);
        if (isset($fields[$c])) { $key = $c; $used[] = $i; break; }
    }
    foreach ($cells as $i => $c) {
        if (in_array($i, $used, true)) continue;
        $n = fmt_io_norm($c);
        if ($n === '') continue;
        if ($key === null) {
            $k = fmt_io_take($map, $c, $vals);
            if ($k !== null) { $key = $k; $used[] = $i; break; }
        } elseif (in_array($key, $map['label'][$n] ?? [], true) || ($map['key'][$n] ?? null) === $key) {
            $used[] = $i; break;
        }
    }
    if ($key === null || array_key_exists($key, $vals)) return;
    foreach ($cells as $i => $c) {
        if (in_array($i, $used, true)) continue;
        $vals[$key] = rtrim(str_replace("\r", '', $c));
        return;
    }
}

/**
 * Lines "Field: value" (also "Field = value", or the field and its value
 * separated by a tab). $more: the lines after one, until the next field, are
 * part of its value (a .txt); a .docx paragraph is one field each.
 */
function fmt_io_lines(array $fields, array $lines, array &$vals, bool $more): void {
    $map = fmt_io_map($fields);
    $cur = null;
    foreach ($lines as $line) {
        $line = rtrim((string)$line);
        $key = null; $val = '';
        if (preg_match('/^\s*([^:=\t\n]{1,120}?)\s*(?::|=|\t)\s?(.*)$/su', $line, $m)) {
            $key = fmt_io_take($map, $m[1], $vals);
            if ($key !== null && array_key_exists($key, $vals)) $key = null;
            $val = $m[2];
        }
        if ($key !== null) {
            $vals[$key] = $val;
            $cur = $more ? $key : null;
            continue;
        }
        if ($cur !== null) $vals[$cur] .= "\n" . $line;
    }
    foreach ($vals as $k => $v) $vals[$k] = rtrim($v);
}

/**
 * Save what was read. Returns ['saved' => n, 'same' => n, 'found' => n, 'skipped' => [msg …]].
 * The same limits as Save on the page: text 300 characters, longer text
 * 6,000, a rate a number from 0 to 100. A value that breaks one is left out
 * (the form keeps what it had).
 */
function fmt_import_apply(string $t, array $vals, int $fy, string $who, bool $fromForm = false): array {
    $fields = fmt_io_fields($t);
    $r = ['saved' => 0, 'same' => 0, 'found' => 0, 'skipped' => []];
    $todo = [];
    if ($fromForm) require_once __DIR__ . '/doc-format-forms.php';
    foreach ($vals as $k => $v) {
        if (!isset($fields[$k])) continue;
        [$lab, $kind] = $fields[$k];
        $r['found']++;
        $v = trim(str_replace("\r", '', (string)$v));
        if ($fromForm) {
            // the form shows the wording as printed: a numbered line "1.0<tab>…" is "1.0 …", and the year / rate are filled in
            $v = preg_replace('/[ ]*\t[ ]*/', ' ', $v);
            $shown = trim(preg_replace('/[ ]*\t[ ]*/', ' ', str_replace("\r", '', fmt_show($t, $k, $fy))));
            if (fmt_io_lines_same($v, $shown)) { $r['same']++; continue; }
            $v = fmt_io_retoken($t, $k, $v, $fy);
        }
        if ($kind === 'percent') {
            require_once __DIR__ . '/price-provision.php';
            $p = nonps_rate_value(trim(str_replace('%', '', $v)));
            if ($p === null) { $r['skipped'][] = "$lab must be a number from 0 to 100."; continue; }
            $v = nonps_rate_text($p);
        } else {
            $cap = $kind === 'html' ? 6000 : 300;
            if (mb_strlen($v) > $cap) { $r['skipped'][] = "$lab is too long (" . number_format($cap) . ' characters max).'; continue; }
        }
        if ($v === trim(str_replace("\r", '', doc_fmt_raw($t, $k, $fy)))) { $r['same']++; continue; }
        $todo[$k] = $v;
    }
    if ($todo) {
        doc_fmt_ensure_year($t, $fy, $who);
        foreach ($todo as $k => $v) { doc_fmt_save($t, $k, $v, $who, $fy); $r['saved']++; }
    }
    return $r;
}


/** The same text, line by line (spaces at the ends of a line and empty lines aside). */
function fmt_io_lines_same(string $a, string $b): bool {
    $n = fn($s) => array_values(array_filter(array_map('trim', preg_split('/\n/', $s)), fn($l) => $l !== ''));
    return $n($a) === $n($b);
}

/**
 * v104: wording edited on the form, back with its fields: where the stored
 * wording has {FY} / {FY-1} / {rate}, the year / rate the form showed goes
 * back to the field, so next year's form still shows next year's.
 */
function fmt_io_retoken(string $t, string $k, string $v, int $fy): string {
    $raw = doc_fmt_raw($t, $k, $fy);
    if (strpos($raw, '{FY-1}') !== false) $v = preg_replace('/(?<!\d)' . ($fy - 1) . '(?!\d)/', '{FY-1}', $v);
    if (strpos($raw, '{FY}') !== false)   $v = preg_replace('/(?<!\d)' . $fy . '(?!\d)/', '{FY}', $v);
    if (strpos($raw, '{rate}') !== false && $t === 'appcse') {
        $rate = trim(str_replace('%', '', doc_fmt_raw('appcse', 'rate_b', $fy)));
        if ($rate === '' || !is_numeric($rate)) $rate = '10';
        $rate = rtrim(rtrim(number_format((float)$rate, 4, '.', ''), '0'), '.');
        $v = preg_replace('/(?<![\d.])' . preg_quote($rate, '/') . '%/', '{rate}%', $v);
    }
    return $v;
}
