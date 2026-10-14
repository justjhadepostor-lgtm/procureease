<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_login();
ensure_admin_tables();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']); exit;
}

$u   = current_user();
$pdo = db();

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) { echo json_encode(['ok' => false, 'error' => 'Invalid data']); exit; }

$fy = (int)($payload['fy'] ?? active_fy());
if (fy_is_past($fy)) { echo json_encode(['ok' => false, 'error' => fy_past_msg('APP-CSE', $fy)]); exit; }   // viewing only
$id = (int)($payload['id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Missing row id']); exit; }

try {
    // Scope to this FY *and* this account's own department, so a row can be
    // removed neither cross-year nor from another department's APP-CSE.
    $deptCode = (string)($u['dept_code'] ?? '');
    // v77: the Procurement Unit, on a department's APP-CSE it was given permission to edit
    if (($u['role'] ?? '') === 'admin' && !empty($payload['dept'])) {
        $__d = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$payload['dept']));
        if ($__d !== '' && $__d !== $deptCode) {
            require_once __DIR__ . '/../config/appcse-review.php';
            require_once __DIR__ . '/../config/edit-permission.php';
            if (!pu_doc_edit_allowed('appcse', $fy . ':' . $__d, $__d, appcse_status($fy, $__d) === 'submitted', $u)) {
                echo json_encode(['ok' => false, 'error' => "Ask $__d for permission to edit its APP-CSE first."]); exit;
            }
            $deptCode = $__d;
        }
    } else {
        // a department removes rows only while its APP-CSE is open (a draft, or sent back)
        require_once __DIR__ . '/../config/appcse-review.php';
        if ($deptCode !== '' && !appcse_open_for_dept($fy, $deptCode)) {
            echo json_encode(['ok' => false, 'error' => 'This APP-CSE can no longer be edited.']); exit;
        }
    }
    if ($deptCode === '') { echo json_encode(['ok' => false, 'error' => 'No department']); exit; }
    $st = $pdo->prepare('DELETE FROM appcse_items WHERE id = ? AND fy = ? AND dept_code = ?');
    $st->execute([$id, $fy, $deptCode]);
    if ($st->rowCount() > 0) {
        log_activity('Deleted APP-CSE item', 'appcse', "FY $fy", "Removed item row #$id.");
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $t) {
    echo json_encode(['ok' => false, 'error' => 'Delete failed']);
}
