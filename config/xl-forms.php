<?php
/* ═══════════════════════════════════════════════════════════════
   v105: a department's documents as the Procurement Unit's own files —
   PPMP.xlsx, APPCSE.xlsx, NONPS.xlsx, ANNEX.xlsx (config/xl-io.php) and
   MARKET SCOPING.docx (config/docx-html.php) — out of the system, and
   back in.

   Export: the document as it is in the system, on the form's template
   (assets/xl-templates), with as many item rows as it has.
   Import: what a file holds, read into plain data for the page to show
   first and then put on the form (includes/xl-io-ui.php). Nothing is
   saved here: the page saves it the way it saves anything typed, with
   every check that goes with that.
   =============================================================== */
require_once __DIR__ . '/xl-io.php';
require_once __DIR__ . '/doc-format.php';
require_once __DIR__ . '/signatories.php';
require_once __DIR__ . '/price-provision.php';

const XL_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** Text in a cell that reads like a month — "02/2027", "Feb", "February 2027", a date — as the month's name; anything else as it is. */
function xl_month_of(array $sh, int $r, int $c): string {
    $v = $sh['cells'][$r][$c] ?? '';
    if (is_float($v)) {
        if (!empty($sh['dates'][$r][$c])) return XL_MONTHS[(int)substr(xl_date($v), 5, 2) - 1];
        if ($v >= 1 && $v <= 12 && floor($v) == $v) return XL_MONTHS[(int)$v - 1];
        return xl_text($sh, $r, $c);
    }
    $t = xl_text($sh, $r, $c);
    if ($t === '') return '';
    if (preg_match('#^(\d{1,2})\s*[/\-.]\s*(\d{2}|\d{4})$#', $t, $m) && (int)$m[1] >= 1 && (int)$m[1] <= 12) return XL_MONTHS[(int)$m[1] - 1];
    if (preg_match('#^(\d{4})\s*[/\-.]\s*(\d{1,2})$#', $t, $m) && (int)$m[2] >= 1 && (int)$m[2] <= 12) return XL_MONTHS[(int)$m[2] - 1];
    if (preg_match('#^([A-Za-z]{3,9})\.?(?:[\s,\-/]+(\d{2}|\d{4}))?$#', $t, $m)) {
        $k = strtolower(substr($m[1], 0, 3));
        foreach (XL_MONTHS as $i => $mn) {
            if (strtolower(substr($mn, 0, 3)) === $k && stripos($mn, rtrim($m[1], '.')) === 0) return $mn;
            if ($k === 'sep' && strtolower($m[1]) === 'sept' && $i === 8) return $mn;
        }
    }
    return $t;
}

/** The first cell (row, col) whose text matches $re, looking in rows $r1…$r2. */
function xl_find(array $sh, string $re, int $r1 = 1, int $r2 = 100000): ?array {
    foreach ($sh['cells'] as $r => $cols) {
        if ($r < $r1 || $r > $r2) continue;
        ksort($cols);
        foreach ($cols as $c => $v) if (!is_float($v) && preg_match($re, xl_text($sh, $r, $c))) return [$r, $c];
    }
    return null;
}

/** The next cell to the right in the row with something in it. */
function xl_right(array $sh, int $r, int $c, int $max = 30): string {
    for ($k = $c + 1; $k <= $c + $max; $k++) { $t = xl_text($sh, $r, $k); if ($t !== '' && $t !== ':') return $t; }
    return '';
}

/** A signature under a label ("Prepared by:"): the name and the position under it, in the same column. */
function xl_signature(array $sh, string $labelRe, int $from = 1): ?array {
    $at = xl_find($sh, $labelRe, $from);
    if (!$at) return null;
    [$r, $c] = $at;
    $lines = [];
    for ($k = $r + 1; $k <= $r + 7 && count($lines) < 2; $k++) {
        $t = xl_text($sh, $k, $c);
        if ($t === '' && $c > 1) $t = '';
        if ($t === '' || preg_match('#^(date|signature|\[)#i', $t) || preg_match('#^_+$#', $t)) continue;
        $lines[] = $t;
    }
    if (!$lines) return null;
    return ['name' => $lines[0], 'position' => $lines[1] ?? ''];
}

/** The rows of a sheet that are hidden (a guide row, say). */
function xl_row_hidden(array $sh, int $r): bool { return !empty($sh['hidden_rows'][$r]); }

/* ═══════════════════════════ PPMP ═══════════════════════════ */

const XL_PPMP_FIELDS = ['description', 'proj_type', 'quantity', 'mode', 'pre_conference', 'activity_start', 'activity_end', 'delivery', 'fund', 'amount', 'supporting_docs', 'remarks'];

/** One sheet read as a PPMP form; null when it is not one. */
function xl_ppmp_sheet(array $sh): ?array {
    $title = xl_find($sh, '#PROCUREMENT\s+MANAGEMENT\s+PLAN#i', 1, 20);
    if (!$title) return null;
    $out = ['name' => $sh['name'], 'hidden' => $sh['hidden'], 'fy' => 0, 'unit' => '', 'ppmp_no' => '', 'final' => null, 'items' => [],
            'prepared' => null, 'approved' => null, 'total' => 0.0];
    $tt = xl_text($sh, $title[0], $title[1]);
    if (preg_match('#\bNO\.?\s*:?\s*([A-Za-z0-9][^_]*?)\s*_*\s*$#i', $tt, $m) && !preg_match('#^_*$#', $m[1])) $out['ppmp_no'] = trim($m[1]);
    if ($at = xl_find($sh, '#Fiscal\s*Year#i', 1, 30)) {
        $t = xl_text($sh, $at[0], $at[1]);
        if (preg_match('#(\d{4})#', $t, $m)) $out['fy'] = (int)$m[1];
        elseif (preg_match('#(\d{4})#', xl_right($sh, $at[0], $at[1]), $m)) $out['fy'] = (int)$m[1];
    }
    if ($at = xl_find($sh, '#End[-\s]*User#i', 1, 30)) {
        $t = xl_text($sh, $at[0], $at[1]);
        $u = trim(preg_replace('#^.*?Unit\s*:?\s*#i', '', $t));
        $out['unit'] = $u !== '' && $u !== $t ? $u : xl_right($sh, $at[0], $at[1]);
    }
    // INDICATIVE / FINAL: the tick in the box before one of them (a shape, or a mark typed in a cell)
    $ind = xl_find($sh, '#^\s*INDICATIVE\s*$#i', 1, 20);
    $fin = xl_find($sh, '#^\s*FINAL\s*$#i', 1, 20);
    if ($ind && $fin) {
        $ticks = [];
        foreach ($sh['shapes'] as $s) if (($s['kind'] === 'cxn' || in_array($s['text'], ['/', '✓', '✔', 'x', 'X', '√'], true)) && abs($s['row'] - $ind[0]) <= 2) $ticks[] = $s['col'];
        foreach ([$ind[0]] as $rr) for ($cc = 1; $cc <= 20; $cc++) if (in_array(xl_text($sh, $rr, $cc), ['/', '✓', '✔', 'x', 'X', '√', '☑', '■'], true)) $ticks[] = $cc;
        foreach ($ticks as $tc) {
            $di = $ind[1] - $tc; $df = $fin[1] - $tc;
            if ($di >= 0 && ($df < 0 || $di <= $df)) $out['final'] = 0;
            elseif ($df >= 0) $out['final'] = 1;
        }
    }
    // the columns: "Column 1" … "Column 12" when the form has them, else A–L under the headings
    $map = [];
    $hdr = xl_find($sh, '#^Column\s*1$#i', 1, 60);
    if ($hdr) {
        foreach ($sh['cells'][$hdr[0]] as $c => $v) if (preg_match('#^Column\s*(\d+)$#i', xl_text($sh, $hdr[0], $c), $m) && (int)$m[1] >= 1 && (int)$m[1] <= 12) $map[(int)$m[1] - 1] = $c;
        $start = $hdr[0] + 1;
    } else {
        $g = xl_find($sh, '#General\s+Description#i', 1, 60);
        if (!$g) return $out;
        $start = $g[0] + 1;
        for ($i = 0; $i < 12; $i++) $map[$i] = $g[1] + $i;
    }
    $end = PHP_INT_MAX;
    foreach ($sh['cells'] as $r => $cols) {
        if ($r < $start) continue;
        foreach ($cols as $c => $v) {
            $t = is_float($v) ? '' : xl_text($sh, $r, $c);
            if (preg_match('#^(TOTAL|GRAND\s+TOTAL)\b#i', $t) || preg_match('#^(Prepared|Submitted|Approved)\s+by#i', $t)) { $end = $r; break 2; }
        }
    }
    foreach ($sh['cells'] as $r => $cols) {
        if ($r < $start || $r >= $end || xl_row_hidden($sh, $r)) continue;
        $e = [];
        foreach (XL_PPMP_FIELDS as $i => $f) {
            $c = $map[$i] ?? 0;
            if (!$c) { $e[$f] = $f === 'amount' ? 0.0 : ''; continue; }
            if ($f === 'amount') { $e[$f] = round((float)(xl_num($sh['cells'][$r][$c] ?? '') ?? 0), 2); continue; }
            if (in_array($f, ['activity_start', 'activity_end', 'delivery'], true)) { $e[$f] = xl_month_of($sh, $r, $c); continue; }
            $e[$f] = xl_text($sh, $r, $c);
        }
        if ($e['description'] === '' && $e['amount'] == 0) continue;
        // the column guide under the headings ("Refers to the type of procurement …") is not a line
        if ($e['amount'] == 0 && preg_match('#^(Refers to|Indicate|This refers|The fund source|Use Estimated|This column)#i', $e['description'])) continue;
        if (in_array(strtolower($e['pre_conference']), ['yes', 'no'], true)) $e['pre_conference'] = ucfirst(strtolower($e['pre_conference']));
        $out['items'][] = $e;
        $out['total'] += $e['amount'];
    }
    $out['total'] = round($out['total'], 2);
    $out['prepared'] = xl_signature($sh, '#^Prepared\s+by#i', $end === PHP_INT_MAX ? 1 : $end);
    $out['approved'] = xl_signature($sh, '#^Approved\s+by#i', $end === PHP_INT_MAX ? 1 : $end);
    return $out;
}

/** What a file holds for the PPMP of $fy: the sheets that are that year's PPMP, and the ones that are not. */
function xl_ppmp_read(array $book, int $fy): array {
    $found = []; $other = [];
    foreach ($book['sheets'] as $sh) {
        $p = xl_ppmp_sheet($sh);
        if ($p === null) { $other[] = ['name' => $sh['name'], 'why' => 'not a PPMP form']; continue; }
        if ($p['fy'] !== $fy) { $other[] = ['name' => $sh['name'], 'why' => $p['fy'] ? 'Fiscal Year ' . $p['fy'] : 'no Fiscal Year on it']; continue; }
        if (!$p['items']) { $other[] = ['name' => $sh['name'], 'why' => 'Fiscal Year ' . $fy . ', but no procurement items on it']; continue; }
        $found[] = $p;
    }
    return ['sheets' => $found, 'skipped' => $other];
}

/** The PPMP as PPMP.xlsx. */
function xl_ppmp_export(array $ppmp, array $entries): string {
    require_once __DIR__ . '/office-sets.php';
    require_once __DIR__ . '/ppmp-columns.php';
    $fy = (int)$ppmp['fy'];
    $dept = (string)$ppmp['dept_code'];
    doc_fmt_use_year($fy);
    $bk = new XlBook('PPMP.xlsx');
    $s = $bk->sheet('PPMP');
    $s->copy(1, 17);
    $no = os_ppmp_no((string)($ppmp['ppmp_no'] ?? ''), $dept);
    $s->set(5, 'A', trim(doc_fmt('ppmp', 'doc_title', $fy)) . ' ' . ($no !== '' ? $no : '___'));
    $s->set(10, 'A', 'Fiscal Year : ' . $fy);
    $deptName = '';
    try { $q = db()->prepare('SELECT name FROM departments WHERE code = ?'); $q->execute([$dept]); $deptName = (string)$q->fetchColumn(); } catch (Throwable $t) {}
    require_once __DIR__ . '/ppmp-unit.php';
    $s->set(11, 'A', 'End-User or Implementing Unit: ' . ppmp_unit_label($ppmp, $dept, $deptName));   // v137: as typed on the PPMP
    // the headings, as the year's Document Format words them
    $cols = ['A' => 'col_desc', 'B' => 'col_type', 'C' => 'col_qty', 'D' => 'col_mode', 'E' => 'col_pre', 'F' => 'col_start', 'G' => 'col_end',
             'H' => 'col_deliv', 'I' => 'col_fund', 'J' => 'col_amount', 'K' => 'col_docs', 'L' => 'col_remarks'];
    foreach ($cols as $col => $k) $s->set(in_array($col, ['K', 'L'], true) ? 14 : 15, $col, doc_fmt('ppmp', $k, $fy));
    $s->set(14, 'A', doc_fmt('ppmp', 'grp_details', $fy));
    $s->set(14, 'F', doc_fmt('ppmp', 'grp_timeline', $fy));
    $s->set(14, 'I', doc_fmt('ppmp', 'grp_funding', $fy));
    $pre = trim(doc_fmt('ppmp', 'col_num_prefix', $fy)) ?: 'Column';
    for ($i = 1; $i <= 12; $i++) $s->set(16, $i, $pre . ' ' . $i);
    // INDICATIVE / FINAL: the tick (the line drawn in the box) goes in the box of the one chosen
    if (!empty($ppmp['is_final'])) {
        $bk->setDrawing(preg_replace_callback('#<xdr:oneCellAnchor>.*?</xdr:oneCellAnchor>#s', function ($m) {
            if (strpos($m[0], 'cxnSp') === false) return $m[0];
            return preg_replace('#<xdr:from><xdr:col>4</xdr:col>(<xdr:colOff>\d+</xdr:colOff><xdr:row>\d+</xdr:row>)<xdr:rowOff>123825</xdr:rowOff>#',
                                '<xdr:from><xdr:col>6</xdr:col>$1<xdr:rowOff>47625</xdr:rowOff>', $m[0]);
        }, $bk->drawing()) ?? $bk->drawing());
    }
    $widths = ['A' => 37.38, 'B' => 30, 'C' => 29.38, 'D' => 28.88, 'E' => 19.88, 'F' => 24.38, 'G' => 19.88, 'H' => 20, 'I' => 27, 'J' => 22, 'K' => 29, 'L' => 46.38];
    $first = $s->last + 1; $total = 0.0;
    foreach ($entries ?: [[]] as $e) {
        $r = $s->copy(19);
        $vals = [
            'A' => (string)($e['description'] ?? ''), 'B' => (string)($e['proj_type'] ?? ''), 'C' => (string)($e['quantity'] ?? ''),
            'D' => (string)($e['mode'] ?? ''), 'E' => (string)($e['pre_conference'] ?? ''), 'F' => (string)($e['activity_start'] ?? ''),
            'G' => (string)($e['activity_end'] ?? ''), 'H' => (string)($e['delivery'] ?? ''), 'I' => (string)($e['fund'] ?? ''),
            'K' => (string)($e['supporting_docs'] ?? ''), 'L' => (string)($e['remarks'] ?? ''),
        ];
        $fit = [];
        foreach ($vals as $col => $v) { $s->set($r, $col, $v); $fit[] = [$widths[$col], $v, 16]; }
        if ($e) { $amt = round((float)($e['amount'] ?? 0), 2); $s->set($r, 'J', $amt); $total += $amt; }
        else $s->set($r, 'J', null);
        $s->height($r, xl_fit($fit, 45), true);
    }
    $last = $s->last;
    $off = $s->copy(24, 34) - 24;
    $s->set(24 + $off, 'I', 'TOTAL BUDGET:');
    $s->set(24 + $off, 'J', ['f' => "SUM(J$first:J$last)", 'v' => round($total, 2)]);
    // the signatures, as the form shows them (ppmp-edit.php)
    $dh = department_head($dept);
    $sigName = trim((string)($ppmp['sig_name'] ?? '')) ?: (string)$dh['name'];
    $sigPos  = trim((string)($ppmp['sig_position'] ?? '')) ?: (string)$dh['position'];
    $ab = ppmp_approved_by(array_merge($ppmp, ['dept_code' => $dept]));
    $s->set(27 + $off, 'B', doc_fmt('ppmp', 'sig_prepared', $fy) ?: 'Prepared by:');
    $s->set(27 + $off, 'E', doc_fmt('ppmp', 'sig_approved', $fy) ?: 'Approved by:');
    $s->set(29 + $off, 'B', mb_strtoupper($sigName));
    $s->set(31 + $off, 'B', $sigPos);
    $s->set(29 + $off, 'E', mb_strtoupper((string)$ab['name']));
    $s->set(31 + $off, 'E', (string)$ab['position']);
    $dp = trim((string)($ppmp['date_prepared'] ?? ''));
    $ds = trim((string)($ppmp['date_submitted_head'] ?? ''));
    $s->set(33 + $off, 'B', 'Date : ' . ($dp !== '' ? $dp : '____________'));
    $s->set(33 + $off, 'E', 'Date : ' . ($ds !== '' ? $ds : '____________'));
    return $bk->bytes('PPMP · FY ' . $fy, 'ProcureEase PPMP');
}

/* ═══════════════════════════ Non-PS ═══════════════════════════ */

function xl_nonps_read(array $book, int $fy): array {
    $err = 'This file has no List of Non-PS Supplies (a table with “Particulars”, “Unit”, “Quantity” and “Unit Cost”).';
    foreach ($book['sheets'] as $sh) {
        $h = xl_find($sh, '#^Particulars$#i');
        if (!$h) continue;
        [$hr] = $h;
        $col = ['no' => 0, 'part' => $h[1], 'unit' => 0, 'qty' => 0, 'cost' => 0, 'total' => 0];
        foreach ($sh['cells'][$hr] as $c => $v) {
            $t = strtolower(xl_text($sh, $hr, $c));
            if (preg_match('#^no\.?$#', $t)) $col['no'] = $c;
            elseif ($t === 'unit') $col['unit'] = $c;
            elseif (strpos($t, 'quantity') === 0 || $t === 'qty') $col['qty'] = $c;
            elseif (strpos($t, 'unit cost') === 0 || strpos($t, 'unit price') === 0) $col['cost'] = $c;
            elseif (strpos($t, 'total') === 0) $col['total'] = $c;
        }
        if (!$col['qty'] || !$col['cost']) continue;
        $out = ['sheet' => $sh['name'], 'fy' => 0, 'items' => [], 'total' => 0.0, 'prepared' => null];
        if ($at = xl_find($sh, '#^FY\s*\d{4}$|Fiscal\s+Year#i', 1, $hr)) {
            if (preg_match('#(\d{4})#', xl_text($sh, $at[0], $at[1]) . ' ' . xl_right($sh, $at[0], $at[1]), $m)) $out['fy'] = (int)$m[1];
        }
        $end = $hr;
        foreach ($sh['cells'] as $r => $cols) {
            if ($r <= $hr) continue;
            $p = xl_text($sh, $r, $col['part']);
            $lead = '';
            foreach ($cols as $c => $v) if (!is_float($v)) { $lead = xl_text($sh, $r, $c); break; }
            if (preg_match('#^(TOTAL|GRAND\s+TOTAL|VALUE[-\s]ADDED|LOGISTICS|INFLATION)\b#i', $p) || preg_match('#^(TOTAL|GRAND\s+TOTAL)\b#i', $lead)
                || preg_match('#^Prepared\s+by#i', $lead)) { $end = $r; break; }
            $q = $col['qty'] ? xl_num($sh['cells'][$r][$col['qty']] ?? '') : null;
            $uc = xl_num($sh['cells'][$r][$col['cost']] ?? '');
            $tc = $col['total'] ? xl_num($sh['cells'][$r][$col['total']] ?? '') : null;
            $unit = $col['unit'] ? xl_text($sh, $r, $col['unit']) : '';
            if ($p === '' && !$q && !$uc) continue;
            if ($uc === null && $tc !== null && $q) $uc = round($tc / $q, 2);
            $qty = (int)round((float)($q ?? 0));
            $uc = round((float)($uc ?? 0), 2);
            $out['items'][] = ['particulars' => $p, 'unit' => $unit, 'quantity' => $qty, 'unit_cost' => $uc];
            $out['total'] += $qty * $uc;
            $end = $r + 1;
        }
        $out['total'] = round($out['total'], 2);
        $out['prepared'] = xl_signature($sh, '#^Prepared\s+by#i', $end);
        if (!$out['items']) return ['error' => 'The List of Non-PS Supplies in this file has no items.'];
        return $out;
    }
    return ['error' => $err];
}

function xl_nonps_export(array $sub, array $items, string $deptName, array $u = []): string {
    require_once __DIR__ . '/price-provision.php';
    $fy = (int)$sub['fy'];
    doc_fmt_use_year($fy);
    $bk = new XlBook('NONPS.xlsx');
    $s = $bk->sheet('List of Non-PS');
    $s->copy(1, 12);
    $s->set(1, 'A', doc_fmt('nonps', 'rep_line', $fy));
    $s->set(2, 'A', doc_fmt('nonps', 'org_name', $fy));
    $s->set(3, 'A', doc_fmt('nonps', 'address', $fy));
    $s->set(6, 'A', doc_fmt('nonps', 'unit_line', $fy));
    $s->set(8, 'A', doc_fmt('nonps', 'doc_title', $fy));
    $s->set(9, 'A', 'FY ' . $fy);
    foreach (['A' => 'col_no', 'B' => 'col_part', 'C' => 'col_unit', 'D' => 'col_qty', 'E' => 'col_cost', 'F' => 'col_total'] as $c => $k) $s->set(12, $c, doc_fmt('nonps', $k, $fy));
    $first = $s->last + 1; $sum = 0.0; $n = 0;
    foreach ($items ?: [[]] as $it) {
        $r = $s->copy(13);
        if (!$it) { foreach (['A', 'B', 'C', 'D', 'E'] as $c) $s->set($r, $c, null); $s->set($r, 'F', ['f' => "D$r*E$r", 'v' => 0]); continue; }
        $n++;
        $q = (int)$it['quantity']; $uc = round((float)$it['unit_cost'], 2);
        $s->set($r, 'A', $n);
        $s->set($r, 'B', (string)$it['particulars']);
        $s->set($r, 'C', (string)$it['unit']);
        $s->set($r, 'D', $q);
        $s->set($r, 'E', $uc);
        $s->set($r, 'F', ['f' => "D$r*E$r", 'v' => round($q * $uc, 2)]);
        $sum += round((float)$it['total_cost'], 2);
        $s->height($r, xl_fit([[36, (string)$it['particulars'], 11]], 26.25), true);
    }
    $last = $s->last;
    $tr = $s->copy(19);
    $s->set($tr, 'B', 'TOTAL');
    $s->set($tr, 'F', ['f' => "SUM(F$first:F$last)", 'v' => round($sum, 2)]);
    // + VAT, logistics, inflation, and the GRAND TOTAL — as on the form
    $np = nonps_provision($sum);
    foreach ($np['lines'] as $l) {
        $r = $s->copy(19);
        $s->set($r, 'B', $l['label'] . ' (' . nonps_rate_text((float)$l['rate']) . '%)');
        $s->set($r, 'F', ['f' => "ROUND(F$tr*" . ((float)$l['rate'] / 100) . ',2)', 'v' => $l['amount']]);
    }
    $g = $s->copy(19);
    $s->set($g, 'B', doc_fmt('nonps', 'lbl_grand', $fy));
    $s->set($g, 'F', ['f' => 'SUM(F' . $tr . ':F' . ($g - 1) . ')', 'v' => $np['grand']]);
    $off = $s->copy(20, 28) - 20;
    // as the list shows them (nonps-edit.php): a name kept on it, else who filed it, else — still being prepared — who prepares it
    $editable = in_array((string)($sub['status'] ?? ''), ['draft', 'revision'], true);
    $prepared = trim((string)($sub['prepared_by'] ?? '')) ?: trim((string)($sub['submitted_by'] ?? ''));
    if ($prepared === '' && $editable) $prepared = trim((string)($u['full_name'] ?? ''));
    $desig = trim((string)($sub['prepared_designation'] ?? '')) ?: (trim((string)($u['position'] ?? '')) ?: $deptName);
    $s->set(24 + $off, 'A', doc_fmt('nonps', 'sig_prepared', $fy) ?: 'Prepared by:');
    $s->set(27 + $off, 'A', mb_strtoupper($prepared));
    $s->set(28 + $off, 'A', $desig);
    return $bk->bytes('List of Non-PS Supplies · FY ' . $fy, 'ProcureEase Non-PS');
}

/* ═══════════════════════════ Annex ═══════════════════════════ */

const XL_ANNEX_GROUPS = ['Supplies and Materials', 'Honoraria', 'Venue (Proposed Location)', 'Meals and Snack', 'Others', 'Registration Fee'];

/** A group heading of the items table → the system's group (null when the text is an item). */
function xl_annex_group(string $t): ?string {
    $k = strtolower(trim($t));
    foreach (XL_ANNEX_GROUPS as $g) if ($k === strtolower($g)) return $g;
    if (preg_match('#^supplies(\s+and\s+materials)?$#', $k)) return 'Supplies and Materials';
    if (preg_match('#^honorari(a|um)$#', $k)) return 'Honoraria';
    if (preg_match('#^venue\b#', $k)) return 'Venue (Proposed Location)';
    if (preg_match('#^meals?\b#', $k)) return 'Meals and Snack';
    if (preg_match('#^registration(\s+fees?)?$#', $k)) return 'Registration Fee';
    if ($k === 'others' || $k === 'other') return 'Others';
    return null;
}

function xl_annex_sheet(array $sh): ?array {
    $t = xl_find($sh, '#^A\s*N\s*N\s*E\s*X\s+([A-E])\s*\d*$#i', 1, 15);
    if (!$t) return null;
    preg_match('#([A-E])\s*\d*$#i', xl_text($sh, $t[0], $t[1]), $m);
    $o = ['name' => $sh['name'], 'letter' => strtoupper($m[1]), 'unit' => '', 'subtitle' => xl_text($sh, $t[0] + 1, $t[1]), 'fy' => 0, 'item_note' => '',
          'project_name' => '', 'general_description' => '', 'nep_account_1' => '', 'allocated_budget_1' => 0.0, 'nep_account_2' => '', 'allocated_budget_2' => 0.0,
          'total_estimated' => 0.0, 'inclusive_dates' => '', 'items' => [], 'total' => 0.0];
    // the unit: the line above the title that is not the letterhead
    for ($r = $t[0] - 1; $r >= 1; $r--) {
        $x = xl_text($sh, $r, 1);
        if ($x === '') continue;
        if (!preg_match('#Republic|STATE\s+COLLEGE|Brgy|Baler|Aurora\s+Phil#i', $x)) $o['unit'] = $x;
        break;
    }
    if ($at = xl_find($sh, '#Fiscal\s+Year#i', $t[0], $t[0] + 4)) if (preg_match('#(\d{4})#', xl_text($sh, $at[0], $at[1]), $mm)) $o['fy'] = (int)$mm[1];
    if ($at = xl_find($sh, '#^\*?\s*Can\s+be\s+used#i', 1, 30)) $o['item_note'] = ltrim(xl_text($sh, $at[0], $at[1]), '* ');
    $val = function (int $r) use ($sh): string {
        for ($c = 2; $c <= 8; $c++) { $x = xl_text($sh, $r, $c); if ($x !== '' && $x !== ':' && !preg_match('#^\#\d$#', $x)) return $x; }
        return '';
    };
    $nep = 0;
    $hdrRow = 0;
    foreach ($sh['cells'] as $r => $cols) {
        if ($r <= $t[0]) continue;
        $a = xl_text($sh, $r, 1);
        if ($a === '') continue;
        if (preg_match('#^Items\s+and\s+Specifications#i', $a)) { $hdrRow = $r; break; }
        if (preg_match('#^Project\s+Name#i', $a)) $o['project_name'] = $val($r);
        elseif (preg_match('#^General\s+Description#i', $a)) $o['general_description'] = $val($r);
        elseif (preg_match('#^NEP\s+Account#i', $a)) { $nep = preg_match('#2#', xl_text($sh, $r, 2)) ? 2 : ($nep + 1 > 2 ? 2 : $nep + 1); $o['nep_account_' . $nep] = $val($r); }
        elseif (preg_match('#^Allocated\s+Budget#i', $a) && $nep) $o['allocated_budget_' . $nep] = (float)(xl_num($val($r)) ?? 0);
        elseif (preg_match('#^Total\s+Estimated#i', $a)) $o['total_estimated'] = (float)(xl_num($val($r)) ?? 0);
        elseif (preg_match('#^Inclusive\s+Date#i', $a)) $o['inclusive_dates'] = $val($r);
    }
    if (!$hdrRow) return $o;
    $qc = 4; $ac = 5;
    foreach ($sh['cells'][$hdrRow] as $c => $v) {
        $x = strtolower(xl_text($sh, $hdrRow, $c));
        if (strpos($x, 'quantity') === 0) $qc = $c;
        elseif (strpos($x, 'total') === 0 || strpos($x, 'amount') !== false) $ac = $c;
    }
    $group = 'Others';
    foreach ($sh['cells'] as $r => $cols) {
        if ($r <= $hdrRow) continue;
        $a = xl_text($sh, $r, 1);
        if (preg_match('#^Total\s+Amount#i', $a) || preg_match('#^INSTRUCTIONS#i', $a)) break;
        $qt = xl_text($sh, $r, $qc);
        $amt = xl_num($sh['cells'][$r][$ac] ?? '');
        if ($a !== '' && $qt === '' && $amt === null && ($g = xl_annex_group($a)) !== null) { $group = $g; continue; }
        if ($a === '' && $qt === '' && $amt === null) continue;
        $q = preg_match('#^\s*([\d,]*\.?\d+)#', $qt, $qm) ? (float)str_replace(',', '', $qm[1]) : 0.0;
        $o['items'][] = ['item_group' => $group, 'specification' => $a, 'quantity' => $q, 'qty_text' => $qt, 'total_amount' => round((float)($amt ?? 0), 2)];
        $o['total'] += (float)($amt ?? 0);
    }
    $o['total'] = round($o['total'], 2);
    if (!$o['total_estimated'] && ($o['allocated_budget_1'] + $o['allocated_budget_2']) > 0) $o['total_estimated'] = $o['allocated_budget_1'] + $o['allocated_budget_2'];
    return $o;
}

function xl_annex_read(array $book): array {
    $out = [];
    foreach ($book['sheets'] as $sh) if (($a = xl_annex_sheet($sh)) !== null) $out[] = $a;
    return $out ? ['sheets' => $out] : ['error' => 'This file has no Annex form (a sheet with “A N N E X” and its letter at the top).'];
}

/** The annexes of a set as ANNEX.xlsx — one sheet each, named by its letter. */
function xl_annex_export(array $forms, string $deptCode): string {
    require_once __DIR__ . '/annex-extra.php';
    $types = ['A' => 'Goods', 'B' => 'Training, Seminars and Other Activities', 'C' => 'Equipment and Other Capital Outlay', 'D' => '', 'E' => ''];
    $bk = new XlBook('ANNEX.xlsx');
    foreach ($forms as [$f, $items]) {
        $fy = (int)$f['fy'];
        doc_fmt_use_year($fy);
        $L = strtoupper((string)($f['annex'] ?? 'B'));
        $s = $bk->sheet($L);
        $s->copy(1, 22);
        $s->set(1, 'A', doc_fmt('annex', 'rep_line', $fy));
        $s->set(2, 'A', doc_fmt('annex', 'org_name', $fy));
        $s->set(3, 'A', doc_fmt('annex', 'address', $fy));
        $s->set(4, 'A', annex_unit_line($f, $deptCode));
        $s->set(6, 'A', 'A N N E X   ' . $L);
        $s->set(7, 'A', trim((string)($f['subtitle'] ?? '')) !== '' ? (string)$f['subtitle'] : ($types[$L] ?? ''));
        $s->set(8, 'A', doc_fmt('annex', 'lbl_fy', $fy) . ' ' . $fy);
        $note = trim((string)($f['item_note'] ?? '')) !== '' ? (string)$f['item_note'] : ($L <= 'C' ? 'Can be used for Item ' . $L . '.1.' : '');
        $s->set(11, 'A', '*' . $note);
        $s->set(12, 'C', (string)($f['project_name'] ?? ''));
        $s->set(13, 'C', (string)($f['general_description'] ?? ''));
        $s->set(15, 'C', (string)($f['nep_account_1'] ?? ''));
        $s->set(16, 'C', (float)($f['allocated_budget_1'] ?? 0) > 0 ? round((float)$f['allocated_budget_1'], 2) : null);
        $s->set(17, 'C', (string)($f['nep_account_2'] ?? ''));
        $s->set(18, 'C', (float)($f['allocated_budget_2'] ?? 0) > 0 ? round((float)$f['allocated_budget_2'], 2) : null);
        $est = annex_estimated($f);
        $s->set(19, 'C', $est > 0 ? round($est, 2) : null, $s->styleOf(19, 'C') !== null ? 26 : null);
        $s->set(20, 'C', trim((string)($f['inclusive_dates'] ?? '')) !== '' ? (string)$f['inclusive_dates'] : 'as per schedule');
        foreach ([12 => 'lbl_project', 19 => 'lbl_totbudget', 20 => 'lbl_dates'] as $r => $k) {
            $w = doc_fmt('annex', $k, $fy);
            if ($w !== '') $s->set($r, 'A', $w . ($k === 'lbl_dates' ? ' (tentative)' : ''));
        }
        $s->set(22, 'A', doc_fmt('annex', 'col_items', $fy));
        $s->set(22, 'D', doc_fmt('annex', 'col_qty', $fy));
        $s->set(22, 'E', doc_fmt('annex', 'col_total', $fy));
        $by = [];
        foreach ($items as $it) { $g = (string)$it['item_group'] !== '' ? (string)$it['item_group'] : 'Others'; $by[$g][] = $it; }
        $groups = XL_ANNEX_GROUPS;
        foreach (array_keys($by) as $g) if (!in_array($g, $groups, true)) $groups[] = $g;
        $first = 0; $sum = 0.0;
        foreach ($groups as $g) {
            $gr = $s->copy(23);
            $s->set($gr, 'A', $g);
            if (!$first) $first = $gr;
            $rows = $by[$g] ?? [];
            $blank = max(5 - count($rows), 1);
            foreach ($rows as $it) {
                $r = $s->copy(24);
                $s->set($r, 'A', (string)$it['specification']);
                $q = (float)$it['quantity'];
                $s->set($r, 'D', $q != 0 ? ($q == floor($q) ? (int)$q : $q) : null);
                $s->set($r, 'E', (float)$it['total_amount'] != 0 ? round((float)$it['total_amount'], 2) : null);
                $sum += (float)$it['total_amount'];
            }
            for ($k = 0; $k < $blank; $k++) { $r = $s->copy(24); $s->set($r, 'A', null); $s->set($r, 'D', null); $s->set($r, 'E', null); }
        }
        $last = $s->last;
        $tr = $s->copy(50);
        $s->set($tr, 'A', doc_fmt('annex', 'col_total', $fy));
        $s->set($tr, 'E', ['f' => "SUM(E$first:E$last)", 'v' => round($sum, 2)]);
        $s->copy(51, 53);
        $ir = $s->copy(54);
        $s->set($ir, 'A', doc_fmt('annex', 'instr_title', $fy));
        foreach (preg_split('/\r?\n/', trim(doc_fmt('annex', 'instr_body', $fy))) as $line) {
            if (trim($line) === '') continue;
            $r = $s->copy(55);
            $s->set($r, 'A', trim($line));
        }
    }
    if (!$forms) { $s = $bk->sheet('Annex'); $s->copy(1, 22); }
    return $bk->bytes('Annex', 'ProcureEase Annex');
}

/* ═══════════════════════════ APP-CSE ═══════════════════════════ */

const XL_AC_MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

function xl_appcse_read(array $book): array {
    foreach ($book['sheets'] as $sh) {
        // the month headings (Jan … Dec) tell which column is which month
        $mrow = 0; $mcol = [];
        foreach ($sh['cells'] as $r => $cols) {
            $found = [];
            foreach ($cols as $c => $v) {
                if (is_float($v)) continue;
                $k = strtolower(substr(trim((string)$v), 0, 3));
                $i = array_search($k, XL_AC_MONTHS, true);
                if ($i !== false && !isset($found[$i]) && preg_match('#^[A-Za-z]{3,9}\.?$#', trim((string)$v))) $found[$i] = $c;
            }
            if (count($found) >= 12) { $mrow = $r; $mcol = $found; break; }
        }
        if (!$mrow) continue;
        $codeRe = '#^\d{6,8}-[A-Z0-9]+(?:-[A-Z0-9]+)*$#i';
        $out = ['sheet' => $sh['name'], 'items' => [], 'header' => [], 'transport' => ['I' => null, 'II' => null], 'with_qty' => 0];
        // the agency block, by its labels
        $labels = ['department' => '#^Department/Bureau/Office#i', 'region' => '#^Region\b#i', 'address' => '#^Address\b#i',
                   'agency_code' => '#^Agency\s+Code#i', 'org_type' => '#^Organi[sz]ation\s+Type#i', 'contact_person' => '#^Contact\s+Person#i',
                   'position' => '#^Position\b#i', 'email' => '#^E-?\s*mail#i', 'telephone' => '#^Telephone#i'];
        foreach ($labels as $k => $re) {
            if ($at = xl_find($sh, $re, 1, $mrow)) {
                $v = '';
                for ($c = $at[1] + 1; $c <= $at[1] + 6; $c++) {
                    $x = xl_text($sh, $at[0], $c);
                    if ($x === '') continue;
                    $isLabel = false;
                    foreach ($labels as $re2) if (preg_match($re2, $x)) $isLabel = true;
                    if (!$isLabel) $v = $x;
                    break;
                }
                $out['header'][$k] = $v;
            }
        }
        $part = 'I';
        foreach ($sh['cells'] as $r => $cols) {
            if ($r <= $mrow) continue;
            $first = '';
            foreach ($cols as $c => $v) if (!is_float($v)) { $first = xl_text($sh, $r, $c); break; }
            if (preg_match('#^PART\s+II\b#i', $first)) $part = 'II';
            elseif (preg_match('#^PART\s+I\b#i', $first)) $part = 'I';
            if (preg_match('#^C\.\s*ADDITIONAL\s+PROVISION\s+FOR\s+TRANSPORT#i', $first)) {
                $val = null;
                krsort($cols);
                foreach ($cols as $c => $v) if (($n = xl_num($v)) !== null) { $val = $n; break; }
                $out['transport'][$part] = round((float)$val, 2);
                continue;
            }
            $code = '';
            foreach ($cols as $c => $v) { $t = is_float($v) ? '' : xl_text($sh, $r, $c); if ($t !== '' && preg_match($codeRe, $t)) { $code = strtoupper($t); $cc = $c; break; } }
            if ($code === '') continue;
            $m = [];
            $any = false;
            foreach (XL_AC_MONTHS as $i => $k) { $m[$k] = round((float)(xl_num($sh['cells'][$r][$mcol[$i]] ?? '') ?? 0), 2); if ($m[$k] != 0) $any = true; }
            $out['items'][] = ['code' => $code, 'part' => $part, 'desc' => xl_text($sh, $r, $cc + 1), 'unit' => xl_text($sh, $r, $cc + 2), 'm' => $m, 'any' => $any];
            if ($any) $out['with_qty']++;
        }
        if (!$out['items']) continue;
        return $out;
    }
    return ['error' => 'This file has no APP-CSE form (the item table with the item codes and the months Jan to Dec).'];
}

function xl_appcse_export(int $fy, string $dept, string $deptName): string {
    require_once __DIR__ . '/appcse-items.php';
    require_once __DIR__ . '/price-provision.php';
    doc_fmt_use_year($fy);
    $pdo = db();
    $grouped = appcse_get_grouped($pdo, $fy, $dept);
    $hdr = ['department' => $deptName, 'region' => 'III', 'address' => 'Barangay Zabali, Baler, Aurora', 'agency_code' => '', 'contact_person' => '',
            'position' => '', 'org_type' => '', 'email' => '', 'telephone' => ''];
    try {
        $h = $pdo->prepare('SELECT * FROM appcse_header WHERE fy = ? AND dept_code = ?');
        $h->execute([$fy, $dept]);
        if ($row = $h->fetch()) foreach ($row as $k => $v) if (array_key_exists($k, $hdr) && trim((string)$v) !== '') $hdr[$k] = $v;
    } catch (Throwable $t) {}
    $bk = new XlBook('APPCSE.xlsx');
    $s = $bk->sheet('APP-CSE');
    $s->copy(1, 31);
    $fyw = fn(string $v): string => str_replace(['{FY-1}', '{FY}'], [(string)($fy - 1), (string)$fy], $v);
    $tl = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $fyw(doc_fmt('appcse', 'form_title', $fy)))), 'strlen'));
    $s->set(1, 'A', $tl[0] ?? ('APP-CSE ' . $fy . ' FORM'));
    $s->set(2, 'A', $tl[1] ?? '');
    $s->set(4, 'A', doc_fmt('appcse', 'intro_label', $fy));
    $s->set(5, 'E', $fyw(doc_fmt('appcse', 'intro_body', $fy)));
    $s->set(9, 'A', doc_fmt('appcse', 'reminders_label', $fy));
    $rem = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $fyw(doc_fmt('appcse', 'reminders_body', $fy)))), 'strlen'));
    for ($i = 0; $i < 9; $i++) {
        $line = $rem[$i] ?? '';
        if ($i === 8 && count($rem) > 9) $line .= ' ' . implode(' ', array_slice($rem, 9));
        $num = preg_match('#^(\d+(?:\.\d+)?)\.?\s+(.*)$#s', $line, $m) ? $m : null;
        $s->set(10 + $i, 'A', $line === '' ? null : ($num ? (float)$num[1] : $i + 1));
        $s->set(10 + $i, 'B', $num ? $num[2] : ($line === '' ? null : $line));
    }
    $s->set(22, 'A', $fyw(doc_fmt('appcse', 'note_text', $fy)));
    $s->set(23, 'D', (string)$hdr['department']);
    $s->set(24, 'D', (string)$hdr['region']);
    $s->set(25, 'D', (string)$hdr['address']);
    $s->set(23, 'J', (string)$hdr['agency_code']);
    $s->set(24, 'J', (string)$hdr['org_type']);
    $s->set(23, 'S', (string)$hdr['contact_person']);
    $s->set(24, 'S', (string)$hdr['position']);
    $s->set(25, 'S', (string)$hdr['email']);
    $s->set(26, 'S', (string)$hdr['telephone']);
    $partRow = ['I' => 32, 'II' => 350];
    $partLbl = ['I' => 'PART I. AVAILABLE AT PS-DBM (MAIN WAREHOUSE AND DEPOTS)',
                'II' => 'PART II. OTHER ITEMS NOT AVAILABLE AT PS-DBM BUT ARE REGULARLY PURCHASED FROM OTHER SOURCES'];
    $range = []; $tot = ['I' => 0.0, 'II' => 0.0];
    foreach (['I', 'II'] as $pk) {
        $pr = $s->copy($partRow[$pk]);
        $s->set($pr, 'A', $partLbl[$pk]);
        $first = 0; $n = 0;
        foreach (($grouped[$pk] ?? []) as $cat => $rows) {
            $cr = $s->copy(33);
            $s->set($cr, 'A', (string)$cat);
            foreach ($rows as $it) {
                $r = $s->copy($pk === 'I' ? 35 : 352);
                if (!$first) $first = $r;
                $n++;
                $s->set($r, 'A', $n);
                $s->set($r, 'B', (string)$it['code']);
                $s->set($r, 'C', (string)$it['description']);
                $s->set($r, 'D', (string)$it['unit']);
                $price = round((float)$it['unit_price'], 2);
                $qs = 0.0; $amt = 0.0;
                foreach ([['E', 'F', 'G', 'H', 'I'], ['J', 'K', 'L', 'M', 'N'], ['O', 'P', 'Q', 'R', 'S'], ['T', 'U', 'V', 'W', 'X']] as $qi => [$a, $b, $c, $q, $qa]) {
                    $sum = 0.0;
                    foreach ([$a, $b, $c] as $k => $col) {
                        $mv = (float)($it[XL_AC_MONTHS[$qi * 3 + $k]] ?? 0);
                        $s->set($r, $col, $mv != 0 ? $mv : null);
                        $sum += $mv;
                    }
                    $s->set($r, $q, ['f' => "$a$r+$b$r+$c$r", 'v' => $sum]);
                    $s->set($r, $qa, ['f' => "$q$r*\$Z$r", 'v' => round($sum * $price, 2)]);
                    $qs += $sum;
                }
                $s->set($r, 'Y', ['f' => "H$r+M$r+R$r+W$r", 'v' => $qs]);
                $s->set($r, 'Z', $price);
                $s->set($r, 'AA', ['f' => "Y$r*Z$r", 'v' => round($qs * $price, 2)]);
                $tot[$pk] += $qs * $price;
            }
        }
        $range[$pk] = [$first, $s->last];
    }
    $sum = appcse_summary($tot, appcse_transport($fy, $dept), $fy);
    $rate = (float)$sum['rate'];
    $s->copy(464);
    foreach (['I' => [465, 470], 'II' => [472, 478]] as $pk => [$a, $b]) {
        $off = $s->copy($a, $b) - $a;
        [$f1, $f2] = $range[$pk];
        $p = $sum[$pk];
        $tRow = ($pk === 'I' ? 466 : 474) + $off;
        $s->set($tRow, 'Y', $f1 ? ['f' => "SUM(AA$f1:AA$f2)", 'v' => $p['a']] : $p['a']);
        $s->set($tRow + 1, 'Y', ['f' => "ROUND(Y$tRow*" . ($rate / 100) . ',2)', 'v' => $p['b']]);
        $s->set($tRow + 1, 'A', 'B. ADDITIONAL PROVISION FOR PRICE CHANGES (' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '% of TOTAL)');
        $s->set($tRow + 2, 'Y', $p['c']);
        $s->set($tRow + 3, 'Y', ['f' => 'SUM(Y' . $tRow . ':AA' . ($tRow + 2) . ')', 'v' => $p['d']]);
        $s->set($tRow + 4, 'D', pp_figures_words($p['d']));
    }
    $off = $s->copy(479, 488) - 479;
    $budget = budget_officer();
    if (($budget['position'] ?? '') === '') $budget['position'] = 'Accountant / Budget Officer';
    $sigs = [['B', 'sig1', ['name' => '', 'position' => 'Property/Supply Officer']], ['H', 'sig2', $budget], ['U', 'sig3', ['name' => '', 'position' => 'Head of Office/Agency']]];
    foreach ($sigs as $i => [$col, $k, $live]) {
        $s->set(482 + $off, $col, doc_fmt('appcse', $k . '_label', $fy));
        $s->set(483 + $off, $col, doc_signatory('appcse', $k . '_name', $live, 'name'));
        $s->set(486 + $off, $i === 1 ? 'I' : $col, doc_signatory('appcse', $k . '_position', $live, 'position'));
    }
    $s->set(480 + $off, 'A', trim(strip_tags(doc_nl('appcse', 'sum_warrant', $fy))));
    $s->set(488 + $off, 'B', doc_fmt('appcse', 'sig_date_label', $fy) ?: 'Date Prepared:');
    return $bk->bytes('APP-CSE · FY ' . $fy, 'ProcureEase APP-CSE');
}

/* ═══════════════════════════ Market Scoping ═══════════════════════════ */

/** The department's Market Scoping as MARKET SCOPING.docx: the letterhead, the title, what was written, and the pictures with their words. */
function ms_export_docx(array $doc): string {
    require_once __DIR__ . '/doc-format-forms.php';
    require_once __DIR__ . '/scoping-photos.php';
    $fy = (int)$doc['fy'];
    doc_fmt_use_year($fy);
    FmtForm::$type = 'scoping'; FmtForm::$fy = $fy; FmtForm::$tagged = [];
    $d = new DocxW();
    $d->subject = 'ProcureEase Market Scoping';
    $page = FMT_LETTER + ['top' => 864, 'right' => 1526, 'bottom' => 1440, 'left' => 1800];
    $width = $page['w'] - $page['left'] - $page['right'];
    $t = fn(string $k) => fmt_show('scoping', $k, $fy);
    $center = DocxW::p(DocxW::r($t('rep_line'), ['sz' => 8.5]), ['align' => 'center'])
            . DocxW::p(DocxW::r($t('org_name'), ['sz' => 12, 'b' => true]), ['align' => 'center'])
            . DocxW::p(DocxW::r($t('address'), ['sz' => 7.5, 'i' => true]), ['align' => 'center']);
    $b = DocxW::table([[
        ['p' => DocxW::p(FmtForm::logo($d, 'left', 60, 60), ['align' => 'center']), 'nob' => true],
        ['p' => $center, 'nob' => true],
        ['p' => DocxW::p(FmtForm::logo($d, 'right', 66, 45), ['align' => 'center']), 'nob' => true],
    ]], [1350, $width - 2700, 1350], ['borders' => false]);
    $b .= DocxW::p(DocxW::r($t('doc_title'), ['sz' => 14, 'b' => true]), ['align' => 'center', 'before' => 60, 'after' => 120, 'border' => ['bottom']]);
    $b .= fmt_html_docx($d, (string)($doc['content'] ?? ''), $width, 11, true);
    // the pictures, each with its words under it (as the printout has them)
    foreach (ms_photos((int)($doc['id'] ?? 0)) as $ph) {
        $img = $d->img(__DIR__ . '/../' . ltrim((string)$ph['path'], '/'), $width / 20, 500);
        if ($img === '') continue;
        $b .= DocxW::p($img, ['align' => 'center', 'before' => 360]);
        if (trim($ph['caption']) !== '') $b .= DocxW::p(DocxW::r($ph['caption'], ['sz' => 11]), ['align' => 'center', 'after' => 120]);
    }
    return $d->build($b, $page, 'Market Scoping · FY ' . $fy);
}

/** A Word file read for the Market Scoping editor: its HTML (without the title the page already shows) and what to show first. */
function ms_import_read(string $bytes): array {
    require_once __DIR__ . '/docx-html.php';
    require_once __DIR__ . '/richtext.php';
    $r = docx_to_html($bytes);
    if ($r === null) return ['error' => 'This file could not be read as a Word document (.docx).'];
    $html = $r['html'];
    // the title is on the page already: leave out the first line when it is that title, and the empty lines before it
    $title = trim(doc_fmt('scoping', 'doc_title'));
    $html = preg_replace('#^(<div[^>]*>)(?:<p><br></p>)*#', '$1', $html);
    if ($title !== '' && strcasecmp(trim($r['title']), $title) === 0) {
        $html = preg_replace('#^(<div[^>]*>)<p[^>]*>(?:(?!</p>).)*?' . preg_quote(htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), '#') . '(?:(?!</p>).)*</p>(?:<p><br></p>)*#is', '$1', $html, 1);
    }
    $html = rt_sanitize($html);
    if (strlen($html) > 12 * 1024 * 1024) return ['error' => 'This document is too large for the Market Scoping (over 12 MB even with its pictures made smaller). Try smaller pictures.'];
    return ['html' => $html, 'tables' => $r['tables'], 'pictures' => $r['pictures'], 'checks' => $r['checks'], 'pairs' => $r['pairs']];
}
