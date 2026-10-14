<?php
/* ═══════════════════════════════════════════════════════════════
   Version history (v79): what a document said each time it changed hands.

   Every time a PPMP, APP-CSE, Non-PS list, Annex or Market Scoping is
   submitted, resubmitted, approved, sent back or pulled back, a copy of what
   it said at that moment is kept (doc_versions). The history page
   (doc-history.php) lists the versions and shows what changed from one to
   the next — a line added or removed, an amount changed (₱10,000.00 →
   ₱8,500.00), a field changed — so a reviewer sees what was changed after a
   revision, and there is a record of it later.

   A copy is never changed or deleted. Nothing about the documents themselves
   changes: this only reads them.

     doc_type  ppmp | appcse | nonps | annex | scoping
     doc_ref   ppmps.id | "FY:DEPT" | nonps_submissions.id | annex_forms.id | market_scoping.id
   =============================================================== */
require_once __DIR__ . '/db.php';

const DOC_VERSION_TYPES = ['ppmp' => 'PPMP', 'appcse' => 'APP-CSE', 'nonps' => 'Non-PS list', 'annex' => 'Annex', 'scoping' => 'Market Scoping'];
const DOC_VERSION_EVENTS = [
    'submitted'       => 'Submitted',
    'resubmitted'     => 'Resubmitted',
    'budget_approved' => 'Approved by the Budget Unit',
    'approved'        => 'Approved by the Procurement Unit',
    'revision'        => 'Sent back for revision',
    'unsubmitted'     => 'Pulled back by the department',
];

/** Creates the table once (never inside a transaction). */
function doc_versions_ensure(): void {
    static $done = false;
    if ($done) return;
    try { if (db()->inTransaction()) return; } catch (Throwable $t) { return; }
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS doc_versions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            doc_type VARCHAR(20) NOT NULL,
            doc_ref VARCHAR(40) NOT NULL,
            fy INT NULL,
            dept_code VARCHAR(30) NULL,
            version_no INT NOT NULL,
            event VARCHAR(30) NOT NULL,
            note TEXT NULL,
            snapshot LONGTEXT NULL,
            total DECIMAL(16,2) NULL,
            created_by VARCHAR(150) NULL,
            created_role VARCHAR(30) NULL,
            created_at DATETIME NULL,
            INDEX idx_doc (doc_type, doc_ref)
        ) ENGINE=InnoDB");
    } catch (Throwable $t) {}
}

/* ─── Reading a document as it is now ─────────────────────────── */

function dv_peso(float $v): string { return '₱' . number_format($v, 2); }
function dv_num($v): string {
    $f = (float)$v;
    return (floor($f) == $f) ? number_format($f, 0) : rtrim(rtrim(number_format($f, 2), '0'), '.');
}
function dv_text($v): string { return trim(preg_replace('/\s+/u', ' ', (string)($v ?? ''))); }
function dv_key(string ...$parts): string { return mb_strtolower(implode('|', array_map('dv_text', $parts))); }

/**
 * What the document says now:
 *   ['fy','dept','title','fields' => [label => text], 'rows' => [[key, label, cols => [label => text]]],
 *    'lines' => [text] (Market Scoping), 'total' => float|null, 'total_label' => string]
 * or null when it cannot be found.
 */
function doc_version_read(string $type, string $ref): ?array {
    $pdo = db();
    try {
        switch ($type) {
        case 'ppmp': {
            $s = $pdo->prepare('SELECT * FROM ppmps WHERE id = ?'); $s->execute([(int)$ref]);
            if (!($p = $s->fetch())) return null;
            $q = $pdo->prepare('SELECT * FROM ppmp_entries WHERE ppmp_id = ? ORDER BY id'); $q->execute([(int)$ref]);
            $rows = []; $total = 0.0;
            foreach ($q->fetchAll() as $e) {
                $total += (float)$e['amount'];
                $label = dv_text($e['description']) !== '' ? dv_text($e['description']) : '(no description)';
                $rows[] = ['key' => dv_key((string)$e['category'], (string)$e['description']), 'label' => $label, 'cols' => [
                    'Category' => dv_text($e['category']), 'Type of project' => dv_text($e['proj_type']), 'Quantity' => dv_text($e['quantity'] ?? ''),
                    'Unit cost' => ($e['unit_cost'] ?? '') !== '' && $e['unit_cost'] !== null ? dv_peso((float)$e['unit_cost']) : '',
                    'Mode of procurement' => dv_text($e['mode']), 'Pre-procurement conference' => dv_text($e['pre_conference'] ?? ''),
                    'Start' => dv_text($e['activity_start']), 'End' => dv_text($e['activity_end']), 'Delivery' => dv_text($e['delivery']),
                    'Source of funds' => dv_text($e['fund']), 'Amount' => dv_peso((float)$e['amount']),
                    'Supporting documents' => dv_text($e['supporting_docs'] ?? ''), 'Remarks' => dv_text($e['remarks'])]];
            }
            return ['fy' => (int)$p['fy'], 'dept' => (string)$p['dept_code'], 'title' => 'PPMP',
                    'fields' => ['PPMP No.' => dv_text($p['ppmp_no'] ?? ''), 'Head of the unit' => dv_text($p['head_name'] ?? ''),
                                 'Position' => dv_text($p['head_position'] ?? '')],
                    'rows' => $rows, 'lines' => [], 'total' => round($total, 2), 'total_label' => 'PPMP Total Amount'];
        }
        case 'appcse': {
            [$fy, $dept] = array_pad(explode(':', $ref, 2), 2, '');
            $fy = (int)$fy;
            require_once __DIR__ . '/price-provision.php';
            $months = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
            $q = $pdo->prepare('SELECT * FROM appcse_items WHERE fy = ? AND dept_code = ? ORDER BY part, sort_order, id'); $q->execute([$fy, $dept]);
            $rows = [];
            foreach ($q->fetchAll() as $it) {
                $qty = 0.0; foreach ($months as $m) $qty += (float)($it[$m] ?? 0);
                if ($qty <= 0) continue;   // only the lines the department asked for
                $cols = ['Unit price' => dv_peso((float)$it['unit_price'])];
                foreach ($months as $m) $cols[ucfirst($m)] = dv_num($it[$m] ?? 0);
                $cols['Total quantity'] = dv_num($qty);
                $cols['Amount'] = dv_peso($qty * (float)$it['unit_price']);
                $label = dv_text($it['description']) . (dv_text($it['code']) !== '' ? ' (' . dv_text($it['code']) . ')' : '');
                $rows[] = ['key' => dv_key((string)$it['part'], (string)$it['code'], (string)$it['description']),
                           'label' => 'Part ' . ($it['part'] === 'II' ? 'II' : 'I') . ' · ' . $label, 'cols' => $cols];
            }
            $h = [];
            try { $s = $pdo->prepare('SELECT * FROM appcse_header WHERE fy = ? AND dept_code = ?'); $s->execute([$fy, $dept]); $h = $s->fetch() ?: []; } catch (Throwable $t) {}
            $sum = appcse_summary(appcse_part_totals($fy, $dept), appcse_transport($fy, $dept), (int)$fy);
            return ['fy' => $fy, 'dept' => $dept, 'title' => 'APP-CSE',
                    'fields' => ['Department' => dv_text($h['department'] ?? ''), 'Region' => dv_text($h['region'] ?? ''),
                                 'Address' => dv_text($h['address'] ?? ''), 'Agency code' => dv_text($h['agency_code'] ?? ''),
                                 'Contact person' => dv_text($h['contact_person'] ?? ''), 'Position' => dv_text($h['position'] ?? ''),
                                 'Email' => dv_text($h['email'] ?? ''), 'Telephone' => dv_text($h['telephone'] ?? ''),
                                 'C. Transport/freight, Part I' => dv_peso((float)$sum['I']['c']), 'C. Transport/freight, Part II' => dv_peso((float)$sum['II']['c'])],
                    'rows' => $rows, 'lines' => [], 'total' => round((float)$sum['d'], 2), 'total_label' => doc_fmt('appcse', 'lbl_grand_d', (int)$fy)];
        }
        case 'nonps': {
            $s = $pdo->prepare('SELECT * FROM nonps_submissions WHERE id = ?'); $s->execute([(int)$ref]);
            if (!($n = $s->fetch())) return null;
            require_once __DIR__ . '/price-provision.php';
            $q = $pdo->prepare('SELECT * FROM nonps_items WHERE submission_id = ? ORDER BY item_no, id'); $q->execute([(int)$ref]);
            $rows = []; $base = 0.0;
            foreach ($q->fetchAll() as $it) {
                $base += (float)$it['total_cost'];
                $rows[] = ['key' => dv_key((string)$it['particulars']), 'label' => dv_text($it['particulars']) ?: '(no particulars)', 'cols' => [
                    'Unit' => dv_text($it['unit']), 'Quantity' => dv_num($it['quantity']), 'Unit cost' => dv_peso((float)$it['unit_cost']),
                    'Total cost' => dv_peso((float)$it['total_cost'])]];
            }
            $was = $GLOBALS['__doc_fmt_year'] ?? null;
            if (function_exists('doc_fmt_use_year')) doc_fmt_use_year((int)$n['fy']);
            $np = nonps_provision($base);
            if (function_exists('doc_fmt_use_year')) doc_fmt_use_year($was);
            return ['fy' => (int)$n['fy'], 'dept' => (string)$n['dept_code'], 'title' => 'Non-PS list',
                    'fields' => ['Prepared by' => dv_text($n['prepared_by'] ?? ''), 'Designation' => dv_text($n['prepared_designation'] ?? ''),
                                 'TOTAL' => dv_peso((float)$np['total'])],
                    'rows' => $rows, 'lines' => [], 'total' => round((float)$np['grand'], 2), 'total_label' => 'GRAND TOTAL'];
        }
        case 'annex': {
            $s = $pdo->prepare('SELECT * FROM annex_forms WHERE id = ?'); $s->execute([(int)$ref]);
            if (!($a = $s->fetch())) return null;
            $q = $pdo->prepare('SELECT * FROM annex_items WHERE form_id = ? ORDER BY item_group, sort_order, id'); $q->execute([(int)$ref]);
            $rows = [];
            foreach ($q->fetchAll() as $it) {
                if (dv_text($it['specification']) === '' && (float)$it['total_amount'] == 0 && dv_text($it['quantity']) === '') continue;
                $rows[] = ['key' => dv_key((string)$it['item_group'], (string)$it['specification']),
                           'label' => dv_text($it['item_group']) . ' · ' . (dv_text($it['specification']) ?: '(no specification)'), 'cols' => [
                    'Quantity' => dv_text($it['quantity']), 'Total amount' => dv_peso((float)$it['total_amount'])]];
            }
            return ['fy' => (int)$a['fy'], 'dept' => (string)$a['dept_code'], 'title' => 'Annex ' . dv_text($a['annex']),
                    'fields' => ['Annex' => dv_text($a['annex']), 'Project name' => dv_text($a['project_name']),
                                 'General description' => dv_text($a['general_description']),
                                 'NEP Account #1' => dv_text($a['nep_account_1']), 'Allocated budget #1' => dv_peso((float)$a['allocated_budget_1']),
                                 'NEP Account #2' => dv_text($a['nep_account_2']), 'Allocated budget #2' => dv_peso((float)$a['allocated_budget_2']),
                                 'Inclusive date/s' => dv_text($a['inclusive_dates'])],
                    'rows' => $rows, 'lines' => [], 'total' => round((float)$a['total_estimated'], 2), 'total_label' => 'Total Estimated Budget'];
        }
        case 'scoping': {
            $s = $pdo->prepare('SELECT * FROM market_scoping WHERE id = ?'); $s->execute([(int)$ref]);
            if (!($m = $s->fetch())) return null;
            return ['fy' => (int)$m['fy'], 'dept' => (string)$m['dept_code'], 'title' => 'Market Scoping',
                    'fields' => ['Title' => dv_text($m['title'] ?? '')], 'rows' => [],
                    'lines' => dv_html_lines((string)($m['content'] ?? '')), 'total' => null, 'total_label' => ''];
        }
        }
    } catch (Throwable $t) { return null; }
    return null;
}

/** A rich-text document as lines of text: a paragraph, a list item, or a table row (cells joined by " | "). */
function dv_html_lines(string $html): array {
    $h = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);
    $h = preg_replace('#</t[dh]>#i', ' | ', $h);
    $h = preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6]|blockquote|pre|table|thead|tbody)>#i', "\n", $h);
    $h = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $out = [];
    foreach (preg_split('/\n+/u', $h) as $line) {
        $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line));
        $line = trim(preg_replace('/(\s*\|\s*)+$/u', '', $line));
        $line = preg_replace('/(\s*\|\s*){2,}/u', ' | ', $line);
        if ($line !== '' && $line !== '|') $out[] = $line;
    }
    return $out;
}

/* ─── Keeping a version ───────────────────────────────────────── */

/**
 * Keeps what the document says now, as the next version, with the event
 * (submitted, approved, …). "submitted" becomes "resubmitted" when it was
 * submitted before. Never stops the action it is called from.
 */
function doc_snapshot(string $type, string $ref, string $event, string $note = ''): void {
    if (!isset(DOC_VERSION_TYPES[$type]) || !isset(DOC_VERSION_EVENTS[$event])) return;
    doc_versions_ensure();
    try {
        $doc = doc_version_read($type, $ref);
        if (!$doc) return;
        $pdo = db();
        $s = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0), SUM(CASE WHEN event IN (?, ?) THEN 1 ELSE 0 END)
                              FROM doc_versions WHERE doc_type = ? AND doc_ref = ?');
        $s->execute(['submitted', 'resubmitted', $type, $ref]);
        [$max, $subs] = $s->fetch(PDO::FETCH_NUM);
        if ($event === 'submitted' && (int)$subs > 0) $event = 'resubmitted';
        $u = function_exists('current_user') ? current_user() : null;
        $role = (string)($u['role'] ?? '');
        $pdo->prepare('INSERT INTO doc_versions (doc_type, doc_ref, fy, dept_code, version_no, event, note, snapshot, total, created_by, created_role, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$type, $ref, $doc['fy'], $doc['dept'], (int)$max + 1, $event, $note !== '' ? $note : null,
                       json_encode($doc, JSON_UNESCAPED_UNICODE), $doc['total'], (string)($u['full_name'] ?? ''),
                       $role !== '' && function_exists('role_label') ? role_label($role) : $role, date('Y-m-d H:i:s')]);
    } catch (Throwable $t) { error_log('doc_snapshot failed: ' . $t->getMessage()); }
}

/** All versions of a document, oldest first. */
function doc_versions(string $type, string $ref): array {
    doc_versions_ensure();
    try {
        $s = db()->prepare('SELECT * FROM doc_versions WHERE doc_type = ? AND doc_ref = ? ORDER BY version_no, id');
        $s->execute([$type, $ref]);
        $out = [];
        foreach ($s->fetchAll() as $r) { $r['doc'] = json_decode((string)$r['snapshot'], true) ?: []; $out[] = $r; }
        return $out;
    } catch (Throwable $t) { return []; }
}

/** How many versions a document has (for the "Version history" button). */
function doc_versions_count(string $type, string $ref): int {
    doc_versions_ensure();
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM doc_versions WHERE doc_type = ? AND doc_ref = ?');
        $s->execute([$type, $ref]);
        return (int)$s->fetchColumn();
    } catch (Throwable $t) { return 0; }
}

/* ─── What changed ────────────────────────────────────────────── */

/**
 * What changed from $old to $new (two doc_version_read() results):
 *   ['fields' => [[label, before, after]], 'added' => [row], 'removed' => [row],
 *    'changed' => [[label, [[col, before, after]]]], 'lines_added' => [], 'lines_removed' => [],
 *    'total' => [before, after] | null, 'none' => bool]
 * Lines are matched by what they are (description, item, particulars), not by their place.
 */
function doc_version_diff(array $old, array $new): array {
    $d = ['fields' => [], 'added' => [], 'removed' => [], 'changed' => [], 'lines_added' => [], 'lines_removed' => [], 'total' => null];
    foreach (($new['fields'] ?? []) + ($old['fields'] ?? []) as $label => $_) {
        $a = (string)($old['fields'][$label] ?? ''); $b = (string)($new['fields'][$label] ?? '');
        if ($a !== $b) $d['fields'][] = [$label, $a, $b];
    }
    // rows: matched by key, in order (the 2nd line with the same key matches the 2nd one before)
    $index = function (array $rows): array {
        $by = []; foreach ($rows as $r) { $by[$r['key']][] = $r; } return $by;
    };
    $oi = $index($old['rows'] ?? []);
    $used = [];
    foreach ($new['rows'] ?? [] as $r) {
        $k = $r['key']; $n = $used[$k] = ($used[$k] ?? 0) + 1;
        $before = $oi[$k][$n - 1] ?? null;
        if (!$before) { $d['added'][] = $r; continue; }
        $cols = [];
        foreach ($r['cols'] + $before['cols'] as $c => $_) {
            $a = (string)($before['cols'][$c] ?? ''); $b = (string)($r['cols'][$c] ?? '');
            if ($a !== $b) $cols[] = [$c, $a, $b];
        }
        if ($cols) $d['changed'][] = [$r['label'], $cols];
    }
    foreach ($oi as $k => $list) {
        $kept = $used[$k] ?? 0;
        foreach (array_slice($list, $kept) as $r) $d['removed'][] = $r;
    }
    // text lines (Market Scoping): what is new, what is gone
    $count = function (array $lines): array { $c = []; foreach ($lines as $l) $c[$l] = ($c[$l] ?? 0) + 1; return $c; };
    $oc = $count($old['lines'] ?? []); $nc = $count($new['lines'] ?? []);
    $tmp = $oc;
    foreach ($new['lines'] ?? [] as $l) { if (($tmp[$l] ?? 0) > 0) $tmp[$l]--; else $d['lines_added'][] = $l; }
    $tmp = $nc;
    foreach ($old['lines'] ?? [] as $l) { if (($tmp[$l] ?? 0) > 0) $tmp[$l]--; else $d['lines_removed'][] = $l; }
    $ta = $old['total'] ?? null; $tb = $new['total'] ?? null;
    if ($ta !== null || $tb !== null) {
        if (round((float)$ta, 2) !== round((float)$tb, 2)) $d['total'] = [(float)$ta, (float)$tb];
    }
    $d['none'] = !$d['fields'] && !$d['added'] && !$d['removed'] && !$d['changed'] && !$d['lines_added'] && !$d['lines_removed'] && !$d['total'];
    return $d;
}

/* ─── Who may see a document's history ────────────────────────── */

/** The history page link for a document. */
function doc_history_url(string $type, string $ref): string {
    return 'doc-history.php?type=' . urlencode($type) . '&ref=' . urlencode($ref);
}

/** May $u see this document's history? The same people who can open the document. */
function doc_history_can_view(array $u, string $type, string $dept): bool {
    $role = (string)($u['role'] ?? '');
    if ($role === 'admin') return true;
    if ($role === 'budget' && $type === 'ppmp') return true;   // the Budget Unit reviews every PPMP
    return $dept !== '' && (string)($u['dept_code'] ?? '') === $dept;
}

/** The "🕘 Version history (n)" button, or nothing when there is none yet. */
function doc_history_button(string $type, string $ref, string $style = ''): string {
    $n = doc_versions_count($type, $ref);
    if ($n < 1) return '';
    return '<a class="btn btn-ghost doc-history-btn" href="' . htmlspecialchars(doc_history_url($type, $ref), ENT_QUOTES, 'UTF-8') . '"'
         . ' style="padding: 8px 16px; font-size: 13px; text-decoration: none; white-space: nowrap;' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '">'
         . '🕘 Version history (' . $n . ')</a>';
}
