<?php

namespace App\Portals\Payment;

use App\Portals\Logistics\StreamedXlsxWriter;
use Illuminate\Support\Facades\Log;
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
    public function __construct(private readonly StreamedXlsxWriter $writer = new StreamedXlsxWriter())
    {
    }

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

    /** @param iterable<array<string,mixed>> $products */
    public function downloadRiskList(iterable $products): StreamedResponse
    {
        $prefix = 'payment.product_risk_profit.';
        $filename = __($prefix.'layout.export_filename', [
            'time' => now('Asia/Shanghai')->format('Ymd_His'),
        ]);
        $columnLabels = [
            'display_name' => 'sort.product_name',
            'pcl' => 'layout.product_pcl',
            'latest_paid_at' => 'layout.latest_paid',
            'order_count' => 'compact.order_count',
            'revenue' => 'compact.sales_amount',
            'combined_refund_rate' => 'table.combined_refund',
            'combined_initial_refund_amount_rate' => 'layout.combined_initial_refund_amount_rate',
            'paypal_refund_rate' => 'compact.refund_rate',
            'paypal_dispute_rate' => 'compact.dispute_rate',
            'paypal_description_mismatch_rate' => 'compact.description_mismatch_rate',
            'paypal_initial_dispute_rate' => 'compact.initial_dispute_rate',
            'paypal_delivered_dispute_rate' => 'compact.delivered_dispute_rate',
            'paypal_initial_refund_amount_rate' => 'compact.initial_refund_amount_rate',
            'credit_card_refund_rate' => 'compact.refund_rate',
            'credit_card_dispute_rate' => 'compact.dispute_rate',
            'credit_card_delivered_dispute_rate' => 'compact.delivered_dispute_rate',
            'credit_card_initial_refund_amount_rate' => 'compact.initial_refund_amount_rate',
        ];

        $columns = [];
        $index = 0;
        foreach ($columnLabels as $label) {
            [$group, $fill] = match (true) {
                $index < 7 => ['product_info', 'E3EFF8'],
                $index < 13 => ['paypal_fee_info', 'D7E8FA'],
                default => ['card_fee_info', 'E8DDF8'],
            };
            $columns[] = [
                'label' => __($prefix.$label),
                'width' => $index === 0 ? 34 : 25,
                'group' => __($prefix.'table.'.$group),
                'headerFill' => $fill,
            ];
            $index++;
        }

        $rowsWritten = 0;
        $dataElapsed = 0;
        $keys = array_keys($columnLabels);
        $rows = function () use ($products, $keys, &$rowsWritten, &$dataElapsed): \Generator {
            $readStarted = hrtime(true);
            try {
                foreach ($products as $product) {
                    $dataElapsed += hrtime(true) - $readStarted;
                    $readStarted = null;
                    yield $this->riskListRow($product, $keys);
                    $rowsWritten++;
                    $readStarted = hrtime(true);
                }
            } finally {
                if ($readStarted !== null) {
                    $dataElapsed += hrtime(true) - $readStarted;
                }
            }
        };

        $response = $this->writer->response($filename, __($prefix.'export.sheet_name'), $columns, $rows,
            streamDirectly: true, compressionLevel: 3);
        $write = $response->getCallback();
        $response->setCallback(static function () use ($write, &$rowsWritten, &$dataElapsed): void {
            $started = hrtime(true);
            $completed = false;
            try {
                $write();
                $completed = true;
            } finally {
                $elapsed = hrtime(true) - $started;
                Log::log($completed ? 'info' : 'warning', 'Payment product risk export.', [
                    'completed' => $completed,
                    'rows' => $rowsWritten,
                    'data_ms' => round($dataElapsed / 1_000_000, 2),
                    'xlsx_ms' => round(max(0, $elapsed - $dataElapsed) / 1_000_000, 2),
                    'total_ms' => round($elapsed / 1_000_000, 2),
                ]);
            }
        });

        return $response;
    }

    /** @param list<string> $keys */
    private function riskListRow(array $product, array $keys): array
    {
        $product['display_name'] = trim((string) $product['tongtu_product_name']) !== ''
            ? $product['tongtu_product_name']
            : (trim((string) $product['source_product_name']) !== '' ? $product['source_product_name'] : $product['name']);
        $product['pcl'] = $product['product_code'] ?: $product['sku'];

        $cells = [];
        foreach ($keys as $index => $key) {
            $cells[] = match (true) {
                $index < 3 => ['value' => (string) $product[$key]],
                $index === 3 => ['value' => (int) $product[$key], 'type' => 'quantity'],
                $index === 4 => ['value' => round((float) $product[$key], 2), 'type' => 'money'],
                default => ['value' => round((float) $product[$key], 2) / 100, 'type' => 'percentage'],
            };
        }

        return ['cells' => $cells];
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
        $sheet->mergeCells('T1:AC1');
        $sheet->setCellValue('A1', __('payment.product_risk_profit.export.groups.product'));
        $sheet->setCellValue('F1', __('payment.product_risk_profit.export.groups.paypal'));
        $sheet->setCellValue('T1', __('payment.product_risk_profit.export.groups.credit_card'));
        $sheet->setCellValue('AD1', __('payment.product_risk_profit.export.groups.result'));

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
                if ($column <= 2 || $column === 5 || $column === 30) {
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
        $sheet->setAutoFilter("A2:AD{$lastRow}");
        $sheet->getStyle('A1:AD2')->getFont()->setBold(true)->getColor()->setRGB('17365D');
        foreach ([
            ['A1:E1', 'D9EAF7'], ['F1:S1', 'CFE8FF'], ['T1:AC1', 'E8DDF8'], ['AD1:AD1', 'FFF2CC'],
            ['A2:E2', 'EAF3F8'], ['F2:S2', 'E7F3FF'], ['T2:AC2', 'F2ECFA'], ['AD2:AD2', 'FFF8E1'],
        ] as [$range, $colour]) {
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colour);
        }
        $sheet->getStyle('F1:S1')->getFont()->getColor()->setRGB('1F4E78');
        $sheet->getStyle('T1:AC1')->getFont()->getColor()->setRGB('7030A0');
        $sheet->getStyle('AD1:AD2')->getFont()->getColor()->setRGB('9C6500');
        $sheet->getStyle("A1:AD{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9E2F3');
        $sheet->getStyle("A1:AD{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A1:AD2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        if ($lastRow >= 3) {
            $sheet->getStyle("C3:D{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            foreach (['G', 'N', 'O', 'T', 'U', 'W', 'X', 'Y'] as $column) $sheet->getStyle("{$column}3:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            foreach (['F', 'H', 'K', 'Q', 'R', 'AA', 'AB'] as $column) $sheet->getStyle("{$column}3:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
            foreach (['I', 'J', 'L', 'M', 'P', 'S', 'V', 'Z', 'AC'] as $column) $sheet->getStyle("{$column}3:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle("A3:AD{$lastRow}")->getAlignment()->setWrapText(false);
        }

        foreach (range('A', 'Z') as $column) $sheet->getColumnDimension($column)->setWidth(16);
        foreach (['AA', 'AB', 'AC', 'AD'] as $column) $sheet->getColumnDimension($column)->setWidth(16);
        foreach (['A' => 24, 'B' => 34, 'E' => 18, 'AD' => 14] as $column => $width) {
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
