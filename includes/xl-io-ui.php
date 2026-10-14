<?php
/* ═══════════════════════════════════════════════════════════════
   v105: 📊 Export / 📂 Import on the PPMP, APP-CSE, Non-PS, Annex and
   Market Scoping pages (config/xl-forms.php).

   Export downloads the document as the Procurement Unit's file —
   PPMP.xlsx, APPCSE.xlsx, NONPS.xlsx, ANNEX.xlsx, MARKET SCOPING.docx.

   Import reads a file of that kind and shows what it found first. Only
   when "Replace" is pressed does it go on the form — in place of what
   is there — and the page saves it the way it saves anything typed.

   xl_io_buttons()  the two buttons, for the page's row of buttons
   xl_io_modal()    the window and its script, once, before </body>
   =============================================================== */

/** The doc kinds: [file name, button word, accepted extension]. */
const XL_IO_DOCS = [
    'ppmp'    => ['PPMP.xlsx', '.xlsx'],
    'appcse'  => ['APPCSE.xlsx', '.xlsx'],
    'nonps'   => ['NONPS.xlsx', '.xlsx'],
    'annex'   => ['ANNEX.xlsx', '.xlsx'],
    'scoping' => ['MARKET SCOPING.docx', '.docx'],
];

function xl_io_buttons(string $doc, string $exportHref, bool $canImport): string {
    if (!isset(XL_IO_DOCS[$doc])) return '';
    [$file, $ext] = XL_IO_DOCS[$doc];
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $icon = $ext === '.docx' ? '📄' : '📊';
    $h = '<span data-no-dock class="xl-io-btns" style="display: contents;">';
    if ($exportHref !== '') {
        $h .= '<a href="' . $e($exportHref) . '" class="btn btn-ghost" data-no-loader download id="xlExportBtn" title="Download ' . $e($file) . '" style="padding: 8px 14px; font-size: 13px; text-decoration: none;">'
            . $icon . ' Export (' . $ext . ')</a>';
    }
    if ($canImport) {
        $h .= '<button type="button" class="btn btn-ghost" id="xlImportBtn" onclick="xlPick()" title="Put the data of a ' . $e($file) . ' on this form" style="padding: 8px 14px; font-size: 13px;">📂 Import (' . $ext . ')</button>'
            . '<input type="file" id="xlFile" accept="' . $ext . '" hidden>';
    }
    return $h . '</span>';
}

/**
 * The import window and its script. $ctx: 'fy' (the form's year), 'letter' (the Annex open).
 */
function xl_io_modal(string $doc, array $ctx = []): string {
    if (!isset(XL_IO_DOCS[$doc])) return '';
    [$file] = XL_IO_DOCS[$doc];
    $cfg = json_encode(['doc' => $doc, 'file' => $file, 'fy' => (int)($ctx['fy'] ?? 0), 'letter' => (string)($ctx['letter'] ?? '')],
                       JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ob_start(); ?>
<style>
  #xlModal .modal { max-width: 900px; padding: 24px 26px; text-align: left; }
  #xlModal .xl-sub { color: var(--muted); font-size: 13px; margin: 2px 0 14px; }
  #xlModal .xl-ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 10px; padding: 9px 12px; font-size: 13px; margin: 8px 0; }
  #xlModal .xl-warn { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; border-radius: 10px; padding: 9px 12px; font-size: 13px; margin: 8px 0; }
  #xlModal .xl-err { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; border-radius: 10px; padding: 9px 12px; font-size: 13px; margin: 8px 0; }
  #xlModal .xl-mute { color: var(--muted); font-size: 12px; margin: 6px 0; }
  #xlModal .xl-scroll { max-height: 42vh; overflow: auto; border: 1px solid var(--border); border-radius: 8px; margin: 8px 0 10px; }
  #xlModal table.xl-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
  #xlModal table.xl-tbl th, #xlModal table.xl-tbl td { border-bottom: 1px solid var(--border); padding: 5px 7px; vertical-align: top; text-align: left; color: var(--ink); }
  #xlModal table.xl-tbl th { position: sticky; top: 0; background: var(--navy-50, #eef3f9); z-index: 1; }
  #xlModal table.xl-tbl td.n, #xlModal table.xl-tbl th.n { text-align: right; white-space: nowrap; }
  #xlModal table.xl-tbl tr.tot td { font-weight: 700; }
  #xlModal .xl-desc { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; max-width: 260px; }
  #xlModal .xl-sheet { display: flex; gap: 12px; align-items: flex-start; border: 1px solid var(--border); border-radius: 12px; padding: 10px 14px; margin: 8px 0; cursor: pointer; }
  #xlModal .xl-sheet.on { border: 2px solid #1e4a8a; background: rgba(30, 74, 138, .05); }
  #xlModal .xl-sheet.off { opacity: .55; cursor: not-allowed; }
  #xlModal .xl-sheet input { margin-top: 3px; }
  #xlModal .xl-kv { display: grid; grid-template-columns: minmax(140px, 220px) 1fr; gap: 4px 12px; font-size: 13px; margin: 6px 0 10px; }
  #xlModal .xl-kv > div:nth-child(odd) { color: var(--muted); }
  #xlModal .xl-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border); }
  body[data-theme="dark"] #xlModal .modal { background: #0b2440 !important; border: 1px solid rgba(255,255,255,.08); }
  body[data-theme="dark"] #xlModal table.xl-tbl th { background: #12345a; }
  body[data-theme="dark"] #xlModal .xl-ok { background: #053b2c; color: #bbf7d0; border-color: #166534; }
  body[data-theme="dark"] #xlModal .xl-warn { background: #3b2a05; color: #fde68a; border-color: #a16207; }
  body[data-theme="dark"] #xlModal .xl-err { background: #3f0d0d; color: #fecaca; border-color: #991b1b; }
  @media (max-width: 640px) { #xlModal .modal { padding: 18px 14px; } #xlModal .xl-kv { grid-template-columns: 1fr; } }
</style>
<div class="modal-overlay" id="xlModal" role="dialog" aria-modal="true" aria-labelledby="xlTitle">
  <div class="modal">
    <div class="modal-header" style="margin-bottom: 0;">
      <div class="modal-title" id="xlTitle">Import</div>
      <button type="button" class="modal-close" onclick="xlClose()" aria-label="Close" style="font-size: 26px; line-height: 1;">&times;</button></div>
    <div id="xlBody"></div>
    <div class="xl-actions" id="xlActions"></div></div></div>
<script>window.XL_IO = <?= $cfg ?>;</script>
<script src="assets/xl-io.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/xl-io.js') ?>"></script>
<?php
    return (string)ob_get_clean();
}
