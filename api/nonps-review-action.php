<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_admin();
ensure_admin_tables();

$u   = current_user();
$pdo = db();

$subId = (int)($_POST['sub'] ?? 0);
$do    = $_POST['do'] ?? '';

// Verify submission exists
$sub = null;
try {
    $q = $pdo->prepare("SELECT * FROM nonps_submissions WHERE id = ?");
    $q->execute([$subId]);
    $sub = $q->fetch();
} catch (Throwable $t) {}

if ($sub) fy_past_stop((int)$sub['fy'], 'Non-PS list', '../nonps-print.php?id=' . (int)$sub['id']);   // viewing only
if (!$sub) {
    flash('error', 'Submission not found.');
    header('Location: ../nonps-consolidated.php'); exit;
}

$back = "../nonps-review.php?sub=$subId";
// v77: the Procurement Unit edits a department's list only with its permission (config/edit-permission.php)
require_once __DIR__ . '/../config/edit-permission.php';
$__withPu = (string)$sub['status'] === 'submitted' && (int)($sub['archived'] ?? 0) !== 1;

try {
    if ($do === 'approve') {
        if ($sub['status'] === 'approved') {
            flash('error', 'This list is already approved.');
            header("Location: $back"); exit;
        }
        $pdo->prepare("UPDATE nonps_submissions SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$u['full_name'], $subId]);
        require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
        doc_snapshot('nonps', (string)$subId, 'approved', '');
        pu_doc_edit_close('nonps', (string)$subId);      // its edit permission ends here
        log_activity('Approved Non-PS', 'nonps', $sub['dept_code'], "FY {$sub['fy']}");
        notify_by_dept($sub['dept_code'], 'approval',
            "Your Non-PS Supplies list for FY {$sub['fy']} is approved",
            'The Procurement Unit approved the list your department submitted.',
            'nonps-list.php');
        flash('success', 'Non-PS Supplies list approved.');
        header("Location: $back"); exit;
    }

    if ($do === 'revision') {
        $note = trim($_POST['note'] ?? '');
        $pdo->prepare("UPDATE nonps_submissions SET status='revision', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
            ->execute([$u['full_name'], $note, $subId]);
        require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
        doc_snapshot('nonps', (string)$subId, 'revision', (string)$note);
        pu_doc_edit_close('nonps', (string)$subId);      // its edit permission ends here
        log_activity('Sent Non-PS back for revision', 'nonps', $sub['dept_code'], "FY {$sub['fy']}");
        notify_mail_reset();
        notify_by_dept($sub['dept_code'], 'revision',
            "Your Non-PS Supplies list for FY {$sub['fy']} needs revision",
            'The Procurement Unit sent it back for changes.' . ($note !== '' ? " Note: $note" : ''),   // v106: with the note, as the other documents have it
            'nonps-list.php');
        flash('success', 'Sent back to the department for revision.' . notify_mail_note());   // v106: and by email
        header("Location: $back"); exit;
    }

    if ($do === 'save') {
        if ($sub['status'] === 'approved') {
            flash('error', 'This list is already approved and can no longer be edited.');
            header("Location: $back"); exit;
        }
        if (!pu_doc_edit_allowed('nonps', (string)$subId, (string)$sub['dept_code'], $__withPu, $u)) {
            flash('error', "Ask {$sub['dept_code']} for permission to edit its Non-PS list first. Nothing was changed.");
            header("Location: $back"); exit;
        }
        // Replace the submission's items with the admin-edited set
        $particulars = $_POST['particulars'] ?? [];
        $units       = $_POST['unit'] ?? [];
        $qtys        = $_POST['quantity'] ?? [];
        $costs       = array_map(fn($v) => str_replace(',', '', (string)$v), (array)($_POST['unit_cost'] ?? []));   // 12,000.00 → 12000.00

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM nonps_items WHERE submission_id = ?")->execute([$subId]);
        $ins = $pdo->prepare("INSERT INTO nonps_items (submission_id, item_no, particulars, unit, quantity, unit_cost, total_cost) VALUES (?,?,?,?,?,?,?)");
        $n = 1;
        foreach ($particulars as $i => $p) {
            $p = trim((string)$p);
            $unit = trim((string)($units[$i] ?? ''));
            $qty  = (int)($qtys[$i] ?? 0);
            $cost = (float)($costs[$i] ?? 0);
            if ($p === '' && $qty === 0 && $cost == 0) continue;
            $ins->execute([$subId, $n++, $p, $unit, $qty, $cost, $qty * $cost]);
        }
        $pdo->prepare("UPDATE nonps_submissions SET updated_at = NOW() WHERE id = ?")->execute([$subId]);
        $pdo->commit();
        log_activity('Edited Non-PS items', 'nonps', $sub['dept_code'], "FY {$sub['fy']}");
        // The department is told its list was changed (once per sitting, like the PPMP)
        if (empty($_SESSION['__nonps_edit_told'][$subId])) {
            $_SESSION['__nonps_edit_told'][$subId] = 1;
            notify_by_dept($sub['dept_code'], 'edit',
                "Your FY {$sub['fy']} Non-PS list was edited by the Procurement Unit",
                ($u['full_name'] ?? 'The Procurement Unit') . ' made changes to it before approving it.',
                'nonps-edit.php?id=' . $subId);
        }
        flash('success', 'Changes saved.');
        header("Location: $back"); exit;
    }

    flash('error', 'Unknown action.');
    header("Location: $back"); exit;

} catch (Throwable $t) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'Could not complete the action.');
    header("Location: $back"); exit;
}
