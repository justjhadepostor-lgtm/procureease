<?php
/* ═══════════════════════════════════════════════════════════════
   The Procurement Unit's edits to the consolidated APP-CSE.

   POST JSON { fy, action, ... }

     save     rows:   [{ key, temp, part, category, fields: {field: value} }]
              header: { field: value }
              Only the fields the Procurement Unit changed are sent. For a
              line of the departments' sum, a field typed to something other
              than the sum is kept as the PU's own; typed back to the sum, it
              follows the sum again. A line with no key is one the PU added.
     remove   key     takes a line of the sum off this copy, or deletes a
                      line the PU added
     restore  key     puts a removed line back
     reset    key     drops every change to a line: it shows the sum again

   Only appcse_consol_edits and the __ALL__ row of appcse_header are ever
   written. No department's own APP-CSE is touched.
   =============================================================== */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/appcse-items.php';
require_admin();
ensure_admin_tables();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { echo json_encode(['ok' => false, 'error' => 'Invalid data']); exit; }

$u   = current_user();
$by  = (string)($u['full_name'] ?? '');
$pdo = db();
$fy  = (int)($body['fy'] ?? 0);
if ($fy < 1900) { echo json_encode(['ok' => false, 'error' => 'Missing fiscal year']); exit; }
appcse_catalogue_tables($pdo);

const CE_TEXT = ['code' => 60, 'description' => 255, 'unit' => 40];
const CE_NUM  = ['unit_price', 'jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];

/** The departments' sum, line by line, with none of the PU's edits. */
function ce_raw(PDO $pdo, int $fy): array {
    $out = [];
    foreach (appcse_consolidated_grouped($pdo, $fy, false) as $byCat) {
        foreach ($byCat as $rows) foreach ($rows as $r) $out[$r['__key']] = $r;
    }
    return $out;
}

/** A submitted value, cleaned; null when it cannot be stored. */
function ce_value(string $f, $v) {
    if (isset(CE_TEXT[$f])) return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, CE_TEXT[$f]);
    $s = str_replace([',', '₱', ' '], '', (string)$v);
    if ($s === '') return 0.0;
    if (!is_numeric($s)) return null;
    $n = round((float)$s, 2);
    if ($n < 0) $n = 0.0;
    $max = $f === 'unit_price' ? 999999999999.99 : 9999999999.99;
    return min($n, $max);
}

function ce_same(string $f, $a, $b): bool {
    if (isset(CE_TEXT[$f])) return trim((string)$a) === trim((string)$b);
    return abs((float)$a - (float)$b) < 0.0051;
}

function ce_get(PDO $pdo, int $fy, string $key): ?array {
    $s = $pdo->prepare('SELECT * FROM appcse_consol_edits WHERE fy = ? AND line_key = ?');
    $s->execute([$fy, $key]);
    return $s->fetch() ?: null;
}

/** Write a line's edit row; drop it when nothing is left on it. */
function ce_put(PDO $pdo, int $fy, array $row, string $by): void {
    $cols = array_merge(['is_added', 'hidden', 'part', 'category', 'item_key'], array_keys(CE_TEXT), CE_NUM);
    $empty = empty($row['is_added']) && empty($row['hidden']);
    foreach (array_merge(array_keys(CE_TEXT), CE_NUM) as $f) {
        if ($row[$f] !== null) $empty = false;
    }
    if (!empty($row['id'])) {
        if ($empty) { $pdo->prepare('DELETE FROM appcse_consol_edits WHERE id = ?')->execute([$row['id']]); return; }
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
        $vals = array_map(fn($c) => $row[$c], $cols);
        $pdo->prepare("UPDATE appcse_consol_edits SET $set, updated_by = ?, updated_at = NOW() WHERE id = ?")
            ->execute(array_merge($vals, [$by, $row['id']]));
        return;
    }
    if ($empty) return;
    $all  = array_merge(['fy', 'line_key'], $cols, ['updated_by']);
    $vals = array_merge([$fy, $row['line_key']], array_map(fn($c) => $row[$c], $cols), [$by]);
    $pdo->prepare('INSERT INTO appcse_consol_edits (' . implode(', ', array_map(fn($c) => "`$c`", $all)) . ')
                   VALUES (' . implode(', ', array_fill(0, count($all), '?')) . ')')
        ->execute($vals);
}

/** A blank edit row for a line of the sum. */
function ce_blank(string $key, array $raw): array {
    $row = ['id' => 0, 'line_key' => $key, 'is_added' => 0, 'hidden' => 0,
            'part' => $raw['part'], 'category' => (string)$raw['category'],
            'item_key' => trim((string)$raw['code']) !== '' ? trim((string)$raw['code']) : trim((string)$raw['description'])];
    foreach (array_merge(array_keys(CE_TEXT), CE_NUM) as $f) $row[$f] = null;
    return $row;
}

$action = (string)($body['action'] ?? 'save');
$key    = (string)($body['key'] ?? '');

try {
    /* ─── remove / restore / reset ─── */
    if (in_array($action, ['remove', 'restore', 'reset'], true)) {
        if ($key === '') { echo json_encode(['ok' => false, 'error' => 'Missing line']); exit; }
        $row = ce_get($pdo, $fy, $key);

        if ($action === 'remove') {
            if ($row && !empty($row['is_added'])) {
                $pdo->prepare('DELETE FROM appcse_consol_edits WHERE id = ?')->execute([$row['id']]);
                log_activity('Edited Consolidated APP-CSE', 'appcse', "FY $fy", 'Deleted a line added on the consolidated copy: ' . ($row['description'] ?? ''));
            } else {
                $raw = ce_raw($pdo, $fy);
                if (!isset($raw[$key])) { echo json_encode(['ok' => false, 'error' => 'That line is no longer on the form.']); exit; }
                $row = $row ?: ce_blank($key, $raw[$key]);
                $row['hidden'] = 1;
                ce_put($pdo, $fy, $row, $by);
                log_activity('Edited Consolidated APP-CSE', 'appcse', "FY $fy", 'Took a line off the consolidated copy: ' . $raw[$key]['description']);
            }
        } elseif ($action === 'restore') {
            if ($row) { $row['hidden'] = 0; ce_put($pdo, $fy, $row, $by); }
            log_activity('Edited Consolidated APP-CSE', 'appcse', "FY $fy", 'Put a line back on the consolidated copy: ' . $key);
        } else { // reset
            if ($row && empty($row['is_added'])) {
                $pdo->prepare('DELETE FROM appcse_consol_edits WHERE id = ?')->execute([$row['id']]);
                log_activity('Edited Consolidated APP-CSE', 'appcse', "FY $fy", 'Put a line back to the departments\' sum: ' . $key);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action !== 'save') { echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit; }

    /* ─── save ─── */
    $rows   = is_array($body['rows'] ?? null) ? $body['rows'] : [];
    $header = is_array($body['header'] ?? null) ? $body['header'] : [];
    $raw    = $rows ? ce_raw($pdo, $fy) : [];
    $assigned = [];
    $edited   = [];
    $count    = 0;

    $pdo->beginTransaction();
    foreach ($rows as $in) {
        $fields = is_array($in['fields'] ?? null) ? $in['fields'] : [];
        $k      = (string)($in['key'] ?? '');

        $row    = $k !== '' ? ce_get($pdo, $fy, $k) : null;

        if ($k === '' || !empty($row['is_added'])) {
            // A line the Procurement Unit added: every field is its own.
            if ($k === '') {
                $cat = trim((string)($in['category'] ?? ''));
                if ($cat === '') continue;
                $k = '+' . bin2hex(random_bytes(8));
                $row = ['id' => 0, 'line_key' => $k, 'is_added' => 1, 'hidden' => 0,
                        'part' => (($in['part'] ?? '') === 'II') ? 'II' : 'I',
                        'category' => mb_substr($cat, 0, 150), 'item_key' => ''];
                foreach (array_keys(CE_TEXT) as $f) $row[$f] = '';
                foreach (CE_NUM as $f) $row[$f] = 0.0;
                $assigned[] = ['temp' => (string)($in['temp'] ?? ''), 'key' => $k];
            }
            foreach ($fields as $f => $v) {
                if (!isset(CE_TEXT[$f]) && !in_array($f, CE_NUM, true)) continue;
                $val = ce_value($f, $v);
                if ($val !== null) $row[$f] = $val;
            }
            ce_put($pdo, $fy, $row, $by);
            $edited[$k] = array_merge(array_keys(CE_TEXT), CE_NUM);
            $count++;
            continue;
        }

        // A line of the departments' sum
        if (!isset($raw[$k])) continue;              // no longer on the form
        $row = $row ?: ce_blank($k, $raw[$k]);
        foreach ($fields as $f => $v) {
            if (!isset(CE_TEXT[$f]) && !in_array($f, CE_NUM, true)) continue;
            $val = ce_value($f, $v);
            if ($val === null) continue;
            $row[$f] = ce_same($f, $val, $raw[$k][$f]) ? null : $val;
        }
        ce_put($pdo, $fy, $row, $by);
        $edited[$k] = array_values(array_filter(array_merge(array_keys(CE_TEXT), CE_NUM), fn($f) => $row[$f] !== null));
        $count++;
    }

    // The agency block: a field typed to what it would show anyway is left
    // blank, so it keeps following the Procurement Unit's own header.
    if ($header) {
        $base = appcse_consolidated_header($pdo, $fy, false);
        $h = $pdo->prepare("SELECT * FROM appcse_header WHERE fy = ? AND dept_code = '__ALL__'");
        $h->execute([$fy]);
        $cur = $h->fetch() ?: null;
        $vals = [];
        foreach (array_keys($base) as $f) $vals[$f] = $cur ? (string)($cur[$f] ?? '') : '';
        foreach ($header as $f => $v) {
            if (!array_key_exists($f, $base)) continue;
            $v = mb_substr(trim((string)$v), 0, 255);
            $vals[$f] = ($v === trim((string)$base[$f])) ? '' : $v;
        }
        $cols = array_keys($vals);
        if ($cur) {
            $pdo->prepare('UPDATE appcse_header SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", $cols))
                          . " WHERE fy = ? AND dept_code = '__ALL__'")
                ->execute(array_merge(array_values($vals), [$fy]));
        } else {
            $pdo->prepare('INSERT INTO appcse_header (fy, dept_code, ' . implode(', ', array_map(fn($c) => "`$c`", $cols))
                          . ") VALUES (?, '__ALL__', " . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_merge([$fy], array_values($vals)));
        }
    }
    $pdo->commit();

    if ($count || $header) {
        log_activity('Edited Consolidated APP-CSE', 'appcse', "FY $fy",
            $count . ' line' . ($count === 1 ? '' : 's') . ($header ? ' and the agency block' : '') . ' saved.');
    }
    echo json_encode(['ok' => true, 'assigned' => $assigned, 'edited' => $edited, 'saved_at' => date('c')]);
} catch (Throwable $t) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Could not save. Nothing was changed.']);
}
