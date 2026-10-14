<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/notify.php';
require_admin();
ensure_admin_tables();

$u   = current_user();
$pdo = db();

$formId = (int)($_POST['id'] ?? 0);
$do     = $_POST['do'] ?? '';

$form = null;
try {
    $s = $pdo->prepare('SELECT * FROM annex_forms WHERE id = ?');
    $s->execute([$formId]);
    $form = $s->fetch();
} catch (Throwable $t) {}

if (!$form) {
    flash('error', 'Annex not found.');
    header('Location: ../annex.php'); exit;
}
fy_past_stop((int)$form['fy'], 'Annex', '../annex-print.php?id=' . (int)$form['id']);   // a past year's annex is for viewing only

$back = "../annex-review.php?id=$formId";

try {
    if ($do === 'approve') {
        if ($form['status'] === 'approved') {
            flash('error', 'This annex is already approved.');
            header("Location: $back"); exit;
        }
        $pdo->prepare("UPDATE annex_forms SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$u['full_name'], $formId]);
        require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
        doc_snapshot('annex', (string)$formId, 'approved', '');

        notify_by_dept($form['dept_code'], 'approval',
            "Annex approved",
            "Your annex for FY {$form['fy']} has been approved by the Procurement Unit.",
            'annex-edit.php?id=' . $formId);

        log_activity("Approved Annex", 'annex', $form['dept_code'], "FY {$form['fy']}");
        flash('success', 'Annex approved.');
        header("Location: $back"); exit;
    }

    if ($do === 'revision') {
        $note = trim($_POST['note'] ?? '');
        $pdo->prepare("UPDATE annex_forms SET status='revision', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
            ->execute([$u['full_name'], $note, $formId]);
        require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
        doc_snapshot('annex', (string)$formId, 'revision', (string)$note);

        notify_mail_reset();
        notify_by_dept($form['dept_code'], 'revision',
            "Annex needs revision",
            $note !== '' ? $note : "Your annex was sent back for revision.",
            'annex-edit.php?id=' . $formId);

        log_activity("Sent Annex back for revision", 'annex', $form['dept_code'], "FY {$form['fy']}");
        flash('success', 'Sent back to the department for revision.' . notify_mail_note());   // v106: and by email
        header("Location: $back"); exit;
    }

    flash('error', 'Unknown action.');
    header("Location: $back"); exit;

} catch (Throwable $t) {
    flash('error', 'Could not complete the action.');
    header("Location: $back"); exit;
}
