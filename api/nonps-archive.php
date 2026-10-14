<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_login();
ensure_admin_tables();
$u = current_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../nonps-list.php'); exit; }

$sid = (int)($_POST['submission_id'] ?? 0);
$action = $_POST['action'] ?? 'archive';

$q = $pdo->prepare("SELECT * FROM nonps_submissions WHERE id = ?");
$q->execute([$sid]);
$sub = $q->fetch();
if (!$sub || $sub['dept_code'] !== $u['dept_code']) {
    flash('error', 'Not found or not yours.');
    header('Location: ../nonps-list.php'); exit;
}

// Safe migration for the archived column
try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN archived TINYINT(1) DEFAULT 0"); } catch (Throwable $t) {}
try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN archived_at TIMESTAMP NULL"); } catch (Throwable $t) {}
try { $pdo->exec("ALTER TABLE nonps_submissions ADD COLUMN archived_by VARCHAR(100) NULL"); } catch (Throwable $t) {}

if ($action === 'delete_permanently') {
    if ((int)($sub['archived'] ?? 0) !== 1) {
        flash('error', 'You can only permanently delete a list that is already archived.');
        header('Location: ../nonps-archive.php'); exit;
    }
    $pdo->prepare("DELETE FROM nonps_items WHERE submission_id = ?")->execute([$sid]);
    $pdo->prepare("DELETE FROM nonps_submissions WHERE id = ?")->execute([$sid]);
    log_activity('Deleted Non-PS list', 'nonps', "{$u['dept_code']} · FY {$sub['fy']}", 'Permanently deleted from archive');
    flash('success', "Non-PS list for FY {$sub['fy']} permanently deleted.");
    header('Location: ../nonps-archive.php'); exit;
}

if ($action === 'restore') {
    $pdo->prepare("UPDATE nonps_submissions SET archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?")->execute([$sid]);
    log_activity('Restored Non-PS list', 'nonps', "{$u['dept_code']} · FY {$sub['fy']}", 'Restored from archive');
    flash('success', "Non-PS list for FY {$sub['fy']} restored.");
    header('Location: ../nonps-list.php'); exit;
}

// Default: archive — the same rules as every other document
require_once __DIR__ . '/../config/archive.php';
$__doc = arc_load('nonps', $sid);
if ($__doc) {
    [$__ok, $__why] = arc_can($u, $__doc, 'archive');
    if (!$__ok) { flash('error', $__why); header('Location: ../nonps-list.php'); exit; }
}
$pdo->prepare("UPDATE nonps_submissions SET archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?")
    ->execute([$u['full_name'], $sid]);
log_activity('Archived Non-PS list', 'nonps', "{$u['dept_code']} · FY {$sub['fy']}", 'Moved to archive');
flash('success', "Non-PS list for FY {$sub['fy']} archived.");
header('Location: ../nonps-list.php'); exit;
