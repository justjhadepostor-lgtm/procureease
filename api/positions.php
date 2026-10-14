<?php
/* The Super Admin adds a position to, or takes one off, the list offered on
   the registration form and the Profile (User Management › Positions, v78). */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/positions.php';
require_admin();   // only the Procurement Unit
ensure_admin_tables();

$back = '../pending-users.php#positions';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }

$do   = (string)($_POST['do'] ?? '');
$name = reg_position_clean((string)($_POST['position'] ?? ''));
$list = reg_positions(true);

if ($do === 'add') {
    if ($name === '') {
        flash('error', 'Type the position to add.');
    } elseif (mb_strlen($name) > REG_POSITION_MAX) {
        flash('error', 'A position can have at most ' . REG_POSITION_MAX . ' characters.');
    } elseif (mb_strtolower($name) === 'others') {
        flash('error', '"Others" is always at the end of the list already — type a position name instead.');
    } elseif (($i = reg_position_index($name, $list)) >= 0) {
        flash('error', '"' . $list[$i] . '" is already on the list.');
    } else {
        $list[] = $name;
        try {
            reg_positions_save($list);
            log_activity('Added a position', 'position', $name, "Added \"$name\" to the positions offered on registration.");
            flash('success', "Added \"$name\". It is now offered on the registration form.");
        } catch (Throwable $t) {
            flash('error', 'The position could not be saved. Please try again.');
        }
    }
} elseif ($do === 'remove') {
    $i = reg_position_index($name, $list);
    if ($i < 0) {
        flash('error', 'That position is not on the list any more.');
    } else {
        $gone = $list[$i];
        array_splice($list, $i, 1);
        try {
            reg_positions_save($list);
            log_activity('Removed a position', 'position', $gone, "Took \"$gone\" off the positions offered on registration.");
            flash('success', "Removed \"$gone\". Users who already have it keep it.");
        } catch (Throwable $t) {
            flash('error', 'The position could not be removed. Please try again.');
        }
    }
} else {
    flash('error', 'Unknown action.');
}
header('Location: ' . $back);
exit;
