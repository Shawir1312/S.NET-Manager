<?php
/**
 * MiniXlsx — minimal XLSX writer, no dependencies, PHP 7.0+
 * Fixed: removed void/string/int return type hints (not supported in PHP 7.0)
 */
class MiniXlsx {
    private $sheets = [];
    private $ss = [];
    private $sc = 0;

    public function add($name, $rows) {
        $this->sheets[$name] = $rows;
    }

    private function si($s) {
        if (!isset($this->ss[$s])) $this->ss[$s] = $this->sc++;
        return $this->ss[$s];
    }

    private function col($n) {
        $r = '';
        for ($n++; $n > 0; $n = intdiv($n-1,26)) $r = chr(65+($n-1)%26).$r;
        return $r;
    }

    private function shXml($rows) {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
           . '<cols>'
           . '<col min="1" max="1" width="5" customWidth="1"/>'
           . '<col min="2" max="2" width="18" customWidth="1"/>'
           . '<col min="3" max="3" width="22" customWidth="1"/>'
           . '<col min="4" max="8" width="20" customWidth="1"/>'
           . '<col min="9" max="9" width="25" customWidth="1"/>'
           . '<col min="10" max="10" width="18" customWidth="1"/>'
           . '</cols><sheetData>';
        foreach ($rows as $ri => $row) {
            $r = $ri+1; $x .= '<row r="'.$r.'">';
            foreach ((array)$row as $ci => $v) {
                $ref = $this->col($ci).$r; $v = (string)$v;
                if ($v === '') { $x .= '<c r="'.$ref.'"/>'; continue; }
                if (ctype_digit(ltrim($v,'-')) && strlen($v)<15) {
                    $x .= '<c r="'.$ref.'" t="n"><v>'.htmlspecialchars($v,ENT_XML1).'</v></c>';
                } else {
                    $x .= '<c r="'.$ref.'" t="s"><v>'.$this->si($v).'</v></c>';
                }
            }
            $x .= '</row>';
        }
        return $x.'</sheetData></worksheet>';
    }

    public function save($path) {
        @mkdir(dirname($path),0755,true);
        $names = array_keys($this->sheets);
        $n = count($names);
        $sxmls = [];
        foreach ($this->sheets as $rows) $sxmls[] = $this->shXml($rows);

        $ss = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$this->sc.'" uniqueCount="'.$this->sc.'">';
        $ord = array_flip($this->ss); ksort($ord);
        foreach ($ord as $s) $ss .= '<si><t xml:space="preserve">'.htmlspecialchars($s,ENT_XML1).'</t></si>';
        $ss .= '</sst>';

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i=1;$i<=$n;$i++) $ct .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $ct .= '</Types>';

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        for ($i=0;$i<$n;$i++) $wb .= '<sheet name="'.htmlspecialchars($names[$i],ENT_XML1).'" sheetId="'.($i+1).'" r:id="rId'.($i+2).'"/>';
        $wb .= '</sheets></workbook>';

        $wbr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        for ($i=0;$i<$n;$i++) $wbr .= '<Relationship Id="rId'.($i+2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i+1).'.xml"/>';
        $wbr .= '</Relationships>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) return false;
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);
        $zip->addFromString('xl/sharedStrings.xml', $ss);
        $zip->addFromString('xl/styles.xml', $styles);
        for ($i=0;$i<count($sxmls);$i++) $zip->addFromString('xl/worksheets/sheet'.($i+1).'.xml', $sxmls[$i]);
        return $zip->close();
    }
}
