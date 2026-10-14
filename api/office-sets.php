<?php
/* Add a set to an office, or remove a set that holds nothing yet (config/office-sets.php, v83). */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_login();
ensure_admin_tables();

$u    = current_user();
$fromPpmp = (string)($_POST['back'] ?? '') === 'ppmp';   // v99: Add / Remove at the top of the PPMP
$back = $fromPpmp ? '../ppmp-edit.php' : '../office-sets.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }

$office = (string)($u['school_code'] ?? '');
if (!os_eligible($office)) { flash('error', 'Sets are for administrative units and offices.'); header('Location: ' . $back); exit; }
$do = (string)($_POST['do'] ?? '');
// v96: everyone of the office may add a set; removing one stays with whoever submits for it
if ($do !== 'add' && !can_submit_for_dept($u)) {
    $head = dept_submitter_name($u);
    flash('error', ($head !== '' ? $head : 'Your Department Admin') . ' removes the sets of ' . $office . '.');
    header('Location: ' . $back); exit;
}

if ($do === 'add') {
    // v99: a set is added only once the office's last set has been submitted for the year
    [$ok, $msg] = os_can_add($office, active_fy());
    $code = '';
    if ($ok) [$ok, $msg, $code] = os_add($office, $u);
    // and the new set is the one being worked on, on every document
    if ($ok && $code !== '') {
        $_SESSION['active_unit'][(int)$u['id']] = $code;
        $msg = 'Set ' . (int)(au_row($code)['set_no'] ?? 2) . ' added — you are now working on it.';
    }
} elseif ($do === 'remove') {
    $code = (string)($_POST['code'] ?? '');
    if (strcasecmp(au_school_of($code), $office) !== 0 || !os_is_set($code)) { $ok = false; $msg = 'That is not a set of ' . $office . '.'; }
    else {
        [$ok, $msg] = $fromPpmp ? os_remove_set($code, (string)($u['full_name'] ?? '')) : os_remove($code);
        // working on the set that is gone: back to Set 1
        if ($ok && isset($_SESSION['active_unit'][(int)$u['id']]) && strcasecmp((string)$_SESSION['active_unit'][(int)$u['id']], $code) === 0) {
            unset($_SESSION['active_unit'][(int)$u['id']]);
        }
    }
} else {
    $ok = false; $msg = 'That request could not be understood.';
}
flash($ok ? 'success' : 'error', $msg);
header('Location: ' . $back);
exit;
