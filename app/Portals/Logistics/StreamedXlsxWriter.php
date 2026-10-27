<?php

namespace App\Portals\Logistics;

use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class StreamedXlsxWriter
{
    /**
     * @param list<array{label:string,width:float|int}> $columns
     * @param callable():iterable<array{kind?:string,height?:float|int,outline?:int,mergeDown?:array<int,int>,mergeAcross?:array<int,int>,cells:list<array{value:mixed,type?:string,align?:string,wrap?:bool}>}> $rows
     */
    public function response(
        string $filename,
        string $sheetName,
        array $columns,
        callable $rows,
        bool $showGridlines = true,
    ): StreamedResponse {
        return response()->streamDownload(function () use ($sheetName, $columns, $rows, $showGridlines): void {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $sheetPath = tempnam(sys_get_temp_dir(), 'erp-sheet-');
            $xlsxPath = tempnam(sys_get_temp_dir(), 'erp-xlsx-');
            if ($sheetPath === false || $xlsxPath === false) {
                throw new RuntimeException('无法创建 Excel 临时文件。');
            }

            try {
                $this->writeSheet($sheetPath, $columns, $rows, $showGridlines);
                $this->writeWorkbook($xlsxPath, $sheetPath, $sheetName);

                $handle = fopen($xlsxPath, 'rb');
                if ($handle === false) {
                    throw new RuntimeException('无法读取生成的 Excel 文件。');
                }
                fpassthru($handle);
                fclose($handle);
            } finally {
                @unlink($sheetPath);
                @unlink($xlsxPath);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * @param list<array{label:string,width:float|int}> $columns
     * @param callable():iterable<array{kind?:string,height?:float|int,outline?:int,mergeDown?:array<int,int>,mergeAcross?:array<int,int>,cells:list<array{value:mixed,type?:string,align?:string,wrap?:bool}>}> $rows
     */
    private function writeSheet(string $path, array $columns, callable $rows, bool $showGridlines): int
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('无法写入 Excel 工作表临时文件。');
        }

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($handle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        fwrite($handle, '<sheetViews><sheetView workbookViewId="0" showGridLines="'.($showGridlines ? '1' : '0').'">');
        fwrite($handle, '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>');
        fwrite($handle, '</sheetView></sheetViews>');
        fwrite($handle, '<cols>');
        foreach ($columns as $index => $column) {
            $number = $index + 1;
            $width = max(1, (float) $column['width']);
            fwrite($handle, '<col min="'.$number.'" max="'.$number.'" width="'.$width.'" customWidth="1"/>');
        }
        fwrite($handle, '</cols><sheetData>');

        fwrite($handle, '<row r="1" ht="26" customHeight="1">');
        foreach ($columns as $index => $column) {
            fwrite($handle, $this->stringCell($this->columnName($index + 1).'1', (string) $column['label'], 1));
        }
        fwrite($handle, '</row>');

        $rowNumber = 2;
        $mergedRanges = [];
        foreach (($rows)() as $row) {
            $kind = in_array(($row['kind'] ?? 'normal'), ['normal', 'parent', 'variant'], true)
                ? (string) ($row['kind'] ?? 'normal')
                : 'normal';
            $attributes = ' r="'.$rowNumber.'"';
            if (isset($row['height'])) {
                $attributes .= ' ht="'.max(1, (float) $row['height']).'" customHeight="1"';
            }
            if (($row['outline'] ?? 0) > 0) {
                $attributes .= ' outlineLevel="'.min(7, (int) $row['outline']).'"';
            }
            foreach (($row['mergeDown'] ?? []) as $columnIndex => $span) {
                $columnIndex = (int) $columnIndex;
                $span = (int) $span;
                if ($columnIndex < 0 || $columnIndex >= count($columns) || $span < 2) {
                    continue;
                }
                $column = $this->columnName($columnIndex + 1);
                $mergedRanges[] = $column.$rowNumber.':'.$column.($rowNumber + $span - 1);
            }
            foreach (($row['mergeAcross'] ?? []) as $columnIndex => $span) {
                $columnIndex = (int) $columnIndex;
                $span = (int) $span;
                if ($columnIndex < 0 || $span < 2 || $columnIndex + $span > count($columns)) {
                    continue;
                }
                $startColumn = $this->columnName($columnIndex + 1);
                $endColumn = $this->columnName($columnIndex + $span);
                $mergedRanges[] = $startColumn.$rowNumber.':'.$endColumn.$rowNumber;
            }
            fwrite($handle, '<row'.$attributes.'>');
            foreach ($columns as $index => $_column) {
                $cell = $row['cells'][$index] ?? ['value' => ''];
                $reference = $this->columnName($index + 1).$rowNumber;
                $type = (string) ($cell['type'] ?? 'text');
                $style = $this->styleIndex(
                    $kind,
                    $type,
                    (string) ($cell['align'] ?? 'left'),
                    (bool) ($cell['wrap'] ?? false),
                );
                $value = $cell['value'] ?? null;
                if (in_array($type, ['money', 'rate', 'number'], true) && $value !== null && $value !== '' && is_numeric($value)) {
                    fwrite($handle, '<c r="'.$reference.'" s="'.$style.'" t="n"><v>'.(string) ((float) $value).'</v></c>');
                } else {
                    fwrite($handle, $this->stringCell($reference, $this->displayValue($value), $style));
                }
            }
            fwrite($handle, '</row>');
            $rowNumber++;
        }

        $lastRow = max(1, $rowNumber - 1);
        $lastColumn = $this->columnName(count($columns));
        fwrite($handle, '</sheetData>');
        if ($mergedRanges !== []) {
            fwrite($handle, '<mergeCells count="'.count($mergedRanges).'">');
            foreach ($mergedRanges as $range) {
                fwrite($handle, '<mergeCell ref="'.$range.'"/>');
            }
            fwrite($handle, '</mergeCells>');
        }
        fwrite($handle, '<autoFilter ref="A1:'.$lastColumn.$lastRow.'"/>');
        fwrite($handle, '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>');
        fwrite($handle, '</worksheet>');
        fclose($handle);

        return $lastRow;
    }

    private function writeWorkbook(string $xlsxPath, string $sheetPath, string $sheetName): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('无法创建 Excel 压缩文件。');
        }

        $safeSheetName = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $sheetName) ?: 'Sheet1', 0, 31);
        $createdAt = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('docProps/app.xml', $this->appProperties());
        $zip->addFromString('docProps/core.xml', $this->coreProperties($createdAt));
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($safeSheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();
    }

    private function styleIndex(string $kind, string $type, string $align, bool $wrap): int
    {
        if ($kind === 'parent') {
            return match (true) {
                $type === 'money' || $type === 'number' => 8,
                $type === 'rate' => 9,
                $wrap => 10,
                $align === 'center' => 7,
                default => 6,
            };
        }
        if ($kind === 'variant') {
            return match (true) {
                $type === 'money' || $type === 'number' => 13,
                $type === 'rate' => 14,
                $wrap => 15,
                $align === 'center' => 12,
                default => 11,
            };
        }

        return match (true) {
            $type === 'money' || $type === 'number' => 2,
            $type === 'rate' => 3,
            $wrap => 4,
            $align === 'center' => 5,
            default => 0,
        };
    }

    private function stringCell(string $reference, string $value, int $style): string
    {
        return '<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'
            .$this->xml($value).'</t></is></c>';
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView/></bookViews><sheets><sheet name="'.$this->xml($sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function appProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>快云 ERP</Application></Properties>';
    }

    private function coreProperties(string $createdAt): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:creator>快云 ERP</dc:creator><cp:lastModifiedBy>快云 ERP</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$createdAt.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$createdAt.'</dcterms:modified></cp:coreProperties>';
    }

    private function stylesXml(): string
    {
        $alignLeft = '<alignment vertical="center"/>';
        $alignCenter = '<alignment horizontal="center" vertical="center"/>';
        $alignWrap = '<alignment vertical="center" wrapText="1"/>';
        $alignRight = '<alignment horizontal="right" vertical="center"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00;[Red]-&quot;$&quot;#,##0.00"/><numFmt numFmtId="165" formatCode="0.00&quot;%&quot;"/></numFmts>'
            .'<fonts count="4">'
            .'<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><color rgb="FF2D4F73"/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><color rgb="FF294F76"/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><color rgb="FF647D96"/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2F8"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF9FCFF"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFCFDFF"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top style="thin"><color rgb="FFBDD8F1"/></top><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="16">'
            .$this->xf(0, 0, 0, 0, $alignLeft).$this->xf(0, 1, 2, 0, $alignCenter)
            .$this->xf(164, 0, 0, 0, $alignRight).$this->xf(165, 0, 0, 0, $alignRight)
            .$this->xf(0, 0, 0, 0, $alignWrap).$this->xf(0, 0, 0, 0, $alignCenter)
            .$this->xf(0, 2, 3, 1, $alignLeft).$this->xf(0, 2, 3, 1, $alignCenter)
            .$this->xf(164, 2, 3, 1, $alignRight).$this->xf(165, 2, 3, 1, $alignRight).$this->xf(0, 2, 3, 1, $alignWrap)
            .$this->xf(0, 3, 4, 0, $alignLeft).$this->xf(0, 3, 4, 0, $alignCenter)
            .$this->xf(164, 3, 4, 0, $alignRight).$this->xf(165, 3, 4, 0, $alignRight).$this->xf(0, 3, 4, 0, $alignWrap)
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function xf(int $numFmtId, int $fontId, int $fillId, int $borderId, string $alignment): string
    {
        return '<xf numFmtId="'.$numFmtId.'" fontId="'.$fontId.'" fillId="'.$fillId.'" borderId="'.$borderId.'" xfId="0" applyAlignment="1"'
            .($numFmtId !== 0 ? ' applyNumberFormat="1"' : '')
            .($fontId !== 0 ? ' applyFont="1"' : '')
            .($fillId !== 0 ? ' applyFill="1"' : '')
            .($borderId !== 0 ? ' applyBorder="1"' : '')
            .'>'.$alignment.'</xf>';
    }
}
