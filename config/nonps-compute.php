<?php
/**
 * Non-PS list computation.
 *
 * The Non-PS list for a (fy, dept_code) is a MERGE of two sources:
 *   1. PPMP-sourced items — auto-computed from ppmp_entries where category IN
 *      ('training','consulting','nonps') for submitted/budget_approved/approved PPMPs.
 *   2. Admin overrides — rows in nonps_admin_items where source_kind='ppmp' override the
 *      auto-computed rows (matched by source_key = lowercase trimmed description),
 *      and source_kind='manual' rows are pure admin additions not tied to any PPMP.
 *
 * Deleted admin rows (`deleted=1`) hide the PPMP row from the merged output.
 *
 * Returns an array of items each shaped like:
 *   [
 *     'source_key'      => 'bond paper a4',
 *     'source_kind'     => 'ppmp' | 'manual',
 *     'source_category' => 'training'|'consulting'|'nonps'|null,
 *     'particulars'     => 'Bond paper A4',
 *     'unit'            => 'ream',
 *     'quantity'        => 100,
 *     'unit_cost'       => 250.00,
 *     'total_cost'      => 25000.00,
 *     'display_order'   => 5,
 *     'is_admin_edited' => true|false,  // true if there's an override for a PPMP row
 *   ]
 */

require_once __DIR__ . '/db.php';

function nonps_compute_effective(int $fy, string $deptCode): array {
    $pdo = db();

    // Step 1 — auto items from PPMP entries
    $autoItems = [];
    try {
        $q = $pdo->prepare("SELECT e.description, e.category, SUM(e.quantity) AS qty,
                                   AVG(e.unit_cost) AS avg_price, SUM(e.amount) AS total
                              FROM ppmp_entries e JOIN ppmps p ON p.id = e.ppmp_id
                             WHERE p.dept_code = ? AND p.fy = ?
                               AND e.category IN ('training','consulting','nonps')
                               AND p.status IN ('submitted','budget_approved','approved')
                          GROUP BY e.description, e.category
                          ORDER BY e.category, MIN(e.id)");
        $q->execute([$deptCode, $fy]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(trim($row['description']));
            if ($key === '') continue;
            $qty = (int)$row['qty']; if ($qty < 1) $qty = 1;
            $totalAmt = (float)$row['total'];
            $unitCost = $qty > 0 ? round($totalAmt / $qty, 2) : (float)$row['avg_price'];
            $autoItems[$key] = [
                'source_key'      => $key,
                'source_kind'     => 'ppmp',
                'source_category' => $row['category'],
                'particulars'     => $row['description'],
                'unit'            => 'unit',
                'quantity'        => $qty,
                'unit_cost'       => $unitCost,
                'total_cost'      => $qty * $unitCost,
                'display_order'   => 0,
                'is_admin_edited' => false,
            ];
        }
    } catch (Throwable $t) { /* graceful */ }

    // Step 2 — admin overrides + manual additions
    $overrides = [];
    $manuals = [];
    try {
        $q = $pdo->prepare("SELECT * FROM nonps_admin_items
                             WHERE fy = ? AND dept_code = ?");
        $q->execute([$fy, $deptCode]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['source_kind'] === 'ppmp') {
                $overrides[$row['source_key']] = $row;
            } else {
                $manuals[] = $row;
            }
        }
    } catch (Throwable $t) {}

    // Step 3 — merge
    $result = [];
    foreach ($autoItems as $key => $item) {
        if (isset($overrides[$key])) {
            $o = $overrides[$key];
            if ((int)$o['deleted'] === 1) continue; // admin removed
            $item['particulars']     = $o['particulars'] ?? $item['particulars'];
            $item['unit']            = $o['unit']        ?? $item['unit'];
            $item['quantity']        = (int)$o['quantity'];
            $item['unit_cost']       = (float)$o['unit_cost'];
            $item['total_cost']      = (float)$o['total_cost'];
            $item['display_order']   = (int)$o['display_order'];
            $item['is_admin_edited'] = true;
        }
        $result[] = $item;
    }
    // Add manual admin-only items
    foreach ($manuals as $m) {
        if ((int)$m['deleted'] === 1) continue;
        $result[] = [
            'source_key'      => $m['source_key'],
            'source_kind'     => 'manual',
            'source_category' => $m['source_category'],
            'particulars'     => $m['particulars'],
            'unit'            => $m['unit'],
            'quantity'        => (int)$m['quantity'],
            'unit_cost'       => (float)$m['unit_cost'],
            'total_cost'      => (float)$m['total_cost'],
            'display_order'   => (int)$m['display_order'],
            'is_admin_edited' => true,
        ];
    }

    // Sort by display_order (0 = auto default), then by source_key
    usort($result, function ($a, $b) {
        if ($a['display_order'] !== $b['display_order']) {
            return $a['display_order'] <=> $b['display_order'];
        }
        return strcmp($a['source_key'], $b['source_key']);
    });
    return $result;
}
