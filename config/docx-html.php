<?php
/* ═══════════════════════════════════════════════════════════════
   v105: a Word file (.docx) as the rich text of the Market Scoping
   editor — its paragraphs (bold, italic, underline, size, colour,
   alignment, numbering), its tables (merged cells, shading, widths) and
   its pictures, every one of them, with the check boxes as ☐ / ☑.

   A ✓ drawn as a little picture over a row of the checklist ticks that
   row's box; a picture too small to be one (1 × 1) is left out. The page
   header (the letterhead) is not taken: the page has its own.
   =============================================================== */
require_once __DIR__ . '/xl-io.php';

const DH_W   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const DH_TICKS = ['✓', '✔', '☑', '☒', '√'];

/**
 * ['html', 'tables', 'pictures', 'checks', 'pairs' => [[label, value], …], 'title' => first line]
 * null when the bytes are not a .docx.
 */
function docx_to_html(string $bytes): ?array {
    if (strncmp($bytes, 'PK', 2) !== 0) return null;
    $f = xl_zip_entries($bytes);
    if (!$f || !isset($f['word/document.xml'])) return null;
    $rels = xl_rels($f, 'word/_rels/document.xml.rels');
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($f['word/document.xml'], LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$ok) return null;
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', DH_W);
    $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
    $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
    $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $xp->registerNamespace('mc', 'http://schemas.openxmlformats.org/markup-compatibility/2006');
    $xp->registerNamespace('v', 'urn:schemas-microsoft-com:vml');
    $st = new DocxHtml($f, $rels, $xp, $f['word/numbering.xml'] ?? '');
    $body = $xp->query('/w:document/w:body')->item(0);
    if (!$body) return null;
    $html = $st->blocks($body);
    return ['html' => '<div style="font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.3;">' . $html . '</div>',
            'tables' => $st->tables, 'pictures' => $st->pictures, 'checks' => $st->checks, 'pairs' => $st->pairs, 'title' => $st->firstLine];
}

final class DocxHtml {
    public int $tables = 0, $pictures = 0, $checks = 0;
    public array $pairs = [];
    public string $firstLine = '';
    private array $f, $rels;
    private DOMXPath $xp;
    private array $num = [];      // numId => abstractNumId
    private array $lvl = [];      // abstractNumId => ilvl => [fmt, text, start]
    private array $count = [];    // numId => ilvl => n
    private array $imgCache = [];
    /** small pictures (a ✓) met while building the current table row */
    private int $rowTicks = 0;
    /** nothing of the document's own put out yet (a letterhead at the very top is left out) */
    private bool $atTop = true;

    public function __construct(array $f, array $rels, DOMXPath $xp, string $numbering) {
        $this->f = $f; $this->rels = $rels; $this->xp = $xp;
        if ($numbering !== '') {
            $d = new DOMDocument();
            if (@$d->loadXML($numbering, LIBXML_NONET)) {
                $x = new DOMXPath($d); $x->registerNamespace('w', DH_W);
                foreach ($x->query('//w:abstractNum') as $an) {
                    $aid = $an->getAttributeNS(DH_W, 'abstractNumId');
                    foreach ($x->query('w:lvl', $an) as $l) {
                        $i = (int)$l->getAttributeNS(DH_W, 'ilvl');
                        $fmt = ($n = $x->query('w:numFmt', $l)->item(0)) ? $n->getAttributeNS(DH_W, 'val') : 'decimal';
                        $txt = ($n = $x->query('w:lvlText', $l)->item(0)) ? $n->getAttributeNS(DH_W, 'val') : '%1.';
                        $start = ($n = $x->query('w:start', $l)->item(0)) ? (int)$n->getAttributeNS(DH_W, 'val') : 1;
                        $this->lvl[$aid][$i] = [$fmt, $txt, $start];
                    }
                }
                foreach ($x->query('//w:num') as $n) {
                    $a = $x->query('w:abstractNumId', $n)->item(0);
                    if ($a) $this->num[$n->getAttributeNS(DH_W, 'numId')] = $a->getAttributeNS(DH_W, 'val');
                }
            }
        }
    }

    private static function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
    private function attr(DOMElement $e, string $path): ?string {
        $n = $this->xp->query($path, $e)->item(0);
        return $n instanceof DOMElement ? ($n->hasAttributeNS(DH_W, 'val') ? $n->getAttributeNS(DH_W, 'val') : '') : null;
    }
    private function on(DOMElement $rpr, string $tag): bool {
        $v = $this->attr($rpr, 'w:' . $tag);
        return $v !== null && !in_array($v, ['0', 'false', 'none'], true);
    }

    /** The paragraphs and tables of a body / cell / text box. */
    public function blocks(DOMNode $n, bool $inCell = false): string {
        $out = []; $html = '';
        foreach ($n->childNodes as $c) {
            if (!($c instanceof DOMElement) || $c->namespaceURI !== DH_W) continue;
            if ($c->localName === 'p') {
                $p = $this->para($c, $inCell);
                if ($inCell) $out[] = $p; else $html .= $p;
                if (!$inCell && trim($c->textContent) !== '') $this->atTop = false;
            } elseif ($c->localName === 'tbl') {
                // the letterhead as a table at the top (the page shows its own): left out
                if (!$inCell && $this->atTop && preg_match('/Republic\s+of\s+the\s+Philippines|STATE\s+COLLEGE\s+OF\s+TECHNOLOGY/i', $c->textContent)
                    && $this->xp->query('w:tr', $c)->length <= 2) { $this->atTop = false; continue; }
                if (!$inCell) $this->atTop = false;
                $t = $this->table($c);
                if ($inCell) $out[] = $t; else $html .= $t;
            } elseif ($c->localName === 'sdt') {
                $sc = $this->xp->query('w:sdtContent', $c)->item(0);
                if ($sc) { $x = $this->blocks($sc, $inCell); if ($inCell) $out[] = $x; else $html .= $x; }
            }
        }
        if ($inCell) {
            // a cell: its lines one under the other, without the space of a paragraph
            while ($out && end($out) === '') array_pop($out);
            return implode('<br>', $out);
        }
        return $html;
    }

    private function numLabel(DOMElement $p): string {
        $id = $this->attr($p, 'w:pPr/w:numPr/w:numId');
        if ($id === null || $id === '0' || !isset($this->num[$id])) return '';
        $il = (int)($this->attr($p, 'w:pPr/w:numPr/w:ilvl') ?? 0);
        $L = $this->lvl[$this->num[$id]] ?? [];
        if (!isset($L[$il])) return '';
        [$fmt, $txt] = $L[$il];
        $this->count[$id][$il] = ($this->count[$id][$il] ?? ($L[$il][2] - 1)) + 1;
        foreach (array_keys($this->count[$id]) as $k) if ($k > $il) unset($this->count[$id][$k]);
        if ($fmt === 'bullet') return '•';
        if ($fmt === 'none') return '';
        return preg_replace_callback('/%(\d)/', function ($m) use ($id, $L) {
            $i = (int)$m[1] - 1;
            $n = $this->count[$id][$i] ?? ($L[$i][2] ?? 1);
            return self::fmtNum($n, $L[$i][0] ?? 'decimal');
        }, $txt);
    }
    private static function fmtNum(int $n, string $fmt): string {
        switch ($fmt) {
            case 'lowerRoman': return strtolower(self::roman($n));
            case 'upperRoman': return self::roman($n);
            case 'lowerLetter': return chr(96 + (($n - 1) % 26) + 1);
            case 'upperLetter': return chr(64 + (($n - 1) % 26) + 1);
            default: return (string)$n;
        }
    }
    private static function roman(int $n): string {
        $m = ['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1];
        $s = '';
        foreach ($m as $k => $v) while ($n >= $v) { $s .= $k; $n -= $v; }
        return $s;
    }

    private function para(DOMElement $p, bool $inCell): string {
        $inner = $this->runs($p);
        $label = $this->numLabel($p);
        if ($label !== '') $inner = self::h($label) . '&nbsp;&nbsp;&nbsp;&nbsp;' . ltrim($inner);
        $style = [];
        $jc = $this->attr($p, 'w:pPr/w:jc');
        if (in_array($jc, ['center', 'right', 'both', 'distribute'], true)) $style[] = 'text-align: ' . ($jc === 'both' || $jc === 'distribute' ? 'justify' : $jc);
        $plain = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES, 'UTF-8'));
        if (!$inCell && $this->firstLine === '' && $plain !== '') $this->firstLine = $plain;
        if ($inCell) return $style ? '<span style="' . implode('; ', $style) . '; display: block;">' . $inner . '</span>' : $inner;
        $ind = $this->xp->query('w:pPr/w:ind', $p)->item(0);
        if ($ind instanceof DOMElement) {
            $left = (int)($ind->getAttributeNS(DH_W, 'left') ?: $ind->getAttributeNS(DH_W, 'start'));
            if ($left > 0 && $label === '') $style[] = 'margin-left: ' . min(40, round($left / 20 * 1.333)) . 'px';
        }
        $style[] = 'margin: 0 0 8px 0';
        if (trim(strip_tags($inner, '<img>')) === '' && strpos($inner, '<img') === false) return '<p><br></p>';
        return '<p style="' . implode('; ', array_reverse($style)) . '">' . $inner . '</p>';
    }

    /** The text, formatting and pictures of a paragraph. */
    private function runs(DOMElement $p): string {
        $h = '';
        foreach ($this->xp->query('w:r | w:hyperlink/w:r | w:ins/w:r | w:smartTag/w:r | w:fldSimple/w:r | w:sdt/w:sdtContent/w:r', $p) as $r) {
            $h .= $this->run($r);
        }
        return $h;
    }

    private function run(DOMElement $r): string {
        $rpr = $this->xp->query('w:rPr', $r)->item(0);
        $txt = '';
        $inField = false;
        foreach ($r->childNodes as $c) {
            if (!($c instanceof DOMElement)) continue;
            $ln = $c->localName;
            if ($ln === 't') $txt .= self::h($c->textContent);
            elseif ($ln === 'tab') $txt .= '&nbsp;&nbsp;&nbsp;&nbsp;';
            elseif ($ln === 'br' || $ln === 'cr') $txt .= '<br>';
            elseif ($ln === 'sym') {
                $ch = strtoupper($c->getAttributeNS(DH_W, 'char'));
                $map = ['F0FE' => '☑', 'F0A8' => '☐', 'F06F' => '☐', 'F0FC' => '✓', 'F0FB' => '☒', 'F071' => '☐', 'F078' => '☒', 'F0A1' => '☐', 'F052' => '☑', 'F0B7' => '•'];
                $txt .= $map[$ch] ?? '';
            } elseif ($ln === 'drawing' || $ln === 'pict' || $ln === 'AlternateContent' || $ln === 'object') {
                $txt .= $this->pictures($c);
            }
        }
        if ($txt === '') return '';
        if (!($rpr instanceof DOMElement)) return $txt;
        $css = [];
        $sz = $this->attr($rpr, 'w:sz');
        if ($sz !== null && is_numeric($sz) && (int)$sz > 0 && (int)$sz !== 22) $css[] = 'font-size: ' . ((int)$sz / 2) . 'pt';
        $col = $this->attr($rpr, 'w:color');
        if ($col !== null && preg_match('/^[0-9A-Fa-f]{6}$/', $col) && strtoupper($col) !== '000000') $css[] = 'color: #' . strtolower($col);
        $hl = $this->attr($rpr, 'w:highlight');
        $hlMap = ['yellow' => '#ffff00', 'green' => '#00ff00', 'cyan' => '#00ffff', 'lightGray' => '#d3d3d3', 'red' => '#ff0000', 'magenta' => '#ff00ff'];
        if ($hl !== null && isset($hlMap[$hl])) $css[] = 'background-color: ' . $hlMap[$hl];
        if ($css) $txt = '<span style="' . implode('; ', $css) . '">' . $txt . '</span>';
        if ($this->on($rpr, 'b')) $txt = '<b>' . $txt . '</b>';
        if ($this->on($rpr, 'i')) $txt = '<i>' . $txt . '</i>';
        $u = $this->attr($rpr, 'w:u');
        if ($u !== null && $u !== 'none') $txt = '<u>' . $txt . '</u>';
        if ($this->on($rpr, 'strike')) $txt = '<s>' . $txt . '</s>';
        $va = $this->attr($rpr, 'w:vertAlign');
        if ($va === 'superscript') $txt = '<sup>' . $txt . '</sup>';
        elseif ($va === 'subscript') $txt = '<sub>' . $txt . '</sub>';
        return $txt;
    }

    /** Every picture in a drawing (a group gives each of its own); a little one (a ✓) only counts. */
    private function pictures(DOMElement $d): string {
        // markup-compatibility: the drawing that Word chose, not its old-style copy
        $scope = $d;
        if ($d->localName === 'AlternateContent') {
            $ch = $this->xp->query('mc:Choice', $d)->item(0);
            if ($ch) $scope = $ch;
        }
        $ext = $this->xp->query('.//wp:extent', $scope)->item(0);
        $wpx = $ext instanceof DOMElement ? (int)round((int)$ext->getAttribute('cx') / 9525) : 0;
        $hpx = $ext instanceof DOMElement ? (int)round((int)$ext->getAttribute('cy') / 9525) : 0;
        $blips = $this->xp->query('.//a:blip', $scope);
        if (!$blips->length) $blips = $this->xp->query('.//v:imagedata', $scope);
        $out = '';
        $ids = [];
        foreach ($blips as $b) {
            $id = $b->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')
               ?: $b->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if ($id !== '') $ids[] = $id;
        }
        // text inside a text box goes in as text
        foreach ($this->xp->query('.//w:txbxContent', $scope) as $tb) {
            $t = $this->blocks($tb, true);
            if (trim(strip_tags($t)) !== '') $out .= ' ' . $t . ' ';
        }
        $real = [];
        foreach ($ids as $id) {
            $img = $this->image($id);
            if ($img === null) continue;
            if ($img['w'] <= 4 || $img['h'] <= 4) continue;            // a spacer
            $real[] = $img;
        }
        if (!$real) return $out;
        // a little picture — a ✓ over a row of the checklist
        if (count($real) === 1 && $wpx > 0 && $wpx <= 40 && $hpx <= 40) { $this->rowTicks++; return $out; }
        $each = count($real) > 1 ? max(120, (int)floor(($wpx ?: 600) / count($real))) : $wpx;
        foreach ($real as $img) {
            $w = $each > 0 ? min(620, $each) : min(620, $img['w']);
            $this->pictures++;
            $out .= '<img src="' . $img['src'] . '" alt="Picture ' . $this->pictures . '" width="' . $w . '" style="max-width: 100%; height: auto;">';
        }
        return $out;
    }

    /** A picture of the file as a data: address, made smaller when it is big ['src', 'w', 'h']. */
    private function image(string $rid): ?array {
        if (array_key_exists($rid, $this->imgCache)) return $this->imgCache[$rid];
        $t = $this->rels[$rid] ?? '';
        $path = $t !== '' ? xl_path('word/', $t) : '';
        $bytes = $this->f[$path] ?? null;
        if ($bytes === null) return $this->imgCache[$rid] = null;
        $info = @getimagesizefromstring($bytes);
        if (!$info) return $this->imgCache[$rid] = null;
        [$w, $h, $type] = $info;
        $mime = image_type_to_mime_type($type);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'], true)) return $this->imgCache[$rid] = null;
        // made lighter for the page: at most 1200 px wide, a photo without see-through parts as JPEG
        if (function_exists('imagecreatefromstring') && $w > 4 && $h > 4 && ($w > 1200 || strlen($bytes) > 250000)) {
            $im = @imagecreatefromstring($bytes);
            if ($im) {
                $nw = min(1200, $w); $nh = (int)round($h * $nw / $w);
                $dst = imagecreatetruecolor($nw, $nh);
                $alpha = $type === IMAGETYPE_PNG && self::hasAlpha($im, $w, $h);
                if ($alpha) { imagealphablending($dst, false); imagesavealpha($dst, true); }
                else { imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); }
                imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                ob_start();
                if ($alpha) { imagepng($dst, null, 6); $m2 = 'image/png'; } else { imagejpeg($dst, null, 85); $m2 = 'image/jpeg'; }
                $nb = (string)ob_get_clean();
                imagedestroy($dst); imagedestroy($im);
                if ($nb !== '' && strlen($nb) < strlen($bytes)) { $bytes = $nb; $mime = $m2; $w = $nw; $h = $nh; }
            }
        }
        return $this->imgCache[$rid] = ['src' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'w' => $w, 'h' => $h];
    }
    private static function hasAlpha($im, int $w, int $h): bool {
        if (!imageistruecolor($im)) return imagecolortransparent($im) >= 0;
        $step = max(1, (int)floor(min($w, $h) / 40));
        for ($y = 0; $y < $h; $y += $step) for ($x = 0; $x < $w; $x += $step) {
            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0) return true;
        }
        return false;
    }

    private function table(DOMElement $tbl): string {
        $this->tables++;
        $grid = [];
        foreach ($this->xp->query('w:tblGrid/w:gridCol', $tbl) as $g) $grid[] = max(1, (int)$g->getAttributeNS(DH_W, 'w'));
        $gridTotal = array_sum($grid) ?: 1;
        $rows = [];
        foreach ($this->xp->query('w:tr', $tbl) as $tr) {
            $this->rowTicks = 0;
            $cells = []; $col = 0;
            foreach ($this->xp->query('w:tc | w:sdt/w:sdtContent/w:tc', $tr) as $tc) {
                $span = max(1, (int)($this->attr($tc, 'w:tcPr/w:gridSpan') ?? 1));
                $vm = $this->attr($tc, 'w:tcPr/w:vMerge');
                $fill = $this->xp->query('w:tcPr/w:shd', $tc)->item(0);
                $fill = $fill instanceof DOMElement ? strtolower($fill->getAttributeNS(DH_W, 'fill')) : '';
                $va = $this->attr($tc, 'w:tcPr/w:vAlign');
                $html = $this->blocks($tc, true);
                $cells[] = ['col' => $col, 'span' => $span, 'vm' => $vm === null ? null : ($vm === 'restart' ? 'restart' : 'cont'),
                            'html' => $html, 'fill' => preg_match('/^[0-9a-f]{6}$/', $fill) && $fill !== 'ffffff' ? $fill : '',
                            'va' => $va, 'text' => trim(html_entity_decode(strip_tags(str_replace('<br>', "\n", $html)), ENT_QUOTES, 'UTF-8'))];
                $col += $span;
            }
            $hdr = $this->attr($tr, 'w:trPr/w:tblHeader') !== null;
            $rows[] = ['cells' => $cells, 'ticks' => $this->rowTicks, 'hdr' => $hdr];
        }
        $this->rowTicks = 0;
        // a checklist: its first column is the box
        $isCheck = $rows && isset($rows[0]['cells'][0]) && preg_match('/^Check\b/i', $rows[0]['cells'][0]['text']);
        foreach ($rows as $ri => &$row) {
            if (!$row['cells']) continue;
            $c0 = &$row['cells'][0];
            $t0 = preg_replace('/\s+/u', '', $c0['text']);
            $boxish = $t0 === '' || $t0 === '☐' || in_array($t0, DH_TICKS, true);
            if ($ri > 0 && $isCheck && $boxish) {
                $ticked = $row['ticks'] > 0 || in_array($t0, DH_TICKS, true);
                $c0['html'] = $ticked ? '☑' : '☐';
                $c0['box'] = true;
                if ($ticked) $this->checks++;
            } elseif ($t0 === '☐' && $row['ticks'] > 0) {
                $c0['html'] = '☑'; $c0['box'] = true; $this->checks++;
            } elseif ($t0 === '☐') {
                $c0['box'] = true;
            }
            unset($c0);
            // the label and value of a two-column row, for the summary shown before importing
            if (count($row['cells']) === 2 && count($this->pairs) < 8 && $row['cells'][0]['text'] !== '' && $row['cells'][1]['text'] !== ''
                && mb_strlen($row['cells'][0]['text']) < 80 && !preg_match('/^(Parameters|Check)/i', $row['cells'][0]['text'])) {
                $this->pairs[] = [preg_replace('/\s+/u', ' ', $row['cells'][0]['text']), preg_replace('/\s+/u', ' ', $row['cells'][1]['text'])];
            }
        }
        unset($row);
        // rows merged downwards: how many rows each first cell covers
        $nr = count($rows);
        foreach ($rows as $ri => &$row) {
            foreach ($row['cells'] as &$c) {
                if ($c['vm'] !== 'restart') continue;
                $n = 1;
                for ($k = $ri + 1; $k < $nr; $k++) {
                    $hit = false;
                    foreach ($rows[$k]['cells'] as $c2) if ($c2['col'] === $c['col'] && $c2['vm'] === 'cont') { $hit = true; break; }
                    if (!$hit) break;
                    $n++;
                }
                $c['rowspan'] = $n;
            }
            unset($c);
        }
        unset($row);
        $bd = 'border: 1px solid #000000; padding: 3px 6px; vertical-align: top;';
        $h = '<table style="border-collapse: collapse; width: 95%; margin-left: 5%; margin-bottom: 14px;"><tbody>';
        foreach ($rows as $ri => $row) {
            $h .= '<tr>';
            foreach ($row['cells'] as $c) {
                if ($c['vm'] === 'cont') continue;
                $w = 0;
                for ($k = $c['col']; $k < $c['col'] + $c['span']; $k++) $w += $grid[$k] ?? 0;
                $css = $bd;
                if ($w > 0) $css .= ' width: ' . round($w / $gridTotal * 100) . '%;';
                if ($c['fill'] !== '') $css .= ' background-color: #' . $c['fill'] . ';';
                if ($c['va'] === 'center') $css = str_replace('vertical-align: top;', 'vertical-align: middle;', $css);
                if (!empty($c['box'])) $css .= ' text-align: center; font-size: 13pt;';
                $at = ($c['span'] > 1 ? ' colspan="' . $c['span'] . '"' : '') . (!empty($c['rowspan']) && $c['rowspan'] > 1 ? ' rowspan="' . $c['rowspan'] . '"' : '');
                $h .= '<td' . $at . ' style="' . $css . '">' . ($c['html'] !== '' ? $c['html'] : '<br>') . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    }
}
