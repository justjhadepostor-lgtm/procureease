<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/notify.php';
require_login();
ensure_admin_tables();
$u = current_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../nonps-list.php'); exit; }

$sid = (int)($_POST['submission_id'] ?? 0);
$submitNow = !empty($_POST['submit_now']);

// Only the Department Admin sends it on; anyone may save a draft
if ($submitNow && !can_submit_for_dept($u)) {
    $__head = dept_submitter_name($u);
    $submitNow = false;
    flash('error', $__head !== ''
        ? "Saved. Only your Department Admin ($__head) can submit this list."
        : 'Saved. Only your Department Admin can submit this list.');
}

// Background auto-save wants a plain answer instead of being redirected
$isAjax = !empty($_POST['ajax']);
function nonps_done(bool $ok, string $msg, int $sid, bool $isAjax, string $where = ''): void {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : $msg, 'id' => $sid]);
        exit;
    }
    if (!$ok) flash('error', $msg);
    header('Location: ' . ($where !== '' ? $where : '../nonps-edit.php?id=' . $sid));
    exit;
}

$q = $pdo->prepare("SELECT * FROM nonps_submissions WHERE id = ?");
$q->execute([$sid]);
$sub = $q->fetch();
if (!$sub) nonps_done(false, 'Non-PS submission not found.', $sid, $isAjax, '../nonps-list.php');
if ($sub['dept_code'] !== $u['dept_code']) nonps_done(false, 'Not your department.', $sid, $isAjax, '../nonps-list.php');
if ((int)($sub['archived'] ?? 0) === 1) nonps_done(false, 'This Non-PS list is in the Archive. Restore it to edit it.', $sid, $isAjax, '../archive.php');
if (fy_is_past((int)$sub['fy'])) nonps_done(false, fy_past_msg('Non-PS list', (int)$sub['fy']), $sid, $isAjax, '../nonps-print.php?id=' . $sid);
if (!in_array($sub['status'], ['draft','revision'], true)) {
    if ($isAjax) nonps_done(false, 'This list is no longer editable.', $sid, true);
    flash('error', 'This list is no longer editable.');
    // "Print" saves first, so the printout always matches the screen
    if ($isAjax) nonps_done(true, '', $sid, true);
    if (!empty($_POST['print_after'])) { header('Location: ../nonps-print.php?id=' . $sid); exit; }
    header('Location: ../nonps-edit.php?id=' . $sid); exit;
}

// Before the PPMP is submitted, the list goes with it (config/companions.php)
if ($submitNow) {
    require_once __DIR__ . '/../config/companions.php';
    [$__solo, $__why] = companion_solo_submit((string)$sub['dept_code'], (int)$sub['fy'], (string)$sub['status']);
    if (!$__solo) { $submitNow = false; flash('error', $__why); }
}

$particulars = $_POST['particulars'] ?? [];
$units       = $_POST['unit'] ?? [];
$quantities  = $_POST['quantity'] ?? [];
$unitCosts   = array_map(fn($v) => str_replace(',', '', (string)$v), (array)($_POST['unit_cost'] ?? []));   // 12,000.00 → 12000.00
// v105: the rows as one field — so a list of any length (an imported NONPS.xlsx, say) is saved
// whole; as separate fields a long list ran past PHP's limit on the number of fields (max_input_vars)
if (isset($_POST['items_json'])) {
    $__rows = json_decode((string)$_POST['items_json'], true);
    if (is_array($__rows)) {
        $particulars = []; $units = []; $quantities = []; $unitCosts = [];
        foreach ($__rows as $__r) {
            if (!is_array($__r)) continue;
            $particulars[] = (string)($__r[0] ?? '');
            $units[]       = (string)($__r[1] ?? '');
            $quantities[]  = (string)($__r[2] ?? '');
            $unitCosts[]   = str_replace(',', '', (string)($__r[3] ?? ''));
        }
    }
}

// The budget check below reads C. of the APP-CSE; its columns are added
// before the transaction, since changing a table would end it
require_once __DIR__ . '/../config/price-provision.php';
pp_ensure_columns();

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM nonps_items WHERE submission_id = ?')->execute([$sid]);
    $ins = $pdo->prepare('INSERT INTO nonps_items (submission_id, item_no, particulars, unit, quantity, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $n = 1;
    for ($i = 0; $i < count($particulars); $i++) {
        $p = trim($particulars[$i] ?? '');
        if ($p === '') continue;
        $unit = trim($units[$i] ?? '');
        $qty  = (int)($quantities[$i] ?? 0);
        $uc   = (float)str_replace(',', '', $unitCosts[$i] ?? 0);
        $tc   = $qty * $uc;
        $ins->execute([$sid, $n++, $p, $unit, $qty, $uc, $tc]);
    }

    // Whoever the department says prepared the list
    try {
        $pdo->prepare('UPDATE nonps_submissions SET prepared_by = ?, prepared_designation = ? WHERE id = ?')
            ->execute([
                trim($_POST['prepared_by'] ?? ''),
                mb_substr(trim($_POST['prepared_designation'] ?? ''), 0, 150),
                $sid,
            ]);
    } catch (Throwable $t) { /* column added by ensure_admin_tables() */ }

    // The budget has to add up with the new items (config/companions.php)
    if ($submitNow) {
        require_once __DIR__ . '/../config/companions.php';
        $__bm = ppmp_budget_match((string)$sub['dept_code'], (int)$sub['fy']);
        if (!$__bm['ok']) {
            $submitNow = false;
            flash('error', 'Saved, but not submitted. ' . $__bm['note']);
        }
    }

    if ($submitNow) {
        $pdo->prepare("UPDATE nonps_submissions
                          SET status = 'submitted',
                              submitted_by = ?,
                              submitted_at = NOW(),
                              review_note = NULL
                        WHERE id = ?")->execute([$u['full_name'], $sid]);
        require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
        doc_snapshot('nonps', (string)$sid, 'submitted', '');
        log_activity('Submitted Non-PS list', 'nonps', "{$u['dept_code']} · FY {$sub['fy']}", "$n items");

        // Tell the Procurement Unit there's a new Non-PS list waiting for review
        $deptLabel = $u['dept_name'] ?? $u['dept_code'];
        notify_by_role('admin', $sub['status'] === 'revision' ? 'resubmission' : 'submission',
            $sub['status'] === 'revision' ? 'Non-PS Supplies list resubmitted after revision' : 'New Non-PS Supplies list submitted',
            "$deptLabel submitted their List of Non-PS Supplies and Materials for FY {$sub['fy']}.",
            'nonps-review.php?sub=' . $sid);
    }

    $pdo->commit();
    // A save in the background answers the page only. It used to leave a "Draft saved."
    // message behind as well, every few seconds, and they all showed at once on the next page.
    if (!$isAjax) flash('success', $submitNow ? 'Submitted to Procurement Unit.' : 'Draft saved.');
    if ($submitNow) celebrate_submitted('FY ' . (int)$sub['fy'] . ' Non-PS list', 'Procurement Unit');   // v97
    // "Print" saves first, so the printout always matches the screen
    if ($isAjax) nonps_done(true, '', $sid, true);
    if (!empty($_POST['print_after'])) { header('Location: ../nonps-print.php?id=' . $sid); exit; }
    header('Location: ../nonps-edit.php?id=' . $sid); exit;
} catch (Throwable $t) {
    $pdo->rollBack();
    // A save in the background is told it failed (it used to be told it worked)
    if ($isAjax) nonps_done(false, 'Save failed', $sid, true);
    flash('error', 'Save failed: ' . $t->getMessage());
    if (!empty($_POST['print_after'])) { header('Location: ../nonps-print.php?id=' . $sid); exit; }
    header('Location: ../nonps-edit.php?id=' . $sid); exit;
}
