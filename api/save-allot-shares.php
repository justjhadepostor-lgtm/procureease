<?php
/* The school's Department Admin divides the school's allotment among its
   programs (optional) — config/allot-shares.php (v81). */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/allot-shares.php';
require_login();
ensure_admin_tables();

$u  = current_user();
$fy = (int)($_POST['fy'] ?? active_fy());
$back = '../dept-tracking.php?fy=' . $fy . '#soAllot';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }

$school = (string)($u['school_code'] ?? '');
$head   = $school !== '' ? dept_admin_of($school) : null;
if (($u['role'] ?? '') !== 'dept_admin' || $school === '' || !au_has_units($school) || ($head !== null && (int)$head['id'] !== (int)$u['id'])) {
    flash('error', 'Only the Department Admin of the school divides its allotment.');
    header('Location: ' . $back); exit;
}
if (fy_is_past($fy)) {
    flash('error', "FY $fy is a past fiscal year. Its allotment can be viewed, not changed.");
    header('Location: ' . $back); exit;
}
$shares = is_array($_POST['share'] ?? null) ? $_POST['share'] : [];
[$ok, $msg] = allot_shares_save($school, $fy, $shares, (string)($u['full_name'] ?? ''));
if ($ok) log_activity('Divided the allotment', 'budget', "$school · FY $fy", $msg);
flash($ok ? 'success' : 'error', $msg);
header('Location: ' . $back);
exit;
