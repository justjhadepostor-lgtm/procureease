<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/ppmp-expense.php';
require_once __DIR__ . '/../config/archive.php';
require_login();  // ownership is enforced below (own dept only), so admin can save its own PU PPMP
header('Content-Type: application/json');

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data || !isset($data['ppmp_id'])) throw new Exception('Invalid payload');

    $u = current_user();
    $ppmp_id = (int)$data['ppmp_id'];

    $stmt = db()->prepare('SELECT dept_code, fy, status FROM ppmps WHERE id = ?');
    $stmt->execute([$ppmp_id]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('Not authorized');
    if (arc_ppmp_is_archived($ppmp_id)) throw new Exception('This PPMP is in the Archive. Restore it to edit it.');
    if (fy_is_past((int)$row['fy'])) throw new Exception(fy_past_msg('PPMP', (int)$row['fy']));

    // The Procurement Unit may edit any department's PPMP while it is with
    // them — after Budget approval, before final approval (ppmp_with_pu()).
    // Everyone else edits only their own department's draft, as before.
    $puEdit = ($u['role'] ?? '') === 'admin' && ppmp_with_pu($row);
    if ($puEdit) {
        // …with the department's permission (config/edit-permission.php)
        require_once __DIR__ . '/../config/edit-permission.php';
        if (!pu_edit_allowed($row + ['id' => $ppmp_id], $u)) {
            throw new Exception('Not saved. You need ' . $row['dept_code'] . '\'s permission to edit this PPMP — use "Request edit permission" on the PPMP.');
        }
    }
    if (!$puEdit) {
        if (($u['role'] ?? '') === 'admin' && $row['status'] === 'approved') {
            throw new Exception('This PPMP has final approval, so it can no longer be edited.');
        }
        if ($row['dept_code'] !== $u['dept_code']) throw new Exception('Not authorized');
        if ($row['status'] !== 'draft') {
            // Sent back for revision by the Procurement Unit: it stays with the
            // PU, but the department makes the changes asked for
            $__open = 0;
            try {
                $__rq = db()->prepare('SELECT COUNT(*) FROM revision_requests WHERE dept_code = ? AND fy = ? AND resolved = 0');
                $__rq->execute([$row['dept_code'], (int)$row['fy']]);
                $__open = (int)$__rq->fetchColumn();
            } catch (Throwable $t) {}
            if ($row['status'] === 'approved' || $__open === 0) {
                throw new Exception($row['status'] === 'approved'
                    ? 'This PPMP has final approval, so it can no longer be edited.'
                    : 'This PPMP has already been submitted.');
            }
        }
    }

    $pdo = db();
    require_once __DIR__ . '/../config/ppmp-columns.php';
    ppmp_extra_column();   // v94: what the added columns hold (never inside the transaction)
    require_once __DIR__ . '/../config/ppmp-unit.php';
    ppmp_unit_column();    // v137: the End-User typed on the PPMP (never inside the transaction)
    $pdo->beginTransaction();

    $pdo->prepare('UPDATE ppmps SET
                        ppmp_no = ?, is_final = ?,
                        preparer = ?, date_prepared = ?, sig_name = ?, sig_position = ?,
                        head_name = ?, head_position = ?, date_submitted_head = ?
                    WHERE id = ?')
        ->execute([
            $data['ppmp_no'] ?? '',
            !empty($data['is_final']) ? 1 : 0,
            $data['sig_name'] ?? '', $data['date_prepared'] ?? '',
            $data['sig_name'] ?? '', $data['sig_position'] ?? '',
            $data['head_name'] ?? '', $data['head_position'] ?? '',
            $data['date_submitted_head'] ?? '',
            $ppmp_id,
        ]);

    // v137: "End-User or Implementing Unit" — what was typed (blank: the department's name)
    if (array_key_exists('unit_name', $data)) {
        $__un = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$data['unit_name'])), 0, 255);
        try {
            $pdo->prepare('UPDATE ppmps SET unit_name = ? WHERE id = ?')->execute([$__un !== '' ? $__un : null, $ppmp_id]);
        } catch (Throwable $t) {}
    }

    // Whether "Approved by" was typed on purpose. Its own statement, so a
    // database that has not been given the column yet still saves the rest.
    try {
        $pdo->prepare('UPDATE ppmps SET head_custom = ? WHERE id = ?')
            ->execute([!empty($data['head_custom']) ? 1 : 0, $ppmp_id]);
    } catch (Throwable $t) {}

    // The budget breakdown under the table. Its own statement too; only
    // when the editor sent it, so nothing else that saves a PPMP clears it.
    if (array_key_exists('expense_lines', $data)) {
        $__xb = ppmp_expense_clean($data['expense_lines']);
        if ($__xb !== null) {
            try {
                $pdo->prepare('UPDATE ppmps SET expense_summary = ? WHERE id = ?')->execute([$__xb, $ppmp_id]);
            } catch (Throwable $t) {}
        }
    }

    $pdo->prepare('DELETE FROM ppmp_entries WHERE ppmp_id = ?')->execute([$ppmp_id]);

    $ins = $pdo->prepare('INSERT INTO ppmp_entries
        (ppmp_id, category, budget_category, description, proj_type, quantity, mode, pre_conference,
         activity_start, activity_end, delivery, fund, amount, supporting_docs, remarks)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $savedEntries = [];
    foreach (($data['entries'] ?? []) as $e) {
        $cat = in_array($e['category'] ?? '', ['training','consulting','nonps','appcse'], true) ? $e['category'] : 'training';
        $ins->execute([
            $ppmp_id, $cat,
            in_array($e['budget_category'] ?? '', ['travel','training','supply','equipment'], true) ? $e['budget_category'] : 'training',
            $e['description']     ?? '',
            $e['proj_type']       ?? 'Goods',
            $e['quantity']        ?? 'as per schedule',
            $e['mode']            ?? 'Small Value Procurement',
            $e['pre_conference']  ?? 'No',
            $e['activity_start']  ?? 'January',
            $e['activity_end']    ?? 'December',
            $e['delivery']        ?? 'February',
            $e['fund']            ?? 'GAA',
            (float)($e['amount']  ?? 0),
            $e['supporting_docs'] ?? '',
            $e['remarks']         ?? '',
        ]);
        $__eid = (int)$pdo->lastInsertId();
        // v94: the added columns (Document Format), kept on the line
        $__x = [];
        foreach ((array)($e['extra'] ?? []) as $__k => $__v) {
            if (is_string($__k) && preg_match('/^x[a-z0-9]{1,16}$/', $__k) && (is_string($__v) || is_numeric($__v)) && count($__x) < 40) {
                $__x[$__k] = mb_substr(trim((string)$__v), 0, 2000);
            }
        }
        if ($__x) {
            try { $pdo->prepare('UPDATE ppmp_entries SET extra = ? WHERE id = ?')->execute([json_encode($__x, JSON_UNESCAPED_UNICODE), $__eid]); }
            catch (Throwable $t) {}
        }
        $savedEntries[] = ['id' => $__eid];
    }

    $pdo->commit();

    if ($puEdit) {
        // Who changed it at the PU, and when — on the review timeline
        try {
            $pdo->prepare('UPDATE ppmps SET pu_edited_at = NOW(), pu_edited_by = ? WHERE id = ?')
                ->execute([$u['full_name'] ?? '', $ppmp_id]);
        } catch (Throwable $t) {}
        // Once per sitting, not on every autosave: the activity log, and a
        // notice to the department that its PPMP was changed.
        if (empty($_SESSION['__pu_edit_told'][$ppmp_id])) {
            $_SESSION['__pu_edit_told'][$ppmp_id] = 1;
            log_activity('Edited PPMP (Procurement Unit)', 'ppmp', "{$row['dept_code']} · FY {$row['fy']}",
                'Edited after Budget approval, before final approval.');
            try {
                $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link)
                               SELECT id, 'edit', ?, ?, ? FROM users WHERE dept_code = ?")
                    ->execute(["Your FY {$row['fy']} PPMP was edited by the Procurement Unit",
                               ($u['full_name'] ?? 'The Procurement Unit') . ' made changes to it before final approval.',
                               "ppmp-file-view.php?id=$ppmp_id", $row['dept_code']]);
            } catch (Throwable $t) {}
        }
    }
    echo json_encode(['ok' => true, 'entries' => $savedEntries]);
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
}
