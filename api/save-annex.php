<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/notify.php';
require_login();
ensure_admin_tables();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']); exit;
}

$u   = current_user();
$pdo = db();
$deptCode = $u['dept_code'] ?? '';

$p = json_decode(file_get_contents('php://input'), true);
if (!is_array($p)) { echo json_encode(['ok' => false, 'error' => 'Invalid data']); exit; }

$fy    = (int)($p['fy'] ?? active_fy());
$annex = strtoupper((string)($p['annex'] ?? 'B'));
if (!in_array($annex, ['A','B','C','D','E'], true)) $annex = 'B';   // v99: A–E
if ($fy < 2000 || $fy > 2100) $fy = active_fy();

$formId    = (int)($p['id'] ?? 0);
$submitNow = !empty($p['submit_now']);

// Only the Department Admin sends it on; anyone may save a draft
if ($submitNow && !can_submit_for_dept($u)) {
    $submitNow = false;
    $__head = dept_submitter_name($u);
    echo json_encode(['ok' => false, 'error' => $__head !== ''
        ? "Only your Department Admin ($__head) can submit this annex. Your changes were not lost — save them instead."
        : 'Only your Department Admin can submit this annex.']);
    exit;
}

try {
    // If editing, make sure it belongs to this department and is still editable
    if ($formId > 0) {
        $s = $pdo->prepare('SELECT * FROM annex_forms WHERE id = ? AND dept_code = ?');
        $s->execute([$formId, $deptCode]);
        $existing = $s->fetch();
        if (!$existing) { echo json_encode(['ok' => false, 'error' => 'Annex not found']); exit; }
        if ((int)($existing['archived'] ?? 0) === 1) { echo json_encode(['ok' => false, 'error' => 'This annex is in the Archive. Restore it to edit it.']); exit; }
        if (fy_is_past((int)$existing['fy'])) { echo json_encode(['ok' => false, 'error' => fy_past_msg('Annex', (int)$existing['fy'])]); exit; }
        if (in_array($existing['status'], ['submitted','approved'], true)) {
            echo json_encode(['ok' => false, 'error' => 'This annex has already been submitted and can no longer be edited.']); exit;
        }
        $fy = (int)$existing['fy'];
    }

    // v99: one annex of each letter in a set — a new one, or one changed to another letter, may not take a letter already used
    require_once __DIR__ . '/../config/annex-extra.php';
    if ($formId === 0 || strtoupper((string)($existing['annex'] ?? '')) !== $annex) {
        if (annex_letter_taken($deptCode, $fy, $annex, $formId)) {
            echo json_encode(['ok' => false, 'error' => "This set already has an Annex $annex. Open it from the list of annexes at the top, or choose another letter."]); exit;
        }
    }

    // Before the PPMP is submitted, the Annex goes with it (config/companions.php)
    $__soloWhy = '';
    if ($submitNow) {
        require_once __DIR__ . '/../config/companions.php';
        [$__solo, $__soloWhy] = companion_solo_submit($deptCode, $fy, (string)($existing['status'] ?? 'draft'));
        if ($__solo) $__soloWhy = ''; else $submitNow = false;
    }

    // The unit line, and Total Estimated Budget = NEP #1 + NEP #2 (config/annex-extra.php)
    require_once __DIR__ . '/../config/annex-extra.php';
    annex_ensure_columns();
    $__a1 = (float)($p['allocated_budget_1'] ?? 0); $__a2 = (float)($p['allocated_budget_2'] ?? 0);
    $__est = ($__a1 + $__a2) > 0 ? round($__a1 + $__a2, 2) : (float)($p['total_estimated'] ?? 0);
    $__unit = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($p['unit_name'] ?? ''))), 0, 255);

    $pdo->beginTransaction();

    $fields = [
        (string)($p['project_name'] ?? ''),
        (string)($p['general_description'] ?? ''),
        (string)($p['nep_account_1'] ?? ''),
        (float)($p['allocated_budget_1'] ?? 0),
        (string)($p['nep_account_2'] ?? ''),
        (float)($p['allocated_budget_2'] ?? 0),
        (string)($p['inclusive_dates'] ?? ''),
        $__est,
        (string)($p['subtitle'] ?? ''),
        (string)($p['item_note'] ?? ''),
        $annex,
    ];

    if ($formId > 0) {
        $pdo->prepare('UPDATE annex_forms SET unit_name = ? WHERE id = ?')->execute([$__unit !== '' ? $__unit : null, $formId]);
        $pdo->prepare('UPDATE annex_forms SET project_name=?, general_description=?, nep_account_1=?, allocated_budget_1=?, nep_account_2=?, allocated_budget_2=?, inclusive_dates=?, total_estimated=?, subtitle=?, item_note=?, annex=?, updated_at=NOW() WHERE id=?')
            ->execute([...$fields, $formId]);
        $pdo->prepare('DELETE FROM annex_items WHERE form_id = ?')->execute([$formId]);
    } else {
        $pdo->prepare("INSERT INTO annex_forms (fy, dept_code, project_name, general_description, nep_account_1, allocated_budget_1, nep_account_2, allocated_budget_2, inclusive_dates, total_estimated, subtitle, item_note, annex, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)")
            ->execute([$fy, $deptCode, ...$fields, $u['full_name']]);
        $formId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE annex_forms SET unit_name = ? WHERE id = ?')->execute([$__unit !== '' ? $__unit : null, $formId]);
    }

    // Line items
    $rows = $p['items'] ?? [];
    if (is_array($rows)) {
        $ins = $pdo->prepare('INSERT INTO annex_items (form_id, item_group, specification, quantity, total_amount, sort_order) VALUES (?,?,?,?,?,?)');
        $order = 0;
        foreach ($rows as $r) {
            $ins->execute([
                $formId,
                (string)($r['item_group'] ?? ''),
                (string)($r['specification'] ?? ''),
                (float)($r['quantity'] ?? 0),
                (float)($r['total_amount'] ?? 0),
                $order++,
            ]);
        }
    }

    if ($submitNow) {
        $pdo->prepare("UPDATE annex_forms SET status='submitted', submitted_by=?, submitted_at=NOW(), review_note=NULL WHERE id=?")
            ->execute([$u['full_name'], $formId]);
        require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
        doc_snapshot('annex', (string)$formId, 'submitted', '');

        $deptLabel = $u['dept_name'] ?? $deptCode;
        $__wasRev = (($existing['status'] ?? '') === 'revision');
        notify_by_role('admin', $__wasRev ? 'resubmission' : 'submission',
            $__wasRev ? 'Annex resubmitted after revision' : "New Annex submitted",
            "$deptLabel submitted their annex for FY $fy.",
            'annex-review.php?id=' . $formId);

        log_activity("Submitted Annex", 'annex', "$deptCode · FY $fy");
    } else {
        log_activity("Saved Annex draft", 'annex', "$deptCode · FY $fy");
    }

    $pdo->commit();
    if ($submitNow) celebrate_submitted("FY $fy Annex", 'Procurement Unit');   // v97: played on the page it goes to next
    echo json_encode($__soloWhy !== '' ? ['ok' => false, 'error' => 'Saved. ' . $__soloWhy, 'id' => $formId]
                                       : ['ok' => true, 'id' => $formId]);
} catch (Throwable $t) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Save failed']);
}
