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
    public function downloadAlerts(array $rows): StreamedResponse
    {
        $filename = __('payment.product_alerts.export.filename', [
            'time' => now('Asia/Shanghai')->format('Ymd_His'),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            $spreadsheet = $this->alertSpreadsheet($rows);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function alertSpreadsheet(array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(__('payment.product_alerts.export.sheet_name'));
        $sheet->setShowGridlines(false);
        $sheet->freezePane('A2');

        $headers = array_values(__('payment.product_alerts.export.columns'));
        foreach ($headers as $index => $label) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $label);
        }

        $rowNumber = 2;
        foreach ($rows as $row) {
            $isPayPal = ($row['channel_type'] ?? '') === 'paypal';
            $values = [
                (string) ($row['sku'] ?? '-'),
                __('payment.product_alerts.channels.'.($row['channel_key'] ?? 'paypal')),
                (int) ($row['eligible_order_count'] ?? 0),
                (int) ($row['dispute_order_count'] ?? 0),
                (float) ($row['dispute_amount'] ?? 0),
                (float) ($row['dispute_rate'] ?? 0) / 100,
                $isPayPal ? (float) ($row['description_mismatch_rate'] ?? 0) / 100 : '',
                (float) ($row['delivered_dispute_rate'] ?? 0) / 100,
                __('payment.product_alerts.severities.'.($row['severity_key'] ?? 'warning')),
                __('payment.product_alerts.statuses.'.($row['workflow_status_key'] ?? 'pending')),
                __('payment.product_alerts.statuses.'.($row['status_key'] ?? 'pending')),
                (string) ($row['latest_remark'] ?? ''),
                (string) ($row['first_paid_at'] ?? '-'),
                (string) ($row['latest_paid_at'] ?? '-'),
            ];
            foreach ($values as $index => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($index + 1).$rowNumber;
                if (in_array($index + 1, [1, 2, 9, 10, 11, 12, 13, 14], true)) {
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
            $rowNumber++;
        }

        $lastRow = max(1, $rowNumber - 1);
        $sheet->setAutoFilter("A1:N{$lastRow}");
        $sheet->getStyle("A1:N1")->getFont()->setBold(true)->getColor()->setRGB('17365D');
        $sheet->getStyle("A1:N1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF2F8');
        $sheet->getStyle("A1:N{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9E2F3');
        $sheet->getStyle("A1:N{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        if ($lastRow >= 2) {
            $sheet->getStyle("C2:D{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("E2:E{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
            foreach (['F', 'G', 'H'] as $column) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
            }
        }
        foreach (range('A', 'N') as $column) {
            $sheet->getColumnDimension($column)->setWidth(18);
        }
        $sheet->getColumnDimension('A')->setWidth(26);
        $sheet->getColumnDimension('L')->setWidth(42);
        $sheet->getColumnDimension('M')->setWidth(20);
        $sheet->getColumnDimension('N')->setWidth(20);
        $sheet->getStyle("A1:N{$lastRow}")->getAlignment()->setWrapText(false);

        return $spreadsheet;
    }

    /** @param list<array<string,mixed>> $rows */
    private function spreadsheet(array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(__('payment.product_risk_profit.export.sheet_name'));
        $sheet->setShowGridlines(false);
        $sheet->freezePane('A3');

        $sheet->mergeCells('A1:E1');
        $sheet->mergeCells('F1:S1');
        $sheet->mergeCells('T1:AE1');
        $sheet->setCellValue('A1', __('payment.product_risk_profit.export.groups.product'));
        $sheet->setCellValue('F1', __('payment.product_risk_profit.export.groups.paypal'));
        $sheet->setCellValue('T1', __('payment.product_risk_profit.export.groups.credit_card'));
        $sheet->setCellValue('AF1', __('payment.product_risk_profit.export.groups.result'));

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
                (string) ($row['latest_paid_at'] ?? '-'),
                (float) $row['received_amount'],
                (int) $row['dispute_order_count'],
                (float) $row['dispute_amount'],
                (float) $row['dispute_rate'] / 100,
                (float) $row['dispute_amount_rate'] / 100,
                (float) $row['description_mismatch_amount'],
                (float) $row['description_mismatch_rate'] / 100,
                (float) $row['sku_dispute_amount_share'] / 100,
                (int) ($row['paypal_delivered_dispute_count'] ?? 0),
                (int) ($row['paypal_delivered_order_count'] ?? 0),
                (float) ($row['paypal_delivered_dispute_rate'] ?? 0) / 100,
                (float) ($row['paypal_initial_refund_amount'] ?? 0),
                (float) ($row['paypal_initial_received_amount'] ?? 0),
                (float) ($row['paypal_initial_refund_amount_rate'] ?? 0) / 100,
                (int) ($row['card_order_count'] ?? 0),
                (int) ($row['card_complaint_count'] ?? 0),
                (float) ($row['card_dispute_rate'] ?? 0) / 100,
                (int) ($row['card_dispute_count'] ?? 0),
                (float) ($row['card_dispute_rate'] ?? 0) / 100,
                (float) ($row['card_dispute_rate'] ?? 0) / 100,
                (int) ($row['credit_card_delivered_dispute_count'] ?? 0),
                (int) ($row['credit_card_delivered_order_count'] ?? 0),
                (float) ($row['credit_card_delivered_dispute_rate'] ?? 0) / 100,
                (float) ($row['credit_card_initial_refund_amount'] ?? 0),
                (float) ($row['credit_card_initial_received_amount'] ?? 0),
                (float) ($row['credit_card_initial_refund_amount_rate'] ?? 0) / 100,
                (bool) ($row['is_alert'] ?? false)
                    ? __('payment.product_risk_profit.export.results.alert')
                    : __('payment.product_risk_profit.export.results.normal'),
            ];

            foreach ($values as $index => $value) {
                $column = $index + 1;
                $coordinate = Coordinate::stringFromColumnIndex($column).$rowNumber;
                if ($column <= 2 || $column === 5 || $column === 32) {
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
            if ((bool) ($row['export_dispute_alert'] ?? false)) {
                $sheet->getStyle('I'.$rowNumber)->getFont()->setBold(true)->getColor()->setRGB('C00000');
                $sheet->getStyle('I'.$rowNumber)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE4D6');
            }
            if ((bool) ($row['export_mismatch_alert'] ?? false)) {
                $sheet->getStyle('L'.$rowNumber)->getFont()->setBold(true)->getColor()->setRGB('C00000');
                $sheet->getStyle('L'.$rowNumber)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE4D6');
            }
            $rowNumber++;
        }

        $lastRow = max(2, $rowNumber - 1);
        $sheet->setAutoFilter("A2:AF{$lastRow}");
        $sheet->getStyle("A1:AF2")->getFont()->setBold(true)->getColor()->setRGB('17365D');
        $sheet->getStyle('A1:AF1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');
        $sheet->getStyle('A2:AF2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF2F8');
        $sheet->getStyle("A1:AF{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9E2F3');
        $sheet->getStyle("A1:AF{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A1:AF2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        if ($lastRow >= 3) {
            $sheet->getStyle("C3:D{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            foreach (['G', 'N', 'O', 'T', 'U', 'W', 'Z', 'AA'] as $column) $sheet->getStyle("{$column}3:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            foreach (['F', 'H', 'K', 'Q', 'R', 'AC', 'AD'] as $column) $sheet->getStyle("{$column}3:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
            foreach (['I', 'J', 'L', 'M', 'P', 'S', 'V', 'X', 'Y', 'AB', 'AE'] as $column) $sheet->getStyle("{$column}3:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle("A3:AF{$lastRow}")->getAlignment()->setWrapText(false);
        }

        foreach (range('A', 'Z') as $column) $sheet->getColumnDimension($column)->setWidth(16);
        foreach (['AA', 'AB', 'AC', 'AD', 'AE', 'AF'] as $column) $sheet->getColumnDimension($column)->setWidth(16);
        foreach (['A' => 24, 'B' => 34, 'E' => 18, 'AF' => 14] as $column => $width) {
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
