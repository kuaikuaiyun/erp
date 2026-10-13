<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use DateTimeInterface;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogisticsOrderSpreadsheetService
{
    private const TYPE_TEXT = 'text';

    private const TYPE_MONEY = 'money';

    private const TYPE_RATE = 'rate';

    private const TYPE_MULTILINE = 'multiline';

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: float|int}>
     */
    private static function columns(): array
    {
        return [
            ['编号', 'id', self::TYPE_TEXT, 12],
            ['Shopify订单号', 'shopify_order_number', self::TYPE_TEXT, 16],
            ['通途订单号', 'tongtu_order_number', self::TYPE_TEXT, 24],
            ['本订单ID', 'shopify_order_id', self::TYPE_TEXT, 24],
            ['店铺名称', 'store_name', self::TYPE_TEXT, 18],
            ['来源Shopify域名', 'source_shop_domain', self::TYPE_TEXT, 28],
            ['所属部门', 'department_name', self::TYPE_TEXT, 18],
            ['运营人员', 'operator_name', self::TYPE_TEXT, 18],
            ['所属组', 'group_names', self::TYPE_TEXT, 22],
            ['是否加购', 'is_add_on', self::TYPE_TEXT, 14],
            ['订阅类型', 'subscription_type', self::TYPE_TEXT, 14],
            ['订阅复购原订单ID', 'subscription_origin_order_id', self::TYPE_TEXT, 24],
            ['订阅状态', 'subscription_status', self::TYPE_TEXT, 14],
            ['通途SKU', 'tongtu_sku', self::TYPE_MULTILINE, 30],
            ['通途配货名称', 'tongtu_product_name', self::TYPE_MULTILINE, 34],
            ['出单数量', 'sale_quantity', self::TYPE_MULTILINE, 14],
            ['出单产品重量', 'sale_product_weight', self::TYPE_MULTILINE, 18],
            ['支付状态', 'payment_status', self::TYPE_TEXT, 14],
            ['支付方式', 'payment_method', self::TYPE_TEXT, 18],
            ['支付渠道', 'payment_gateway', self::TYPE_TEXT, 18],
            ['实付金额（USD）', 'paid_amount_usd', self::TYPE_MONEY, 16],
            ['付款时间', 'paid_at', self::TYPE_TEXT, 20],
            ['货品成本（USD）', 'product_cost_usd', self::TYPE_MONEY, 18],
            ['手续费（USD）', 'handling_fee_usd', self::TYPE_MONEY, 16],
            ['总运费（USD）', 'total_shipping_usd', self::TYPE_MONEY, 16],
            ['比例退款（USD）', 'proportional_refund_usd', self::TYPE_MONEY, 18],
            ['比例利润（USD）', 'proportional_profit_usd', self::TYPE_MONEY, 18],
            ['比例利润率', 'proportional_profit_rate', self::TYPE_RATE, 14],
            ['财务利润（USD）', 'financial_profit_usd', self::TYPE_MONEY, 18],
            ['财务利润率', 'financial_profit_rate', self::TYPE_RATE, 14],
            ['运营利润（USD）', 'operation_profit_usd', self::TYPE_MONEY, 18],
            ['运营利润率', 'operation_profit_rate', self::TYPE_RATE, 14],
            ['渠道退款额（USD）', 'channel_refund_amount_usd', self::TYPE_MONEY, 20],
            ['Shopify店铺退款额（USD）', 'shopify_refund_amount_usd', self::TYPE_MONEY, 24],
            ['退款时间', 'refunded_at', self::TYPE_TEXT, 20],
            ['退款原因', 'refund_reason', self::TYPE_TEXT, 28],
            ['退款单号', 'import_refund_number', self::TYPE_MULTILINE, 24],
            ['退款金额（USD）', 'import_refund_amount_usd', self::TYPE_MONEY, 18],
            ['退款类型', 'import_refund_type', self::TYPE_MULTILINE, 20],
            ['导入退款原因', 'import_refund_reason', self::TYPE_MULTILINE, 28],
            ['退款备注', 'import_refund_remark', self::TYPE_MULTILINE, 28],
            ['收款账号', 'collection_account', self::TYPE_TEXT, 30],
            ['收款账号邮箱', 'collection_account_email', self::TYPE_TEXT, 28],
            ['收款账号实收金额（USD）', 'received_amount_usd', self::TYPE_MONEY, 24],
            ['平台费用（USD）', 'platform_fee_usd', self::TYPE_MONEY, 18],
            ['净额（USD）', 'net_amount_usd', self::TYPE_MONEY, 16],
            ['交易号', 'transaction_id', self::TYPE_TEXT, 26],
            ['账单号', 'bill_number', self::TYPE_TEXT, 24],
            ['买家名称', 'buyer_name', self::TYPE_TEXT, 20],
            ['Email', 'buyer_email', self::TYPE_TEXT, 28],
            ['目的地', 'destination', self::TYPE_TEXT, 32],
            ['物流公司', 'shipping_company', self::TYPE_TEXT, 20],
            ['跟踪单号', 'tracking_number', self::TYPE_MULTILINE, 26],
            ['物流状态', 'logistics_status', self::TYPE_TEXT, 16],
            ['订单创建时间', 'order_created_at', self::TYPE_TEXT, 20],
            ['数据状态', 'data_status', self::TYPE_TEXT, 20],
        ];
    }

    /**
     * @param Collection<int, LogisticsOrder> $orders
     */
    public function exportResponse(Collection $orders): StreamedResponse
    {
        return response()->streamDownload(function () use ($orders): void {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('物流订单');
            $columns = self::columns();

            foreach ($columns as $columnIndex => [$label, $field, $type, $width]) {
                $columnName = Coordinate::stringFromColumnIndex($columnIndex + 1);
                $sheet->setCellValueExplicit($columnName.'1', $label, DataType::TYPE_STRING);
                $sheet->getColumnDimension($columnName)->setWidth($width);
            }

            $rowNumber = 2;
            foreach ($orders as $order) {
                foreach ($columns as $columnIndex => [, $field, $type]) {
                    $columnName = Coordinate::stringFromColumnIndex($columnIndex + 1);
                    $cell = $columnName.$rowNumber;
                    $this->writeValue($sheet, $cell, $this->exportValue($order, $field), $type);
                }

                $sheet->getRowDimension($rowNumber)->setRowHeight(-1);
                $rowNumber++;
            }

            $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
            $lastRow = max(1, $rowNumber - 1);
            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
            $sheet->getRowDimension(1)->setRowHeight(24);
            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setRGB('2D4F73');
            $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF2F8');
            $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, '物流订单_'.now('Asia/Shanghai')->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function refundImportTemplateResponse(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('退款订单导入模板');
            $headers = ['退款单号', '退款金额', '退款类型', '退款原因', '备注'];
            $widths = [26, 16, 18, 32, 32];

            foreach ($headers as $index => $header) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValueExplicit($column.'1', $header, DataType::TYPE_STRING);
                $sheet->getColumnDimension($column)->setWidth($widths[$index]);
            }

            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:{$lastColumn}1");
            $sheet->getRowDimension(1)->setRowHeight(26);
            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F80C9');
            $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);

            $instructions = $spreadsheet->createSheet();
            $instructions->setTitle('填写说明');
            $instructionRows = [
                ['项目', '说明'],
                ['退款单号', '必填；用于匹配物流订单中的账单号。'],
                ['退款金额', '必填；请填写已换算为 USD 的数值，可包含小数。'],
                ['退款类型', '可选；保留原始业务分类。'],
                ['退款原因', '可选；填写实际退款原因。'],
                ['备注', '可选；填写需要补充的信息。'],
                ['重复规则', '完全相同的退款明细会自动跳过；同一退款单号允许多次退款并累计金额。'],
                ['利润规则', '本次导入的实际退款金额暂不参与利润计算。'],
            ];
            foreach ($instructionRows as $rowIndex => $row) {
                $excelRow = $rowIndex + 1;
                $instructions->setCellValueExplicit('A'.$excelRow, $row[0], DataType::TYPE_STRING);
                $instructions->setCellValueExplicit('B'.$excelRow, $row[1], DataType::TYPE_STRING);
            }
            $instructions->getColumnDimension('A')->setWidth(18);
            $instructions->getColumnDimension('B')->setWidth(72);
            $instructions->getStyle('A1:B1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $instructions->getStyle('A1:B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F80C9');
            $instructions->getStyle('A1:B'.count($instructionRows))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $spreadsheet->setActiveSheetIndex(0);

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, '退款订单导入模板.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function writeValue(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, mixed $value, string $type): void
    {
        if ($type === self::TYPE_MONEY || $type === self::TYPE_RATE) {
            if ($value === null || $value === '' || !is_numeric($value)) {
                $sheet->setCellValueExplicit($cell, '', DataType::TYPE_STRING);

                return;
            }

            $sheet->setCellValue($cell, (float) $value);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(
                $type === self::TYPE_MONEY ? '"$"#,##0.00;[Red]-"$"#,##0.00' : '0.00"%"',
            );

            return;
        }

        $sheet->setCellValueExplicit($cell, $this->displayValue($value), DataType::TYPE_STRING);

        if ($type === self::TYPE_MULTILINE) {
            $sheet->getStyle($cell)->getAlignment()->setWrapText(true);
        }
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            if (method_exists($value, 'timezone')) {
                return $value->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');
            }

            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function exportValue(LogisticsOrder $order, string $field): mixed
    {
        $refunds = $order->relationLoaded('activeRefundDetails') ? $order->activeRefundDetails : collect();

        return match ($field) {
            'import_refund_number' => $refunds->pluck('refund_number')->filter()->implode("\n"),
            'import_refund_amount_usd' => $refunds->isEmpty() ? null : $refunds->sum(fn ($refund): float => (float) $refund->refund_amount_usd),
            'import_refund_type' => $refunds->pluck('refund_type')->filter()->implode("\n"),
            'import_refund_reason' => $refunds->pluck('refund_reason')->filter()->implode("\n"),
            'import_refund_remark' => $refunds->pluck('remark')->filter()->implode("\n"),
            default => $order->getAttribute($field),
        };
    }
}
