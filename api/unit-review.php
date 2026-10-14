<?php
/* Send a program's / major's documents for review, or (the school's
   Department Admin) approve or return them — config/unit-review.php (v81). */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/unit-review.php';
require_login();
ensure_admin_tables();

$u = current_user();

// Back to a page of this system only
$back = (string)($_POST['back'] ?? '');
if ($back === '' || !preg_match('#^[a-z0-9\-]+\.php(\?[A-Za-z0-9_=&%.:\-]*)?(\#[A-Za-z0-9_\-%]*)?$#', $back)) $back = 'dept-tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../' . $back); exit; }

$unit = (string)($_POST['unit'] ?? '');
$fy   = (int)($_POST['fy'] ?? 0);
$do   = (string)($_POST['do'] ?? '');
$row  = au_row($unit);
if (!$row || !au_is_unit($unit) || $fy <= 0 || !in_array($do, ['request', 'approve', 'return'], true)) {
    flash('error', 'That request could not be understood.');
    header('Location: ../' . $back); exit;
}
$unit = (string)$row['code'];

// Only an account of the school, working on this unit (or its Department Admin)
$scope = $u['scope_units'] ?? [];
$mine  = false;
foreach ($scope as $c) if (strcasecmp((string)$c, $unit) === 0) $mine = true;
if (!$mine) {
    flash('error', 'That program or major is not one you work on.');
    header('Location: ../' . $back); exit;
}

if ($do === 'request') {
    [$ok, $msg] = ur_request($unit, $fy, $u);
} else {
    [$ok, $msg] = ur_decide($unit, $fy, $do === 'approve', (string)($_POST['note'] ?? ''), $u);
}
flash($ok ? 'success' : 'error', $msg);
header('Location: ../' . $back);
exit;
