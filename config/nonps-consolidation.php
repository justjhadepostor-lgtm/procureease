<?php
/* ═══════════════════════════════════════════════════════════════
   Non-PS (Consolidated) — the departments' Non-PS Supplies lists on one form.

   Laid out like APP-CSE (Consolidated): which departments are counted, which
   are with the Procurement Unit, which are still with the department, and
   below them one List of Non-PS Supplies adding up the counted ones.

   A department is counted once the Procurement Unit has APPROVED its Non-PS
   list for the fiscal year. Lists in the Archive are left out, and so are
   lists of other years (a restored list of an earlier year is for viewing
   only). The same item asked for by several departments (same particulars,
   same unit) becomes one line: quantities and amounts add up.

   consolidation.php shows it; nonps-print.php?fy=…&dept=__ALL__ prints it.
   =============================================================== */
require_once __DIR__ . '/db.php';

/**
 * The departments with a Non-PS list for $fy, in three groups:
 *   counted — approved by the Procurement Unit (on the form)
 *   waiting — submitted, with the Procurement Unit for review
 *   rest    — still with the department (draft, or sent back)
 * Each: ['id','code','name','status','submitted_at','total','items']
 */
function nonps_consol_departments(int $fy): array {
    $out = ['counted' => [], 'waiting' => [], 'rest' => []];
    try {
        $s = db()->prepare("SELECT s.id, s.dept_code, s.status, s.submitted_at, d.name AS dept_name,
                                   (SELECT COUNT(*) FROM nonps_items i WHERE i.submission_id = s.id) AS items,
                                   (SELECT COALESCE(SUM(i.total_cost), 0) FROM nonps_items i WHERE i.submission_id = s.id) AS total
                              FROM nonps_submissions s
                         LEFT JOIN departments d ON d.code = s.dept_code
                             WHERE s.fy = ? AND (s.archived IS NULL OR s.archived = 0)
                          ORDER BY s.submitted_at DESC, d.name");
        $s->execute([$fy]);
        foreach ($s->fetchAll() as $r) {
            $row = ['id' => (int)$r['id'], 'code' => (string)$r['dept_code'], 'name' => (string)($r['dept_name'] ?: $r['dept_code']),
                    'status' => (string)$r['status'], 'submitted_at' => $r['submitted_at'],
                    'total' => (float)$r['total'], 'items' => (int)$r['items']];
            if ($row['status'] === 'approved')      $out['counted'][] = $row;
            elseif ($row['status'] === 'submitted') $out['waiting'][] = $row;
            else                                    $out['rest'][]    = $row;
        }
    } catch (Throwable $t) {}
    return $out;
}

/**
 * The consolidated lines for $fy: the approved lists added together.
 * Each: ['particulars','unit','quantity','unit_cost','total_cost','mixed_price','depts']
 * Biggest amount first.
 */
function nonps_consol_items(int $fy): array {
    $rows = [];
    try {
        $q = db()->prepare("SELECT i.particulars, i.unit, i.quantity, i.unit_cost, i.total_cost, s.dept_code
                              FROM nonps_items i
                              JOIN nonps_submissions s ON s.id = i.submission_id
                             WHERE s.fy = ? AND s.status = 'approved'
                               AND (s.archived IS NULL OR s.archived = 0)
                          ORDER BY i.item_no, i.id");
        $q->execute([$fy]);
        $rows = $q->fetchAll();
    } catch (Throwable $t) {}

    $merged = [];
    foreach ($rows as $it) {
        $name = trim((string)$it['particulars']);
        $unit = trim((string)$it['unit']);
        $key  = mb_strtolower($name) . '|' . mb_strtolower($unit);
        if (!isset($merged[$key])) {
            $merged[$key] = ['particulars' => $name !== '' ? $name : '(no description)', 'unit' => $unit,
                             'quantity' => 0, 'total_cost' => 0, 'unit_costs' => [], 'depts' => []];
        }
        $merged[$key]['quantity']   += (float)$it['quantity'];
        $merged[$key]['total_cost'] += (float)$it['total_cost'];
        $merged[$key]['unit_costs'][] = (float)$it['unit_cost'];
        $merged[$key]['depts'][(string)$it['dept_code']] = true;
    }
    foreach ($merged as $k => $m) {
        $uc = array_unique(array_map(fn($v) => round($v, 2), $m['unit_costs']));
        $merged[$k]['unit_cost']   = count($uc) === 1 ? (float)reset($uc) : ($m['quantity'] > 0 ? $m['total_cost'] / $m['quantity'] : 0);
        $merged[$k]['mixed_price'] = count($uc) > 1;
        $merged[$k]['depts']       = array_keys($m['depts']);
        unset($merged[$k]['unit_costs']);
    }
    uasort($merged, fn($a, $b) => $b['total_cost'] <=> $a['total_cost']);
    return array_values($merged);
}

/** A quantity as it is written on the form: 12,000 — 2.5 — 0 */
function nonps_consol_qty(float $q): string {
    return rtrim(rtrim(number_format($q, 2), '0'), '.');
}
