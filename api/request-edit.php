<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notify.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../admin-dashboard.php'); exit; }

$u = current_user();

/* v77: asking to edit a department's Non-PS list or APP-CSE (config/edit-permission.php) */
$__docType = (string)($_POST['doc_type'] ?? '');
if (in_array($__docType, ['nonps', 'appcse'], true)) {
    require_once __DIR__ . '/../config/edit-permission.php';
    ensure_edit_request_columns();
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($__docType === 'nonps') {
        $sid = (int)($_POST['sub_id'] ?? 0);
        $back = '../nonps-review.php?sub=' . $sid;
        $q = db()->prepare('SELECT id, dept_code, fy, status, archived FROM nonps_submissions WHERE id = ?');
        $q->execute([$sid]);
        $doc = $q->fetch();
        if (!$doc) { flash('error', 'Non-PS list not found.'); header('Location: ../nonps-inbox.php'); exit; }
        $dept = (string)$doc['dept_code']; $fy = (int)$doc['fy']; $ref = (string)$sid;
        $withPu = (string)$doc['status'] === 'submitted' && (int)($doc['archived'] ?? 0) !== 1;
    } else {
        require_once __DIR__ . '/../config/appcse-review.php';
        $fy   = (int)($_POST['fy'] ?? 0);
        $dept = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['dept'] ?? '')));
        $back = '../appcse-review.php?fy=' . $fy . '&dept=' . urlencode($dept);
        $ref  = $fy . ':' . $dept;
        $withPu = $dept !== '' && appcse_status($fy, $dept) === 'submitted';
    }
    fy_past_stop($fy, PU_EDIT_DOCS[$__docType], $back);
    $label = PU_EDIT_DOCS[$__docType];
    if (!$withPu) { flash('error', "Edit permission can be asked for only while the $label is with the Procurement Unit."); header('Location: ' . $back); exit; }
    if (in_array(pu_doc_edit_state($__docType, $ref, $dept, $u)['state'], ['own', 'granted'], true)) {
        flash('success', "You can already edit this $label."); header('Location: ' . $back); exit;
    }
    if ($reason === '') { flash('error', 'Write why you need to edit it, so the department can decide.'); header('Location: ' . $back); exit; }
    $reason = mb_substr($reason, 0, 1000);
    // An earlier request for the same document no longer counts
    db()->prepare("DELETE FROM edit_requests WHERE doc_type = ? AND doc_ref = ? AND status = 'pending'")->execute([$__docType, $ref]);
    pu_doc_edit_close($__docType, $ref);
    db()->prepare('INSERT INTO edit_requests (dept_code, ppmp_id, fy, reason, requested_by, status, doc_type, doc_ref) VALUES (?, NULL, ?, ?, ?, ?, ?, ?)')
        ->execute([$dept, $fy, $reason, $u['full_name'] . ', ' . $u['position'], 'pending', $__docType, $ref]);
    log_activity('Requested edit', $__docType, "$dept · FY $fy", "$label. Reason: $reason");
    notify_by_dept($dept, 'revision', "Edit permission requested on your FY $fy $label",
        "{$u['full_name']} is asking to edit it. Reason: $reason", 'user-dashboard.php');
    flash('success', "Edit request sent to $dept. You will be notified when they respond.");
    header('Location: ' . $back);
    exit;
}

$dept  = trim($_POST['dept_code'] ?? '');
$ppmp  = (int)($_POST['ppmp_id'] ?? 0);
fy_past_stop(ppmp_fy($ppmp), 'PPMP', '../ppmp-file-view.php?id=' . $ppmp);   // a past year's PPMP is for viewing only
$fy    = (int)($_POST['fy'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

// The PPMP itself decides the department and year; asking only makes sense
// while it is with the Procurement Unit (config/edit-permission.php)
require_once __DIR__ . '/../config/edit-permission.php';
$__p = db()->prepare('SELECT id, dept_code, fy, status FROM ppmps WHERE id = ?');
$__p->execute([$ppmp]);
$__row = $__p->fetch();
if ($__row) { $dept = (string)$__row['dept_code']; $fy = (int)$__row['fy']; }
if (!$__row || !ppmp_with_pu($__row)) {
    flash('error', 'Edit permission can be asked for only while the PPMP is with the Procurement Unit.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp);
    exit;
}
if (in_array(pu_edit_state($__row, $u)['state'], ['own', 'granted'], true)) {
    flash('success', 'You can already edit this PPMP.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp);
    exit;
}

if ($dept === '' || $ppmp === 0 || $fy === 0 || $reason === '') {
    flash('error', 'Missing required fields for edit request.');
    header('Location: ../ppmp-file-view.php?id=' . $ppmp);
    exit;
}

// Remove any prior pending request for the same file; an earlier answer no longer counts
$stmt = db()->prepare("DELETE FROM edit_requests WHERE dept_code = ? AND fy = ? AND status = ? AND (doc_type IS NULL OR doc_type = '' OR doc_type = 'ppmp')");
$stmt->execute([$dept, $fy, 'pending']);
pu_edit_close($ppmp);

$stmt = db()->prepare('INSERT INTO edit_requests (dept_code, ppmp_id, fy, reason, requested_by, status) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute([$dept, $ppmp, $fy, $reason, $u['full_name'] . ', ' . $u['position'], 'pending']);

log_activity('Requested edit', 'ppmp', "$dept · FY $fy", 'Reason: ' . $reason);

// The department has to grant or decline this, so they need to know it exists
notify_by_dept($dept, 'revision',
    "Edit permission requested on your FY $fy PPMP",
    "{$u['full_name']} is asking to edit it. Reason: $reason",
    'user-dashboard.php');

flash('success', "Edit request sent to $dept. You will be notified when they respond.");
header('Location: ../ppmp-file-view.php?id=' . $ppmp);
exit;
