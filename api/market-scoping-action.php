<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/notify.php';
require_admin();
ensure_admin_tables();

$u   = current_user();
$pdo = db();

$docId = (int)($_POST['id'] ?? 0);
$do    = $_POST['do'] ?? '';

$doc = null;
try {
    $s = $pdo->prepare('SELECT * FROM market_scoping WHERE id = ?');
    $s->execute([$docId]);
    $doc = $s->fetch();
} catch (Throwable $t) {}

if (!$doc) {
    flash('error', 'Document not found.');
    header('Location: ../market-scoping.php'); exit;
}
fy_past_stop((int)$doc['fy'], 'Market Scoping', '../market-scoping-print.php?id=' . (int)$doc['id']);   // viewing only

$back = "../market-scoping-review.php?id=$docId";

try {
    if ($do === 'approve') {
        if ($doc['status'] === 'approved') {
            flash('error', 'This document is already approved.');
            header("Location: $back"); exit;
        }
        $pdo->prepare("UPDATE market_scoping SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$u['full_name'], $docId]);
        require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
        doc_snapshot('scoping', (string)$docId, 'approved', '');

        notify_by_dept($doc['dept_code'], 'approval',
            'Market Scoping approved',
            "Your market scoping for FY {$doc['fy']} has been approved by the Procurement Unit.",
            'market-scoping-edit.php?id=' . $docId);

        log_activity('Approved Market Scoping', 'scoping', $doc['dept_code'], "FY {$doc['fy']}");
        flash('success', 'Market scoping approved.');
        header("Location: $back"); exit;
    }

    if ($do === 'revision') {
        $note = trim($_POST['note'] ?? '');
        $pdo->prepare("UPDATE market_scoping SET status='revision', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
            ->execute([$u['full_name'], $note, $docId]);
        require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
        doc_snapshot('scoping', (string)$docId, 'revision', (string)$note);

        notify_mail_reset();
        notify_by_dept($doc['dept_code'], 'revision',
            'Market Scoping needs revision',
            $note !== '' ? $note : 'Your market scoping was sent back for revision.',
            'market-scoping-edit.php?id=' . $docId);

        log_activity('Sent Market Scoping back for revision', 'scoping', $doc['dept_code'], "FY {$doc['fy']}");
        flash('success', 'Sent back to the department for revision.' . notify_mail_note());   // v106: and by email
        header("Location: $back"); exit;
    }

    flash('error', 'Unknown action.');
    header("Location: $back"); exit;
} catch (Throwable $t) {
    flash('error', 'Could not complete the action.');
    header("Location: $back"); exit;
}
