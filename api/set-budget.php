<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_budget();  // only the Budget Unit sets alloted budgets, based on GAA
ensure_admin_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../admin-dashboard.php');
    exit;
}

$dept_code = trim($_POST['dept_code'] ?? '');
// v81: the allotment belongs to the school, never to one of its programs / majors
require_once __DIR__ . '/../config/academic-units.php';
if (au_is_unit($dept_code) || os_is_set($dept_code)) $dept_code = au_school_of($dept_code);   // (v83: a set → its office)
$fy        = (int)($_POST['fy'] ?? 0);
$alloted   = (float)str_replace(',', '', $_POST['alloted_budget'] ?? '0');
$notes     = trim($_POST['notes'] ?? '');
$return_to = $_POST['return_to'] ?? 'admin-dashboard.php';
$u = current_user();

if ($dept_code === '' || $fy <= 0) {
    flash('error', 'Invalid department or fiscal year.');
    header('Location: ../' . $return_to);
    exit;
}
if ($alloted < 0) {
    flash('error', 'Alloted budget cannot be negative.');
    header('Location: ../' . $return_to);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('INSERT INTO dept_budgets
    (dept_code, fy, alloted_budget, notes, set_by, set_role)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        alloted_budget = VALUES(alloted_budget),
        notes = VALUES(notes),
        set_by = VALUES(set_by),
        set_role = VALUES(set_role)');
$stmt->execute([$dept_code, $fy, $alloted, $notes, $u['full_name'], $u['role']]);

log_activity('Set alloted budget', 'budget', "$dept_code · FY $fy",
    "₱" . number_format($alloted, 2) . ($notes ? " · Notes: $notes" : ''));

flash('success', "Alloted budget for $dept_code (FY $fy) set to ₱" . number_format($alloted, 2) . '.');

header('Location: ../' . $return_to);
exit;
