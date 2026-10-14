<?php
/* The Procurement Unit sets which Bachelor / Master an account of a school
   works on, or the whole school (User Management, v81). */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/academic-units.php';
require_admin();

$back = '../pending-users.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }

$id  = (int)($_POST['user_id'] ?? 0);
$sub = trim((string)($_POST['sub_unit'] ?? ''));
$s = db()->prepare('SELECT id, username, full_name, dept_code, sub_unit, role FROM users WHERE id = ?');
$s->execute([$id]);
$t = $s->fetch();
if (!$t || !au_has_units((string)$t['dept_code'])) { flash('error', 'That account could not be found.'); header('Location: ' . $back); exit; }
if ($t['role'] === 'dept_admin') { flash('error', 'The Department Admin works on the whole school.'); header('Location: ' . $back); exit; }

$programs = au_programs_of((string)$t['dept_code']);
$match = '';
foreach ($programs as $pc => $pn) if (strcasecmp((string)$pc, $sub) === 0) $match = (string)$pc;
if ($sub !== '' && $match === '') { flash('error', 'Pick one of the programs of ' . $t['dept_code'] . '.'); header('Location: ' . $back); exit; }

db()->prepare('UPDATE users SET sub_unit = ? WHERE id = ?')->execute([$match !== '' ? $match : null, $id]);
$label = $match !== '' ? $match . ' – ' . $programs[$match] : 'the whole school';
log_activity('Set account program', 'account', (string)$t['username'], "{$t['full_name']} ({$t['dept_code']}) now works on $label.");
flash('success', "{$t['full_name']} now works on $label.");
header('Location: ' . $back);
exit;
