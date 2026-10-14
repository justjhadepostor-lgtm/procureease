<?php
/* The Market Scoping form of one fiscal year (Document Format page).
   JSON {fy, content} saves it; {fy, action: "reset"} puts the built-in
   NGPA checklist back. Super Admin only. See config/market-scoping-template.php. */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/market-scoping-template.php';
require_admin();
ensure_admin_tables();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }

$u = current_user();
$p = json_decode(file_get_contents('php://input'), true);
if (!is_array($p)) {
    // The reset button on the Document Format page is a plain form
    $p = $_POST;
    $plain = true;
}
$fy = (int)($p['fy'] ?? 0);
// The format being edited is always the active year's (as on the rest of Document Format)
if ($fy !== active_fy()) {
    $msg = 'Only the active fiscal year\'s form (FY ' . active_fy() . ') can be edited. Earlier years keep their own form.';
    if (!empty($plain)) { flash('error', $msg); header('Location: ../document-format.php?type=scoping#ms-form'); exit; }
    echo json_encode(['ok' => false, 'error' => $msg]); exit;
}

try {
    if (($p['action'] ?? '') === 'reset') {
        ms_form_save($fy, null, (string)($u['full_name'] ?? ''));
        log_activity('Reset the Market Scoping form', 'doc_format', "FY $fy", 'Back to the built-in NGPA Market Scoping Checklist.');
        if (!empty($plain)) {
            flash('success', "The FY $fy Market Scoping form is back to the NGPA Market Scoping Checklist. Documents already started are not changed.");
            header('Location: ../document-format.php?type=scoping#ms-form'); exit;
        }
        echo json_encode(['ok' => true]); exit;
    }
    $content = rt_sanitize((string)($p['content'] ?? ''));
    if (trim(strip_tags($content, '<img><table>')) === '') {
        echo json_encode(['ok' => false, 'error' => 'The form cannot be empty. Use "Reset to the NGPA form" to start again.']); exit;
    }
    if (strlen($content) > 12 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'The form is too large. Try using smaller images.']); exit;
    }
    ms_form_save($fy, $content, (string)($u['full_name'] ?? ''));
    // One log line per sitting, not one per autosave
    if (empty($_SESSION['__ms_form_logged'][$fy])) {
        $_SESSION['__ms_form_logged'][$fy] = 1;
        log_activity('Edited the Market Scoping form', 'doc_format', "FY $fy", 'Document Format, Market Scoping tab.');
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $t) {
    error_log('save-scoping-form: ' . $t->getMessage());
    if (!empty($plain)) { flash('error', 'Could not save the form.'); header('Location: ../document-format.php?type=scoping#ms-form'); exit; }
    echo json_encode(['ok' => false, 'error' => 'Save failed']);
}
