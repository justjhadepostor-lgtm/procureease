<?php
require_once __DIR__ . '/../config/auth.php';
require_login();

$u   = current_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user-dashboard.php'); exit;
}

$ppmpId = (int)($_POST['ppmp_id'] ?? 0);
if ($ppmpId <= 0) {
    flash('error', 'Invalid request.');
    header('Location: ../user-dashboard.php'); exit;
}

// Fetch the PPMP and verify ownership + draft status
$p = $pdo->prepare('SELECT id, dept_code, fy, status FROM ppmps WHERE id = ?');
$p->execute([$ppmpId]);
$ppmp = $p->fetch();

if (!$ppmp) {
    flash('error', 'That PPMP no longer exists.');
    header('Location: ../user-dashboard.php'); exit;
}

// Only the owner (same department) may archive, and only while it's still a draft
if ($ppmp['dept_code'] !== ($u['dept_code'] ?? '')) {
    flash('error', 'You can only archive your own department\'s drafts.');
    header('Location: ../user-dashboard.php'); exit;
}

if ($ppmp['status'] !== 'draft') {
    flash('error', 'Only drafts can be archived here. This PPMP has already been submitted.');
    header('Location: ../user-dashboard.php'); exit;
}

try {
    $pdo->prepare('INSERT INTO archived_ppmps (ppmp_id, dept_code, fy, archived_by, reason) VALUES (?,?,?,?,?)')
        ->execute([$ppmpId, $ppmp['dept_code'], (int)$ppmp['fy'], $u['full_name'], 'Draft archived by user']);
    log_activity('Archived draft PPMP', 'ppmp', "{$ppmp['dept_code']} · FY {$ppmp['fy']}");
    flash('success', "Draft for FY {$ppmp['fy']} moved to the archive.");
} catch (Throwable $t) {
    flash('error', 'That draft is already in the archive.');
}

header('Location: ../user-dashboard.php');
exit;
