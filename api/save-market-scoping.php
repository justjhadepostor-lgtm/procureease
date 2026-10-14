<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/richtext.php';
require_once __DIR__ . '/../config/notify.php';
require_login();
ensure_admin_tables();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']); exit;
}

$u   = current_user();
$pdo = db();
$deptCode = $u['dept_code'] ?? '';

// Handle multipart/form-data for image upload
$hasImageUpload = !empty($_FILES['signatory_image']);
$p = [];
// v101: a form post (a picture, or only "Remove image") is read from $_POST; anything else is the usual JSON
if ($hasImageUpload || !empty($_POST)) {
    $p = $_POST;
} else {
    $p = json_decode(file_get_contents('php://input'), true);
}

if (!is_array($p)) { echo json_encode(['ok' => false, 'error' => 'Invalid data']); exit; }

$docId     = (int)($p['id'] ?? 0);
$fy        = (int)($p['fy'] ?? active_fy());
$title     = trim((string)($p['title'] ?? ''));
$submitNow = !empty($p['submit_now']);

// Only the Department Admin sends it on; anyone may save a draft
if ($submitNow && !can_submit_for_dept($u)) {
    $submitNow = false;
    $__head = dept_submitter_name($u);
    echo json_encode(['ok' => false, 'error' => $__head !== ''
        ? "Only your Department Admin ($__head) can submit this document. Save your work and ask them to send it."
        : 'Only your Department Admin can submit this document.']);
    exit;
}

if ($fy < 2000 || $fy > 2100) $fy = active_fy();

// Everything the person wrote is cleaned before it is stored
$content = rt_sanitize((string)($p['content'] ?? ''));

if (strlen($content) > 12 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'This document is too large. Try using smaller images.']); exit;
}

$__soloWhy = '';
try {
    if ($docId > 0) {
        $s = $pdo->prepare('SELECT * FROM market_scoping WHERE id = ? AND dept_code = ?');
        $s->execute([$docId, $deptCode]);
        $existing = $s->fetch();
        if (!$existing) { echo json_encode(['ok' => false, 'error' => 'Document not found']); exit; }
        if ((int)($existing['archived'] ?? 0) === 1) { echo json_encode(['ok' => false, 'error' => 'This document is in the Archive. Restore it to edit it.']); exit; }
        if (fy_is_past((int)$existing['fy'])) { echo json_encode(['ok' => false, 'error' => fy_past_msg('Market Scoping', (int)$existing['fy'])]); exit; }
        if (in_array($existing['status'], ['submitted', 'approved'], true)) {
            echo json_encode(['ok' => false, 'error' => 'This document has already been submitted and can no longer be edited.']); exit;
        }
        $fy = (int)$existing['fy'];

        $pdo->prepare('UPDATE market_scoping SET title = ?, content = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$title, $content, $docId]);
        
    } else {
        $pdo->prepare("INSERT INTO market_scoping (fy, dept_code, title, content, status, created_by)
                       VALUES (?, ?, ?, ?, 'draft', ?)")
            ->execute([$fy, $deptCode, $title, $content, $u['full_name']]);
        $docId = (int)$pdo->lastInsertId();
    }

    // v104: the pictures, each with its words (config/scoping-photos.php) — words changed,
    // pictures removed, new pictures. A single picture sent the v101 way counts as a new one.
    require_once __DIR__ . '/../config/scoping-photos.php';
    $__files = $_FILES['photos'] ?? null;
    if (!$__files && !empty($_FILES['signatory_image']['name'])) {
        $__f = $_FILES['signatory_image'];
        $__files = ['name' => [$__f['name']], 'type' => [$__f['type']], 'tmp_name' => [$__f['tmp_name']], 'error' => [$__f['error']], 'size' => [$__f['size']]];
        if (isset($p['signatory_caption'])) $p['new_caption'] = [(string)$p['signatory_caption']];
    }
    $__photoNote = ms_photos_save($docId, $p, $__files);

    // The year's form was put on top of an old free-form draft
    if (!empty($p['from_form'])) {
        try { $pdo->prepare('UPDATE market_scoping SET from_form = 1 WHERE id = ?')->execute([$docId]); } catch (Throwable $t) {}
    }

    // Before the PPMP is submitted, the Market Scoping goes with it (config/companions.php)
    if ($submitNow) {
        require_once __DIR__ . '/../config/companions.php';
        [$__solo, $__soloWhy] = companion_solo_submit($deptCode, $fy, (string)($existing['status'] ?? 'draft'));
        if ($__solo) $__soloWhy = ''; else $submitNow = false;
    }

    if ($submitNow) {
        $pdo->prepare("UPDATE market_scoping SET status = 'submitted', submitted_by = ?, submitted_at = NOW(), review_note = NULL WHERE id = ?")
            ->execute([$u['full_name'], $docId]);
        require_once __DIR__ . '/../config/doc-versions.php';   // v79: version history
        doc_snapshot('scoping', (string)$docId, 'submitted', '');

        $deptLabel = $u['dept_name'] ?? $deptCode;
        $__wasRev = (($existing['status'] ?? '') === 'revision');
        notify_by_role('admin', $__wasRev ? 'resubmission' : 'submission',
            $__wasRev ? 'Market Scoping resubmitted after revision' : 'New Market Scoping submitted',
            "$deptLabel submitted their market scoping for FY $fy.",
            'market-scoping-review.php?id=' . $docId);

        log_activity('Submitted Market Scoping', 'scoping', "$deptCode · FY $fy", $title);
    } else {
        log_activity('Saved Market Scoping draft', 'scoping', "$deptCode · FY $fy", $title);
    }

    if ($submitNow) celebrate_submitted("FY $fy Market Scoping", 'Procurement Unit');   // v97: played on the page it goes to next
    echo json_encode($__soloWhy !== '' ? ['ok' => false, 'error' => 'Saved. ' . $__soloWhy, 'id' => $docId]
                                       : ['ok' => true, 'id' => $docId, 'photos' => ms_photos($docId), 'photo_note' => $__photoNote]);
} catch (Throwable $t) {
    echo json_encode(['ok' => false, 'error' => 'Save failed']);
}
