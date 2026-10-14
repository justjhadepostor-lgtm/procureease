<?php
/* ═══════════════════════════════════════════════════════════════
   The Market Scoping Checklist — the NGPA form (RA 12009, Section 10 and
   its IRR), as the page every Market Scoping document starts from.

   It is ordinary rich text, not a locked form: the same editor and toolbar
   as before (images, tables, links, fonts …) work on it, and whatever is
   typed into the tables is saved as it is. Only inline styles and the tags
   config/richtext.php allows are used, so nothing is lost on saving.

   The wording, order, columns and parameters follow the NGPA "Market
   Scoping Checklist" form exactly:
     1. Agency Information   2. Project Overview
     3. Market Scoping Activity/ies Conducted   4. Market Scoping Results
     Prepared by / Approved by
   =============================================================== */

require_once __DIR__ . '/richtext.php';
require_once __DIR__ . '/doc-format.php';

/** A phrase that only the NGPA checklist has — how an older document is told apart. */
const MS_NGPA_MARKER = 'MARKET SCOPING ACTIVITY/IES CONDUCTED';

function ms_is_ngpa_format(string $html): bool {
    return stripos(strip_tags($html), MS_NGPA_MARKER) !== false;
}

/**
 * What the system already knows, filled in where the form asks for it.
 * Everything stays editable in the document.
 */
function ms_template_context(array $u, string $deptCode): array {
    $entity = '';
    try {
        $entity = (string)db()->query("SELECT value FROM settings WHERE key_name = 'institution_name'")->fetchColumn();
    } catch (Throwable $t) {}
    if (trim($entity) === '' && function_exists('doc_fmt')) $entity = (string)doc_fmt('scoping', 'org_name');
    if (trim($entity) === '') $entity = 'Aurora State College of Technology';

    $unit = (string)($u['dept_name'] ?? '');
    if ($unit === '' && $deptCode !== '') {
        try {
            $s = db()->prepare('SELECT name FROM departments WHERE code = ?');
            $s->execute([$deptCode]);
            $unit = (string)($s->fetchColumn() ?: $deptCode);
        } catch (Throwable $t) { $unit = $deptCode; }
    }

    $name = trim((string)($u['full_name'] ?? ''));
    $pos  = trim((string)($u['position'] ?? ''));
    $head = function_exists('department_head') ? department_head($deptCode) : ['name' => '', 'position' => ''];

    return [
        'entity'   => $entity,
        'unit'     => $unit,
        'rep'      => trim($name . ($pos !== '' ? ', ' . $pos : '')),
        'prepName' => $name,
        'prepPos'  => $pos,
        'headName' => trim((string)($head['name'] ?? '')),
        'headPos'  => trim((string)($head['position'] ?? '')),
    ];
}

/** The checklist, ready to drop into the editor. */
function ms_template_html(array $c): string {
    $h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $bd  = 'border: 1px solid #000000; padding: 3px 6px; vertical-align: top;';
    $hd  = 'border: 1px solid #000000; padding: 5px 6px; vertical-align: middle; text-align: center; font-weight: bold; background-color: #f2f2f2;';
    $tbl = 'border-collapse: collapse; width: 95%; margin-left: 5%; margin-bottom: 14px;';
    $sec = 'font-weight: bold; margin: 16px 0 8px 0;';
    $par = 'margin: 0 0 10px 5%; text-align: justify;';
    $cell = fn($v) => $v !== '' ? $h($v) : '<br>';

    $out  = '<div style="font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.3;">';

    // 1. Agency information
    $out .= '<p style="' . $sec . '">1.&nbsp;&nbsp;&nbsp;&nbsp;AGENCY INFORMATION</p>';
    $out .= '<table style="' . $tbl . '"><tbody>';
    foreach ([['Name of Procuring Entity', $c['entity']],
              ['End-User/Implementing Unit', $c['unit']],
              ['Name &amp; Designation of Representative', $c['rep']]] as [$label, $val]) {
        $out .= '<tr><td style="' . $bd . ' width: 51%;">' . $label . '</td>'
              . '<td style="' . $bd . ' width: 49%;">' . $cell($val) . '</td></tr>';
    }
    $out .= '</tbody></table>';

    // 2. Project overview
    $out .= '<p style="' . $sec . '">2.&nbsp;&nbsp;&nbsp;&nbsp;PROJECT OVERVIEW</p>';
    $out .= '<table style="' . $tbl . '"><tbody>';
    foreach (['Project Name', 'Estimated Budget',
              'Period of Market Scoping<br>[From (mm/yyyy) To (mm/yyyy)]',
              'Expected Date of Delivery <i>(mm/yyyy)</i>'] as $label) {
        $out .= '<tr><td style="' . $bd . ' width: 51%;">' . $label . '</td>'
              . '<td style="' . $bd . ' width: 49%;"><br></td></tr>';
    }
    $out .= '</tbody></table>';

    // 3. Activities conducted
    $out .= '<p style="' . $sec . '">3.&nbsp;&nbsp;&nbsp;&nbsp;' . MS_NGPA_MARKER
          . ' <span style="font-weight: normal;">(<i>Check all that apply</i>)</span></p>';
    $out .= '<p style="' . $par . '">This confirms that market scoping activities were conducted in accordance with '
          . 'Section 10 of Republic Act No. 12009 and its Implementing Rules and Regulations (IRR), and considered '
          . 'in the Project Procurement Management Plan, consistent with the Principle of Proportionality.</p>';
    // The heading row is a <thead>, so a printed copy repeats it on the next
    // page the way the form does.
    $out .= '<table style="' . $tbl . '"><thead>';
    $out .= '<tr><td style="' . $hd . ' width: 14%;">Check<br>(✓)</td>'
          . '<td style="' . $hd . ' width: 45%;">Activity/ies Conducted</td>'
          . '<td style="' . $hd . ' width: 41%;">Documentation<br><span style="font-weight: normal;">(as may be applicable)</span></td></tr>';
    $out .= '</thead><tbody>';
    $acts = [
        ['Consultations with suppliers / contractors / consultants/ professional associations or industry groups',
         'Highlights of consultations or meetings/ Proof of attendance/ Reports / Summaries/ Screenshots / Brochures / Publications/ Price quotations/ Canvass sheets/ Market Analysis Report or similar document/s'],
        ['Participation in summits, fora, or conferences',
         'Highlights of consultations or meetings/ Proof of Attendance/ Reports'],
        ['Review of technical, financial, or market/scientific reports',
         'Reports / Summaries/ Screenshots / Brochures / Publications, Market Analysis Report or similar document/ Online Product Reviews'],
        ['Review of product or service brochures, marketing materials, industry journals and publications or related materials',
         'Reports / Summaries/ Screenshots / Brochures / Publications/ Online Product Reviews'],
        ['Price sourcing for quotations or cost estimates from suppliers, contractors, or consultants',
         'Price quotations/ Canvass sheets/ Online Product Reviews'],
        ['Use of data from PhilGEPS or agency websites',
         'Reports / Summaries/ Screenshots, Price quotations/ Canvass sheets/ PhilGEPS Postings/ Online Product Reviews'],
    ];
    foreach ($acts as [$act, $docs]) {
        $out .= '<tr><td style="' . $bd . ' text-align: center; font-size: 13pt;">☐</td>'
              . '<td style="' . $bd . '">' . $act . '<br><br></td>'
              . '<td style="' . $bd . '">' . $docs . '</td></tr>';
    }
    $out .= '<tr><td style="' . $bd . ' text-align: center; font-size: 13pt;">☐</td>'
          . '<td style="' . $bd . '">Other analogous market scoping activity/ies undertaken:<br><br>__________________________</td>'
          . '<td style="' . $bd . '"><br></td></tr>';
    $out .= '</tbody></table>';
    $out .= '<p style="margin: 0 0 6px 5%; font-style: italic;">Notes:</p>';
    $out .= '<p style="margin: 0 0 8px 10%; font-style: italic; text-align: justify;">i.&nbsp;&nbsp;&nbsp;The market scoping activities shall be identified and undertaken at the option of the End-User or Implementing Unit based on its needs and objectives.</p>';
    $out .= '<p style="margin: 0 0 14px 10%; font-style: italic; text-align: justify;">ii.&nbsp;&nbsp;The list of supporting documents in the Documentation column is not exclusive and may include other documents that may be gathered by the End-User or Implementing Unit pertinent to the activity/ies conducted.</p>';

    // 4. Results
    $out .= '<p style="' . $sec . '">4.&nbsp;&nbsp;&nbsp;&nbsp;MARKET SCOPING RESULTS</p>';
    $out .= '<p style="' . $par . '">Indicate recommendations in the column provided based on the results of the market '
          . 'scoping activities undertaken.&nbsp; These recommendations shall be considered in the development of a '
          . 'comprehensive and realistic PPMP, taking into account the parameters outlined under Section 10.4 of the '
          . 'IRR of RA 12009, as may be applicable.</p>';
    $out .= '<table style="' . $tbl . '"><thead>';
    $out .= '<tr><td style="' . $hd . ' width: 38%;">Parameters</td>'
          . '<td style="' . $hd . ' width: 19%;">Considered?<br><span style="font-weight: normal;"><i>(Yes/No/ Not Applicable)</i></span></td>'
          . '<td style="' . $hd . ' width: 43%;">Recommendations<br>based on the Market Scoping<br>'
          . '<span style="font-weight: normal;">(<i>Attach additional documents<br>if necessary)</i></span></td></tr>';
    $out .= '</thead><tbody>';
    $params = [
        ['a.', 'Project Cost Estimate', 'Does the cost estimate align with current market prices?'],
        ['b.', 'Project Design and Specification', 'Does available supplier/s meet technical and financial requirements?'],
        ['c.', 'Technical Criteria', 'Does the market support the proposed technical requirements?'],
        ['d.', 'Delivery Lead Time', 'Are the timelines for delivery feasible?'],
        ['e.', 'Storage and Warehousing Requirements', 'Can the storage/ warehousing needs be met considering specific conditions like temperature, humidity, and handling?'],
        ['f.', 'Identified Risk/s', 'Were there any market risks identified? (e.g., limited suppliers, price volatility)'],
    ];
    foreach ($params as [$n, $name, $q]) {
        $out .= '<tr><td style="' . $bd . ' height: 70px;"><b>' . $n . '&nbsp;&nbsp;' . $name . '</b><br>[' . $q . ']</td>'
              . '<td style="' . $bd . '"><br></td>'
              . '<td style="' . $bd . '"><br></td></tr>';
    }
    $out .= '</tbody></table>';

    // Signatories — two rows, so the two names line up whatever the titles above them
    $sc  = 'border: none; padding: 4px 6px; vertical-align: top; width: 50%;';
    $nm  = fn($v, $ph) => '<b><i>' . ($v !== '' ? $h($v) : $ph) . '</i></b>';
    $out .= '<table style="border-collapse: collapse; width: 95%; margin-left: 5%; margin-top: 18px;"><tbody>'
          . '<tr><td style="' . $sc . '">Prepared by:<br><b>Personnel-in-Charge, End-User or Implementing Unit</b></td>'
          . '<td style="' . $sc . '">Approved by:<br><b>Head, End-User or Implementing Unit</b></td></tr>'
          . '<tr><td style="' . $sc . ' padding-top: 40px;">' . $nm($c['prepName'], '[Signature over Printed Name]') . '<br>'
          . $nm($c['prepPos'], '[Position/Designation]') . '<br><b><i>[Date]</i></b></td>'
          . '<td style="' . $sc . ' padding-top: 40px;">' . $nm($c['headName'], '[Signature over Printed Name]') . '<br>'
          . $nm($c['headPos'], '[Position/Designation]') . '<br><b><i>[Date]</i></b></td></tr>'
          . '</tbody></table>';

    $out .= '</div>';
    return $out;
}

/* ═══════════════════════════════════════════════════════════════
   The form itself is kept per fiscal year on the Document Format page
   (Market Scoping tab), because the form handed down can change from one
   year to the next.

   · The Super Admin edits the whole checklist in the same editor, with the
     same toolbar (market-scoping-edit.php?form=YEAR). It is stored in
     doc_formats as (scoping, ms_form, year).
   · A year with no form of its own uses the nearest earlier year's, and a
     year before any edit uses the built-in NGPA checklist above. A later
     year never reaches back, so each year keeps its own form.
   · A new Market Scoping document starts from its year's form, with the
     fields below filled in from the account. Documents already started
     keep exactly what they have.
   =============================================================== */

/** The fields the form may carry, and what fills them. */
const MS_FORM_TOKENS = [
    '{{Procuring Entity}}'     => 'entity',
    '{{End-User Unit}}'        => 'unit',
    '{{Representative}}'       => 'rep',
    '{{Prepared by}}'          => 'prepName',
    '{{Prepared by Position}}' => 'prepPos',
    '{{Approved by}}'          => 'headName',
    '{{Approved by Position}}' => 'headPos',
    '{{Fiscal Year}}'          => 'fy',
];

/** What each field says on the Document Format page. */
const MS_FORM_TOKEN_HELP = [
    '{{Procuring Entity}}'     => 'the institution',
    '{{End-User Unit}}'        => 'the department or unit',
    '{{Representative}}'       => 'name and position of whoever starts the document',
    '{{Prepared by}}'          => 'the same person, for the signature',
    '{{Prepared by Position}}' => 'their position',
    '{{Approved by}}'          => 'the head of the department or unit',
    '{{Approved by Position}}' => 'the head\'s position',
    '{{Fiscal Year}}'          => 'the fiscal year',
];

/** The built-in NGPA checklist, with the fields in place of names. */
function ms_form_builtin(): string {
    $t = array_flip(MS_FORM_TOKENS);
    return rt_sanitize(ms_template_html([
        'entity' => $t['entity'], 'unit' => $t['unit'], 'rep' => $t['rep'],
        'prepName' => $t['prepName'], 'prepPos' => $t['prepPos'],
        'headName' => $t['headName'], 'headPos' => $t['headPos'],
    ]));
}

/** The year whose form this year uses (0 = the built-in one). */
function ms_form_origin(int $fy): int {
    $over = doc_fmt_overrides()['scoping']['ms_form'] ?? [];
    $best = 0;
    foreach (array_keys($over) as $y) {
        if ($y <= $fy && $y > $best && trim((string)$over[$y]) !== '') $best = (int)$y;
    }
    return $best;
}

/** The form of one fiscal year, with its {{fields}}. */
function ms_form_source(int $fy): string {
    $y = ms_form_origin($fy);
    return $y > 0 ? (string)doc_fmt_overrides()['scoping']['ms_form'][$y] : ms_form_builtin();
}

/** Who last changed this year's form, and when (null = never). */
function ms_form_meta(int $fy): ?array {
    $y = ms_form_origin($fy);
    if ($y === 0) return null;
    try {
        $s = db()->prepare("SELECT fy, updated_at, updated_by FROM doc_formats WHERE doc_type = 'scoping' AND key_name = 'ms_form' AND fy = ?");
        $s->execute([$y]);
        return $s->fetch() ?: null;
    } catch (Throwable $t) { return null; }
}

/** Store one year's form ($html null = go back to the built-in NGPA form). */
function ms_form_save(int $fy, ?string $html, string $who): void {
    ensure_doc_format_table();
    $pdo = db();
    // Years after this one that have no form of their own keep what they
    // show now, so a change here cannot reach forward into them.
    $later = [];
    try {
        $s = $pdo->prepare("SELECT DISTINCT fy FROM doc_formats WHERE doc_type = 'scoping' AND fy > ?");
        $s->execute([$fy]);
        $later = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $t) {}
    $over = doc_fmt_overrides()['scoping']['ms_form'] ?? [];
    $ins = $pdo->prepare("INSERT INTO doc_formats (doc_type, key_name, fy, value, updated_at, updated_by)
                          VALUES ('scoping', 'ms_form', ?, ?, NOW(), ?)");
    foreach ($later as $ly) {
        if (!isset($over[$ly])) $ins->execute([$ly, ms_form_source($ly), $who]);
    }
    $pdo->prepare("DELETE FROM doc_formats WHERE doc_type = 'scoping' AND key_name = 'ms_form' AND fy = ?")->execute([$fy]);
    $ins->execute([$fy, $html ?? ms_form_builtin(), $who]);
    doc_fmt_reset_cache();
}

/** A form with its fields filled in for one person and department. */
function ms_form_fill(string $src, array $c, int $fy): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $blankAs = ['prepName' => '[Signature over Printed Name]', 'headName' => '[Signature over Printed Name]',
                'prepPos' => '[Position/Designation]', 'headPos' => '[Position/Designation]'];
    $vals = [];
    foreach (MS_FORM_TOKENS as $tok => $key) {
        $v = $key === 'fy' ? (string)$fy : trim((string)($c[$key] ?? ''));
        if ($v === '' && isset($blankAs[$key])) $v = $blankAs[$key];
        $vals[$tok] = $v;
        // A field that is the whole of a table cell leaves the cell clickable when it is empty
        if ($v === '') {
            $src = preg_replace('#(<t[dh]\b[^>]*>)\s*' . preg_quote($tok, '#') . '\s*(</t[dh]>)#i', '$1<br>$2', $src);
        }
    }
    foreach ($vals as $tok => $v) $src = str_replace($tok, $h($v), $src);
    return $src;
}

/** The document a department starts from this year. */
function ms_form_for(array $u, string $deptCode, int $fy): string {
    return rt_sanitize(ms_form_fill(ms_form_source($fy), ms_template_context($u, $deptCode), $fy));
}

/** The form with its {{fields}} marked, for the Document Format preview. */
function ms_form_preview(int $fy): string {
    $src = ms_form_source($fy);
    foreach (array_keys(MS_FORM_TOKENS) as $tok) {
        $src = str_replace($tok, '<span class="ms-tok">' . htmlspecialchars($tok, ENT_QUOTES, 'UTF-8') . '</span>', $src);
    }
    return $src;
}
