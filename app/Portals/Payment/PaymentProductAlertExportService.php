<?php

namespace App\Portals\Payment;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentProductAlertExportService
{
    /** @param list<array<string,mixed>> $rows */
    public function download(array $rows): StreamedResponse
    {
        $filename = __('payment.product_risk_profit.export.filename', [
            'time' => now('Asia/Shanghai')->format('Ymd_His'),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            $spreadsheet = $this->spreadsheet($rows);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function spreadsheet(array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(__('payment.product_risk_profit.export.sheet_name'));
        $sheet->setShowGridlines(false);
        $sheet->freezePane('A3');

        $sheet->mergeCells('A1:D1');
        $sheet->mergeCells('E1:L1');
        $sheet->mergeCells('M1:R1');
        $sheet->setCellValue('A1', __('payment.product_risk_profit.export.groups.product'));
        $sheet->setCellValue('E1', __('payment.product_risk_profit.export.groups.paypal'));
        $sheet->setCellValue('M1', __('payment.product_risk_profit.export.groups.credit_card'));
        $sheet->setCellValue('S1', __('payment.product_risk_profit.export.groups.result'));

        $headers = __('payment.product_risk_profit.export.columns');
        foreach (array_values($headers) as $index => $label) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'2', $label);
        }

        $rowNumber = 3;
        foreach ($rows as $row) {
            $values = [
                (string) $row['sku'],
                (string) $row['product_name'],
                (float) $row['total_sales'],
                (int) $row['total_order_count'],
                (float) $row['received_amount'],
                (int) $row['dispute_order_count'],
                (float) $row['dispute_amount'],
                (float) $row['dispute_rate'] / 100,
                (float) $row['dispute_amount_rate'] / 100,
                (float) $row['description_mismatch_amount'],
                (float) $row['description_mismatch_rate'] / 100,
                (float) $row['sku_dispute_amount_share'] / 100,
                (int) ($row['card_order_count'] ?? 0),
                (int) ($row['card_complaint_count'] ?? 0),
                (float) ($row['card_dispute_rate'] ?? 0) / 100,
                (int) ($row['card_dispute_count'] ?? 0),
                (float) ($row['card_dispute_rate'] ?? 0) / 100,
                (float) ($row['card_dispute_rate'] ?? 0) / 100,
                (bool) ($row['is_alert'] ?? false)
                    ? __('payment.product_risk_profit.export.results.alert')
                    : __('payment.product_risk_profit.export.results.normal'),
            ];

            foreach ($values as $index => $value) {
                $column = $index + 1;
                $coordinate = Coordinate::stringFromColumnIndex($column).$rowNumber;
                if ($column <= 2 || $column === 19) {
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
            if ((bool) ($row['export_dispute_alert'] ?? false)) {
                $sheet->getStyle('H'.$rowNumber)->getFont()->setBold(true)->getColor()->setRGB('C00000');
                $sheet->getStyle('H'.$rowNumber)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE4D6');
            }
            if ((bool) ($row['export_mismatch_alert'] ?? false)) {
                $sheet->getStyle('K'.$rowNumber)->getFont()->setBold(true)->getColor()->setRGB('C00000');
                $sheet->getStyle('K'.$rowNumber)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE4D6');
            }
            $rowNumber++;
        }

        $lastRow = max(2, $rowNumber - 1);
        $sheet->setAutoFilter("A2:S{$lastRow}");
        $sheet->getStyle("A1:S2")->getFont()->setBold(true)->getColor()->setRGB('17365D');
        $sheet->getStyle('A1:S1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');
        $sheet->getStyle('A2:S2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF2F8');
        $sheet->getStyle("A1:S{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9E2F3');
        $sheet->getStyle("A1:S{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A1:S2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        if ($lastRow >= 3) {
            $sheet->getStyle("C3:D{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("F3:F{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("M3:N{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("P3:P{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("E3:E{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $sheet->getStyle("G3:G{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $sheet->getStyle("J3:J{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $sheet->getStyle("H3:I{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle("K3:L{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle("O3:O{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle("Q3:R{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle("A3:S{$lastRow}")->getAlignment()->setWrapText(false);
        }

        foreach (['A' => 24, 'B' => 34, 'C' => 12, 'D' => 13, 'E' => 16, 'F' => 14, 'G' => 16, 'H' => 16, 'I' => 16, 'J' => 18, 'K' => 18, 'L' => 20, 'M' => 15, 'N' => 15, 'O' => 14, 'P' => 15, 'Q' => 15, 'R' => 14, 'S' => 12] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getRowDimension(2)->setRowHeight(36);
        $spreadsheet->getProperties()
            ->setTitle(__('payment.product_risk_profit.export.document_title'))
            ->setSubject(__('payment.product_risk_profit.export.document_subject'));

        return $spreadsheet;
    }
}
