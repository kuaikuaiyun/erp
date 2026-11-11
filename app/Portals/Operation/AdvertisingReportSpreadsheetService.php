<?php

namespace App\Portals\Operation;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvertisingReportSpreadsheetService
{
    private const HEADERS = ['提报日期', '运营人员', '广告代理', '广告社媒', '广告账户名称', '广告账户编号', '账面广告费($)', '广告消耗截图'];

    public function exportFashionResponse(iterable $reports): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('广告费导入');
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', '服装运营报表广告费导出（可直接重新导入）');
        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A2', '字段、顺序和截图均与导入模板一致；同日同运营的数据重新导入时将整份覆盖。');
        $sheet->fromArray(['提报日期', '运营人员', '广告代理', '广告社媒', '广告账户名称', '广告账户编号', '账面广告费($)', '广告消耗截图'], null, 'A4');
        $rowNumber = 5;
        foreach ($reports as $report) {
            foreach ($report->adSpendEntries as $entry) {
                $sheet->fromArray([[$report->report_date?->format('Y-m-d'), $report->submitter_name, $entry->agency, $entry->media, $entry->account_name, '', (float) $entry->book_spend_usd, '']], null, 'A'.$rowNumber);
                $sheet->setCellValueExplicit('F'.$rowNumber, (string) $entry->account_number, DataType::TYPE_STRING);
                if ($entry->screenshot_path && Storage::disk('local')->exists($entry->screenshot_path)) {
                    $drawing = new Drawing();
                    $drawing->setName('广告消耗截图');
                    $drawing->setPath(Storage::disk('local')->path($entry->screenshot_path));
                    $drawing->setCoordinates('H'.$rowNumber);
                    $drawing->setOffsetX(5)->setOffsetY(4)->setHeight(52)->setWorksheet($sheet);
                }
                $sheet->getRowDimension($rowNumber)->setRowHeight(62);
                $rowNumber++;
            }
        }
        $lastRow = max(4, $rowNumber - 1);
        $sheet->freezePane('A5');
        $sheet->setAutoFilter('A4:H'.$lastRow);
        $sheet->getStyle('A1:H1')->applyFromArray(['font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F4665']]]);
        $sheet->getStyle('A2:H2')->applyFromArray(['font' => ['size' => 10, 'color' => ['rgb' => '6D8293']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F8FC']]]);
        $sheet->getStyle('A4:H4')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '245B86']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
        foreach (['A'=>16,'B'=>18,'C'=>22,'D'=>18,'E'=>26,'F'=>24,'G'=>20,'H'=>24] as $column => $width) $sheet->getColumnDimension($column)->setWidth($width);
        if ($lastRow >= 5) {
            $sheet->getStyle('B5:F'.$lastRow)->getNumberFormat()->setFormatCode('@');
            $sheet->getStyle('G5:G'.$lastRow)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('A5:H'.$lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }
        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, '服装运营报表审核数据_'.now('Asia/Shanghai')->format('Ymd_His').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function exportResponse(iterable $reports): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('广告费导入');
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', 'Shopify运营报表广告费导出（可直接重新导入）');
        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A2', '字段、顺序和截图均与导入模板一致；修改后可直接导入，同日同运营数据将整份覆盖。');
        $sheet->fromArray(self::HEADERS, null, 'A4');
        $rowNumber = 5;
        foreach ($reports as $report) {
            $advertisingRows = collect($report->advertising_rows ?: []);
            foreach ($advertisingRows as $advertising) {
                $sheet->fromArray([[
                    $report->report_date?->format('Y-m-d'), $report->operator_name,
                    $advertising['agency'] ?? '',
                    $advertising['media_source'] ?? '',
                    $advertising['account_name'] ?? '',
                    '',
                    (float) ($advertising['book_spend'] ?? 0),
                    '',
                ]], null, 'A'.$rowNumber);
                $sheet->setCellValueExplicit('F'.$rowNumber, (string) ($advertising['account_number'] ?? ''), DataType::TYPE_STRING);
                $screenshot = trim((string) ($advertising['screenshot'] ?? ''));
                if ($screenshot !== '' && Storage::disk('public')->exists($screenshot)) {
                    $drawing = new Drawing();
                    $drawing->setName('广告消耗截图');
                    $drawing->setDescription('广告消耗截图');
                    $drawing->setPath(Storage::disk('public')->path($screenshot));
                    $drawing->setCoordinates('H'.$rowNumber);
                    $drawing->setOffsetX(5);
                    $drawing->setOffsetY(4);
                    $drawing->setHeight(52);
                    $drawing->setWorksheet($sheet);
                }
                $sheet->getRowDimension($rowNumber)->setRowHeight(62);
                $rowNumber++;
            }
        }
        $lastRow = max(4, $rowNumber - 1);
        $sheet->freezePane('A5');
        $sheet->setAutoFilter('A4:H'.$lastRow);
        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F4665']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A2:H2')->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '6D8293']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F8FC']],
        ]);
        $sheet->getStyle('A4:H4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '245B86']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        foreach (['A'=>16,'B'=>18,'C'=>22,'D'=>18,'E'=>26,'F'=>24,'G'=>20,'H'=>24] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getRowDimension(1)->setRowHeight(34);
        $sheet->getRowDimension(2)->setRowHeight(25);
        $sheet->getRowDimension(4)->setRowHeight(28);
        if ($lastRow >= 5) {
            $sheet->getStyle('B5:F'.$lastRow)->getNumberFormat()->setFormatCode('@');
            $sheet->getStyle('G5:G'.$lastRow)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('A5:H'.$lastRow)->applyFromArray([
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E3EBF1']]],
            ]);
        }
        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'Shopify运营报表审核数据_'.now('Asia/Shanghai')->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param array<string, array<string, list<array{key:string,media:string,coefficient:float}>>> $operatorAgencyGroups */
    public function templateResponse(array $operatorAgencyGroups): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('广告费导入');
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', '广告费批量导入模板');
        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A2', '每行填写提报日期和运营人员；按“人员可选代理社媒”填写，并将截图粘贴到同一行最后一列。');
        $sheet->fromArray(self::HEADERS, null, 'A4');
        $sheet->freezePane('A5');
        $sheet->setAutoFilter('A4:H4');
        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F4665']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A2:H2')->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '6D8293']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F8FC']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A4:H4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '245B86']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5E2EC']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(34);
        $sheet->getRowDimension(2)->setRowHeight(25);
        $sheet->getRowDimension(4)->setRowHeight(28);
        foreach (['A' => 16, 'B' => 18, 'C' => 22, 'D' => 18, 'E' => 26, 'F' => 24, 'G' => 20, 'H' => 24] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('A5:A1004')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->getStyle('B5:F1004')->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('G5:G1004')->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle('A5:H1004')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FBFDFE']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E3EBF1']]],
        ]);
        for ($row = 5; $row <= 1004; $row++) $sheet->getRowDimension($row)->setRowHeight(62);

        $options = $spreadsheet->createSheet();
        $options->setTitle('人员可选代理社媒');
        $options->setShowGridlines(false);
        $options->fromArray(['运营人员', '广告代理', '广告社媒', '结算系数'], null, 'A1');
        $optionRows = [];
        foreach ($operatorAgencyGroups as $operatorName => $agencyGroups) {
            foreach ($agencyGroups as $agencyName => $items) {
                foreach ($items as $item) {
                    $optionRows[] = [$operatorName, $agencyName, $item['media'], (float) $item['coefficient']];
                }
            }
        }
        if ($optionRows !== []) $options->fromArray($optionRows, null, 'A2');
        $lastRow = max(1, count($optionRows) + 1);
        $options->setAutoFilter('A1:D'.$lastRow);
        $options->freezePane('A2');
        $options->getStyle('A1:D1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E58A2B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $options->getColumnDimension('A')->setWidth(20);
        $options->getColumnDimension('B')->setWidth(24);
        $options->getColumnDimension('C')->setWidth(20);
        $options->getColumnDimension('D')->setWidth(16);
        $options->getStyle('D2:D'.$lastRow)->getNumberFormat()->setFormatCode('0.0000');

        $spreadsheet->setActiveSheetIndex(0);
        $fileName = '广告费导入模板.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $fileName, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return list<array{row:int,report_date:string,operator_name:string,agency_name:string,media_source:string,account_name:string,account_number:string,book_spend:float,image_bytes:string,image_extension:string}> */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new InvalidArgumentException('仅支持 XLSX、XLS 或 CSV 文件。');
        }

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            // 必须读取绘图对象，广告截图以 Excel 图片形式锚定在对应数据行。
            $reader->setReadDataOnly(false);
            $sheet = $reader->load($file->getRealPath())->getSheet(0);
        } catch (\Throwable) {
            throw new InvalidArgumentException('文件无法读取，请使用系统下载的模板重新填写。');
        }

        $images = $this->extractImages($sheet);
        $cellImages = $this->extractWpsCellImages($file->getRealPath());
        $rows = $sheet->toArray(null, false, false, false);
        if ($rows === []) throw new InvalidArgumentException('导入文件为空。');
        $headerOffset = collect(array_slice($rows, 0, 10))->search(function ($row): bool {
            $values = array_map(fn ($value) => trim((string) $value), is_array($row) ? $row : []);

            return collect(self::HEADERS)->every(fn (string $header): bool => in_array($header, $values, true));
        });
        if ($headerOffset === false) throw new InvalidArgumentException('未找到模板表头，请使用系统下载的模板重新填写。');
        $headers = array_map(fn ($value) => trim((string) $value), $rows[$headerOffset]);
        $rows = array_slice($rows, $headerOffset + 1);
        foreach (self::HEADERS as $header) {
            if (! in_array($header, $headers, true)) {
                throw new InvalidArgumentException('缺少必填列：'.$header.'。请使用系统模板导入。');
            }
        }
        $columns = array_flip($headers);
        $result = [];
        foreach ($rows as $index => $row) {
            $values = [];
            foreach (array_slice(self::HEADERS, 0, 7) as $header) $values[$header] = $row[$columns[$header]] ?? '';
            if (collect($values)->every(fn ($value) => $value === '')) continue;
            $excelRow = $index + $headerOffset + 2;
            $rawImageCellValue = $row[$columns['广告消耗截图']] ?? '';
            // Some Excel/WPS versions expose an in-cell image as a Drawing
            // object in toArray(), without adding it to the drawing collection.
            if (! isset($images[$excelRow]) && ($rawImageCellValue instanceof Drawing || $rawImageCellValue instanceof MemoryDrawing)) {
                $images[$excelRow] = $this->imageFromDrawing($rawImageCellValue, $excelRow);
            }
            $imageCellValue = is_scalar($rawImageCellValue) ? (string) $rawImageCellValue : '';
            if (! isset($images[$excelRow]) && preg_match('/DISPIMG\("([^"]+)"/i', $imageCellValue, $match)) {
                $images[$excelRow] = $cellImages[$match[1]] ?? null;
                if ($images[$excelRow] === null) unset($images[$excelRow]);
            }
            foreach (array_slice(self::HEADERS, 0, 6) as $header) {
                if (trim((string) $values[$header]) === '') throw new InvalidArgumentException("第 {$excelRow} 行的「{$header}」不能为空。");
            }
            $reportDate = $this->parseDate($values['提报日期'], $excelRow);
            if ($values['账面广告费($)'] === '' || ! is_numeric($values['账面广告费($)']) || (float) $values['账面广告费($)'] < 0) {
                throw new InvalidArgumentException("第 {$excelRow} 行的「账面广告费($)」必须是大于或等于 0 的数字。");
            }
            if (! isset($images[$excelRow])) {
                throw new InvalidArgumentException("第 {$excelRow} 行缺少广告消耗截图，请把图片粘贴到该行的「广告消耗截图」单元格中。");
            }
            $result[] = [
                'row' => $excelRow,
                'report_date' => $reportDate,
                'operator_name' => trim((string) $values['运营人员']),
                'agency_name' => trim((string) $values['广告代理']),
                'media_source' => trim((string) $values['广告社媒']),
                'account_name' => trim((string) $values['广告账户名称']),
                'account_number' => trim((string) $values['广告账户编号']),
                'book_spend' => round((float) $values['账面广告费($)'], 4),
                'image_bytes' => $images[$excelRow]['bytes'],
                'image_extension' => $images[$excelRow]['extension'],
            ];
        }
        if ($result === []) throw new InvalidArgumentException('模板中没有可导入的广告数据。');

        return $result;
    }

    /** @return array<int, array{bytes:string,extension:string}> */
    private function extractImages(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $images = [];
        foreach ($sheet->getDrawingCollection() as $drawing) {
            [$column, $row] = Coordinate::coordinateFromString($drawing->getCoordinates());
            if (Coordinate::columnIndexFromString($column) !== 8) continue;
            if (isset($images[$row])) throw new InvalidArgumentException("第 {$row} 行只能放置一张广告消耗截图。");

            $images[$row] = $this->imageFromDrawing($drawing, $row);
        }

        return $images;
    }

    /** @return array{bytes:string,extension:string} */
    private function imageFromDrawing(Drawing|MemoryDrawing $drawing, int $row): array
    {
        $bytes = null;
        if ($drawing instanceof MemoryDrawing) {
            ob_start();
            $render = $drawing->getRenderingFunction();
            $render($drawing->getImageResource());
            $bytes = ob_get_clean();
        } else {
            $bytes = @file_get_contents($drawing->getPath());
        }

        return $this->validatedImage($bytes, $row);
    }

    /** @return array<string, array{bytes:string,extension:string}> */
    private function extractWpsCellImages(string $path): array
    {
        if (! class_exists(\ZipArchive::class)) return [];
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return [];
        try {
            $imageXml = $zip->getFromName('xl/cellimages.xml');
            $relationsXml = $zip->getFromName('xl/_rels/cellimages.xml.rels');
            if (! is_string($imageXml) || ! is_string($relationsXml)) return [];

            $relations = [];
            // WPS/Excel may serialize package relationships with a namespace prefix
            // (for example <ns0:Relationship ...>) instead of an unprefixed element.
            if (preg_match_all('/<(?:[A-Za-z_][\w.-]*:)?Relationship\b[^>]*\bId="([^"]+)"[^>]*\bTarget="([^"]+)"[^>]*\/?\s*>/i', $relationsXml, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) if ($match[2] !== 'NULL') $relations[$match[1]] = 'xl/'.ltrim($match[2], '/');
            }
            $images = [];
            if (preg_match_all('/<etc:cellImage\b[^>]*>(.*?)<\/etc:cellImage>/is', $imageXml, $blocks)) {
                foreach ($blocks[1] as $block) {
                    if (! preg_match('/<xdr:cNvPr\b[^>]*\bname="([^"]+)"/i', $block, $nameMatch)
                        || ! preg_match('/<a:blip\b[^>]*\br:embed="([^"]+)"/i', $block, $relationMatch)) continue;
                    $target = $relations[$relationMatch[1]] ?? null;
                    $bytes = $target ? $zip->getFromName($target) : false;
                    if (! is_string($bytes) || $bytes === '') continue;
                    $images[$nameMatch[1]] = $this->validatedImage($bytes, 0);
                }
            }

            return $images;
        } finally {
            $zip->close();
        }
    }

    /** @return array{bytes:string,extension:string} */
    private function validatedImage(mixed $bytes, int $row): array
    {
        $prefix = $row > 0 ? "第 {$row} 行的" : '';
        if (! is_string($bytes) || $bytes === '') throw new InvalidArgumentException($prefix.'广告消耗截图无法读取。');
        if (strlen($bytes) > 5 * 1024 * 1024) throw new InvalidArgumentException($prefix.'广告消耗截图不能超过 5 MB。');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if ($extension === null) throw new InvalidArgumentException($prefix.'截图格式不支持，仅支持 JPG、PNG、WEBP。');

        return ['bytes' => $bytes, 'extension' => $extension];
    }

    private function parseDate(mixed $value, int $row): string
    {
        try {
            if (is_numeric($value)) {
                $date = SpreadsheetDate::excelToDateTimeObject((float) $value);
                if ((int) $date->format('Y') >= 2000 && (int) $date->format('Y') <= 2100) {
                    return $date->format('Y-m-d');
                }
            }
            $text = str_replace('/', '-', trim((string) $value));
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
            if ($date && $date->format('Y-m-d') === $text
                && (int) $date->format('Y') >= 2000 && (int) $date->format('Y') <= 2100) {
                return $text;
            }
        } catch (\Throwable) {
        }

        throw new InvalidArgumentException("第 {$row} 行的「提报日期」格式不正确，请填写完整的 YYYY-MM-DD 日期（例如 2026-11-01），不能只填写月和日。");
    }
}
