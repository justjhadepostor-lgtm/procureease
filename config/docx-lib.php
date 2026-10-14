<?php
/* ═══════════════════════════════════════════════════════════════
   v104: Word (.docx) files — written and read with plain PHP.

   Written: Document Format › Export form gives a .docx with the form's
   wording in a table (Field | Value | Key), which opens in Microsoft Word.
   Read:    Document Format › Import form reads any .docx — the rows of its
   tables and its paragraphs, as text.

   A .docx is a ZIP of XML files. Both the ZIP and the XML are done here
   with what every PHP has (zlib, DOM), so nothing has to be switched on
   in php.ini.
   =============================================================== */

/** XML text, with line breaks as Word line breaks. */
function docx_runs(string $text, array $o = []): string {
    $rpr = '';
    if (!empty($o['bold']))  $rpr .= '<w:b/>';
    if (!empty($o['color'])) $rpr .= '<w:color w:val="' . $o['color'] . '"/>';
    if (!empty($o['size']))  $rpr .= '<w:sz w:val="' . (int)$o['size'] . '"/><w:szCs w:val="' . (int)$o['size'] . '"/>';
    $rpr = $rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '';
    $out = '';
    $lines = preg_split("/\r\n|\r|\n/", $text);
    foreach ($lines as $i => $line) {
        if ($i > 0) $out .= '<w:r>' . $rpr . '<w:br/></w:r>';
        $line = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $line) ?? '';
        $out .= '<w:r>' . $rpr . '<w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r>';
    }
    return $out;
}

/** One paragraph. */
function docx_p(string $text, array $o = []): string {
    $ppr = '<w:pPr><w:spacing w:after="' . (int)($o['after'] ?? 120) . '"/>' . (!empty($o['center']) ? '<w:jc w:val="center"/>' : '') . '</w:pPr>';
    return '<w:p>' . $ppr . docx_runs($text, $o) . '</w:p>';
}

/**
 * A table. $rows: each a list of cells (text), or ['section' => text] for a
 * row across the whole table. $widths in twentieths of a point.
 */
function docx_table(array $rows, array $widths, bool $header = true): string {
    $b = '<w:top w:val="single" w:sz="4" w:color="9AA8B8"/><w:left w:val="single" w:sz="4" w:color="9AA8B8"/>'
       . '<w:bottom w:val="single" w:sz="4" w:color="9AA8B8"/><w:right w:val="single" w:sz="4" w:color="9AA8B8"/>'
       . '<w:insideH w:val="single" w:sz="4" w:color="9AA8B8"/><w:insideV w:val="single" w:sz="4" w:color="9AA8B8"/>';
    $x = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:tblBorders>' . $b . '</w:tblBorders>'
       . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
    foreach ($widths as $w) $x .= '<w:gridCol w:w="' . (int)$w . '"/>';
    $x .= '</w:tblGrid>';
    foreach ($rows as $ri => $row) {
        $isHead = $header && $ri === 0;
        $x .= '<w:tr>' . ($isHead ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
        if (isset($row['section'])) {
            $x .= '<w:tc><w:tcPr><w:tcW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:gridSpan w:val="' . count($widths) . '"/>'
                . '<w:shd w:val="clear" w:color="auto" w:fill="EAF0F7"/></w:tcPr>'
                . docx_p((string)$row['section'], ['bold' => true, 'after' => 0, 'color' => '1C3A5E']) . '</w:tc>';
        } else {
            foreach (array_values($row) as $ci => $cell) {
                $o = ['after' => 0];
                if ($isHead) $o['bold'] = true;
                elseif ($ci === count($widths) - 1 && count($widths) > 2) { $o['color'] = '6B7C8F'; $o['size'] = 16; }
                $x .= '<w:tc><w:tcPr><w:tcW w:w="' . (int)($widths[$ci] ?? 2000) . '" w:type="dxa"/>'
                    . ($isHead ? '<w:shd w:val="clear" w:color="auto" w:fill="1C3A5E"/>' : '') . '</w:tcPr>'
                    . docx_p((string)$cell, $isHead ? $o + ['color' => 'FFFFFF'] : $o) . '</w:tc>';
            }
        }
        $x .= '</w:tr>';
    }
    return $x . '</w:tbl>';
}

/** The .docx itself (bytes), from the body XML. Landscape when $wide. */
function docx_build(string $bodyXml, string $title = '', bool $wide = false): string {
    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $sect = $wide
        ? '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="1000" w:right="1000" w:bottom="1000" w:left="1000" w:header="600" w:footer="600" w:gutter="0"/></w:sectPr>'
        : '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1000" w:right="1000" w:bottom="1000" w:left="1000" w:header="600" w:footer="600" w:gutter="0"/></w:sectPr>';
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
         . '<w:document ' . $ns . '><w:body>' . $bodyXml . $sect . '</w:body></w:document>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Arial" w:cs="Arial"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:lang w:val="en-PH"/>'
            . '</w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="252" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
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
            . '<dc:title>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</dc:title><dc:creator>ProcureEase</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'word/document.xml' => $doc,
        'word/styles.xml'   => $styles,
    ];
    return docx_zip($files);
}

/** A ZIP (deflated) of [name => bytes]. */
function docx_zip(array $files): string {
    $data = ''; $central = ''; $n = 0;
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    foreach ($files as $name => $bytes) {
        $crc  = crc32($bytes);
        $comp = gzdeflate($bytes, 6);
        $head = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($comp), strlen($bytes), strlen($name), 0);
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($comp), strlen($bytes),
                         strlen($name), 0, 0, 0, 0, 0, strlen($data)) . $name;
        $data .= $head . $name . $comp;
        $n++;
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($data), 0);
}

/** One file out of a ZIP's bytes (null when it is not there or cannot be read). */
function docx_unzip_one(string $zip, string $want): ?string {
    $eocd = strrpos($zip, "PK\x05\x06");
    if ($eocd === false || strlen($zip) < $eocd + 22) return null;
    $e = unpack('Vsig/vdisk/vcd/vthis/vtotal/Vsize/Voffset', substr($zip, $eocd, 20));
    $p = (int)$e['offset'];
    for ($i = 0; $i < (int)$e['total'] && $p + 46 <= strlen($zip); $i++) {
        $c = unpack('Vsig/vmade/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voff', substr($zip, $p, 46));
        if ($c['sig'] !== 0x02014b50) return null;
        $name = substr($zip, $p + 46, $c['nlen']);
        $p += 46 + $c['nlen'] + $c['xlen'] + $c['clen'];
        if (strcasecmp($name, $want) !== 0) continue;
        $lo = (int)$c['off'];
        if ($lo + 30 > strlen($zip)) return null;
        $l = unpack('Vsig/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen', substr($zip, $lo, 30));
        if ($l['sig'] !== 0x04034b50) return null;
        $raw = substr($zip, $lo + 30 + $l['nlen'] + $l['xlen'], (int)$c['csize']);
        if ((int)$c['method'] === 0) return $raw;
        if ((int)$c['method'] === 8) { $out = @gzinflate($raw); return $out === false ? null : $out; }
        return null;
    }
    return null;
}

/**
 * The text of a .docx: ['tables' => [[ [cell, cell, …], … ], …], 'paras' => [text, …]]
 * — paragraphs outside tables, in order. null when the file is not a .docx.
 */
function docx_read(string $bytes): ?array {
    if (strncmp($bytes, "PK", 2) !== 0) return null;
    $xml = docx_unzip_one($bytes, 'word/document.xml');
    if ($xml === null || $xml === '') return null;
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | (defined('LIBXML_BIGLINES') ? LIBXML_BIGLINES : 0));
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return null;
    $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $paraText = function (DOMNode $p) use ($W): string {
        $s = '';
        $walk = function (DOMNode $n) use (&$walk, &$s, $W) {
            foreach ($n->childNodes as $ch) {
                if ($ch->namespaceURI !== $W) { if ($ch->hasChildNodes()) $walk($ch); continue; }
                switch ($ch->localName) {
                    case 't':   $s .= $ch->textContent; break;
                    case 'br': case 'cr': $s .= "\n"; break;
                    case 'tab': $s .= "\t"; break;
                    case 'tbl': break;                       // a table inside is read as a table
                    default: if ($ch->hasChildNodes()) $walk($ch);
                }
            }
        };
        $walk($p);
        return $s;
    };
    $out = ['tables' => [], 'paras' => [], 'tags' => [], 'subject' => ''];
    // v104: the Document Format wording of a form exported from ProcureEase sits in tagged content controls
    $sdtText = function (DOMNode $n) use (&$sdtText, $W): string {
        $s = '';
        foreach ($n->childNodes as $ch) {
            if ($ch->namespaceURI !== $W) { if ($ch->hasChildNodes()) $s .= $sdtText($ch); continue; }
            switch ($ch->localName) {
                case 't': $s .= $ch->textContent; break;
                case 'br': case 'cr': $s .= "\n"; break;
                case 'tab': $s .= "\t"; break;
                case 'p': $s .= ($s !== '' ? "\n" : '') . $sdtText($ch); break;
                case 'sdtPr': case 'rPr': case 'pPr': break;
                default: if ($ch->hasChildNodes()) $s .= $sdtText($ch);
            }
        }
        return $s;
    };
    foreach ($dom->getElementsByTagNameNS($W, 'sdt') as $sdt) {
        $tag = ''; $content = null;
        foreach ($sdt->childNodes as $ch) {
            if ($ch->namespaceURI !== $W) continue;
            if ($ch->localName === 'sdtPr') foreach ($ch->childNodes as $pp) if ($pp->namespaceURI === $W && $pp->localName === 'tag') $tag = (string)$pp->getAttributeNS($W, 'val');
            if ($ch->localName === 'sdtContent') $content = $ch;
        }
        if ($tag !== '' && $content && !array_key_exists($tag, $out['tags'])) $out['tags'][$tag] = $sdtText($content);
    }
    $core = docx_unzip_one($bytes, 'docProps/core.xml');
    if ($core !== null && preg_match('#<dc:subject>(.*?)</dc:subject>#s', $core, $m)) $out['subject'] = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
    $readTable = function (DOMNode $tbl) use (&$readTable, &$out, $paraText, $W) {
        $rows = [];
        foreach ($tbl->childNodes as $tr) {
            if ($tr->namespaceURI !== $W || $tr->localName !== 'tr') continue;
            $cells = [];
            foreach ($tr->childNodes as $tc) {
                if ($tc->namespaceURI === $W && $tc->localName === 'sdt') {   // a content control around cells
                    foreach ($tc->getElementsByTagNameNS($W, 'tc') as $inner) { $cells[] = $inner; }
                    continue;
                }
                if ($tc->namespaceURI === $W && $tc->localName === 'tc') $cells[] = $tc;
            }
            $texts = [];
            foreach ($cells as $tc) {
                $ps = [];
                foreach ($tc->childNodes as $c) {
                    if ($c->namespaceURI !== $W) continue;
                    if ($c->localName === 'p') $ps[] = $paraText($c);
                    elseif ($c->localName === 'tbl') $readTable($c);
                }
                $texts[] = implode("\n", $ps);
            }
            $rows[] = $texts;
        }
        $out['tables'][] = $rows;
    };
    $body = $dom->getElementsByTagNameNS($W, 'body')->item(0);
    if (!$body) return $out;
    $walkBody = function (DOMNode $n) use (&$walkBody, &$out, $readTable, $paraText, $W) {
        foreach ($n->childNodes as $ch) {
            if ($ch->namespaceURI !== $W) continue;
            if ($ch->localName === 'p') $out['paras'][] = $paraText($ch);
            elseif ($ch->localName === 'tbl') $readTable($ch);
            elseif ($ch->localName === 'sdt' || $ch->localName === 'sdtContent') $walkBody($ch);
        }
    };
    $walkBody($body);
    return $out;
}
