<?php
/* ═══════════════════════════════════════════════════════════════
   v104: a small Word (.docx) writer for the forms themselves — the PPMP,
   APP-CSE, Non-PS list, Annex and Market Scoping exactly as they are laid
   out on paper (config/doc-format-forms.php).

   Plain PHP only (the ZIP is written by docx_zip() in docx-lib.php), so
   nothing has to be switched on in php.ini. Sizes are in twentieths of a
   point ("twips": 1 inch = 1440) unless named otherwise; font sizes in
   points.

   Every wording that the Document Format page keeps is put in a Word
   content control tagged with its key (ctl()), so a form edited in Word
   imports back field by field.
   =============================================================== */
require_once __DIR__ . '/docx-lib.php';

class DocxW {
    private $media = [];      // [[rId, file name, bytes, content type]]
    private $pic = 0;
    public  $subject = '';    // docProps/core.xml subject (which form this is)

    /* ─── text ─────────────────────────────────────────────── */

    /** XML-safe text. */
    public static function x(string $s): string {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Run properties. o: b, i, u ('single'|'double'), sz (pt), color, font, caps, spacing (twips) */
    public static function rpr(array $o): string {
        $p = '';
        if (!empty($o['font']))  $p .= '<w:rFonts w:ascii="' . $o['font'] . '" w:hAnsi="' . $o['font'] . '" w:cs="' . $o['font'] . '" w:eastAsia="' . $o['font'] . '"/>';
        if (!empty($o['b']))     $p .= '<w:b/><w:bCs/>';
        if (!empty($o['i']))     $p .= '<w:i/><w:iCs/>';
        if (!empty($o['caps']))  $p .= '<w:caps/>';
        if (!empty($o['color'])) $p .= '<w:color w:val="' . $o['color'] . '"/>';
        if (!empty($o['spacing'])) $p .= '<w:spacing w:val="' . (int)$o['spacing'] . '"/>';
        if (!empty($o['sz']))    { $hs = (int)round($o['sz'] * 2); $p .= '<w:sz w:val="' . $hs . '"/><w:szCs w:val="' . $hs . '"/>'; }
        if (!empty($o['u']))     $p .= '<w:u w:val="' . ($o['u'] === true ? 'single' : $o['u']) . '"/>';
        return $p !== '' ? '<w:rPr>' . $p . '</w:rPr>' : '';
    }

    /** A run of text; "\n" is a line break, "\t" a tab. */
    public static function r(string $text, array $o = []): string {
        $rpr = self::rpr($o);
        $out = '';
        foreach (preg_split("/(\r\n|\r|\n|\t)/", $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $bit) {
            if ($bit === "\t") { $out .= '<w:r>' . $rpr . '<w:tab/></w:r>'; continue; }
            if ($bit === "\n" || $bit === "\r\n" || $bit === "\r") { $out .= '<w:r>' . $rpr . '<w:br/></w:r>'; continue; }
            if ($bit === '') continue;
            $out .= '<w:r>' . $rpr . '<w:t xml:space="preserve">' . self::x($bit) . '</w:t></w:r>';
        }
        return $out === '' ? '<w:r>' . $rpr . '<w:t xml:space="preserve"></w:t></w:r>' : $out;
    }

    /** A content control around runs (inline) — tagged with the Document Format key. */
    public static function ctl(string $tag, string $alias, string $text, array $o = []): string {
        return '<w:sdt><w:sdtPr><w:alias w:val="' . self::x($alias) . '"/><w:tag w:val="' . self::x($tag) . '"/></w:sdtPr>'
             . '<w:sdtContent>' . self::r($text, $o) . '</w:sdtContent></w:sdt>';
    }

    /** A content control around whole paragraphs (block). */
    public static function ctlBlock(string $tag, string $alias, string $paragraphs): string {
        return '<w:sdt><w:sdtPr><w:alias w:val="' . self::x($alias) . '"/><w:tag w:val="' . self::x($tag) . '"/></w:sdtPr>'
             . '<w:sdtContent>' . $paragraphs . '</w:sdtContent></w:sdt>';
    }

    /**
     * A paragraph of runs. o: align (left|center|right|both), before, after, line (240 = single),
     * left, right, hanging, keep (with next), border ('bottom'|'top'), page (break before)
     */
    public static function p(string $runs, array $o = []): string {
        $pp = '';
        if (!empty($o['keep']))  $pp .= '<w:keepNext/>';
        if (!empty($o['page']))  $pp .= '<w:pageBreakBefore/>';
        if (!empty($o['border'])) {
            $b = '';
            foreach ((array)$o['border'] as $side) $b .= '<w:' . $side . ' w:val="single" w:sz="' . (int)($o['bsz'] ?? 6) . '" w:space="1" w:color="000000"/>';
            $pp .= '<w:pBdr>' . $b . '</w:pBdr>';
        }
        if (!empty($o['fill']))  $pp .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['fill'] . '"/>';
        if (!empty($o['tabs'])) {
            $pp .= '<w:tabs>';
            foreach ($o['tabs'] as $t) $pp .= '<w:tab w:val="left" w:pos="' . (int)$t . '"/>';
            $pp .= '</w:tabs>';
        }
        $pp .= '<w:spacing w:before="' . (int)($o['before'] ?? 0) . '" w:after="' . (int)($o['after'] ?? 0) . '" w:line="' . (int)($o['line'] ?? 240) . '" w:lineRule="auto"/>';
        if (isset($o['left']) || isset($o['right']) || isset($o['hanging'])) {
            $pp .= '<w:ind w:left="' . (int)($o['left'] ?? 0) . '" w:right="' . (int)($o['right'] ?? 0) . '"'
                 . (isset($o['hanging']) ? ' w:hanging="' . (int)$o['hanging'] . '"' : '') . '/>';
        }
        if (!empty($o['align'])) $pp .= '<w:jc w:val="' . $o['align'] . '"/>';
        // the paragraph mark carries the size too, so an empty line is as tall as its text
        $mark = self::rpr(array_intersect_key($o, ['sz' => 1, 'b' => 1, 'font' => 1]));
        if ($mark !== '') $pp .= $mark;
        return '<w:p><w:pPr>' . $pp . '</w:pPr>' . $runs . '</w:p>';
    }

    /** A paragraph of plain text. */
    public static function pt(string $text, array $run = [], array $par = []): string {
        return self::p(self::r($text, $run), $par + array_intersect_key($run, ['sz' => 1]));
    }

    /** A page break. */
    public static function pageBreak(): string {
        return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    /* ─── tables ───────────────────────────────────────────── */

    /**
     * A table.
     *   $rows   [['cells' => [cell, …], 'header' => bool, 'h' => twips (at least), 'exact' => bool], …]
     *           (a row may also be just the list of its cells)
     *   cell    ['p' => paragraph XML] or ['t' => text, 'o' => run options, 'po' => paragraph options]
     *           + 'span' => n, 'vm' => 'restart'|'cont', 'fill' => hex, 'valign' => top|center|bottom,
     *             'nob' => true (no borders) | 'bd' => ['top' => 'nil'|'single', …]
     *   $widths grid column widths (twips)
     *   $o      'borders' => true|false, 'align' => 'center', 'mar' => [top, left, bottom, right]
     */
    public static function table(array $rows, array $widths, array $o = []): string {
        $total = array_sum($widths);
        $bd = ($o['borders'] ?? true)
            ? '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="000000"/><w:left w:val="single" w:sz="4" w:color="000000"/>'
              . '<w:bottom w:val="single" w:sz="4" w:color="000000"/><w:right w:val="single" w:sz="4" w:color="000000"/>'
              . '<w:insideH w:val="single" w:sz="4" w:color="000000"/><w:insideV w:val="single" w:sz="4" w:color="000000"/></w:tblBorders>'
            : '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>';
        $m = $o['mar'] ?? [30, 80, 30, 80];
        $x = '<w:tbl><w:tblPr><w:tblW w:w="' . $total . '" w:type="dxa"/>' . (!empty($o['align']) ? '<w:jc w:val="' . $o['align'] . '"/>' : '')
           . (!empty($o['indent']) ? '<w:tblInd w:w="' . (int)$o['indent'] . '" w:type="dxa"/>' : '')
           . $bd . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="' . $m[0] . '" w:type="dxa"/><w:left w:w="' . $m[1] . '" w:type="dxa"/>'
           . '<w:bottom w:w="' . $m[2] . '" w:type="dxa"/><w:right w:w="' . $m[3] . '" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($widths as $w) $x .= '<w:gridCol w:w="' . (int)$w . '"/>';
        $x .= '</w:tblGrid>';
        foreach ($rows as $row) {
            $cells = isset($row['cells']) ? $row['cells'] : $row;
            $trPr = '';
            $trPr .= '<w:cantSplit/>';   // (in the order Word expects: cantSplit, trHeight, tblHeader)
            if (!empty($row['h']))      $trPr .= '<w:trHeight w:val="' . (int)$row['h'] . '" w:hRule="' . (!empty($row['exact']) ? 'exact' : 'atLeast') . '"/>';
            if (!empty($row['header'])) $trPr .= '<w:tblHeader/>';
            $x .= '<w:tr><w:trPr>' . $trPr . '</w:trPr>';
            $col = 0;
            foreach ($cells as $c) {
                $span = max(1, (int)($c['span'] ?? 1));
                $w = array_sum(array_slice($widths, $col, $span));
                $col += $span;
                $tc = '<w:tcW w:w="' . $w . '" w:type="dxa"/>';
                if ($span > 1) $tc .= '<w:gridSpan w:val="' . $span . '"/>';
                if (!empty($c['vm'])) $tc .= $c['vm'] === 'restart' ? '<w:vMerge w:val="restart"/>' : '<w:vMerge/>';
                if (!empty($c['nob'])) {
                    $tc .= '<w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>';
                } elseif (!empty($c['bd'])) {
                    $tb = '';
                    foreach (['top', 'left', 'bottom', 'right'] as $side) {
                        if (!isset($c['bd'][$side])) continue;
                        $tb .= $c['bd'][$side] === 'nil' ? '<w:' . $side . ' w:val="nil"/>'
                             : '<w:' . $side . ' w:val="single" w:sz="' . (int)($c['bsz'] ?? 4) . '" w:color="' . ($c['bcolor'] ?? '000000') . '"/>';
                    }
                    if ($tb !== '') $tc .= '<w:tcBorders>' . $tb . '</w:tcBorders>';
                }
                if (!empty($c['fill'])) $tc .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $c['fill'] . '"/>';
                $tc .= '<w:vAlign w:val="' . ($c['valign'] ?? 'center') . '"/>';
                $body = $c['p'] ?? self::p(self::r((string)($c['t'] ?? ''), $c['o'] ?? []), ($c['po'] ?? []) + array_intersect_key($c['o'] ?? [], ['sz' => 1]));
                $x .= '<w:tc><w:tcPr>' . $tc . '</w:tcPr>' . $body . '</w:tc>';
            }
            $x .= '</w:tr>';
        }
        // a paragraph after every table: two tables that touch become one in Word (and the second
        // takes the first's borders); a cell must also end with a paragraph
        return $x . '</w:tbl>' . (empty($o['nogap']) ? self::p('', ['sz' => (float)($o['gap'] ?? 2)]) : '');
    }

    /* ─── pictures ─────────────────────────────────────────── */

    /**
     * A picture (a run), fitted inside $maxW × $maxH points, keeping its shape.
     * PNG, JPEG and GIF go in as they are; WEBP is turned into PNG when GD
     * can. '' when the file cannot be used.
     */
    public function img(string $file, float $maxW, float $maxH): string {
        if ($file === '' || !is_file($file)) return '';
        return $this->imgBytes((string)@file_get_contents($file), $maxW, $maxH);
    }

    /** v105: the same, from the picture's bytes (a picture written into the Market Scoping, say). */
    public function imgBytes(string $bytes, float $maxW, float $maxH): string {
        if ($bytes === '') return '';
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 1 || $info[1] < 1) return '';
        $types = [IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_JPEG => ['jpeg', 'image/jpeg'], IMAGETYPE_GIF => ['gif', 'image/gif']];
        if (!isset($types[$info[2]])) {
            if (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
                $im = @imagecreatefromstring($bytes);
                if (!$im) return '';
                ob_start(); imagepng($im); $bytes = (string)ob_get_clean(); imagedestroy($im);
                $info[2] = IMAGETYPE_PNG;
            } else {
                return '';
            }
        }
        [$ext, $ct] = $types[$info[2]];
        $scale = min($maxW / $info[0], $maxH / $info[1]);
        $wPt = $info[0] * $scale; $hPt = $info[1] * $scale;
        $this->pic++;
        $rid = 'rIdImg' . $this->pic;
        $name = 'image' . $this->pic . '.' . $ext;
        $this->media[] = [$rid, $name, $bytes, $ct, $ext];
        $cx = (int)round($wPt * 12700); $cy = (int)round($hPt * 12700);   // EMU
        $id = 100 + $this->pic;
        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
             . '<wp:docPr id="' . $id . '" name="Picture ' . $id . '"/><wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
             . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
             . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . $id . '" name="' . $name . '"/><pic:cNvPicPr/></pic:nvPicPr>'
             . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
             . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
             . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    /* ─── the file ─────────────────────────────────────────── */

    /**
     * The .docx (bytes).
     *   $page  ['w' => twips, 'h' => twips, 'top' =>, 'right' =>, 'bottom' =>, 'left' =>] (landscape when w > h)
     */
    public function build(string $body, array $page, string $title): string {
        $orient = $page['w'] > $page['h'] ? ' w:orient="landscape"' : '';
        $sect = '<w:sectPr><w:pgSz w:w="' . (int)$page['w'] . '" w:h="' . (int)$page['h'] . '"' . $orient . '/>'
              . '<w:pgMar w:top="' . (int)$page['top'] . '" w:right="' . (int)$page['right'] . '" w:bottom="' . (int)$page['bottom'] . '" w:left="' . (int)$page['left'] . '"'
              . ' w:header="360" w:footer="360" w:gutter="0"/></w:sectPr>';
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<w:document ' . $ns . '><w:body>' . $body . $sect . '</w:body></w:document>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr>'
                . '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Arial" w:cs="Arial"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:lang w:val="en-PH"/>'
                . '</w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
                . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
                . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/>'
                . '<w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
                . '</w:styles>';
        $ctDefaults = '';
        $seen = [];
        foreach ($this->media as [, , , $ct, $ext]) {
            if (isset($seen[$ext])) continue;
            $seen[$ext] = 1;
            $ctDefaults .= '<Default Extension="' . $ext . '" ContentType="' . $ct . '"/>';
        }
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $files = [];
        foreach ($this->media as [$rid, $name, $bytes]) {
            $rels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $name . '"/>';
            $files['word/media/' . $name] = $bytes;
        }
        $x = fn($s) => htmlspecialchars($s, ENT_XML1, 'UTF-8');
        $out = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>' . $ctDefaults
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '</Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:title>' . $x($title) . '</dc:title><dc:subject>' . $x($this->subject) . '</dc:subject><dc:creator>ProcureEase</dc:creator>'
                . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>',
            'word/document.xml' => $doc,
            'word/styles.xml'   => $styles,
        ] + $files;
        return docx_zip($out);
    }
}
