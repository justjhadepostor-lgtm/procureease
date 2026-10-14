<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/appcse-items.php';
require_once __DIR__ . '/../config/appcse-signatories.php';
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

$fy   = (int)($payload['fy'] ?? active_fy());
if (fy_is_past($fy)) { echo json_encode(['ok' => false, 'error' => fy_past_msg('APP-CSE', $fy)]); exit; }   // viewing only
// The department is taken from the session, never from the request, so a
// tampered payload cannot write into another department's APP-CSE.
$deptCode = (string)($u['dept_code'] ?? '');
// v77: the Procurement Unit saves a department's APP-CSE only with the
// department's permission, while it is with the PU (config/edit-permission.php)
$__puFor = '';
if (($u['role'] ?? '') === 'admin' && !empty($payload['dept'])) {
    $__d = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$payload['dept']));
    if ($__d !== '' && $__d !== $deptCode) {
        require_once __DIR__ . '/../config/appcse-review.php';
        require_once __DIR__ . '/../config/edit-permission.php';
        if (!pu_doc_edit_allowed('appcse', $fy . ':' . $__d, $__d, appcse_status($fy, $__d) === 'submitted', $u)) {
            echo json_encode(['ok' => false, 'error' => "Ask $__d for permission to edit its APP-CSE first."]); exit;
        }
        $deptCode = $__d; $__puFor = $__d;
    }
}
if ($deptCode === '') { echo json_encode(['ok' => false, 'error' => 'No department on this account']); exit; }

// In the Archive: nothing is saved until it is restored
try {
    $__ar = $pdo->prepare('SELECT archived FROM appcse_header WHERE fy = ? AND dept_code = ?');
    $__ar->execute([$fy, $deptCode]);
    if ((int)$__ar->fetchColumn() === 1) {
        echo json_encode(['ok' => false, 'error' => 'This APP-CSE is in the Archive. Restore it to edit it.']);
        exit;
    }
} catch (Throwable $t) {}

// While it is with the Procurement Unit, or approved, it can no longer be
// edited (config/appcse-review.php)
require_once __DIR__ . '/../config/appcse-review.php';
$__acSt = appcse_status($fy, $deptCode);
if ($__puFor === '' && in_array($__acSt, ['submitted', 'approved'], true)) {
    echo json_encode(['ok' => false, 'error' => $__acSt === 'approved'
        ? 'This APP-CSE was approved by the Procurement Unit and can no longer be edited.'
        : 'This APP-CSE was submitted to the Procurement Unit and can no longer be edited.']);
    exit;
}
$rows = $payload['rows'] ?? [];
if ($fy < 2000 || $fy > 2100) $fy = active_fy();

$months = APPCSE_MONTHS;
// C. of Part I and II is kept on appcse_header — added before the transaction,
// since changing a table would end it
require_once __DIR__ . '/../config/price-provision.php';
pp_ensure_columns();

try {
    $pdo->beginTransaction();

    $updCols = '`part` = ?, `category` = ?, code = ?, description = ?, unit = ?, unit_price = ?, ' .
               implode(', ', array_map(fn($m) => "`$m` = ?", $months)) . ', updated_by = ?';
    $upd = $pdo->prepare("UPDATE appcse_items SET $updCols WHERE id = ? AND fy = ? AND dept_code = ?");

    /* Write the columns once and count the placeholders from that list.
       They used to be written out separately, and when dept_code was added
       to the columns the count beside it was not — 22 columns, 21 "?" — so
       every insert failed and took the whole save down with it. */
    $insColumns = array_merge(
        ['fy', 'dept_code', 'part', 'category', 'code', 'description', 'unit', 'unit_price'],
        $months,
        ['updated_by', 'sort_order']
    );
    $insCols = implode(', ', array_map(fn($c) => "`$c`", $insColumns));
    $insPh   = implode(', ', array_fill(0, count($insColumns), '?'));
    $ins     = $pdo->prepare("INSERT INTO appcse_items ($insCols) VALUES ($insPh)");

    $maxOrder = 0;
    try {
        $mo = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM appcse_items WHERE fy = ? AND dept_code = ?');
        $mo->execute([$fy, $deptCode]);
        $maxOrder = (int)$mo->fetchColumn();
    } catch (Throwable $t) {}

    $assigned = [];
    $count = 0;
    foreach ($rows as $r) {
        $part = ($r['part'] ?? 'I') === 'II' ? 'II' : 'I';
        $cat  = trim($r['category'] ?? '');
        $code = trim($r['code'] ?? '');
        $desc = trim($r['desc'] ?? '');
        $unit = trim($r['unit'] ?? '');
        $price = (float)($r['price'] ?? 0);
        $vals = [$part, $cat, $code, $desc, $unit, $price];
        foreach ($months as $m) $vals[] = (float)($r[$m] ?? 0);

        $id = trim((string)($r['id'] ?? ''));
        // v104: a new item (no id yet) is added by the Super Admin only
        if (!($id !== '' && ctype_digit($id)) && ($u['role'] ?? '') !== 'admin') {
            throw new RuntimeException('add_item');
        }
        if ($id !== '' && ctype_digit($id)) {
            $upd->execute(array_merge($vals, [$u['full_name'], (int)$id, $fy, $deptCode]));
        } else {
            $maxOrder++;
            $ins->execute(array_merge([$fy, $deptCode], $vals, [$u['full_name'], $maxOrder]));
            $newId = (int)$pdo->lastInsertId();
            $temp = trim((string)($r['temp'] ?? ''));
            if ($temp !== '') $assigned[] = ['temp' => $temp, 'id' => $newId];
        }
        $count++;
    }

    if ($__puFor !== '') log_activity('Edited APP-CSE (Procurement Unit)', 'appcse', "$__puFor · FY $fy", "Saved $count item row(s) with the department's permission.");
    else log_activity('Saved APP-CSE', 'appcse', "FY $fy", "Saved $count item row(s).");

    // Save agency header info for this FY
    $hdr = $payload['header'] ?? [];
    if (is_array($hdr)) {
        /* appcse_header is keyed (fy, dept_code). dept_code was missing from
           this insert, so every department's header was written to the row
           keyed (fy, '') and the page never found it again. */
        $totalItemsRaw = trim((string)($hdr['total_items'] ?? ''));
        if ($totalItemsRaw !== '' && (!ctype_digit($totalItemsRaw) || (int)$totalItemsRaw > 99999)) throw new RuntimeException('total_items');
        $totalItems = $totalItemsRaw === '' ? null : (int)$totalItemsRaw;
        $sigFields = ['sig1_name','sig1_position','sig2_name','sig2_position','sig3_name','sig3_position','sig_date_value'];
        $headerFields = ['department','region','address','agency_code','contact_person','position','org_type','email','telephone','total_items'];
        $allFields = array_merge($headerFields, $sigFields);
        $fieldSql = implode(', ', array_map(fn($c) => "`$c`", $allFields));
        $placeholders = implode(', ', array_fill(0, count($allFields) + 2, '?'));
        $updates = implode(', ', array_map(fn($c) => "`$c`=VALUES(`$c`)", $allFields));
        $pdo->prepare("INSERT INTO appcse_header (fy, dept_code, $fieldSql) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates")
            ->execute([
                $fy,
                $deptCode,
                trim($hdr['department'] ?? ''),
                trim($hdr['region'] ?? ''),
                trim($hdr['address'] ?? ''),
                trim($hdr['agency_code'] ?? ''),
                trim($hdr['contact_person'] ?? ''),
                trim($hdr['position'] ?? ''),
                trim($hdr['org_type'] ?? ''),
                trim($hdr['email'] ?? ''),
                trim($hdr['telephone'] ?? ''),
                $totalItems,
                trim((string)($hdr['sig1_name'] ?? '')),
                trim((string)($hdr['sig1_position'] ?? '')),
                trim((string)($hdr['sig2_name'] ?? '')),
                trim((string)($hdr['sig2_position'] ?? '')),
                trim((string)($hdr['sig3_name'] ?? '')),
                trim((string)($hdr['sig3_position'] ?? '')),
                trim((string)($hdr['sig_date_value'] ?? '')),
            ]);
        // C. Additional provision for transport and freight cost (if applicable), Part I and II
        foreach (['transport_1', 'transport_2'] as $__tc) {
            if (!array_key_exists($__tc, $hdr)) continue;          // not on the page: left as it is
            $__v = str_replace([',', '₱', ' '], '', (string)$hdr[$__tc]);
            if ($__v === '') $__v = '0';
            if (!is_numeric($__v) || (float)$__v < 0 || (float)$__v > 999999999999.99) {
                throw new RuntimeException('transport');
            }
            $pdo->prepare("UPDATE appcse_header SET $__tc = ? WHERE fy = ? AND dept_code = ?")
                ->execute([round((float)$__v, 2), $fy, $deptCode]);
        }
        // Editable D totals: blank means return to automatic calculation; typed values are stored as overrides.
        foreach (['override_d_1', 'override_d_2'] as $__dc) {
            if (!array_key_exists($__dc, $hdr)) continue;
            $__dv = str_replace([',', '₱', ' '], '', (string)$hdr[$__dc]);
            if ($__dv === '') {
                $pdo->prepare("UPDATE appcse_header SET $__dc = NULL WHERE fy = ? AND dept_code = ?")->execute([$fy, $deptCode]);
                continue;
            }
            if (!is_numeric($__dv) || (float)$__dv < 0 || (float)$__dv > 999999999999.99) throw new RuntimeException('override_total');
            $pdo->prepare("UPDATE appcse_header SET $__dc = ? WHERE fy = ? AND dept_code = ?")
                ->execute([round((float)$__dv, 2), $fy, $deptCode]);
        }
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'saved' => $count, 'assigned' => $assigned]);
} catch (Throwable $t) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $__m = $t instanceof RuntimeException ? $t->getMessage() : '';
    echo json_encode(['ok' => false, 'error' => $__m === 'transport'
        ? 'C. Transport and freight cost must be an amount of 0 or more.'
        : ($__m === 'override_total' ? 'Grand total must be an amount of 0 or more.'
        : ($__m === 'total_items' ? 'Total items must be a whole number from 0 to 99,999.'
        : ($__m === 'add_item' ? 'Only the Procurement Unit adds items to the APP-CSE. Enter your quantities on the items listed.' : 'Save failed')))]);
}
