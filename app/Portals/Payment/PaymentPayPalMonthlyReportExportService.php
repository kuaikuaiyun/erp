<?php

namespace App\Portals\Payment;

use App\Models\LogisticsPayPalAccount;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentPayPalMonthlyReportExportService
{
    public function __construct(private readonly PaymentPayPalMonthlyReportService $reports)
    {
    }

    public function download(LogisticsPayPalAccount $account, int $year, string $dimension): StreamedResponse
    {
        $dimension = $dimension === PaymentPayPalMonthlyReportService::DIMENSION_EVENT ? 'event' : 'payment';
        $filename = __('payment.account_risk.monthly_report.export.filename', [
            'account' => preg_replace('/[\\\/:*?"<>|]+/u', '_', (string) $account->account_name),
            'year' => $year,
            'dimension' => __('payment.account_risk.monthly_report.dimensions.'.$dimension),
        ]);

        return response()->streamDownload(function () use ($account, $year, $dimension): void {
            $data = $this->reports->exportData($account, $year, $dimension);
            $spreadsheet = $this->spreadsheet($account, $year, $dimension, $data);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    /** @param array{rows:list<array<string,mixed>>,details:list<array<string,mixed>>} $data */
    private function spreadsheet(LogisticsPayPalAccount $account, int $year, string $dimension, array $data): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle(__('payment.account_risk.monthly_report.export.summary_sheet'));
        $summary->setShowGridlines(false);
        $summary->freezePane('A4');
        $summary->mergeCells('A1:Q1');
        $summary->setCellValue('A1', __('payment.account_risk.monthly_report.export.title', [
            'account' => $account->account_name,
            'year' => $year,
            'dimension' => __('payment.account_risk.monthly_report.dimensions.'.$dimension),
        ]));
        $summary->mergeCells('A2:Q2');
        $summary->setCellValue('A2', __('payment.account_risk.monthly_report.export.formula_note'));
        $headers = array_values(__('payment.account_risk.monthly_report.export.summary_columns'));
        foreach ($headers as $index => $label) {
            $summary->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'3', $label);
        }
        $rowNumber = 4;
        foreach ($data['rows'] as $row) {
            $values = [
                $row['month'], $row['payment_order_count'], $row['received_amount'], $row['refund_order_count'],
                $row['refund_amount'], $row['refund_amount_rate'] / 100, $row['dispute_order_count'],
                $row['dispute_amount'], $row['dispute_order_rate'] / 100, $row['dispute_amount_rate'] / 100,
                $row['dispute_fee_amount'], $row['dispute_fee_rate'] / 100, $row['chargeback_fee_amount'],
                $row['actual_payout_amount'], $row['actual_payout_rate'] === null ? null : $row['actual_payout_rate'] / 100,
                __('payment.account_risk.monthly_report.coverage.'.$row['coverage']['status']),
                $row['coverage']['covered_days'].' / '.$row['coverage']['expected_days'],
            ];
            foreach ($values as $index => $value) {
                $summary->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$rowNumber, $value);
            }
            $rowNumber++;
        }
        $summaryLastRow = max(3, $rowNumber - 1);
        $summary->setAutoFilter("A3:Q{$summaryLastRow}");
        $summary->getStyle("A1:Q{$summaryLastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $summary->getStyle('A1:Q3')->getFont()->setBold(true)->getColor()->setRGB('17365D');
        $summary->getStyle('A1:Q1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');
        $summary->getStyle('A3:Q3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF2F8');
        $summary->getStyle("A3:Q{$summaryLastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9E2F3');
        $summary->getStyle('A1:Q3')->getAlignment()->setWrapText(true);
        if ($summaryLastRow >= 4) {
            $summary->getStyle("B4:B{$summaryLastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $summary->getStyle("D4:D{$summaryLastRow}")->getNumberFormat()->setFormatCode('#,##0');
            $summary->getStyle("G4:G{$summaryLastRow}")->getNumberFormat()->setFormatCode('#,##0');
            foreach (['C', 'E', 'H', 'K', 'M', 'N'] as $column) {
                $summary->getStyle("{$column}4:{$column}{$summaryLastRow}")->getNumberFormat()->setFormatCode('$#,##0.00;[Red]-$#,##0.00');
            }
            foreach (['F', 'I', 'J', 'L', 'O'] as $column) {
                $summary->getStyle("{$column}4:{$column}{$summaryLastRow}")->getNumberFormat()->setFormatCode('0.00%');
            }
        }
        foreach (range('A', 'Q') as $column) {
            $summary->getColumnDimension($column)->setWidth(in_array($column, ['A', 'P', 'Q'], true) ? 16 : 18);
        }

        $details = $spreadsheet->createSheet();
        $details->setTitle(__('payment.account_risk.monthly_report.export.detail_sheet'));
        $details->setShowGridlines(false);
        $details->freezePane('A2');
        $detailHeaders = array_values(__('payment.account_risk.monthly_report.export.detail_columns'));
        foreach ($detailHeaders as $index => $label) {
            $details->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $label);
        }
        $detailRow = 2;
        foreach ($data['details'] as $entry) {
            $values = [
                $entry['month'], __('payment.account_risk.monthly_report.detail_types.'.$entry['type']),
                $entry['event_code'], $entry['transaction_id'], $entry['reference_id'], $entry['amount'],
                $entry['happened_at'], $entry['status'], $entry['note'],
            ];
            foreach ($values as $index => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($index + 1).$detailRow;
                if (in_array($index, [2, 3, 4], true)) {
                    $details->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                } else {
                    $details->setCellValue($coordinate, $value);
                }
            }
            $detailRow++;
        }
        $detailLastRow = max(1, $detailRow - 1);
        $details->setAutoFilter("A1:I{$detailLastRow}");
        $details->getStyle('A1:I1')->getFont()->setBold(true)->getColor()->setRGB('17365D');
        $details->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF2F8');
        $details->getStyle("A1:I{$detailLastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9E2F3');
        $details->getStyle("A1:I{$detailLastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        if ($detailLastRow >= 2) {
            $details->getStyle("F2:F{$detailLastRow}")->getNumberFormat()->setFormatCode('$#,##0.00;[Red]-$#,##0.00');
        }
        foreach (['A' => 14, 'B' => 18, 'C' => 14, 'D' => 28, 'E' => 28, 'F' => 16, 'G' => 20, 'H' => 14, 'I' => 44] as $column => $width) {
            $details->getColumnDimension($column)->setWidth($width);
        }
        $spreadsheet->setActiveSheetIndex(0);
        $spreadsheet->getProperties()->setTitle((string) $summary->getCell('A1')->getValue());

        return $spreadsheet;
    }
}
