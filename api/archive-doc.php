<?php
/* Archive or restore one document by hand — the rules are in config/archive.php. */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin-schema.php';
require_once __DIR__ . '/../config/archive.php';
require_login();
ensure_admin_tables();

$u = current_user();

// Back to a page of this system only
$back = (string)($_POST['back'] ?? '');
if ($back === '' || !preg_match('#^[a-z0-9\-]+\.php(\?[A-Za-z0-9_=&%.:\-]*)?$#', $back)) $back = 'archive.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../' . $back); exit; }

$type = (string)($_POST['type'] ?? '');
$act  = (string)($_POST['act'] ?? '');
if (!isset(ARC_LABELS[$type]) || !in_array($act, ['archive', 'restore'], true)) {
    flash('error', 'That request could not be understood.');
    header('Location: ../' . $back); exit;
}

$doc = arc_load($type, $_POST['ref'] ?? '');
if (!$doc) {
    flash('error', 'That document could not be found.');
    header('Location: ../' . $back); exit;
}

[$ok, $why] = arc_can($u, $doc, $act);
if (!$ok) {
    flash('error', $why);
    header('Location: ../' . $back); exit;
}

if ($act === 'archive' && $doc['archived']) {
    flash('error', 'It is already in the Archive.');
} elseif ($act === 'restore' && !$doc['archived']) {
    flash('error', 'It is not in the Archive.');
} else {
    try {
        flash('success', arc_set($doc, $act === 'archive', $u));
    } catch (Throwable $t) {
        error_log('archive-doc failed: ' . $t->getMessage());
        flash('error', 'Could not ' . $act . ' it. Please try again.');
    }
}

// An editor cannot show an archived document, so send people to the Archive
if ($act === 'archive' && preg_match('#-(edit)\.php#', $back)) $back = 'archive.php';
header('Location: ../' . $back);
exit;
