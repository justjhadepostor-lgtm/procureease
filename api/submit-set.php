<?php
/* The Submit window for this department's FY set, as the APP-CSE, Non-PS
   list, Annex and Market Scoping pages show it (config/submit-set.php).
   Read-only: it submits nothing. Submitting goes through api/submit-ppmp.php. */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/submit-set.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$fy = (int)($_GET['fy'] ?? 0);
if ($fy < 2000 || $fy > 2100) $fy = active_fy();
require_once __DIR__ . '/../config/head-submit.php';
$__u = current_user();
echo json_encode(submit_set_for($__u, $fy, hs_applies($__u)));   // v112: an end user's set goes to the Department Admin
