<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use Illuminate\Database\Eloquent\Builder;
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

    public function __construct(private readonly StreamedXlsxWriter $streamedWriter)
    {
    }

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

    /** @return list<string> */
    public function requiredOrderColumns(): array
    {
        return array_values(array_unique(array_merge(
            ['id'],
            array_filter(
                array_column(self::columns(), 1),
                static fn (string $field): bool => !str_starts_with($field, 'import_refund_'),
            ),
        )));
    }

    public function exportResponse(Builder $orders): StreamedResponse
    {
        $columns = self::columns();

        return $this->streamedWriter->response(
            '物流订单_'.now('Asia/Shanghai')->format('Ymd-His').'.xlsx',
            '物流订单',
            array_map(static fn (array $column): array => [
                'label' => $column[0],
                'width' => $column[3],
            ], $columns),
            function () use ($orders, $columns): iterable {
                foreach ($orders->lazy(200) as $order) {
                    $maximumLines = 1;
                    $cells = array_map(function (array $column) use ($order, &$maximumLines): array {
                        [, $field, $type] = $column;
                        $value = $this->exportValue($order, $field);
                        if ($type === self::TYPE_MULTILINE) {
                            $maximumLines = max($maximumLines, substr_count((string) $value, "\n") + 1);
                        }

                        return [
                            'value' => $value,
                            'type' => $type === self::TYPE_MULTILINE ? self::TYPE_TEXT : $type,
                            'wrap' => $type === self::TYPE_MULTILINE,
                        ];
                    }, $columns);

                    yield [
                        'kind' => 'normal',
                        'height' => min(120, max(18, $maximumLines * 18)),
                        'cells' => $cells,
                    ];
                }
            },
        );
    }

    public function refundImportTemplateResponse(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(__('logistics.refund_import.template.sheet_name'));
            $headers = [
                __('logistics.refund_import.headers.payment_method'),
                __('logistics.refund_import.headers.reference_number'),
                __('logistics.refund_import.headers.refund_reference'),
                __('logistics.refund_import.headers.refund_reason'),
                __('logistics.refund_import.headers.refund_amount_usd'),
            ];
            $widths = [16, 28, 28, 36, 16];

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
            $instructions->setTitle(__('logistics.refund_import.template.instructions_sheet'));
            $instructionRows = [
                [__('logistics.refund_import.template.item'), __('logistics.refund_import.template.description')],
                [__('logistics.refund_import.headers.payment_method'), __('logistics.refund_import.template.payment_method')],
                [__('logistics.refund_import.headers.reference_number'), __('logistics.refund_import.template.reference_number')],
                [__('logistics.refund_import.headers.refund_reference'), __('logistics.refund_import.template.refund_reference')],
                [__('logistics.refund_import.headers.refund_reason'), __('logistics.refund_import.template.refund_reason')],
                [__('logistics.refund_import.headers.refund_amount_usd'), __('logistics.refund_import.template.refund_amount_usd')],
                [__('logistics.refund_import.template.overwrite_rule'), __('logistics.refund_import.template.overwrite_rule_description')],
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
        }, __('logistics.refund_import.template.filename'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param list<array<string,mixed>> $errors */
    public function refundImportErrorResponse(array $errors): StreamedResponse
    {
        return response()->streamDownload(function () use ($errors): void {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(__('logistics.refund_import.error_report.sheet_name'));
            $headers = [
                __('logistics.refund_import.headers.payment_method'),
                __('logistics.refund_import.headers.reference_number'),
                __('logistics.refund_import.headers.refund_reference'),
                __('logistics.refund_import.headers.refund_reason'),
                __('logistics.refund_import.headers.refund_amount_usd'),
                __('logistics.refund_import.error_report.row'),
                __('logistics.refund_import.error_report.field'),
                __('logistics.refund_import.error_report.reason'),
                __('logistics.refund_import.error_report.hint'),
            ];
            $widths = [16, 28, 28, 34, 16, 12, 18, 36, 54];
            foreach ($headers as $index => $header) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValueExplicit($column.'1', $header, DataType::TYPE_STRING);
                $sheet->getColumnDimension($column)->setWidth($widths[$index]);
            }
            foreach ($errors as $index => $error) {
                $values = [
                    $error['payment_method'] ?? '', $error['reference_number'] ?? '', $error['refund_reference'] ?? '',
                    $error['refund_reason'] ?? '', $error['refund_amount_usd'] ?? '', $error['row'] ?? '',
                    $error['field'] ?? '', $error['reason'] ?? '', $error['hint'] ?? '',
                ];
                foreach ($values as $columnIndex => $value) {
                    $sheet->setCellValueExplicit(
                        Coordinate::stringFromColumnIndex($columnIndex + 1).($index + 2),
                        (string) $value,
                        DataType::TYPE_STRING,
                    );
                }
            }
            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:{$lastColumn}1");
            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9534F');
            $sheet->getStyle("A1:{$lastColumn}".max(1, count($errors) + 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, __('logistics.refund_import.error_report.filename'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
