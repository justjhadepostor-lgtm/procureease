<?php
/**
 * Minimal native XLSX writer — no external libraries.
 *
 * Produces a valid .xlsx file by writing the Office Open XML SpreadsheetML
 * format directly and packaging it as a ZIP with PHP's ZipArchive.
 *
 * Supports what a government APP submission actually needs:
 *   - Multiple sheets
 *   - Text + number cells
 *   - Bold header rows
 *   - Column widths
 *   - Simple borders on data tables
 *   - Merged cells for title rows
 *
 * Usage:
 *   $x = new SimpleXlsxWriter();
 *   $x->addSheet('APP', [
 *       ['Title row that spans everything'],
 *       [],
 *       ['Dept', 'Description', 'Amount'],    // header (auto-bold)
 *       ['ICTU', 'Bond paper', 5000.00],
 *   ], ['headerRow' => 3, 'columnWidths' => [12, 40, 15]]);
 *   $x->download('APP-FY2027.xlsx');
 */
class SimpleXlsxWriter {
    private $sheets = [];
    private $sharedStrings = [];
    private $sharedStringMap = [];

    public function addSheet(string $name, array $rows, array $opts = []): void {
        $this->sheets[] = ['name' => $this->cleanSheetName($name), 'rows' => $rows, 'opts' => $opts];
    }

    public function download(string $filename): void {
        if (!class_exists('ZipArchive')) {
            // PHP zip extension is not enabled — cannot build XLSX files
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><title>XLSX export unavailable</title>';
            echo '<style>body{font-family:Arial,sans-serif;max-width:640px;margin:60px auto;padding:24px;color:#0F172A;line-height:1.6;}';
            echo 'h1{color:#E5580C;font-size:20px;} code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:13px;}';
            echo '.steps{background:#EBF3F2;border-left:4px solid #4274D9;padding:12px 20px;border-radius:8px;margin:14px 0;}';
            echo '</style></head><body>';
            echo '<h1>⚠ XLSX report cannot be generated</h1>';
            echo '<p>The PHP <strong>zip</strong> extension is required to generate .xlsx files but is not enabled on this server.</p>';
            echo '<div class="steps"><strong>To fix this:</strong>';
            echo '<ol><li>Open <code>php.ini</code> in your XAMPP <code>php</code> folder</li>';
            echo '<li>Find the line <code>;extension=zip</code> and remove the leading semicolon so it reads <code>extension=zip</code></li>';
            echo '<li>Save the file and restart Apache from the XAMPP Control Panel</li>';
            echo '<li>Come back to this page and try again</li></ol></div>';
            echo '<p><a href="javascript:history.back()">← Go back</a></p>';
            echo '</body></html>';
            exit;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->writeTo($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    private function writeTo(string $path): void {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive class not found. Please enable the PHP zip extension in php.ini (uncomment extension=zip) and restart Apache.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Cannot create XLSX archive.');
        }

        // Sheet XML first (populates shared strings)
        $sheetXmls = [];
        foreach ($this->sheets as $i => $s) {
            $sheetXmls[] = $this->buildSheetXml($s['rows'], $s['opts']);
        }

        // Package parts
        $zip->addFromString('[Content_Types].xml', $this->buildContentTypes());
        $zip->addFromString('_rels/.rels', $this->buildRootRels());
        $zip->addFromString('xl/workbook.xml', $this->buildWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRels());
        $zip->addFromString('xl/styles.xml', $this->buildStyles());
        $zip->addFromString('xl/sharedStrings.xml', $this->buildSharedStrings());
        foreach ($sheetXmls as $i => $xml) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
        }
        $zip->close();
    }

    private function cleanSheetName(string $name): string {
        // Excel sheet names: max 31 chars, no : \ / ? * [ ]
        $name = preg_replace('/[:\\\\\/\?\*\[\]]/', '_', $name);
        return mb_substr($name, 0, 31);
    }

    private function siIndex(string $s): int {
        if (isset($this->sharedStringMap[$s])) return $this->sharedStringMap[$s];
        $idx = count($this->sharedStrings);
        $this->sharedStrings[] = $s;
        $this->sharedStringMap[$s] = $idx;
        return $idx;
    }

    private function colLetter(int $c): string {
        // 1 => A, 2 => B, ..., 27 => AA
        $s = '';
        while ($c > 0) {
            $mod = ($c - 1) % 26;
            $s = chr(65 + $mod) . $s;
            $c = intdiv($c - 1, 26);
        }
        return $s;
    }

    private function buildSheetXml(array $rows, array $opts): string {
        $headerRow  = (int)($opts['headerRow'] ?? 0);
        $titleRow   = (int)($opts['titleRow']  ?? 0);   // full-width bold title
        $titleCols  = (int)($opts['titleCols'] ?? 0);   // how many columns to span
        $widths     = $opts['columnWidths'] ?? [];
        $borderData = (bool)($opts['bordersOnData'] ?? true);

        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Column widths
        if ($widths) {
            $xml .= '<cols>';
            foreach ($widths as $i => $w) {
                $col = $i + 1;
                $xml .= '<col min="' . $col . '" max="' . $col . '" width="' . (float)$w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($rows as $r => $rowData) {
            $rowNum = $r + 1;
            $xml .= '<row r="' . $rowNum . '"' . ($rowNum === $headerRow ? ' ht="18" customHeight="1"' : '') . '>';
            foreach ($rowData as $c => $val) {
                $ref = $this->colLetter($c + 1) . $rowNum;
                $style = 0;
                if ($rowNum === $titleRow)       $style = 1;   // title
                elseif ($rowNum === $headerRow)  $style = 2;   // header (bold + border)
                elseif ($borderData && $rowNum > $headerRow && $headerRow > 0) $style = 3; // data (border)

                if (is_int($val) || is_float($val) || (is_string($val) && $val !== '' && is_numeric($val))) {
                    $num = (float)$val;
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . htmlspecialchars((string)$num, ENT_QUOTES) . '</v></c>';
                } else {
                    $str = (string)$val;
                    if ($str === '') {
                        // empty cell — still apply style so borders show
                        $xml .= '<c r="' . $ref . '" s="' . $style . '"/>';
                    } else {
                        $si = $this->siIndex($str);
                        $xml .= '<c r="' . $ref . '" s="' . $style . '" t="s"><v>' . $si . '</v></c>';
                    }
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        // Merged title row
        if ($titleRow > 0 && $titleCols > 1) {
            $range = $this->colLetter(1) . $titleRow . ':' . $this->colLetter($titleCols) . $titleRow;
            $xml .= '<mergeCells count="1"><mergeCell ref="' . $range . '"/></mergeCells>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    private function buildContentTypes(): string {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        foreach ($this->sheets as $i => $_s) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        $xml .= '</Types>';
        return $xml;
    }

    private function buildRootRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
             . '</Relationships>';
    }

    private function buildWorkbook(): string {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';
        foreach ($this->sheets as $i => $s) {
            $xml .= '<sheet name="' . htmlspecialchars($s['name'], ENT_QUOTES) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        $xml .= '</sheets></workbook>';
        return $xml;
    }

    private function buildWorkbookRels(): string {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $_s) {
            $xml .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $nextId = count($this->sheets) + 1;
        $xml .= '<Relationship Id="rId' . $nextId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $xml .= '<Relationship Id="rId' . ($nextId + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $xml .= '</Relationships>';
        return $xml;
    }

    private function buildStyles(): string {
        // Style 0 = default; 1 = title (bold, centered, size 14); 2 = header (bold, border, fill); 3 = data (border)
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<fonts count="3">'
             .   '<font><sz val="11"/><name val="Calibri"/></font>'
             .   '<font><b/><sz val="14"/><name val="Calibri"/></font>'
             .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
             . '</fonts>'
             . '<fills count="3">'
             .   '<fill><patternFill patternType="none"/></fill>'
             .   '<fill><patternFill patternType="gray125"/></fill>'
             .   '<fill><patternFill patternType="solid"><fgColor rgb="FFEBF3F2"/></patternFill></fill>'
             . '</fills>'
             . '<borders count="2">'
             .   '<border><left/><right/><top/><bottom/></border>'
             .   '<border>'
             .     '<left style="thin"><color rgb="FFC4D9D7"/></left>'
             .     '<right style="thin"><color rgb="FFC4D9D7"/></right>'
             .     '<top style="thin"><color rgb="FFC4D9D7"/></top>'
             .     '<bottom style="thin"><color rgb="FFC4D9D7"/></bottom>'
             .   '</border>'
             . '</borders>'
             . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
             . '<cellXfs count="4">'
             .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
             .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
             .   '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
             .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
             . '</cellXfs>'
             . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
             . '</styleSheet>';
    }

    private function buildSharedStrings(): string {
        $count = count($this->sharedStrings);
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';
        foreach ($this->sharedStrings as $s) {
            $xml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_QUOTES) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }
}
