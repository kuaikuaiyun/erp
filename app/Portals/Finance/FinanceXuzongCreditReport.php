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
    private ?\Closure $attribution = null;

    public function __construct(
        private readonly FinanceSettlementService $settlements,
        private readonly FinanceXuzongCreditMatches $matches,
    ) {}

    public static function headers(): array
    {
        return FinanceCatalog::dataset('xuzong2-details')['headers'];
    }

    public function rows(Collection $records, string $timezone): Collection
    {
        if ($records->isEmpty()) {
            return collect();
        }

        $orders = $this->settlements->liveCreditReportOrders($records->pluck('id'));
        $attribution = $this->attribution ??= $this->settlements->liveOrderAttributionResolver();
        $matched = $this->matches->forOrders($records, $orders);

        return $records->mapWithKeys(function (FinanceRecord $record) use ($orders, $attribution, $timezone, $matched): array {
            $order = $orders->get($record->id);
            $values = $this->values($record, $order, $attribution($record, $order), $timezone);
            $matches = array_diff_key($matched->get($record->id, []), array_filter($values, fn ($value) => filled($value)));

            return [$record->id => array_replace($values, $matches)];
        });
    }

    private function values(FinanceRecord $record, ?object $order, array $attribution, string $timezone): array
    {
        $payload = $record->payload;
        $time = $record->occurred_at?->setTimezone($timezone);
        $date = static fn ($value) => $value ? CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s') : '';
        $success = FinanceSettlementService::isSuccessfulTransaction($record->status);
        $currency = $payload['交易币种'] ?? '';
        $amount = $payload['交易金额'] ?? '';
        $upsell = $payload['是否加购'] ?? '';

        return [
            '商户名称' => $record->account,
            '商户订单号' => $payload['Shopify订单号'] ?? '',
            '平台订单号' => FinanceXuzongCreditMatches::reference($order),
            '订单币种' => $payload['店铺币种'] ?? '',
            '订单金额' => $payload['店铺订单金额'] ?? '',
            '交易币种' => $currency,
            '交易金额' => $amount,
            '支付状态' => $success ? 'SUCCESS' : $record->status,
            '支付方式' => $payload['支付方式'] ?? '',
            '消费者邮箱' => $order->buyer_email ?? '',
            '创建时间' => $date($payload['创建时间'] ?? null),
            '完成时间' => $date($payload['支付时间'] ?? null),
            '年月' => $time?->format('Y-m'),
            '时间点' => $time?->format('Y-m-d H:i:s'),
            '导出时区' => $time === null ? '' : $time->getOffset() / 3600,
            '订阅类型' => FinanceSettlementService::subscriptionLabel($payload['订阅类型'] ?? ''),
            '是否加购' => $upsell === '' ? '' : (in_array(strtolower((string) $upsell), ['1', 'true', 'yes', '是', '加购', '已加购'], true) ? '加购' : '未加购'),
            '结算-美元' => $success && $currency === 'USD' ? $amount : '',
            '域名' => $record->domain,
            '使用人' => $attribution['owner'],
            '团队' => $attribution['department'],
            '通途订单' => $payload['通途订单'] ?? '',
            '备注' => $success && $currency !== '' && $currency !== 'USD' ? '交易币种为'.$currency.'，请核对美元结算' : '',
        ];
    }

    public static function downloadXlsx(array $headers, iterable $rows, string $filename): StreamedResponse
    {
        $columns = array_map(static fn ($header) => [
            'label' => $header,
            'width' => match ($header) {
                '商户订单号', '平台订单号', '消费者邮箱', '备注' => 32,
                '创建时间', '完成时间', '时间点', '域名', '通途订单' => 23,
                default => max(11, mb_strwidth($header) + 5),
            },
            'headerFill' => match ($header) {
                '商户订单号' => 'F5B482',
                '域名', '通途订单' => 'FFFF00',
                '年月', '时间点' => 'FFC000',
                '订阅类型' => 'EF949E',
                '应结算' => 'EE822F',
                default => '',
            },
        ], $headers);

        return app(StreamedXlsxWriter::class)->response($filename, '徐总支付2明细表', $columns,
            function () use ($headers, $rows): iterable {
                foreach ($rows as $row) {
                    $cells = [];
                    foreach ($row as $index => $value) {
                        $header = $headers[$index];
                        if ($value !== '' && in_array($header, ['创建时间', '完成时间', '时间点'], true)) {
                            $cells[] = ['value' => Date::PHPToExcel(CarbonImmutable::parse($value, 'UTC')), 'type' => 'datetime'];
                        } elseif (is_numeric($value) && (FinanceXuzongCreditMatches::isAmount($header) || in_array($header, ['订单金额', '交易金额', '结算-美元', '导出时区'], true))) {
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
