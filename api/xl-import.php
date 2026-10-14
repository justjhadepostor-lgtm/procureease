<?php
/* v105: what a PPMP.xlsx / APPCSE.xlsx / NONPS.xlsx / ANNEX.xlsx / MARKET SCOPING.docx holds, as JSON
   for the page to show before anything is put on the form (includes/xl-io-ui.php). Nothing is saved here. */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/xl-forms.php';
require_login();
header('Content-Type: application/json');

function xl_import_out(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$doc = (string)($_POST['doc'] ?? '');
$fy  = (int)($_POST['fy'] ?? 0) ?: active_fy();
$f   = $_FILES['file'] ?? null;
$names = ['ppmp' => 'PPMP', 'appcse' => 'APP-CSE', 'nonps' => 'Non-PS list', 'annex' => 'Annex', 'scoping' => 'Market Scoping'];
if (!isset($names[$doc])) xl_import_out(['ok' => false, 'error' => 'Nothing to import into.']);
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    $big = in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    xl_import_out(['ok' => false, 'error' => $big ? 'This file is too big to send.' : 'No file came through. Choose the file again.']);
}
$ext   = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
$want  = $doc === 'scoping' ? 'docx' : 'xlsx';
$bytes = (string)file_get_contents($f['tmp_name']);
if ($ext !== $want) {
    $msg = $want === 'xlsx'
        ? ($ext === 'xls' ? 'This is an older Excel file (.xls). Open it in Excel and save it as “Excel Workbook (.xlsx)”, then import that.'
                          : 'Choose an Excel file (.xlsx) — the ' . $names[$doc] . ' as the Procurement Unit’s form.')
        : ($ext === 'doc' ? 'This is an older Word file (.doc). Open it in Word and save it as “Word Document (.docx)”, then import that.'
                          : 'Choose a Word file (.docx) — the Market Scoping as the Procurement Unit’s form.');
    xl_import_out(['ok' => false, 'error' => $msg]);
}

try {
    if ($doc === 'scoping') {
        $r = ms_import_read($bytes);
        if (isset($r['error'])) xl_import_out(['ok' => false, 'error' => $r['error']]);
        xl_import_out(['ok' => true] + $r);
    }
    $book = xl_read($bytes);
    if ($book === null || !$book['sheets']) xl_import_out(['ok' => false, 'error' => 'This file could not be read as an Excel workbook (.xlsx).']);
    switch ($doc) {
        case 'ppmp':
            $r = xl_ppmp_read($book, $fy);
            if (!$r['sheets']) {
                $why = $r['skipped'] ? ' What it has: ' . implode('; ', array_map(fn($s) => '“' . $s['name'] . '” (' . $s['why'] . ')', $r['skipped'])) . '.' : '';
                xl_import_out(['ok' => false, 'error' => 'This file has no PPMP for Fiscal Year ' . $fy . ' with procurement items on it.' . $why]);
            }
            xl_import_out(['ok' => true] + $r);
        case 'nonps':
            $r = xl_nonps_read($book, $fy);
            break;
        case 'annex':
            $r = xl_annex_read($book);
            break;
        default:
            $r = xl_appcse_read($book);
            if (!isset($r['error']) && !$r['with_qty']) $r = ['error' => 'The APP-CSE in this file has no quantities on it.'];
    }
    if (isset($r['error'])) xl_import_out(['ok' => false, 'error' => $r['error']]);
    xl_import_out(['ok' => true] + $r);
} catch (Throwable $t) {
    xl_import_out(['ok' => false, 'error' => 'This file could not be read: ' . $t->getMessage()]);
}
