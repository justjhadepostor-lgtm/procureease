<?php
/* ═══════════════════════════════════════════════════════════════
   Document Format — the Procurement Unit's master copy of every form.

   One tab per document. Every fixed word on that form is editable
   here: letterhead, group headings, column headings, signatory block.
   Save once and every department gets the new form immediately — this
   is how a format handed down by a higher office gets rolled out.

   What this page does NOT touch: anything a department writes INTO
   the form. Entries, funds, signatories, "Prepared by" and its
   designation stay editable by each department exactly as before.

   The APP-CSE tab also carries the list of categories and items every
   department's APP-CSE starts from. The rules for carrying a change into
   the departments' forms are at the top of
   config/appcse-catalogue-admin.php. Quantities are never touched.
   =============================================================== */
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/admin-schema.php';
require_once __DIR__ . '/config/doc-format.php';
require_once __DIR__ . '/config/market-scoping-template.php';
require_admin();
ensure_admin_tables();
ensure_doc_format_table();

$u = current_user();
$activeNav = 'doc-format';
$pageTitle = 'Document Format';

// Formats are kept per fiscal year. Editing always applies to the ACTIVE
// year; earlier years keep the form their documents were filed under.
$fmtYear = active_fy();
doc_fmt_use_year($fmtYear);

$type = $_GET['type'] ?? 'ppmp';
if (!isset(DOC_FORMAT_TYPES[$type])) $type = 'ppmp';

$LOGO_DIR = __DIR__ . '/uploads/logos';
$LOGO_REL = 'uploads/logos';

/* ─── Export (v104: a Word file, .docx — config/doc-format-io.php) ─── */
if (isset($_GET['export']) && isset(DOC_FORMAT_TYPES[$_GET['export']])) {
    require_once __DIR__ . '/config/doc-format-io.php';
    $t = $_GET['export'];
    $fmtYear = active_fy();
    doc_fmt_use_year($fmtYear);
    $__docx = fmt_export_docx($t, $fmtYear, (string)(current_user()['full_name'] ?? ''));
    log_activity('Exported document format', 'format', $t, 'Exported the ' . DOC_FORMAT_TYPES[$t] . " form (FY $fmtYear) as a Word file");
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $t . '-form-fy' . $fmtYear . '-' . date('YmdHis') . '.docx"');
    header('Content-Length: ' . strlen($__docx));
    echo $__docx;
    exit;
}

/* ─── Import (v104: any .docx, or .json / .txt — config/doc-format-io.php) ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_action']) && $_POST['import_action'] === 'import') {
    require_once __DIR__ . '/config/doc-format-io.php';
    $t = $_POST['doc_type'] ?? '';
    if (!isset(DOC_FORMAT_TYPES[$t])) {
        flash('error', 'Unknown document type.');
        header('Location: document-format.php'); exit;
    }
    $__back = 'Location: document-format.php?type=' . urlencode($t);
    $__f = $_FILES['import_file'] ?? null;
    if (!$__f || empty($__f['name']) || ($__f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        flash('error', 'Please choose a file to import.');
        header($__back); exit;
    }
    if (in_array($__f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)$__f['size'] > 10 * 1024 * 1024) {
        flash('error', 'The file is too big to import (10 MB at most).');
        header($__back); exit;
    }
    if ($__f['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'The file could not be uploaded. Please try again.');
        header($__back); exit;
    }
    $__read = fmt_import_read($t, (string)$__f['name'], (string)file_get_contents($__f['tmp_name']));
    if (!$__read['ok']) {
        flash('error', $__read['error']);
        header($__back); exit;
    }
    $__all = count(fmt_io_fields($t));
    if (!$__read['values']) {
        flash('error', 'Nothing in "' . $__f['name'] . '" matched the ' . DOC_FORMAT_TYPES[$t] . ' form. Each value needs the name of its field (or its Key) beside it, as in a file from Export form. Nothing was changed.');
        header($__back); exit;
    }
    $fmtYear = active_fy();
    $__r = fmt_import_apply($t, $__read['values'], $fmtYear, (string)current_user()['full_name'], ($__read['kind'] ?? '') === 'form');
    $__msg = 'Imported "' . $__f['name'] . '" into the ' . DOC_FORMAT_TYPES[$t] . " form for FY $fmtYear: {$__r['found']} of $__all field" . ($__all === 1 ? '' : 's') . ' found — '
           . $__r['saved'] . ' changed, ' . $__r['same'] . ' already the same.'
           . ($__r['found'] < $__all ? ' The ' . ($__all - $__r['found']) . ' not in the file were left as they are.' : '');
    if ($__r['saved'] > 0) {
        log_activity('Imported document format', 'format', $t,
            'Imported the ' . DOC_FORMAT_TYPES[$t] . ' form from ' . $__f['name'] . " ({$__r['saved']} changed)");
    }
    if ($__r['skipped']) flash('warning', $__msg . ' Not imported: ' . implode(' ', $__r['skipped']));
    else                 flash('success', $__msg);
    header($__back); exit;
}

/* ─── Save ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['import_action'])) {
    $t = $_POST['doc_type'] ?? '';
    if (!isset(DOC_FORMAT_TYPES[$t])) {
        flash('error', 'Unknown document type.');
        header('Location: document-format.php'); exit;
    }
    /* ─── The APP-CSE list of categories and items ─────────── */
    if ($t === 'appcse' && !empty($_POST['cat_action'])) {
        require_once __DIR__ . '/config/appcse-catalogue-admin.php';
        $pdo  = db();
        $by   = (string)$u['full_name'];
        $part = (($_POST['part'] ?? '') === 'II') ? 'II' : 'I';
        $cat  = (string)($_POST['category'] ?? '');
        $item = ['code'        => $_POST['code'] ?? '',
                 'description' => $_POST['description'] ?? '',
                 'unit'        => $_POST['unit'] ?? '',
                 'unit_price'  => $_POST['unit_price'] ?? ''];
        $id   = (int)($_POST['item_id'] ?? 0);
        $open = $part . '|' . $cat;                 // the category to show on return
        $form = (string)($_POST['form_id'] ?? '');  // the form to refill on an error
        // A row of a category's list sends its fields keyed by the item's id
        if (is_array($_POST['description'] ?? null)) {
            foreach (array_keys($item) as $__k) {
                $item[$__k] = is_array($_POST[$__k] ?? null) ? (string)($_POST[$__k][$id] ?? '') : '';
            }
            $form = 'it-' . $id . '-' . substr(md5($open), 0, 10);
        }

        appcse_catalogue_ensure($pdo, $fmtYear);
        switch ($_POST['cat_action']) {
            case 'rename_category':
                $r = appcse_category_rename($pdo, $fmtYear, $part, $cat, $_POST['new_name'] ?? '', $by);
                if ($r['ok']) $open = $part . '|' . appcse_clean_text($_POST['new_name'] ?? '');
                break;
            case 'add_category':
                $r = appcse_category_add($pdo, $fmtYear, $part, $_POST['new_name'] ?? '', $item, $by);
                if ($r['ok']) $open = $part . '|' . appcse_clean_text($_POST['new_name'] ?? '');
                break;
            case 'delete_category':
                $r = appcse_category_delete($pdo, $fmtYear, $part, $cat, $by);
                if ($r['ok']) $open = '';
                break;
            case 'add_item':
                $r = appcse_item_add($pdo, $fmtYear, $part, $cat, $item, $by);
                break;
            case 'save_item':
                $r = appcse_item_update($pdo, $fmtYear, $id, $item, $by);
                break;
            case 'delete_item':
                $r = appcse_item_delete($pdo, $fmtYear, $id, $by);
                break;
            default:
                $r = ['ok' => false, 'msg' => 'Unknown change.'];
        }
        flash($r['ok'] ? 'success' : 'error', $r['msg']);
        // On an error, put back what was typed so it does not have to be typed again
        if (!$r['ok'] && $form !== '') {
            $_SESSION['__appcse_cat_retry'] = ['form' => $form, 'v' => [
                'new_name' => (string)($_POST['new_name'] ?? ''), 'part' => $part,
                'code' => (string)$item['code'], 'description' => (string)$item['description'],
                'unit' => (string)$item['unit'], 'unit_price' => (string)$item['unit_price']]];
        }
        $anchor = $open !== '' ? substr(md5($open), 0, 10) : '';
        header('Location: document-format.php?type=appcse'
               . ($anchor !== '' ? '&open=' . $anchor . '#cat-' . $anchor : '#appcse-list'));
        exit;
    }

    $fields = doc_fmt_fields($t);

    if (($_POST['action'] ?? 'save') === 'reset') {
        foreach (array_keys($fields) as $k) doc_fmt_save($t, $k, null, $u['full_name'], $fmtYear);
        log_activity('Reset document format', 'format', $t,
            'Restored the built-in form for ' . DOC_FORMAT_TYPES[$t]);
        flash('success', DOC_FORMAT_TYPES[$t] . " for FY $fmtYear is back to the built-in form. Earlier years are unchanged.");
        header('Location: document-format.php?type=' . urlencode($t)); exit;
    }

    // Pin this year before its first change, so a later correction to an
    // earlier year can never reach forward into it.
    doc_fmt_ensure_year($t, $fmtYear, $u['full_name']);

    $errors = [];
    $__colMsg = '';
    foreach ($fields as $key => [$label, $kind]) {
        if ($kind === 'columns') {
            // v94: the PPMP's columns — built-in ones on or off, added ones renamed, removed or added
            require_once __DIR__ . '/config/ppmp-columns.php';
            $L = ppmp_layout($fmtYear);
            $on = (array)($_POST['col_on'] ?? []);
            $off = [];
            foreach (array_keys(PPMP_COLS) as $__id) {
                if (!in_array($__id, PPMP_COLS_REQUIRED, true) && empty($on[$__id])) $off[] = $__id;
            }
            $del = (string)($_POST['col_del'] ?? '');
            $custom = [];
            foreach ($L['custom'] as $__c) {
                if ($__c['id'] === $del) { $__colMsg = 'Column "' . $__c['label'] . '" removed.'; continue; }
                $__lb = trim((string)($_POST['cx_label'][$__c['id']] ?? $__c['label']));
                $__gr = (string)($_POST['cx_group'][$__c['id']] ?? $__c['group']);
                if ($__lb === '') { $errors[] = 'A column needs a heading — type one, or remove the column.'; $__lb = $__c['label']; }
                if (mb_strlen($__lb) > 200) { $errors[] = 'A column heading can have at most 200 characters.'; $__lb = mb_substr($__lb, 0, 200); }
                if ($__gr !== '' && !isset(PPMP_COL_GROUPS[$__gr])) $__gr = '';
                $custom[] = ['id' => $__c['id'], 'label' => $__lb, 'group' => $__gr];
            }
            if (!empty($_POST['col_add'])) {
                $__lb = trim((string)($_POST['new_col_label'] ?? ''));
                $__gr = (string)($_POST['new_col_group'] ?? '');
                if ($__gr !== '' && !isset(PPMP_COL_GROUPS[$__gr])) $__gr = '';
                if ($__lb === '')                                  $errors[] = 'Type the heading of the new column.';
                elseif (mb_strlen($__lb) > 200)                    $errors[] = 'A column heading can have at most 200 characters.';
                elseif (count($custom) >= PPMP_COLS_MAX_CUSTOM)    $errors[] = 'At most ' . PPMP_COLS_MAX_CUSTOM . ' columns can be added.';
                else { $custom[] = ['id' => ppmp_col_new_id(), 'label' => $__lb, 'group' => $__gr]; $__colMsg = 'Column "' . $__lb . '" added.'; }
            }
            doc_fmt_save($t, $key, ppmp_layout_json(['off' => $off, 'custom' => $custom]), $u['full_name'], $fmtYear);
            continue;
        }
        if ($kind === 'percent') {
            // A rate: a number from 0 to 100 (config/price-provision.php)
            require_once __DIR__ . '/config/price-provision.php';
            $v = trim(str_replace('%', '', (string)($_POST['f_' . $key] ?? '')));
            if (nonps_rate_value($v) === null) { $errors[] = "$label must be a number from 0 to 100."; continue; }
            doc_fmt_save($t, $key, nonps_rate_text(nonps_rate_value($v)), $u['full_name'], $fmtYear);
            continue;
        }
        if ($kind === 'text' || $kind === 'html') {
            $v = trim($_POST['f_' . $key] ?? '');
            $cap = ($kind === 'html') ? 6000 : 300;
            if (mb_strlen($v) > $cap) { $errors[] = "$label is too long ($cap characters max)."; continue; }
            doc_fmt_save($t, $key, $v, $u['full_name'], $fmtYear);
            continue;
        }
        // image
        if (!empty($_POST['clear_' . $key])) { doc_fmt_save($t, $key, '', $u['full_name'], $fmtYear); continue; }
        if (empty($_FILES[$key]['name']) || ($_FILES[$key]['error'] ?? 1) !== UPLOAD_ERR_OK) continue;
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = @mime_content_type($_FILES[$key]['tmp_name']);
        if (!isset($allowed[$mime])) { $errors[] = "$label must be a JPG, PNG, WEBP or GIF image."; continue; }
        if ($_FILES[$key]['size'] > 3 * 1024 * 1024) { $errors[] = "$label must be under 3 MB."; continue; }
        if (!is_dir($LOGO_DIR)) @mkdir($LOGO_DIR, 0775, true);
        $name = $t . '-' . $key . '-' . time() . '.' . $allowed[$mime];
        if (@move_uploaded_file($_FILES[$key]['tmp_name'], $LOGO_DIR . '/' . $name)) {
            doc_fmt_save($t, $key, $LOGO_REL . '/' . $name, $u['full_name'], $fmtYear);
        } else {
            $errors[] = "Could not save $label. Please try again.";
        }
    }

    if ($errors) {
        flash('error', implode(' ', $errors));
    } else {
        log_activity('Updated document format', 'format', $t,
            'Updated the ' . DOC_FORMAT_TYPES[$t] . ' form');
        flash('success', ($__colMsg !== '' ? $__colMsg . ' ' : '') . DOC_FORMAT_TYPES[$t] . " form saved for FY $fmtYear. Documents from earlier years keep their own form.");
    }
    header('Location: document-format.php?type=' . urlencode($t)); exit;
}

$fmt     = doc_fmt_all($type, null, true);   // v94: as stored ({rate} stays {rate})
$touched = doc_fmt_touched($type);

/* The APP-CSE list, for the APP-CSE tab only */
$catList = null;
if ($type === 'appcse') {
    require_once __DIR__ . '/config/appcse-catalogue-admin.php';
    $__pdo   = db();
    $catList = appcse_catalogue_grouped($__pdo, $fmtYear);

    // How many departments have a quantity on each item, so it is plain
    // before trying which ones can be removed.
    $catUse = [];
    try {
        $__s = $__pdo->prepare('SELECT `part`, `category`, code, description, COUNT(DISTINCT dept_code) AS n
                                  FROM appcse_items
                                 WHERE fy = ? AND ' . APPCSE_MONTH_SUM . ' <> 0
                              GROUP BY `part`, `category`, code, description');
        $__s->execute([$fmtYear]);
        foreach ($__s->fetchAll() as $__r) {
            $__k = $__r['part'] . '|' . $__r['category'] . '|'
                 . (trim((string)$__r['code']) !== '' ? $__r['code'] : $__r['description']);
            $catUse[$__k] = ($catUse[$__k] ?? 0) + (int)$__r['n'];
        }
    } catch (Throwable $t) {}

    $catRetry = $_SESSION['__appcse_cat_retry'] ?? null;
    unset($_SESSION['__appcse_cat_retry']);
    $catOpen  = (string)($_GET['open'] ?? '');
    $catItems = 0;
    foreach ($catList as $__byCat) foreach ($__byCat as $__rows) $catItems += count($__rows);
}
/** A value to show in a list form: what was typed, if that form just failed. */
function cat_v(?array $retry, string $form, string $k, $default) {
    return ($retry && $retry['form'] === $form) ? (string)($retry['v'][$k] ?? '') : (string)$default;
}

require __DIR__ . '/includes/head.php';
?>
<style>
  .fmt-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
  .fmt-tab { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px;
    border-radius: 10px; text-decoration: none; border: 1.5px solid var(--border);
    background: var(--white); color: var(--ink);
    font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; }
  .fmt-tab:hover { border-color: var(--navy-500); }
  .fmt-tab.active { background: var(--navy-800); border-color: var(--navy-800); color: #fff; }
  .fmt-tab .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--orange-500); }
  .fmt-group { margin-bottom: 22px; }
  .fmt-group-title { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px;
    color: var(--navy-800); text-transform: uppercase; letter-spacing: .05em;
    padding-bottom: 7px; margin-bottom: 12px; border-bottom: 1px solid var(--border); }
  .fmt-row { display: grid; grid-template-columns: 210px 1fr; gap: 14px; align-items: start; margin-bottom: 10px; }
  .fmt-row > label { font-size: 12.5px; font-weight: 600; color: var(--ink); padding-top: 9px; }
  .fmt-changed { display: inline-block; margin-left: 6px; font-size: 10px; font-weight: 700;
    color: var(--orange-600); background: var(--orange-50); border: 1px solid var(--orange-100);
    border-radius: 999px; padding: 1px 7px; vertical-align: middle; }
  .logo-box { display: flex; gap: 14px; align-items: center; padding: 12px;
    border: 1.5px dashed var(--border); border-radius: 12px; }
  .logo-prev { width: 66px; height: 66px; flex-shrink: 0; display: grid; place-items: center;
    overflow: hidden; background: var(--navy-50); border-radius: 10px; }
  .logo-prev img { max-width: 100%; max-height: 100%; object-fit: contain; }
  .lh-preview { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 20px 24px; color: #133458; }
  /* The Market Scoping form (Market Scoping tab) */
  .ms-form-state { font-size: 12.5px; color: var(--ink); background: var(--navy-50); border: 1px solid var(--navy-100);
                   border-radius: 10px; padding: 9px 13px; margin-bottom: 12px; }
  .ms-form-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
  .ms-fields { font-size: 12.5px; color: var(--ink); margin-bottom: 14px; }
  .ms-fields summary { cursor: pointer; font-weight: 600; }
  .ms-fields ul { margin: 8px 0 0 18px; padding: 0; line-height: 1.7; }
  .ms-fields code { background: #fff4d6; color: #5c3d00; padding: 1px 5px; border-radius: 4px; font-size: 12px; }
  .ms-form-preview { background: #eef2f6; border: 1px solid var(--border); border-radius: 10px; padding: 18px;
                     max-height: 560px; overflow: auto; }
  .ms-form-page { background: #fff; color: #000; max-width: 8.5in; margin: 0 auto; padding: 0.5in 0.7in;
                  font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.3;
                  box-shadow: 0 3px 14px rgba(0,0,0,0.13); }
  .ms-form-page table { border-collapse: collapse; }
  .ms-form-page .ms-tok { background: #fff4d6; color: #5c3d00; border-radius: 3px; padding: 0 2px; }
  body[data-theme="dark"] .ms-form-page, body[data-theme="dark"] .ms-form-page * { color: #000; }
  body[data-theme="dark"] .ms-form-page .ms-tok { color: #5c3d00 !important; background: #fff4d6 !important; }
  body[data-theme="dark"] .ms-fields code { color: #5c3d00; background: #fff4d6; }
  .lh-row { display: grid; grid-template-columns: 90px 1fr 90px; align-items: center; gap: 12px; }
  .lh-row .side { display: grid; place-items: center; }
  .lh-row .side img { max-width: 78px; max-height: 78px; object-fit: contain; }
  .lh-mid { text-align: center; font-family: Arial, sans-serif; }
  .lh-mid .rep { font-size: 11px; } .lh-mid .org { font-size: 17px; font-weight: 700; margin: 1px 0; }
  .lh-mid .addr { font-size: 10px; font-style: italic; }
  .lh-mid .doc { font-size: 12.5px; font-weight: 700; margin-top: 6px; }
  .colprev { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-top: 14px; table-layout: fixed; }
  .colprev th { border: 1px solid #000; padding: 4px 3px; text-align: center; vertical-align: middle;
    font-weight: 700; word-wrap: break-word; }
  /* v94: the PPMP's columns */
  .pc-wrap { overflow-x: auto; }
  .fmt-row > div { min-width: 0; }   /* a wide list scrolls inside its box, not the page */
  .pc-table { width: 100%; min-width: 640px; border-collapse: collapse; font-size: 12.5px; }
  .pc-table th { text-align: left; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em;
    padding: 6px 8px; border-bottom: 1px solid var(--border); }
  .pc-table td { padding: 7px 8px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--ink); }
  .pc-table td .form-input { width: 100%; box-sizing: border-box; }
  .pc-table tr.pc-off td { color: var(--muted); }
  .pc-n { width: 34px; font-weight: 700; }
  .pc-hint { font-size: 11px; color: var(--muted); }
  .pc-on { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; cursor: pointer; }
  .pc-del { padding: 5px 12px; font-size: 12px; color: var(--orange-600); }
  .pc-add { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 12px; }
  .pc-add .form-input { flex: 1 1 260px; min-width: 0; }

  /* ── The APP-CSE list of categories and items ── */
  .ce .btn-xs { padding: 6px 12px; font-size: 12px; border-radius: 8px; }
  .ce-rules { margin: 0 0 16px; padding: 12px 16px 12px 32px; font-size: 12.5px; line-height: 1.6;
    color: var(--ink); background: var(--navy-50); border: 1px solid var(--navy-100); border-radius: 10px; }
  .ce-tools { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .ce-tools .form-input { flex: 1 1 260px; min-width: 0; max-width: 420px; }
  .ce-count { font-size: 12px; color: var(--muted); }
  .ce-part { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: var(--ink);
    text-transform: uppercase; letter-spacing: .04em; margin: 20px 0 8px; }
  .ce-cat { border: 1px solid var(--border); border-radius: 10px; margin-bottom: 8px; background: var(--white); }
  .ce-cat > summary { display: flex; align-items: center; gap: 10px; padding: 11px 14px; cursor: pointer;
    font-size: 13px; font-weight: 600; color: var(--ink); list-style: none; }
  .ce-cat > summary::-webkit-details-marker { display: none; }
  .ce-cat > summary::before { content: '\25B8'; color: var(--muted); font-size: 11px; transition: transform .15s; }
  .ce-cat[open] > summary::before { transform: rotate(90deg); }
  .ce-cat > summary .nm { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }
  .ce-cat > summary .ct { flex-shrink: 0; font-size: 11.5px; font-weight: 600; color: var(--muted); }
  .ce-body { padding: 4px 14px 14px; border-top: 1px solid var(--border); }
  .ce-catbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 10px 0 12px; }
  .ce-catbar form { display: flex; gap: 8px; align-items: center; margin: 0; }
  .ce-catbar .rename { flex: 1 1 360px; min-width: 0; }
  .ce-catbar .rename .form-input { flex: 1 1 auto; min-width: 0; }
  .ce-scroll { overflow-x: auto; }
  table.ce-items { width: 100%; min-width: 820px; border-collapse: collapse; font-size: 12.5px; }
  table.ce-items th { text-align: left; font-size: 11px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .04em; padding: 6px 6px; border-bottom: 1px solid var(--border); }
  table.ce-items td { padding: 5px 6px; border-bottom: 1px solid var(--border); vertical-align: middle; }
  table.ce-items tr:last-child td { border-bottom: none; }
  table.ce-items .ci-in { width: 100%; box-sizing: border-box; padding: 6px 8px; font: inherit; font-size: 12.5px;
    color: var(--ink); background: var(--white); border: 1px solid var(--border); border-radius: 6px; }
  table.ce-items .ci-in:focus { outline: none; border-color: var(--navy-500); box-shadow: 0 0 0 2px var(--navy-50); }
  table.ce-items .ci-in.code { font-family: ui-monospace, monospace; font-size: 11.5px; }
  table.ce-items .ci-in.price { text-align: right; }
  table.ce-items td.use { font-size: 11.5px; color: var(--muted); white-space: nowrap; }
  table.ce-items td.act { white-space: nowrap; }
  table.ce-items td.act .ce-del { color: var(--orange-600); }
  table.ce-items tr.ce-add td { background: var(--navy-50); }
  table.ce-items tr.dirty .ci-in { border-color: var(--navy-500); }
  .ce-new { border: 1.5px dashed var(--border); border-radius: 10px; margin-bottom: 14px; }
  .ce-new > summary { padding: 11px 14px; cursor: pointer; font-size: 13px; font-weight: 700; color: var(--ink); }
  .ce-new-grid { display: grid; grid-template-columns: 170px 1fr; gap: 10px 14px; align-items: center; padding: 4px 14px 14px; }
  .ce-new-grid > label { font-size: 12.5px; font-weight: 600; color: var(--ink); }
  .ce-new-item { display: grid; grid-template-columns: 150px 1fr 110px 120px; gap: 8px; min-width: 0; }
  .ce-new-item .form-input { min-width: 0; }
  @media (max-width: 900px) {
    .ce-new-grid { grid-template-columns: 1fr; }
    .ce-new-item { grid-template-columns: 1fr 1fr; }
  }
</style>

<div class="app">
<?php require __DIR__ . '/includes/sidebar-admin.php'; ?>
  <main class="main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Document Format · FY <?= $fmtYear ?></h1>
      </div>
    </div>
    <?php render_flashes(); ?>

    <div class="fmt-tabs">
      <?php foreach (DOC_FORMAT_TYPES as $code => $label): ?>
        <a href="?type=<?= urlencode($code) ?>" class="fmt-tab <?= $code === $type ? 'active' : '' ?>">
          <?= e($label) ?><?php if (doc_fmt_type_is_custom($code)): ?><span class="dot" title="Changed from the built-in form"></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Preview -->
    <div class="card" style="margin-bottom: 16px;">
      <div class="card-title">How the <?= e(DOC_FORMAT_TYPES[$type]) ?> form looks now</div>
      <div class="card-sub" style="margin-bottom: 14px;">
        <?php if ($touched): ?>
          Last changed <?= e(date('j M Y, g:ia', strtotime($touched['updated_at']))) ?><?= !empty($touched['updated_by']) ? ' by ' . e($touched['updated_by']) : '' ?>.
        <?php else: ?>Currently the built-in form.<?php endif; ?>
      </div>
      <div class="lh-preview">
        <?php if (isset($fmt['org_name'])): ?>
          <div class="lh-row">
            <div class="side"><?php if (!empty($fmt['logo_left'])): ?><img src="<?= e(doc_logo($type, 'left')) ?>" alt=""><?php endif; ?></div>
            <div class="lh-mid">
              <?php if (isset($fmt['rep_line'])): ?><div class="rep"><?= e($fmt['rep_line']) ?></div><?php endif; ?>
              <div class="org"><?= e($fmt['org_name']) ?></div>
              <?php if (isset($fmt['address'])): ?><div class="addr"><?= e($fmt['address']) ?></div><?php endif; ?>
              <?php if (!empty($fmt['unit_line'])): ?><div class="doc"><?= e($fmt['unit_line']) ?></div><?php endif; ?>
              <?php if (!empty($fmt['doc_title'])): ?><div class="doc"><?= e($fmt['doc_title']) ?></div><?php endif; ?>
            </div>
            <div class="side"><?php if (!empty($fmt['logo_right'])): ?><img src="<?= e(doc_logo($type, 'right')) ?>" alt=""><?php endif; ?></div>
          </div>
        <?php endif; ?>

        <?php
          // Column strip preview, for documents that have one
          $colKeys = array_keys(DOC_FORMAT_SCHEMA[$type]['Column headings'] ?? []);
          if ($type === 'ppmp'):   // v94: the columns the FY's PPMP really has
            require_once __DIR__ . '/config/ppmp-columns.php';
            $__pv = ppmp_cols($fmtYear); $__ph = ppmp_cols_header($__pv, $fmtYear); ?>
          <div style="overflow-x: auto;"><table class="colprev" id="ppmpColPreview" style="min-width: 760px;">
            <tr><?php foreach ($__ph['top'] as $__t): ?>
              <?php if (isset($__t['col'])): ?><th rowspan="2"><?= nl2br(e($__t['col']['label'])) ?></th>
              <?php else: ?><th colspan="<?= (int)$__t['span'] ?>"><?= e($__t['label']) ?></th><?php endif; ?>
            <?php endforeach; ?></tr>
            <tr><?php foreach ($__ph['second'] as $__c): ?><th><?= nl2br(e($__c['label'])) ?></th><?php endforeach; ?></tr>
          </table></div>
        <?php elseif ($colKeys): ?>
          <table class="colprev">
            <tr><?php foreach ($colKeys as $ck): ?>
              <th><?= nl2br(e($fmt[$ck])) ?></th>
            <?php endforeach; ?></tr>
          </table>
        <?php endif; ?>

        <?php
          // Show the name that will really print: what is typed here, or,
          // when that is blank, the Procurement Unit head's own.
          $__pvHead = procurement_head();
          $fmt['sig_head_name']     = ($fmt['sig_head_name'] ?? '') !== ''
                                      ? $fmt['sig_head_name'] : $__pvHead['name'];
          $fmt['sig_head_position'] = ($fmt['sig_head_position'] ?? '') !== ''
                                      ? $fmt['sig_head_position'] : $__pvHead['position'];
        ?>
        <?php if (!empty($fmt['sig_head_name'])): ?>
          <div style="margin-top: 18px; font-family: Arial, sans-serif; font-size: 11px;">
            <div><?= e($fmt['sig_prepared'] ?? '') ?></div>
            <div style="margin-top: 22px; font-weight: 700;"><?= e($fmt['sig_head_name']) ?></div>
            <div><?= e($fmt['sig_head_position'] ?? '') ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Editor -->
    <div class="card">
      <div class="card-title">Edit the <?= e(DOC_FORMAT_TYPES[$type]) ?> form &middot; FY <?= $fmtYear ?></div>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="doc_type" value="<?= e($type) ?>">

        <?php foreach (DOC_FORMAT_SCHEMA[$type] as $groupName => $fields): ?>
          <div class="fmt-group">
            <div class="fmt-group-title"><?= e($groupName) ?></div>
            <?php foreach ($fields as $key => [$label, $kind]): ?>
              <div class="fmt-row">
                <label for="fld_<?= e($key) ?>">
                  <?= e($label) ?>
                  <?php if (doc_fmt_is_custom($type, $key)): ?><span class="fmt-changed">changed</span><?php endif; ?>
                </label>
                <div>
                  <?php if ($kind === 'image'): ?>
                    <div class="logo-box">
                      <div class="logo-prev">
                        <?php if (!empty($fmt[$key])): ?>
                          <img src="<?= e(doc_logo($type, $key === 'logo_right' ? 'right' : 'left')) ?>" alt="">
                        <?php else: ?><span style="font-size:10px;color:var(--muted);">none</span><?php endif; ?>
                      </div>
                      <div style="flex:1; min-width:0;">
                        <input type="file" id="fld_<?= e($key) ?>" name="<?= e($key) ?>" accept="image/*" style="font-size:12px; max-width:100%;">
                        <div style="font-size:11px; color:var(--muted); margin-top:5px;">Leave empty to keep the current image. Under 3 MB.</div>
                        <?php if (!empty($fmt[$key])): ?>
                          <label style="display:flex; align-items:center; gap:6px; font-size:11.5px; color:var(--muted); margin-top:6px;">
                            <input type="checkbox" name="clear_<?= e($key) ?>" value="1"> Show no image here
                          </label>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php elseif ($kind === 'html'): ?>
                    <textarea id="fld_<?= e($key) ?>" name="f_<?= e($key) ?>" class="form-input"
                              rows="<?= mb_strlen($fmt[$key] ?? '') > 300 ? 8 : 3 ?>"
                              style="font-family: ui-monospace, monospace; font-size: 12px; line-height: 1.5; resize: vertical;"><?= e($fmt[$key] ?? '') ?></textarea>
                    <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">
                      Plain text. Put each line on its own line &mdash; that is where the line breaks go.
                      <?php if (strpos((string)($fmt[$key] ?? ''), '{FY}') !== false || strpos((string)($fmt[$key] ?? ''), '{FY-1}') !== false): ?>
                        Keep <code>{FY}</code> and <code>{FY-1}</code> where the fiscal year should appear.
                      <?php endif; ?>
                    </div>
                  <?php elseif ($kind === 'columns'): require_once __DIR__ . '/config/ppmp-columns.php'; $__cols = ppmp_cols($fmtYear, true);
                        $__grpName = ['details' => doc_fmt('ppmp', 'grp_details'), 'timeline' => doc_fmt('ppmp', 'grp_timeline'), 'funding' => doc_fmt('ppmp', 'grp_funding'), '' => 'Its own heading']; ?>
                    <div class="pc-wrap" id="ppmpColumns">
                      <table class="pc-table">
                        <thead><tr><th>#</th><th>Column heading</th><th>Under</th><th>On the form</th><th></th></tr></thead>
                        <tbody>
                        <?php $__n = 0; foreach ($__cols as $__c): if ($__c['on']) $__n++; ?>
                          <tr class="<?= $__c['on'] ? '' : 'pc-off' ?>" data-col="<?= e($__c['id']) ?>">
                            <td class="pc-n"><?= $__c['on'] ? $__n : '—' ?></td>
                            <?php if ($__c['builtin']): ?>
                              <td><?= e($__c['label']) ?><div class="pc-hint">Built in · its heading is under Column headings</div></td>
                              <td><?= e($__grpName[$__c['group']]) ?></td>
                              <td><?php if (in_array($__c['id'], PPMP_COLS_REQUIRED, true)): ?>
                                    <input type="hidden" name="col_on[<?= e($__c['id']) ?>]" value="1"><span class="pc-hint">Always (the totals use it)</span>
                                  <?php else: ?>
                                    <label class="pc-on"><input type="checkbox" name="col_on[<?= e($__c['id']) ?>]" value="1" <?= $__c['on'] ? 'checked' : '' ?>> Show</label>
                                  <?php endif; ?></td>
                              <td></td>
                            <?php else: ?>
                              <td><input type="text" class="form-input" name="cx_label[<?= e($__c['id']) ?>]" value="<?= e($__c['label']) ?>" maxlength="200" aria-label="Heading of the added column"></td>
                              <td><select class="filter-select" name="cx_group[<?= e($__c['id']) ?>]" aria-label="Under which heading">
                                  <?php foreach ($__grpName as $__gk => $__gl): ?><option value="<?= e($__gk) ?>" <?= $__c['group'] === $__gk ? 'selected' : '' ?>><?= e($__gl) ?></option><?php endforeach; ?></select></td>
                              <td><span class="pc-hint">Added</span></td>
                              <td><button type="submit" name="col_del" value="<?= e($__c['id']) ?>" class="btn btn-ghost pc-del"
                                          data-label="<?= e($__c['label']) ?>">✕ Remove</button></td>
                            <?php endif; ?>
                          </tr>
                        <?php endforeach; ?>
                        </tbody>
                      </table>
                      <div class="pc-add">
                        <input type="text" class="form-input" name="new_col_label" id="newColLabel" maxlength="200" placeholder="Heading of a new column" aria-label="Heading of a new column">
                        <select class="filter-select" name="new_col_group" aria-label="Under which heading">
                          <?php foreach ($__grpName as $__gk => $__gl): ?><option value="<?= e($__gk) ?>"><?= e($__gl) ?></option><?php endforeach; ?></select>
                        <button type="submit" name="col_add" value="1" class="btn btn-primary" id="addColBtn">＋ Add column</button></div>
                      <div class="pc-hint" style="margin-top:6px;">Changes apply to the FY <?= $fmtYear ?> PPMP of every department. PPMPs of earlier years keep their columns.</div>
                    </div>
                    <script>
                    /* Enter in the new column's box adds it */
                    (function () { var i = document.getElementById('newColLabel'), b = document.getElementById('addColBtn');
                      if (i && b) i.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); b.click(); } }); })();
                    /* ✕ Remove asks first, in the system's own confirm box */
                    document.querySelectorAll('#ppmpColumns .pc-del').forEach(function (b) {
                      b.addEventListener('click', function (ev) {
                        if (!window.swiftConfirm) return;
                        ev.preventDefault();
                        swiftConfirm({ title: 'Remove this column?', confirmLabel: 'Remove', tone: 'danger',
                          text: '"' + b.dataset.label + '" comes off the FY <?= (int)$fmtYear ?> PPMP of every department. What departments typed in it is kept.',
                          onConfirm: function () {
                            var f = b.form, h = document.createElement('input');
                            h.type = 'hidden'; h.name = 'col_del'; h.value = b.value; f.appendChild(h); f.submit();
                          } });
                      });
                    });
                    </script>
                  <?php elseif ($kind === 'percent'): ?>
                    <div style="display:flex; align-items:center; gap:8px;">
                      <input type="text" inputmode="decimal" id="fld_<?= e($key) ?>" name="f_<?= e($key) ?>" class="form-input"
                             maxlength="8" style="max-width:120px;" value="<?= e($fmt[$key] ?? '') ?>"><span style="font-weight:700;">%</span></div>
                  <?php else: ?>
                    <input type="text" id="fld_<?= e($key) ?>" name="f_<?= e($key) ?>" class="form-input"
                           maxlength="300" value="<?= e($fmt[$key] ?? '') ?>">
                    <?php if (strpos((string)($fmt[$key] ?? ''), '{rate}') !== false): ?>
                      <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Keep <code>{rate}</code> where the % should appear.</div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <div style="display:flex; gap:10px; margin-top:6px; flex-wrap:wrap;">
          <button type="submit" class="btn btn-primary" id="fmtSave">Save &amp; apply to everyone</button>
          <script>
          /* v94: Enter in a box saves the form, as "Save & apply to everyone" — never a ✕ Remove of a column */
          (function () {
            var save = document.getElementById('fmtSave'), f = save && save.form; if (!f) return;
            f.addEventListener('keydown', function (ev) {
              var t = ev.target;
              if (ev.key !== 'Enter' || !t || t.tagName !== 'INPUT' || t.id === 'newColLabel' || /^(button|submit|checkbox|radio|file)$/.test(t.type)) return;
              ev.preventDefault();
              if (f.requestSubmit) f.requestSubmit(save); else save.click();
            });
          })();
          </script>
          <a href="?export=<?= urlencode($type) ?>" class="btn btn-ghost" style="color: var(--navy-700);" data-no-loader title="Download the form itself, as the departments fill it in, as a Word file (.docx)">📥 Export form (.docx)</a>
          <a href="?type=<?= urlencode($type) ?>" class="btn btn-ghost">Cancel</a>
        </div>
      </form>

      <!-- Import Form -->
      <form method="POST" enctype="multipart/form-data" style="margin-top:14px; padding-top:14px; border-top:1px dashed var(--border);">
        <input type="hidden" name="import_action" value="import">
        <input type="hidden" name="doc_type" value="<?= e($type) ?>">
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <div style="flex:1; min-width:200px;">
            <input type="file" name="import_file" class="form-input" style="font-size:12px;">
          </div>
          <button type="submit" class="btn btn-ghost" style="color: var(--navy-700);">📤 Import form</button>
        </div>
        <div style="font-size:11.5px; color:var(--muted); margin-top:6px;">Import a Word file (.docx) — the form from Export form after editing its wording in Word, or any .docx with each field's name and its value — or a .json or .txt file. What the file has replaces the wording of the <?= e(DOC_FORMAT_TYPES[$type]) ?> form for FY <?= $fmtYear ?>; what it does not have stays as it is. A PDF cannot be imported.</div>
      </form>

      <form method="POST" style="margin-top:14px; padding-top:14px; border-top:1px dashed var(--border);"
            data-confirm="The <?= e(DOC_FORMAT_TYPES[$type]) ?> form goes back to its original wording and logos for every department."
            data-confirm-title="Restore the built-in form?" data-confirm-label="Restore" data-confirm-tone="danger">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="doc_type" value="<?= e($type) ?>">
        <button type="submit" class="btn btn-ghost" style="color: var(--orange-600);">Restore built-in form</button>
        <span style="font-size:11.5px; color:var(--muted); margin-left:8px;">Uploaded images are kept, so you can put them back.<?php if ($type === 'appcse'): ?> The list of categories and items below is not affected.<?php endif; ?></span>
      </form>
    </div>

    <?php if ($type === 'scoping'):
      $__msFrom = ms_form_origin($fmtYear);
      $__msMeta = ms_form_meta($fmtYear);
    ?>
    <!-- The Market Scoping form itself, for this year -->
    <div class="card" id="ms-form" style="margin-top:16px;">
      <div class="card-title">Market Scoping form &middot; FY <?= $fmtYear ?></div>
      <div class="ms-form-state" id="msFormState">
        <?php if ($__msFrom === 0): ?>
          Using the built-in <strong>NGPA Market Scoping Checklist</strong>.
        <?php elseif ($__msFrom === $fmtYear): ?>
          Edited for FY <?= $fmtYear ?><?php if ($__msMeta && !empty($__msMeta['updated_at'])): ?> &mdash; last saved <?= e(date('j M Y, g:ia', strtotime($__msMeta['updated_at']))) ?><?= !empty($__msMeta['updated_by']) ? ' by ' . e($__msMeta['updated_by']) : '' ?><?php endif; ?>.
        <?php else: ?>
          Carried over from FY <?= $__msFrom ?>. Editing it here makes a FY <?= $fmtYear ?> form of its own; FY <?= $__msFrom ?> keeps its form.
        <?php endif; ?>
      </div>
      <div class="ms-form-actions">
        <a href="market-scoping-edit.php?form=<?= $fmtYear ?>" class="btn btn-primary" id="msFormEdit" style="text-decoration:none;">✎ Edit the form</a>
        <?php if ($__msFrom === $fmtYear): ?>
          <form method="POST" action="api/save-scoping-form.php" style="margin:0;"
                data-confirm="The FY <?= $fmtYear ?> Market Scoping form goes back to the NGPA Market Scoping Checklist. Documents already started are not changed."
                data-confirm-title="Reset to the NGPA form?" data-confirm-label="Reset" data-confirm-tone="danger">
            <input type="hidden" name="fy" value="<?= $fmtYear ?>">
            <input type="hidden" name="action" value="reset">
            <button type="submit" class="btn btn-ghost" style="color: var(--orange-600);">↺ Reset to the NGPA form</button>
          </form>
        <?php endif; ?>
      </div>
      <details class="ms-fields">
        <summary>Fields filled in for each department</summary>
        <ul>
          <?php foreach (MS_FORM_TOKEN_HELP as $__tok => $__help): ?>
            <li><code><?= e($__tok) ?></code> &mdash; <?= e($__help) ?></li>
          <?php endforeach; ?>
        </ul>
      </details>
      <div class="ms-form-preview"><div class="ms-form-page" id="msFormPreview"><?= ms_form_preview($fmtYear) ?></div></div>
    </div>
    <?php endif; ?>

    <?php if ($type === 'appcse' && $catList !== null): ?>
    <!-- The APP-CSE list of categories and items -->
    <div class="card ce" id="appcse-list">
      <div class="card-title">APP-CSE categories and items &middot; FY <?= $fmtYear ?></div>
      <div class="card-sub" style="margin-bottom: 14px;">
        <?= count($catList['I']) + count($catList['II']) ?> categories, <?= $catItems ?> items.
      </div>

      <div class="ce-tools">
        <?= search_field(['id' => 'ceFind', 'placeholder' => 'Find a category or an item…', 'label' => 'Find a category or an item', 'style' => 'max-width:420px;']) ?>
        <span class="ce-count" id="ceCount"></span>
      </div>

      <?php $__f = 'newcat'; ?>
      <details class="ce-new"<?= ($catRetry && $catRetry['form'] === $__f) ? ' open' : '' ?>>
        <summary>+ Add a category</summary>
        <form method="POST" class="ce-new-grid">
          <input type="hidden" name="doc_type" value="appcse">
          <input type="hidden" name="cat_action" value="add_category">
          <input type="hidden" name="form_id" value="<?= $__f ?>">
          <label for="nc_part">Part</label>
          <select id="nc_part" name="part" class="form-input">
            <?php $__p = cat_v($catRetry, $__f, 'part', 'I'); ?>
            <option value="I"<?= $__p === 'I' ? ' selected' : '' ?>>Part I &mdash; Available at PS-DBM</option>
            <option value="II"<?= $__p === 'II' ? ' selected' : '' ?>>Part II &mdash; Other items not available at PS-DBM</option>
          </select>
          <label for="nc_name">Category name</label>
          <input type="text" id="nc_name" name="new_name" class="form-input" maxlength="150" required
                 value="<?= e(cat_v($catRetry, $__f, 'new_name', '')) ?>">
          <label>Its first item</label>
          <div class="ce-new-item">
            <input type="text" name="code" class="form-input" maxlength="60" placeholder="Code" aria-label="Item code"
                   value="<?= e(cat_v($catRetry, $__f, 'code', '')) ?>">
            <input type="text" name="description" class="form-input" maxlength="255" required placeholder="Item &amp; specifications" aria-label="Item and specifications"
                   value="<?= e(cat_v($catRetry, $__f, 'description', '')) ?>">
            <input type="text" name="unit" class="form-input" maxlength="40" required placeholder="Unit" aria-label="Unit of measure"
                   value="<?= e(cat_v($catRetry, $__f, 'unit', '')) ?>">
            <input type="text" name="unit_price" class="form-input money" inputmode="decimal" required placeholder="Price" aria-label="Unit price"
                   value="<?= e(cat_v($catRetry, $__f, 'unit_price', '')) ?>">
          </div>
          <span></span>
          <div><button type="submit" class="btn btn-primary btn-xs">Add category</button></div>
        </form>
      </details>

      <?php foreach (['I'  => 'Part I. Available at PS-DBM (main warehouse and depots)',
                      'II' => 'Part II. Other items not available at PS-DBM'] as $__part => $__label): ?>
        <div class="ce-part"><?= e($__label) ?></div>
        <?php if (!$catList[$__part]): ?>
          <div class="ce-count" style="margin-bottom: 8px;">No categories in this part.</div>
        <?php endif; ?>
        <?php foreach ($catList[$__part] as $__cat => $__rows):
          $__h = substr(md5($__part . '|' . $__cat), 0, 10);
          $__catUse = 0;
          foreach ($__rows as $__r) {
              $__catUse += $catUse[$__part . '|' . $__cat . '|' . ($__r['code'] !== '' ? $__r['code'] : $__r['description'])] ?? 0;
          }
          $__isOpen = $catOpen === $__h || ($catRetry && substr((string)$catRetry['form'], -10) === $__h);
        ?>
          <details class="ce-cat" id="cat-<?= $__h ?>"<?= $__isOpen ? ' open' : '' ?>>
            <summary><span class="nm"><?= e($__cat) ?></span><span class="ct"><?= count($__rows) ?> item<?= count($__rows) === 1 ? '' : 's' ?></span></summary>
            <div class="ce-body">
              <div class="ce-catbar">
                <?php $__f = 'ren-' . $__h; ?>
                <form method="POST" class="rename">
                  <input type="hidden" name="doc_type" value="appcse">
                  <input type="hidden" name="cat_action" value="rename_category">
                  <input type="hidden" name="form_id" value="<?= $__f ?>">
                  <input type="hidden" name="part" value="<?= $__part ?>">
                  <input type="hidden" name="category" value="<?= e($__cat) ?>">
                  <input type="text" name="new_name" class="form-input" maxlength="150" required aria-label="Category name"
                         value="<?= e(cat_v($catRetry, $__f, 'new_name', $__cat)) ?>">
                  <button type="submit" class="btn btn-ghost btn-xs">Rename</button>
                </form>
                <form method="POST"
                      data-confirm="<?= e($__cat) ?> and its <?= count($__rows) ?> item<?= count($__rows) === 1 ? '' : 's' ?> come off the FY <?= $fmtYear ?> list and off every department's APP-CSE. This only works while no department has a quantity on any of them."
                      data-confirm-title="Remove this category?" data-confirm-label="Remove" data-confirm-tone="danger">
                  <input type="hidden" name="doc_type" value="appcse">
                  <input type="hidden" name="cat_action" value="delete_category">
                  <input type="hidden" name="part" value="<?= $__part ?>">
                  <input type="hidden" name="category" value="<?= e($__cat) ?>">
                  <button type="submit" class="btn btn-ghost btn-xs" style="color: var(--orange-600);"
                          <?= $__catUse ? 'title="Departments have quantities in this category"' : '' ?>>Remove category</button>
                </form>
              </div>

              <form method="POST" id="sv-<?= $__h ?>" style="display:none;">
                <input type="hidden" name="doc_type" value="appcse">
                <input type="hidden" name="cat_action" value="save_item">
                <input type="hidden" name="part" value="<?= $__part ?>">
                <input type="hidden" name="category" value="<?= e($__cat) ?>">
              </form>
              <div class="ce-scroll">
              <table class="ce-items">
                <thead><tr>
                  <th style="width: 150px;">Code</th><th>Item &amp; specifications</th>
                  <th style="width: 100px;">Unit</th><th style="width: 110px;">Price (&#8369;)</th>
                  <th style="width: 72px;">In use</th><th style="width: 150px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($__rows as $__r):
                  $__id = (int)$__r['id'];
                  $__rf = 'it-' . $__id . '-' . $__h;     // what an error sends back
                  $__n  = $catUse[$__part . '|' . $__cat . '|' . ($__r['code'] !== '' ? $__r['code'] : $__r['description'])] ?? 0;
                  $__pr = number_format((float)$__r['unit_price'], 2, '.', '');
                ?>
                  <?php /* one line per row: the list is 400 rows long */ ?>
                  <tr class="ci"><td><input type="text" form="sv-<?= $__h ?>" name="code[<?= $__id ?>]" class="ci-in code" maxlength="60" aria-label="Code" value="<?= e(cat_v($catRetry, $__rf, 'code', $__r['code'])) ?>"></td><td><input type="text" form="sv-<?= $__h ?>" name="description[<?= $__id ?>]" class="ci-in" maxlength="255" required aria-label="Item" value="<?= e(cat_v($catRetry, $__rf, 'description', $__r['description'])) ?>"></td><td><input type="text" form="sv-<?= $__h ?>" name="unit[<?= $__id ?>]" class="ci-in" maxlength="40" required aria-label="Unit" value="<?= e(cat_v($catRetry, $__rf, 'unit', $__r['unit'])) ?>"></td><td><input type="text" form="sv-<?= $__h ?>" name="unit_price[<?= $__id ?>]" class="ci-in price money" inputmode="decimal" required aria-label="Price" value="<?= e(cat_v($catRetry, $__rf, 'unit_price', $__pr)) ?>"></td><td class="use"><?= $__n ? $__n . ' dept' . ($__n === 1 ? '' : 's') : '&mdash;' ?></td><td class="act"><button type="submit" form="sv-<?= $__h ?>" name="item_id" value="<?= $__id ?>" class="btn btn-ghost btn-xs ce-save">Save</button> <button type="button" class="btn btn-ghost btn-xs ce-del" data-id="<?= $__id ?>" onclick="ceRemove(this)"<?= $__n ? ' title="Departments have a quantity on this item"' : '' ?>>Remove</button></td></tr>
                <?php endforeach; ?>
                <?php $__f = 'add-' . $__h; ?>
                  <tr class="ce-add">
                    <td><input type="text" form="<?= $__f ?>" name="code" class="ci-in code" maxlength="60" placeholder="Code" aria-label="New item code"
                               value="<?= e(cat_v($catRetry, $__f, 'code', '')) ?>"></td>
                    <td><input type="text" form="<?= $__f ?>" name="description" class="ci-in" maxlength="255" required placeholder="New item &amp; specifications" aria-label="New item and specifications"
                               value="<?= e(cat_v($catRetry, $__f, 'description', '')) ?>"></td>
                    <td><input type="text" form="<?= $__f ?>" name="unit" class="ci-in" maxlength="40" required placeholder="Unit" aria-label="New item unit of measure"
                               value="<?= e(cat_v($catRetry, $__f, 'unit', '')) ?>"></td>
                    <td><input type="text" form="<?= $__f ?>" name="unit_price" class="ci-in price money" inputmode="decimal" required placeholder="0.00" aria-label="New item unit price"
                               value="<?= e(cat_v($catRetry, $__f, 'unit_price', '')) ?>"></td>
                    <td></td>
                    <td class="act">
                      <form method="POST" id="<?= $__f ?>">
                        <input type="hidden" name="doc_type" value="appcse">
                        <input type="hidden" name="cat_action" value="add_item">
                        <input type="hidden" name="form_id" value="<?= $__f ?>">
                        <input type="hidden" name="part" value="<?= $__part ?>">
                        <input type="hidden" name="category" value="<?= e($__cat) ?>">
                        <button type="submit" class="btn btn-primary btn-xs">+ Add item</button>
                      </form>
                    </td>
                  </tr>
                </tbody>
              </table>
              </div>
            </div>
          </details>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>

    <form method="POST" id="ceDelForm" style="display:none;">
      <input type="hidden" name="doc_type" value="appcse">
      <input type="hidden" name="cat_action" value="delete_item">
      <input type="hidden" name="item_id" value="">
      <input type="hidden" name="part" value="">
      <input type="hidden" name="category" value="">
    </form>

    <script>
    /* Remove an item: ask first, then send the one shared form. */
    function ceRemove(btn){
      var tr = btn.closest('tr'), desc = tr.querySelector('input[name^="description"]').value;
      swiftConfirm({
        title: 'Remove this item?',
        text: desc + ' comes off the FY <?= $fmtYear ?> list and off every department\'s APP-CSE. '
            + 'This only works while no department has a quantity on it.',
        confirmLabel: 'Remove', tone: 'danger',
        onConfirm: function(){
          var f = document.getElementById('ceDelForm');
          var sv = document.getElementById(btn.closest('tr').querySelector('input').getAttribute('form'));
          f.item_id.value = btn.dataset.id; f.part.value = sv.part.value; f.category.value = sv.category.value;
          f.submit();
        }
      });
    }

    /* Find a category or an item: open the categories that match and show
       only the matching items, or everything again when the box is empty. */
    (function(){
      var box = document.getElementById('ceFind'), out = document.getElementById('ceCount');
      if (!box) return;
      var cats = Array.prototype.slice.call(document.querySelectorAll('.ce-cat'));
      cats.forEach(function(d){ d.dataset.wasOpen = d.open ? '1' : ''; });
      box.addEventListener('input', function(){
        var q = box.value.trim().toLowerCase(), hits = 0;
        cats.forEach(function(d){
          var name = d.querySelector('summary .nm').textContent.toLowerCase();
          var catHit = q !== '' && name.indexOf(q) !== -1, any = false;
          d.querySelectorAll('tr.ci').forEach(function(tr){
            var text = (tr.querySelector('input.code').value + ' ' + tr.querySelector('input[name^="description"]').value).toLowerCase();
            var show = q === '' || catHit || text.indexOf(q) !== -1;
            tr.style.display = show ? '' : 'none';
            if (q !== '' && show) { any = true; hits++; }
          });
          d.style.display = (q === '' || catHit || any) ? '' : 'none';
          d.open = q === '' ? d.dataset.wasOpen === '1' : (catHit || any);
        });
        out.textContent = q === '' ? '' : (hits === 1 ? '1 item matches' : hits + ' items match');
      });
      // A row whose values were changed and not saved yet stands out
      document.querySelectorAll('tr.ci').forEach(function(tr){
        var ins = tr.querySelectorAll('.ci-in');
        ins.forEach(function(i){ i.dataset.orig = i.value; i.addEventListener('input', function(){
          var dirty = false; ins.forEach(function(j){ if (j.value !== j.dataset.orig) dirty = true; });
          tr.classList.toggle('dirty', dirty);
          var b = tr.querySelector('.ce-save');
          if (b) { b.classList.toggle('btn-primary', dirty); b.classList.toggle('btn-ghost', !dirty); }
        }); });
      });
    })();
    </script>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
