<?php

namespace App\Portals\Finance;

use App\Models\FinanceRecord;
use App\Portals\Logistics\StreamedXlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceXuzongCreditReport
{
    public const DISPLAY_HEADERS = [
        '交易流水号' => '交易 ID',
        '交易币种' => '货币',
        '交易金额' => '总额',
        '支付状态' => '状态',
        '结算-美元' => FinancePayPalSettlementOrders::USD_RECEIPT_LABEL,
        '交易手续费' => FinanceSettlementService::MONEY['fee'],
        '争议处理费' => FinanceSettlementService::MONEY['dispute_fee'],
        '区域' => '地区',
    ];

    private ?\Closure $attribution = null;

    public function __construct(
        private readonly FinanceSettlementService $settlements,
        private readonly FinanceXuzongCreditMatches $matches,
    ) {}

    public static function headers(): array
    {
        // Keep the export IDs stable for saved column selections.
        $headers = FinanceCatalog::dataset('xuzong2-details')['headers'];
        $ordered = [];
        foreach ($headers as $id => $header) {
            $columns = match ($header) {
                '平台订单号' => [$id => $header, 43 => '交易流水号'],
                '结算-美元' => [$id => $header, 51 => FinanceSettlementService::SHOP_DIFFERENCE_LABEL, 49 => '汇率'],
                '结算金额' => [50 => '结算币种', $id => $header],
                '拒付RDR+CDRN费用' => [46 => 'RDR费用', 47 => 'CDRN费用', 44 => '拒付返还', 45 => '争议处理费'],
                '应结算' => [$id => '应结算（USD）'],
                '团队' => [$id => FinanceSettlementService::OPERATION_DEPARTMENT, 48 => '区域'],
                default => [$id => $header],
            };
            $ordered += $columns;
        }

        return array_map(fn ($header) => self::DISPLAY_HEADERS[$header] ?? $header, $ordered);
    }

    public function rows(Collection $records, string $timezone): Collection
    {
        if ($records->isEmpty()) {
            return collect();
        }

        $linked = $records->filter(fn ($record) => $record->dataset === FinanceLogisticsCreditOrders::IMPORTED_DATASET)
            ->mapWithKeys(fn ($record) => [$record->id => $record->payload['_linked_logistics_id'] ?? null])->filter();
        $orders = $this->settlements->liveCreditReportOrders($records->pluck('id')->filter(fn ($id) => $id > 0)->concat($linked)->unique());
        foreach ($linked as $id => $orderId) {
            if ($orders->has($orderId)) {
                $orders->put($id, $orders->get($orderId));
            }
        }
        $attribution = $this->attribution ??= $this->settlements->liveOrderAttributionResolver();
        $matched = $this->matches->forOrders($records, $orders);

        return $records->mapWithKeys(function (FinanceRecord $record) use ($orders, $attribution, $timezone, $matched): array {
            $order = $orders->get($record->id);
            $bill = $matched->get($record->id, []);
            $payment = $bill['_statement_payment'] ?? FinanceLogisticsCreditOrders::actualPayment($record->payment_transactions);
            $imported = $record->dataset === FinanceLogisticsCreditOrders::IMPORTED_DATASET;
            if ($imported) {
                $payment = ['currency' => $record->currency ?? '', 'amount' => $record->payload['交易金额'] ?? ''];
            }
            $values = $this->values($record, $order, $attribution($record, $order), $timezone, $payment);
            $matches = array_diff_key($bill, array_filter($values, fn ($value) => filled($value)));
            if (! filled($matches['商户订单号'] ?? null)) {
                $shopify = json_decode($order->shopify_payload ?? '', true) ?? [];
                $attributes = isset($shopify['customAttributes'])
                    ? array_column($shopify['customAttributes'], 'value', 'key')
                    : array_column($shopify['note_attributes'] ?? [], 'value', 'name');
                $matches['商户订单号'] = trim((string) ($attributes['yx_order_id'] ?? $record->payload['_legacy_order_id'] ?? ''));
            }

            $row = array_replace($values, $matches);
            if ($imported) {
                $row = array_replace($row, array_filter(array_intersect_key($record->payload, array_flip([
                    '商户名称', '商户订单号', '交易流水号', '订单币种', '订单金额', '支付方式', '是否3D',
                    '发卡国家', '卡号', '消费者邮箱', '通途订单',
                ])), fn ($value) => filled($value)));
                $row['创建时间'] = $record->occurred_at?->setTimezone($timezone)->format('Y-m-d H:i:s') ?? '';
                $row['平台订单号'] = ($record->payload['_platform_missing'] ?? false) ? ($order->payment_gateway_order_id ?? '') : $record->reference;
                $completed = $record->payload['完成时间'] ?? '';
                if ($completed !== '') {
                    $row['完成时间'] = CarbonImmutable::parse($completed, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s');
                }
            }
            $row['商户号'] = $row['通途订单'] ?? '';
            $exchange = $matches['_usd_exchange'] ?? null;
            if (($exchange['currency'] ?? null) !== $payment['currency']) {
                $exchange = $payment['usd_exchange'] ?? null;
            }
            $this->applyUsdSettlement($row, $record->status, $exchange);
            $row[FinanceSettlementService::SHOP_DIFFERENCE_LABEL] = $this->settlements->shopDifference(
                $row['结算-美元'], $order->paid_amount_usd ?? null,
            );
            $settlementCurrency = $row['结算币种'];
            $row['应结算（USD）'] = $settlementCurrency === 'USD' || ($settlementCurrency === '' && $payment['currency'] === 'USD')
                ? ($row['应结算'] ?? '') : '';
            unset($row['_usd_exchange'], $row['_statement_payment'], $row['应结算']);
            foreach (self::DISPLAY_HEADERS as $field => $label) {
                $row[$label] = $row[$field] ?? '';
            }

            return [$record->id => $row];
        });
    }

    private function applyUsdSettlement(array &$row, ?string $status, ?array $exchange): void
    {
        $row['汇率'] = $row['结算-美元'] = '';
        if (FinanceSettlementService::transactionStatusCategory($status) === 'failed') {
            $note = trim((string) ($row['备注'] ?? ''));
            $row['备注'] = $note === '' ? '失败'
                : ($note === '失败' || str_starts_with($note, '失败；') ? $note : '失败；'.$note);

            return;
        }
        if (! FinanceSettlementService::isSuccessfulTransaction($status) || $row['交易金额'] === '') {
            return;
        }

        $currency = $row['交易币种'];
        $amount = $this->settlements->number($row['交易金额']);
        if ($currency === 'USD') {
            $row['汇率'] = '1';
            $row['结算-美元'] = $row['交易金额'];
        } elseif ($currency !== '' && $amount !== null && $exchange !== null && $exchange['currency'] === $currency) {
            $row['汇率'] = bcdiv($exchange['usd'], $exchange['original'], 8);
            $row['结算-美元'] = bcdiv(bcmul($amount, $exchange['usd'], 16), $exchange['original'], 8);
        }
    }

    private function values(FinanceRecord $record, ?object $order, array $attribution, string $timezone, array $payment): array
    {
        $payload = $record->payload;
        $time = $record->occurred_at?->setTimezone($timezone);
        $date = static fn ($value) => $value ? CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s') : '';
        $success = FinanceSettlementService::isSuccessfulTransaction($record->status);
        ['currency' => $currency, 'amount' => $amount] = $payment;
        $subscription = filled($payload['订阅类型'] ?? null) ? $payload['订阅类型'] : ($order->subscription_type ?? '');
        $upsell = filled($payload['是否加购'] ?? null) ? $payload['是否加购'] : ($order->is_add_on ?? '');

        return [
            '商户名称' => $payload['收款账户'] ?? $record->account,
            '平台订单号' => $record->reference ?? '',
            '交易流水号' => $order->bill_number ?? ($payload['_bill_number'] ?? ''),
            '订单币种' => $payload['店铺币种'] ?? '',
            '订单金额' => $payload['店铺订单金额'] ?? '',
            '交易币种' => $currency,
            '交易金额' => $amount,
            '支付状态' => $success ? 'SUCCESS' : $record->status,
            '支付方式' => $payload['支付方式'] ?? '',
            '消费者邮箱' => $order->buyer_email ?? ($payload['_buyer_email'] ?? ''),
            '创建时间' => $date($payload['创建时间'] ?? null),
            '完成时间' => $date($payload['支付时间'] ?? null),
            '年月' => $time?->format('Y-m'),
            '时间点' => $time?->format('Y-m-d H:i:s'),
            '导出时区' => $time === null ? '' : $time->getOffset() / 3600,
            '订阅类型' => FinanceSettlementService::subscriptionLabel($subscription),
            '是否加购' => $upsell === '' ? '' : (in_array(strtolower((string) $upsell), ['1', 'true', 'yes', '是', '加购', '已加购'], true) ? '加购' : '未加购'),
            '域名' => $record->domain,
            '使用人' => $attribution['owner'],
            FinanceSettlementService::OPERATION_DEPARTMENT => $attribution['department'],
            '区域' => FinanceSettlementService::operationRegion($attribution['department']),
            '通途订单' => $payload['通途订单'] ?? '',
            '备注' => $payload['备注'] ?? '',
        ];
    }

    public static function downloadXlsx(array $headers, iterable $rows, string $filename): StreamedResponse
    {
        $columns = array_map(static fn ($header) => [
            'label' => $header,
            'width' => match ($header) {
                '商户订单号', '平台订单号', '交易 ID', '消费者邮箱', '备注' => 32,
                '创建时间', '完成时间', '时间点', '域名', '通途订单' => 23,
                default => max(11, mb_strwidth($header) + 5),
            },
            'headerFill' => match ($header) {
                '商户订单号' => 'F5B482',
                '域名', '通途订单' => 'FFFF00',
                '年月', '时间点' => 'FFC000',
                '订阅类型' => 'EF949E',
                '应结算（USD）' => 'EE822F',
                default => '',
            },
        ], $headers);

        return app(StreamedXlsxWriter::class)->response($filename, '徐总支付2明细表', $columns,
            function () use ($headers, $rows): iterable {
                foreach ($rows as $row) {
                    $cells = [];
                    foreach ($row as $index => $value) {
                        $header = $headers[$index];
                        $field = array_search($header, self::DISPLAY_HEADERS, true) ?: $header;
                        if ($value !== '' && in_array($header, ['创建时间', '完成时间', '时间点'], true)) {
                            $cells[] = ['value' => Date::PHPToExcel(CarbonImmutable::parse($value, 'UTC')), 'type' => 'datetime'];
                        } elseif (is_numeric($value) && (FinanceXuzongCreditMatches::isAmount($field) || in_array($field, ['订单金额', '交易金额', '结算-美元', '应结算（USD）', '汇率', '导出时区', FinanceSettlementService::SHOP_DIFFERENCE_LABEL], true))) {
                            $cells[] = ['value' => $value, 'type' => 'number'];
                        } else {
                            $cells[] = ['value' => $value];
                        }
                    }
                    yield ['cells' => $cells];
                }
            }, plain: true, fontName: '宋体');
    }
}
