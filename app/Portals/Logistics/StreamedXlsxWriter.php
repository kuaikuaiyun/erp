<?php

namespace App\Portals\Logistics;

use GuzzleHttp\Psr7\PumpStream;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;
use ZipStream\ZipStream;

class StreamedXlsxWriter
{
    /**
     * @param list<array{label:string,width:float|int,headerFill?:string}> $columns
     * @param callable():iterable<array{kind?:string,height?:float|int,outline?:int,mergeDown?:array<int,int>,mergeAcross?:array<int,int>,cells:list<array{value:mixed,type?:string,align?:string,wrap?:bool}>}> $rows
     */
    public function response(
        string $filename,
        string $sheetName,
        array $columns,
        callable $rows,
        bool $showGridlines = true,
        bool $plain = false,
        string $fontName = 'Calibri',
        array $additionalSheets = [],
        bool $streamDirectly = false,
    ): StreamedResponse {
        $headerFills = array_values(array_unique(array_filter(array_column($columns, 'headerFill'), fn ($color) => preg_match('/^[0-9A-F]{6}$/D', $color))));

        return response()->streamDownload(function () use ($sheetName, $columns, $rows, $showGridlines, $plain, $fontName, $headerFills, $additionalSheets, $streamDirectly): void {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $sheets = [[$sheetName, $columns, $rows], ...$additionalSheets];
            if ($streamDirectly) {
                $this->streamWorkbook($sheets, $showGridlines, $plain, $fontName, $headerFills);
                return;
            }
            $xlsxPath = tempnam(sys_get_temp_dir(), 'erp-xlsx-');
            if ($xlsxPath === false) {
                throw new RuntimeException('无法创建 Excel 临时文件。');
            }
            $paths = [];
            try {
                foreach ($sheets as [$name, $sheetColumns, $sheetRows]) {
                    $path = tempnam(sys_get_temp_dir(), 'erp-sheet-');
                    if ($path === false) {
                        throw new RuntimeException('无法创建 Excel 临时文件。');
                    }
                    $paths[] = $path;
                    $this->writeSheet($path, $sheetColumns, $sheetRows, $showGridlines, $plain, $headerFills);
                }
                $this->writeWorkbook($xlsxPath, $paths, array_column($sheets, 0), $fontName, $headerFills);

                $handle = fopen($xlsxPath, 'rb');
                if ($handle === false) {
                    throw new RuntimeException('无法读取生成的 Excel 文件。');
                }
                fpassthru($handle);
                fclose($handle);
            } finally {
                foreach ($paths as $path) {
                    @unlink($path);
                }
                @unlink($xlsxPath);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ] + ($streamDirectly ? ['X-Accel-Buffering' => 'no'] : []));
    }

    /**
     * @param list<array{label:string,width:float|int,headerFill?:string}> $columns
     * @param callable():iterable<array{kind?:string,height?:float|int,outline?:int,mergeDown?:array<int,int>,mergeAcross?:array<int,int>,cells:list<array{value:mixed,type?:string,align?:string,wrap?:bool}>}> $rows
     */
    private function writeSheet(string $path, array $columns, callable $rows, bool $showGridlines, bool $plain, array $headerFills): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('无法写入 Excel 工作表临时文件。');
        }

        try {
            foreach ($this->sheetXml($columns, $rows, $showGridlines, $plain, $headerFills) as $xml) {
                fwrite($handle, $xml);
            }
        } finally {
            fclose($handle);
        }
    }

    private function sheetXml(array $columns, callable $rows, bool $showGridlines, bool $plain, array $headerFills): \Generator
    {
        yield '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        yield '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if (! $plain) {
            yield '<sheetViews><sheetView workbookViewId="0" showGridLines="'.($showGridlines ? '1' : '0').'">';
            yield '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
            yield '</sheetView></sheetViews>';
        }
        yield '<cols>';
        foreach ($columns as $index => $column) {
            $number = $index + 1;
            $width = max(1, (float) $column['width']);
            yield '<col min="'.$number.'" max="'.$number.'" width="'.$width.'" customWidth="1"/>';
        }
        yield '</cols>';

        yield $plain ? '<sheetData><row r="1">' : '<sheetData><row r="1" ht="26" customHeight="1">';
        foreach ($columns as $index => $column) {
            $fillIndex = array_search($column['headerFill'] ?? '', $headerFills, true);
            $style = $fillIndex === false ? ($plain ? 0 : 1) : 19 + $fillIndex;
            yield $this->stringCell($this->columnName($index + 1).'1', (string) $column['label'], $style);
        }
        yield '</row>';

        $rowNumber = 2;
        $mergedRanges = [];
        foreach (($rows)() as $row) {
            $kind = in_array(($row['kind'] ?? 'normal'), ['normal', 'parent', 'variant'], true)
                ? (string) ($row['kind'] ?? 'normal')
                : 'normal';
            $attributes = ' r="'.$rowNumber.'"';
            if (! $plain && isset($row['height'])) {
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
            $rowXml = '<row'.$attributes.'>';
            foreach ($columns as $index => $_column) {
                $cell = $row['cells'][$index] ?? ['value' => ''];
                $reference = $this->columnName($index + 1).$rowNumber;
                $type = (string) ($cell['type'] ?? 'text');
                $style = $type === 'datetime' ? 18 : ($plain ? 0 : $this->styleIndex(
                    $kind,
                    $type,
                    (string) ($cell['align'] ?? 'left'),
                    (bool) ($cell['wrap'] ?? false),
                ));
                $value = $cell['value'] ?? null;
                if (in_array($type, ['money', 'rate', 'number', 'decimal', 'quantity', 'datetime'], true) && $value !== null && $value !== '' && is_numeric($value)) {
                    $rowXml .= '<c r="'.$reference.'" s="'.$style.'" t="n"><v>'.(string) ((float) $value).'</v></c>';
                } else {
                    $rowXml .= $this->stringCell($reference, $this->displayValue($value), $style);
                }
            }
            yield $rowXml.'</row>';
            $rowNumber++;
        }

        $lastRow = max(1, $rowNumber - 1);
        $lastColumn = $this->columnName(count($columns));
        yield '</sheetData>';
        if ($mergedRanges !== []) {
            yield '<mergeCells count="'.count($mergedRanges).'">';
            foreach ($mergedRanges as $range) {
                yield '<mergeCell ref="'.$range.'"/>';
            }
            yield '</mergeCells>';
        }
        yield '<autoFilter ref="A1:'.$lastColumn.$lastRow.'"/>';
        yield '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>';
        yield '</worksheet>';
    }

    private function streamWorkbook(array $sheets, bool $showGridlines, bool $plain, string $fontName, array $headerFills): void
    {
        $zip = new ZipStream(sendHttpHeaders: false, flushOutput: PHP_SAPI !== 'cli');
        foreach ($this->workbookParts(array_column($sheets, 0), $fontName, $headerFills) as $path => $data) {
            $zip->addFile($path, $data);
        }
        foreach ($sheets as $index => [, $columns, $rows]) {
            $xml = $this->sheetXml($columns, $rows, $showGridlines, $plain, $headerFills);
            $started = false;
            $stream = new PumpStream(function () use ($xml, &$started): string|false {
                if ($started) {
                    $xml->next();
                }
                $started = true;
                return $xml->valid() ? $xml->current() : false;
            });
            $zip->addFileFromPsr7Stream('xl/worksheets/sheet'.($index + 1).'.xml', $stream);
        }
        $zip->finish();
    }

    private function writeWorkbook(string $xlsxPath, array $sheetPaths, array $sheetNames, string $fontName, array $headerFills): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('无法创建 Excel 压缩文件。');
        }

        foreach ($this->workbookParts($sheetNames, $fontName, $headerFills) as $path => $data) {
            $zip->addFromString($path, $data);
        }
        foreach ($sheetPaths as $index => $path) {
            $zip->addFile($path, 'xl/worksheets/sheet'.($index + 1).'.xml');
        }
        $zip->close();
    }

    private function workbookParts(array $sheetNames, string $fontName, array $headerFills): array
    {
        return [
            '[Content_Types].xml' => $this->contentTypes(count($sheetNames)),
            '_rels/.rels' => $this->rootRelationships(),
            'docProps/app.xml' => $this->appProperties(),
            'docProps/core.xml' => $this->coreProperties(now()->utc()->format('Y-m-d\TH:i:s\Z')),
            'xl/workbook.xml' => $this->workbookXml($sheetNames),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationships(count($sheetNames)),
            'xl/styles.xml' => $this->stylesXml($fontName, $headerFills),
        ];
    }

    private function styleIndex(string $kind, string $type, string $align, bool $wrap): int
    {
        if ($type === 'decimal') {
            return 16;
        }
        if ($type === 'quantity') {
            return 17;
        }
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

    private function contentTypes(int $sheetCount): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .implode('', array_map(fn ($id) => '<Override PartName="/xl/worksheets/sheet'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, $sheetCount)))
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

    private function workbookRelationships(int $sheetCount): string
    {
        $sheets = implode('', array_map(fn ($id) => '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>', range(1, $sheetCount)));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$sheets.'<Relationship Id="rId'.($sheetCount + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(array $sheetNames): string
    {
        $sheets = '';
        foreach ($sheetNames as $index => $name) {
            $id = $index + 1;
            $safeName = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $name) ?: 'Sheet'.$id, 0, 31);
            $sheets .= '<sheet name="'.$this->xml($safeName).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView/></bookViews><sheets>'.$sheets.'</sheets></workbook>';
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

    private function stylesXml(string $fontName, array $headerFills): string
    {
        $alignLeft = '<alignment vertical="center"/>';
        $alignCenter = '<alignment horizontal="center" vertical="center"/>';
        $alignWrap = '<alignment vertical="center" wrapText="1"/>';
        $alignRight = '<alignment horizontal="right" vertical="center"/>';
        $extraFills = '';
        $extraStyles = '';
        foreach ($headerFills as $index => $color) {
            $extraFills .= '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$color.'"/><bgColor indexed="64"/></patternFill></fill>';
            $extraStyles .= $this->xf(0, 0, 5 + $index, 0, $alignLeft);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="5"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00;[Red]-&quot;$&quot;#,##0.00"/><numFmt numFmtId="165" formatCode="0.00&quot;%&quot;"/><numFmt numFmtId="166" formatCode="#,##0.00########"/><numFmt numFmtId="167" formatCode="#,##0.########"/><numFmt numFmtId="168" formatCode="yyyy/m/d hh:mm:ss"/></numFmts>'
            .'<fonts count="4">'
            .'<font><sz val="11"/><name val="'.$this->xml($fontName).'"/><family val="2"/></font>'
            .'<font><b/><color rgb="FF2D4F73"/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><color rgb="FF294F76"/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><color rgb="FF647D96"/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="'.(5 + count($headerFills)).'"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2F8"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF9FCFF"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFCFDFF"/><bgColor indexed="64"/></patternFill></fill>'.$extraFills.'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top style="thin"><color rgb="FFBDD8F1"/></top><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.(19 + count($headerFills)).'">'
            .$this->xf(0, 0, 0, 0, $alignLeft).$this->xf(0, 1, 2, 0, $alignCenter)
            .$this->xf(164, 0, 0, 0, $alignRight).$this->xf(165, 0, 0, 0, $alignRight)
            .$this->xf(0, 0, 0, 0, $alignWrap).$this->xf(0, 0, 0, 0, $alignCenter)
            .$this->xf(0, 2, 3, 1, $alignLeft).$this->xf(0, 2, 3, 1, $alignCenter)
            .$this->xf(164, 2, 3, 1, $alignRight).$this->xf(165, 2, 3, 1, $alignRight).$this->xf(0, 2, 3, 1, $alignWrap)
            .$this->xf(0, 3, 4, 0, $alignLeft).$this->xf(0, 3, 4, 0, $alignCenter)
            .$this->xf(164, 3, 4, 0, $alignRight).$this->xf(165, 3, 4, 0, $alignRight).$this->xf(0, 3, 4, 0, $alignWrap)
            .$this->xf(166, 0, 0, 0, $alignRight).$this->xf(167, 0, 0, 0, $alignRight)
            .$this->xf(168, 0, 0, 0, $alignRight).$extraStyles
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
