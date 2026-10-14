<?php
/* The school's Department Admin submits every program and major at once
   (config/school-submit.php, v81). */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/school-submit.php';
require_login();
ensure_admin_tables();

$u  = current_user();
$fy = (int)($_POST['fy'] ?? active_fy());
$back = '../dept-tracking.php?fy=' . $fy;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }

$school = (string)($u['school_code'] ?? '');
if (($u['role'] ?? '') !== 'dept_admin' || $school === '' || !au_has_units($school)) {
    flash('error', 'Only the Department Admin of a school submits the whole school.');
    header('Location: ' . $back); exit;
}
$head = dept_admin_of($school);
if ($head !== null && (int)$head['id'] !== (int)$u['id']) {
    flash('error', 'Only ' . $head['full_name'] . ', the Department Admin of ' . $school . ', can submit the whole school.');
    header('Location: ' . $back); exit;
}

$check = school_submit_check($school, $fy);
if (!$check['ready']) {
    flash('error', 'Not submitted. ' . implode(' ', $check['problems']));
    header('Location: ' . $back); exit;
}
$sent = school_submit_do($school, $fy, $check, $u);
if (!$sent) {
    flash('error', 'Nothing was submitted — it may have just been submitted by someone else.');
} else {
    $n = count($sent);
    flash('success', "Submitted. The FY $fy documents of all $n program" . ($n === 1 ? '' : 's') . '/major' . ($n === 1 ? '' : 's')
        . " of $school went at once: the PPMPs to the Budget Unit, and the APP-CSE, Non-PS lists, Annexes and Market Scoping to the Procurement Unit.");
    celebrate_submitted("FY $fy documents of $school", '');   // v97
}
header('Location: ' . $back);
exit;
