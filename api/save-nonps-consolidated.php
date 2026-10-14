<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_admin();
ensure_admin_tables();
header('Content-Type: application/json');

$u = current_user();
$pdo = db();

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) { echo json_encode(['ok'=>false,'error'=>'Bad JSON']); exit; }

$fy = (int)($body['fy'] ?? 0);
$rows = $body['rows'] ?? [];
if ($fy <= 0) { echo json_encode(['ok'=>false,'error'=>'Missing FY']); exit; }

try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO nonps_admin_items
                              (fy, dept_code, source_key, source_kind, source_category,
                               particulars, unit, quantity, unit_cost, total_cost, deleted, updated_by)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE
                                  source_kind     = VALUES(source_kind),
                                  source_category = VALUES(source_category),
                                  particulars     = VALUES(particulars),
                                  unit            = VALUES(unit),
                                  quantity        = VALUES(quantity),
                                  unit_cost       = VALUES(unit_cost),
                                  total_cost      = VALUES(total_cost),
                                  deleted         = VALUES(deleted),
                                  updated_by      = VALUES(updated_by)");
    $count = 0;
    foreach ($rows as $r) {
        $dept = trim((string)($r['dept_code'] ?? ''));
        $key  = trim((string)($r['source_key'] ?? ''));
        if ($dept === '' || $key === '') continue;

        $kind = in_array($r['source_kind'] ?? '', ['ppmp','manual'], true) ? $r['source_kind'] : 'ppmp';
        $cat  = $r['source_category'] ?: null;
        $part = trim((string)($r['particulars'] ?? ''));
        $unit = trim((string)($r['unit'] ?? 'unit'));
        $qty  = (int)($r['quantity'] ?? 0);
        $uc   = (float)($r['unit_cost'] ?? 0);
        $tc   = $qty * $uc;
        $del  = !empty($r['deleted']) ? 1 : 0;

        // For manual rows the description is required
        if ($kind === 'manual' && $part === '') continue;

        $ins->execute([$fy, $dept, substr($key, 0, 255), $kind, $cat, $part, $unit, $qty, $uc, $tc, $del, $u['full_name']]);
        $count++;
    }
    log_activity('Edited Consolidated Non-PS', 'nonps', "FY $fy", "$count row" . ($count === 1 ? '' : 's') . " saved.");
    $pdo->commit();
    echo json_encode(['ok' => true, 'saved' => $count, 'saved_at' => date('c')]);
} catch (Throwable $t) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $t->getMessage()]);
}
