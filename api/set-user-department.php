<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_admin(); ensure_admin_tables();
$pdo = db(); $actor = current_user();
$id = (int)($_POST['user_id'] ?? 0); $dept = trim((string)($_POST['dept_code'] ?? ''));
$back = '../pending-users.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id < 1) { header('Location: ' . $back); exit; }
try {
  $u = $pdo->prepare("SELECT id,username,full_name,role,dept_code FROM users WHERE id=? AND role IN ('user','dept_admin') AND status IN ('active','blocked')");
  $u->execute([$id]); $target = $u->fetch();
  $d = $pdo->prepare("SELECT code FROM departments WHERE code=? AND COALESCE(status,'active')='active'"); $d->execute([$dept]);
  if (!$target || !$d->fetch()) flash('error','Choose a valid active department user and department.');
  else {
    $pdo->prepare('UPDATE users SET dept_code=?, sub_unit=NULL WHERE id=?')->execute([$dept,$id]);
    $pdo->prepare("UPDATE department_change_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE user_id=? AND status='pending'")->execute([$actor['full_name'] ?? 'Superadmin',$id]);
    log_activity('Changed user department','user',$target['username'],($target['dept_code'] ?? '') . ' → ' . $dept);
    flash('success','Department updated for ' . $target['full_name'] . '.');
  }
} catch (Throwable $t) { error_log('set user department: '.$t->getMessage()); flash('error','Could not update department.'); }
header('Location: ' . $back); exit;
