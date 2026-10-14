<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_budget();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../budget-dashboard.php');
    exit;
}

$ppmp_id = (int)($_POST['ppmp_id'] ?? 0);
fy_past_stop(ppmp_fy($ppmp_id), 'PPMP', '../ppmp-file-view.php?id=' . $ppmp_id);   // a past year's PPMP is for viewing only
$note    = trim($_POST['note'] ?? '');
$u       = current_user();

$stmt = db()->prepare("SELECT p.dept_code, p.fy, p.status FROM ppmps p WHERE p.id = ?");
$stmt->execute([$ppmp_id]);
$ppmp = $stmt->fetch();

if (!$ppmp) {
    flash('error', 'PPMP not found.');
    header('Location: ../budget-dashboard.php');
    exit;
}
if ($ppmp['status'] !== 'submitted') {
    flash('error', 'This PPMP is not in the pending-review state.');
    header('Location: ../budget-dashboard.php');
    exit;
}
// (v77: the APP-CSE no longer comes to the Budget Unit — it goes straight
// to the Procurement Unit — so the PPMP is approved on its own)

$upd = db()->prepare("UPDATE ppmps
                       SET status = 'budget_approved',
                           is_final = 1,
                           budget_reviewed_at = NOW(),
                           budget_reviewed_by = ?,
                           budget_note = ?
                       WHERE id = ?");
$upd->execute([$u['full_name'], $note ?: null, $ppmp_id]);
require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
doc_snapshot('ppmp', (string)$ppmp_id, 'budget_approved', (string)($note ?? ''));

// ─── Fund pool overspend check ─────────────────────────────
// Compute this PPMP's amount per fund
$overspendMessages = [];
try {
    $ppmpFunds = db()->prepare("SELECT fund, COALESCE(SUM(amount), 0) AS total
                                  FROM ppmp_entries WHERE ppmp_id = ? GROUP BY fund");
    $ppmpFunds->execute([$ppmp_id]);
    $ppmpTotals = [];
    foreach ($ppmpFunds->fetchAll() as $r) {
        if (is_fund((string)$r['fund'])) {
            $ppmpTotals[$r['fund']] = (float)$r['total'];
        }
    }

    if ($ppmpTotals) {
        // Get pool and existing committed (excluding this PPMP since it was just approved above)
        $poolStmt = db()->prepare("SELECT fund_source, total_amount FROM fund_pools WHERE fy = ?");
        $poolStmt->execute([$ppmp['fy']]);
        $pools = [];
        foreach ($poolStmt->fetchAll() as $r) $pools[$r['fund_source']] = (float)$r['total_amount'];

        $commitStmt = db()->prepare("SELECT e.fund, COALESCE(SUM(e.amount), 0) AS total
                                      FROM ppmp_entries e JOIN ppmps p ON p.id = e.ppmp_id
                                     WHERE p.fy = ? AND p.status IN ('budget_approved','approved')
                                  GROUP BY e.fund");
        $commitStmt->execute([$ppmp['fy']]);
        $committed = [];
        foreach ($commitStmt->fetchAll() as $r) $committed[$r['fund']] = (float)$r['total'];

        foreach ($ppmpTotals as $fund => $amt) {
            $pool = $pools[$fund] ?? 0;
            if ($pool <= 0) continue; // no pool set → skip
            $usedNow = $committed[$fund] ?? 0; // includes this PPMP's amount since we already approved
            if ($usedNow > $pool) {
                $over = $usedNow - $pool;
                $overspendMessages[] = "$fund is over by ₱" . number_format($over, 2);
            }
        }
    }
} catch (Throwable $t) { /* pool table might not exist yet on fresh install */ }

$logNote = 'Forwarded to Procurement Unit for final approval.'
         . ($note ? " Note: $note" : '')
         . ($overspendMessages ? ' [Fund overspend: ' . implode('; ', $overspendMessages) . ']' : '');
log_activity('Budget approved PPMP', 'ppmp', "{$ppmp['dept_code']} · FY {$ppmp['fy']}", $logNote);

// Notify all admin(s) that a new PPMP is ready for final approval
notify_by_role('admin', 'submission',
    "PPMP ready for final approval: {$ppmp['dept_code']} · FY {$ppmp['fy']}",
    "The Budget Unit has approved and forwarded this PPMP." . ($note ? " Note: $note" : ''),
    "ppmp-file-view.php?id=$ppmp_id");
// Also notify the department that submitted it
notify_by_dept($ppmp['dept_code'], 'approval',
    "Budget Unit approved your PPMP for FY {$ppmp['fy']}",
    "Your PPMP has passed Budget review and is now with the Procurement Unit for final approval.",
    "ppmp-file-view.php?id=$ppmp_id");

$successMsg = "{$ppmp['dept_code']} FY {$ppmp['fy']} approved and forwarded to the Procurement Unit.";
if ($overspendMessages) {
    $successMsg .= ' ⚠ Warning — fund pool overspend: ' . implode('; ', $overspendMessages) . '. Consider increasing the pool.';
}
flash('success', $successMsg);

header('Location: ../budget-dashboard.php');
exit;
