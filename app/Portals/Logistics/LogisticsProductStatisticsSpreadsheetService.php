<?php

namespace App\Portals\Logistics;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogisticsProductStatisticsSpreadsheetService
{
    private const TYPE_TEXT = 'text';
    private const TYPE_MONEY = 'money';
    private const TYPE_RATE = 'rate';

    /** @return list<array{0:string,1:string,2:string,3:float|int}> */
    private static function columns(): array
    {
        return [
            ['层级', 'level', self::TYPE_TEXT, 12],
            [__('logistics.product_statistics.headers.product'), 'name', self::TYPE_TEXT, 30],
            ['SKU', 'sku', self::TYPE_TEXT, 30],
            [__('logistics.product_statistics.headers.stores'), 'stores', self::TYPE_TEXT, 24],
            [__('logistics.product_statistics.headers.sales'), 'sales', self::TYPE_TEXT, 15],
            [__('logistics.product_statistics.headers.revenue'), 'revenue', self::TYPE_MONEY, 18],
            [__('logistics.product_statistics.headers.cost'), 'cost', self::TYPE_MONEY, 18],
            [__('logistics.product_statistics.headers.fees'), 'fees', self::TYPE_MONEY, 20],
            [__('logistics.product_statistics.headers.refunds'), 'refunds', self::TYPE_MONEY, 17],
            [__('logistics.product_statistics.ui.actual_refunds'), 'actual_refunds', self::TYPE_MONEY, 18],
            [__('logistics.product_statistics.ui.actual_refund_count'), 'actual_refund_count', self::TYPE_TEXT, 16],
            [__('logistics.product_statistics.headers.profit'), 'profit', self::TYPE_MONEY, 17],
            [__('logistics.product_statistics.headers.profit_rate'), 'profit_rate', self::TYPE_RATE, 14],
            [__('logistics.product_statistics.headers.refund_rate'), 'refund_rate', self::TYPE_RATE, 14],
            [__('logistics.product_statistics.headers.decision'), 'decision', self::TYPE_TEXT, 16],
        ];
    }

    /** @param Collection<int, array<string, mixed>> $products */
    public function exportResponse(Collection $products): StreamedResponse
    {
        return response()->streamDownload(function () use ($products): void {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(__('logistics.product_statistics.title'));
            $columns = self::columns();

            foreach ($columns as $index => [$label, , , $width]) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValueExplicit($column.'1', $label, DataType::TYPE_STRING);
                $sheet->getColumnDimension($column)->setWidth($width);
            }

            $rowNumber = 2;
            foreach ($products as $product) {
                $this->writeRow($sheet, $rowNumber, $this->parentRow($product), false);
                $this->styleParentRow($sheet, $rowNumber, count($columns));
                $rowNumber++;
                foreach ($product['variants'] as $variant) {
                    $this->writeRow($sheet, $rowNumber, $this->variantRow($variant), true);
                    $this->styleVariantRow($sheet, $rowNumber, count($columns));
                    $rowNumber++;
                }
            }

            $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
            $lastRow = max(1, $rowNumber - 1);
            $this->styleSheet($sheet, $lastColumn, $lastRow);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, __('logistics.product_statistics.title').'_'.now('Asia/Shanghai')->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function parentRow(array $product): array
    {
        return [
            'level' => '主产品',
            'name' => (string) $product['name']."\n".(string) $product['category'],
            'sku' => (string) $product['sku'],
            'stores' => implode('、', $product['stores']),
            'sales' => $product['sales'],
            'revenue' => $product['revenue'],
            'cost' => $product['cost'],
            'fees' => (float) $product['shipping'] + (float) $product['handling'],
            'refunds' => $product['refunds'],
            'actual_refunds' => $product['actual_refunds'],
            'actual_refund_count' => $product['actual_refund_count'],
            'profit' => $product['profit'],
            'profit_rate' => $product['profit_rate'],
            'refund_rate' => $product['refund_rate'],
            'decision' => $this->decisionLabel((string) $product['decision']),
        ];
    }

    /** @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    private function variantRow(array $variant): array
    {
        return [
            'level' => '关联变体',
            'name' => (string) $variant['name']."\n".__('logistics.product_statistics.ui.ordered_variant'),
            'sku' => (string) $variant['sku'],
            'stores' => __('logistics.product_statistics.ui.ordered_variant'),
            'sales' => $variant['sales'],
            'revenue' => $variant['revenue'],
            'cost' => $variant['cost'],
            'fees' => (float) $variant['shipping'] + (float) $variant['handling'],
            'refunds' => $variant['refunds'],
            'actual_refunds' => $variant['actual_refunds'],
            'actual_refund_count' => $variant['actual_refund_count'],
            'profit' => $variant['profit'],
            'profit_rate' => $variant['profit_rate'],
            'refund_rate' => $variant['refund_rate'],
            'decision' => __('logistics.common.not_available'),
        ];
    }

    /** @param array<string, mixed> $row */
    private function writeRow(Worksheet $sheet, int $rowNumber, array $row, bool $isVariant): void
    {
        foreach (self::columns() as $columnIndex => [, $field, $type]) {
            $column = Coordinate::stringFromColumnIndex($columnIndex + 1);
            $cell = $column.$rowNumber;
            $value = $row[$field] ?? null;
            if (($type === self::TYPE_MONEY || $type === self::TYPE_RATE) && is_numeric($value)) {
                $sheet->setCellValue($cell, (float) $value);
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($type === self::TYPE_MONEY ? '"$"#,##0.00;[Red]-"$"#,##0.00' : '0.00"%"');
                continue;
            }
            $sheet->setCellValueExplicit($cell, $value === null || $value === '' ? __('logistics.common.not_available') : (string) $value, DataType::TYPE_STRING);
            if ($field === 'name' || $field === 'stores') {
                $sheet->getStyle($cell)->getAlignment()->setWrapText(true);
            }
        }
        $sheet->getRowDimension($rowNumber)->setRowHeight($isVariant ? 34 : 42);
        if ($isVariant) $sheet->getRowDimension($rowNumber)->setOutlineLevel(1);
    }

    private function styleSheet(Worksheet $sheet, string $lastColumn, int $lastRow): void
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        $sheet->setShowGridlines(false);
        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->setColor(new Color('2A5E8F'));
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F7FC');
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->setColor(new Color('DCE7F1'));
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getBorders()->getRight()->setBorderStyle(Border::BORDER_HAIR)->setColor(new Color('E5EDF5'));
        $sheet->getStyle("B2:B{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("C2:C{$lastRow}")->getFont()->setName('Consolas')->setSize(10);
    }

    private function styleParentRow(Worksheet $sheet, int $rowNumber, int $columnCount): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $range = "A{$rowNumber}:{$lastColumn}{$rowNumber}";
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FCFF');
        $sheet->getStyle($range)->getFont()->setBold(true)->setColor(new Color('294F76'));
        $sheet->getStyle("A{$rowNumber}")->getFont()->setColor(new Color('2D80C8'));
        $sheet->getStyle($range)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('BDD8F1'));
    }

    private function styleVariantRow(Worksheet $sheet, int $rowNumber, int $columnCount): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $range = "A{$rowNumber}:{$lastColumn}{$rowNumber}";
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCFDFF');
        $sheet->getStyle($range)->getFont()->setColor(new Color('647D96'));
        $sheet->getStyle("B{$rowNumber}")->getAlignment()->setIndent(1);
        $sheet->getStyle("A{$rowNumber}")->getFont()->setColor(new Color('7A9ABB'));
    }

    private function decisionLabel(string $decision): string
    {
        return [
            'continue' => __('logistics.product_statistics.decisions.continue'),
            'optimize' => __('logistics.product_statistics.decisions.optimize'),
            'watch' => __('logistics.product_statistics.decisions.watch'),
            'stop' => __('logistics.product_statistics.decisions.stop'),
        ][$decision] ?? __('logistics.product_statistics.decisions.watch');
    }
}
