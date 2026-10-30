<?php

namespace App\Portals\Operation;

use Illuminate\Http\UploadedFile;
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
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvertisingReportSpreadsheetService
{
    private const HEADERS = ['广告代理', '广告社媒', '广告账户名称', '广告账户编号', '账面广告费($)', '广告消耗截图'];

    /** @param array<string, list<array{key:string,media:string,coefficient:float}>> $agencyGroups */
    public function templateResponse(array $agencyGroups): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('广告费导入');
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', '广告费批量导入模板');
        $sheet->mergeCells('A2:F2');
        $sheet->setCellValue('A2', '请按“可选代理社媒”填写；将广告截图直接粘贴到同一行的“广告消耗截图”单元格中，请勿删除或修改表头。');
        $sheet->fromArray(self::HEADERS, null, 'A4');
        $sheet->freezePane('A5');
        $sheet->setAutoFilter('A4:F4');
        $sheet->getStyle('A1:F1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F4665']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A2:F2')->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '6D8293']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F8FC']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A4:F4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '245B86']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5E2EC']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(34);
        $sheet->getRowDimension(2)->setRowHeight(25);
        $sheet->getRowDimension(4)->setRowHeight(28);
        foreach (['A' => 22, 'B' => 18, 'C' => 26, 'D' => 24, 'E' => 20, 'F' => 24] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('A5:D104')->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('E5:E104')->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle('A5:F104')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FBFDFE']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E3EBF1']]],
        ]);
        for ($row = 5; $row <= 104; $row++) $sheet->getRowDimension($row)->setRowHeight(62);

        $options = $spreadsheet->createSheet();
        $options->setTitle('可选代理社媒');
        $options->setShowGridlines(false);
        $options->fromArray(['广告代理', '广告社媒', '结算系数'], null, 'A1');
        $optionRows = [];
        foreach ($agencyGroups as $agencyName => $items) {
            foreach ($items as $item) {
                $optionRows[] = [$agencyName, $item['media'], (float) $item['coefficient']];
            }
        }
        if ($optionRows !== []) $options->fromArray($optionRows, null, 'A2');
        $lastRow = max(1, count($optionRows) + 1);
        $options->setAutoFilter('A1:C'.$lastRow);
        $options->freezePane('A2');
        $options->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E58A2B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $options->getColumnDimension('A')->setWidth(24);
        $options->getColumnDimension('B')->setWidth(20);
        $options->getColumnDimension('C')->setWidth(16);
        $options->getStyle('C2:C'.$lastRow)->getNumberFormat()->setFormatCode('0.0000');

        $spreadsheet->setActiveSheetIndex(0);
        $fileName = '广告费导入模板.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $fileName, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return list<array{row:int,agency_name:string,media_source:string,account_name:string,account_number:string,book_spend:float,image_bytes:string,image_extension:string}> */
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
            foreach (array_slice(self::HEADERS, 0, 5) as $header) $values[$header] = trim((string) ($row[$columns[$header]] ?? ''));
            if (collect($values)->every(fn ($value) => $value === '')) continue;
            $excelRow = $index + $headerOffset + 2;
            foreach (array_slice(self::HEADERS, 0, 4) as $header) {
                if ($values[$header] === '') throw new InvalidArgumentException("第 {$excelRow} 行的「{$header}」不能为空。");
            }
            if ($values['账面广告费($)'] === '' || ! is_numeric($values['账面广告费($)']) || (float) $values['账面广告费($)'] < 0) {
                throw new InvalidArgumentException("第 {$excelRow} 行的「账面广告费($)」必须是大于或等于 0 的数字。");
            }
            if (! isset($images[$excelRow])) {
                throw new InvalidArgumentException("第 {$excelRow} 行缺少广告消耗截图，请把图片粘贴到该行的「广告消耗截图」单元格中。");
            }
            $result[] = [
                'row' => $excelRow,
                'agency_name' => $values['广告代理'],
                'media_source' => $values['广告社媒'],
                'account_name' => $values['广告账户名称'],
                'account_number' => $values['广告账户编号'],
                'book_spend' => round((float) $values['账面广告费($)'], 4),
                'image_bytes' => $images[$excelRow]['bytes'],
                'image_extension' => $images[$excelRow]['extension'],
            ];
        }
        if ($result === []) throw new InvalidArgumentException('模板中没有可导入的广告数据。');
        if (count($result) > 100) throw new InvalidArgumentException('一次最多导入 100 条广告数据。');

        return $result;
    }

    /** @return array<int, array{bytes:string,extension:string}> */
    private function extractImages(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $images = [];
        foreach ($sheet->getDrawingCollection() as $drawing) {
            [$column, $row] = Coordinate::coordinateFromString($drawing->getCoordinates());
            if (Coordinate::columnIndexFromString($column) !== 6) continue;
            if (isset($images[$row])) throw new InvalidArgumentException("第 {$row} 行只能放置一张广告消耗截图。");

            $bytes = null;
            if ($drawing instanceof MemoryDrawing) {
                ob_start();
                $render = $drawing->getRenderingFunction();
                $render($drawing->getImageResource());
                $bytes = ob_get_clean();
            } elseif ($drawing instanceof Drawing) {
                $bytes = @file_get_contents($drawing->getPath());
            }
            if (! is_string($bytes) || $bytes === '') throw new InvalidArgumentException("第 {$row} 行的广告消耗截图无法读取。");
            if (strlen($bytes) > 5 * 1024 * 1024) throw new InvalidArgumentException("第 {$row} 行的广告消耗截图不能超过 5 MB。");
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => null,
            };
            if ($extension === null) throw new InvalidArgumentException("第 {$row} 行的截图格式不支持，仅支持 JPG、PNG、WEBP。");
            $images[$row] = ['bytes' => $bytes, 'extension' => $extension];
        }

        return $images;
    }
}
