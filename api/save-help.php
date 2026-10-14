<?php
/* The Help guide, as edited on the Help page. Super Admin only.
   JSON {sections: [{id, title, who, html}, …]} saves it for everyone;
   the plain form action=reset goes back to the built-in guide.
   See config/help-guide.php. */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/help-guide.php';
require_admin();
ensure_admin_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../help.php'); exit; }
$u  = current_user();
$by = (string)($u['full_name'] ?? '');

$p = json_decode(file_get_contents('php://input'), true);
if (!is_array($p)) {
    // "Reset to the built-in guide" is a plain form
    if (($_POST['action'] ?? '') === 'reset') {
        try {
            help_save(null, $by);
            log_activity('Reset the Help guide', 'settings', 'help', 'Back to the built-in guide.');
            flash('success', 'The Help guide is back to the built-in guide, for everyone.');
        } catch (Throwable $t) {
            error_log('save-help reset: ' . $t->getMessage());
            flash('error', 'Could not reset the guide. Nothing was changed.');
        }
    }
    header('Location: ../help.php'); exit;
}

header('Content-Type: application/json');
[$sections, $err] = help_validate(is_array($p['sections'] ?? null) ? $p['sections'] : []);
if ($err !== '') { echo json_encode(['ok' => false, 'error' => $err]); exit; }
try {
    help_save($sections, $by);
    log_activity('Edited the Help guide', 'settings', 'help', count($sections) . ' part' . (count($sections) === 1 ? '' : 's') . '.');
    flash('success', 'The Help guide is saved. Everyone now sees this version.');
    echo json_encode(['ok' => true]);
} catch (Throwable $t) {
    error_log('save-help: ' . $t->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Could not save the guide. Nothing was changed.']);
}
