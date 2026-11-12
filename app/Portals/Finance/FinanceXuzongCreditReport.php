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
    public const PLATFORM_RESULT = '平台返回结果';
    public const PLATFORM_CODE = '平台返回code';
    public const PLATFORM_MESSAGE = '平台返回message';

    private const PLATFORM_RESULTS = [
        '50001' => ['pick up card', '发卡行要求收回卡片'],
        '50002' => ['lost card', '卡片已挂失'],
        '50003' => ['stolen card', '卡片被盗'],
        '50004' => ['closed account', '账户已关闭'],
        '50005' => ['invalid card number', '卡号无效'],
        '50006' => ['invalid cam, dcvv, icvv, cvv or cvv2', '卡片安全验证码无效'],
        '50007' => ['expired card', '卡片已过期'],
        '50008' => ['insufficient funds', '余额不足'],
        '50010' => ['no completed 3d secure', '未完成 3D 安全验证'],
        '50011' => ['refer to card issuer', '请联系发卡行'],
        '50012' => ['do not honor', '发卡行拒绝交易'],
        '50013' => ['transaction not permitted to cardholder', '持卡人不允许进行此类交易'],
        '50014' => ['suspected fraud', '疑似欺诈'],
        '50015' => ['fraud/security related reasons', '因欺诈或安全原因被拒绝'],
        '50016' => ['processer declind by high risk', '支付处理机构因高风险拒绝交易'],
        '50017' => ['failed', '支付失败'],
        '60001' => ['blacklist rejected', '命中黑名单，交易被拒绝'],
        '60002' => ['risk rule blocked', '触发风控规则，交易被拦截'],
        '3DS_INTERCEPTED' => ['3ds challenge intercepted', '3D 安全验证被拦截'],
        'DECLINED' => ['declined', '交易被拒绝'],
    ];

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
        private readonly FinanceXuzongOrderImport $orderImport,
    ) {}

    public static function headers(): array
    {
        // Keep the export IDs stable for saved column selections.
        $headers = FinanceCatalog::dataset('xuzong2-details')['headers'];
        $ordered = [];
        foreach ($headers as $id => $header) {
            $columns = match ($header) {
                '商户号' => [],
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

        return array_map(fn ($header) => self::DISPLAY_HEADERS[$header] ?? $header,
            $ordered + [52 => '原始支付状态', 53 => self::PLATFORM_RESULT]);
    }

    public static function additionalEditableHeaders(): array
    {
        $baseHeaders = array_map(fn ($field) => self::DISPLAY_HEADERS[$field] ?? $field,
            FinanceXuzongOrderImport::EDITABLE_ORDER_FIELDS);

        return array_values(array_diff(self::headers(), $baseHeaders));
    }

    public function orderValues(int $id): array
    {
        $records = app(FinanceLogisticsCreditOrders::class)->query(xuzongReport: true, ids: [-$id])->get();

        return $this->rows($records, 'UTC')->get(-$id, []);
    }

    public function rows(Collection $records, string $timezone): Collection
    {
        if ($records->isEmpty()) {
            return collect();
        }

        $linked = $records->filter(fn ($record) => $record->dataset === FinanceLogisticsCreditOrders::IMPORTED_DATASET)
            ->mapWithKeys(fn ($record) => [$record->id => $record->payload['_linked_logistics_id'] ?? null])->filter();
        $orders = $this->settlements->liveCreditReportOrders($records->pluck('id')->filter(fn ($id) => $id > 0)->concat($linked)->unique());
        $unlinked = $records->filter(fn ($record) => $record->dataset === FinanceLogisticsCreditOrders::IMPORTED_DATASET
            && ! $orders->has($linked->get($record->id)))->groupBy('account');
        $newLinks = collect();
        foreach ($unlinked as $account => $group) {
            $references = $group->mapWithKeys(fn ($record) => [$record->id => [
                '交易流水号' => $record->payload['交易流水号'] ?? '',
                '平台订单号' => ($record->payload['_platform_missing'] ?? false) ? '' : $record->reference,
                '商户订单号' => $record->payload['商户订单号'] ?? '',
                '通途订单' => $record->payload['通途订单'] ?? '',
            ]]);
            foreach ($this->orderImport->matches($references->all(), $account) as $id => $match) {
                $newLinks->put($id, $match->id);
            }
        }
        $orders = $orders->union($this->settlements->liveCreditReportOrders($newLinks->unique()));
        $linked = $newLinks->union($linked);
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
            $displayRecord = clone $record;
            if (blank($displayRecord->domain)) {
                $displayRecord->domain = $order->report_domain ?? null;
            }
            $values = $this->values($displayRecord, $order, $attribution($displayRecord, $order), $timezone, $payment);
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
                    '发卡国家', '卡号', '消费者邮箱', '通途订单', '物流-元', '采购成本-元', '备注',
                ])), fn ($value) => filled($value)));
                $row['创建时间'] = $record->occurred_at?->setTimezone($timezone)->format('Y-m-d H:i:s') ?? '';
                $missingPlatform = $record->payload['_platform_missing'] ?? false;
                $sourceFlow = trim((string) ($record->payload['交易流水号'] ?? ''));
                $linkedFlow = trim((string) ($order->bill_number ?? ''));
                $canUsePaymentIds = ($sourceFlow !== '' && $sourceFlow === $linkedFlow)
                    || ($sourceFlow === '' && FinanceSettlementService::isSuccessfulTransaction($record->status));
                $row['平台订单号'] = $missingPlatform ? ($canUsePaymentIds ? ($order->payment_gateway_order_id ?? '') : '') : $record->reference;
                if ($missingPlatform && ! $canUsePaymentIds) {
                    $row['交易流水号'] = $sourceFlow;
                }
                if (blank($row['订单币种']) && blank($row['订单金额'])) {
                    $shopify = json_decode($order->shopify_payload ?? '', true) ?? [];
                    $shopMoney = data_get($shopify, 'totalPriceSet.presentmentMoney')
                        ?? data_get($shopify, 'total_price_set.presentment_money');
                    if ($shopMoney === null) {
                        $current = data_get($shopify, 'currentTotalPriceSet.shopMoney');
                        $shopAmount = $this->settlements->number($current['amount'] ?? null);
                        $tradeAmount = $this->settlements->number($row['交易金额']);
                        if (($current['currencyCode'] ?? null) === $row['交易币种']
                            && $shopAmount !== null && $tradeAmount !== null && bccomp($shopAmount, $tradeAmount, 8) === 0) {
                            $shopMoney = $current;
                        }
                    }
                    $currency = $shopMoney['currencyCode'] ?? $shopMoney['currency_code'] ?? '';
                    $amount = $shopMoney['amount'] ?? null;
                    if (preg_match('/^[A-Z]{3}$/D', (string) $currency) && is_numeric($amount)) {
                        $row['订单币种'] = $currency;
                        $row['订单金额'] = $amount;
                    }
                }
                $row['平台订单号'] = $record->payload['_edited_platform_order_id'] ?? $row['平台订单号'];
                $completed = $record->payload['完成时间'] ?? '';
                if ($completed !== '') {
                    $row['完成时间'] = CarbonImmutable::parse($completed, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s');
                }
            }
            $exchange = $matches['_usd_exchange'] ?? null;
            if (($exchange['currency'] ?? null) !== $payment['currency']) {
                $exchange = $payment['usd_exchange'] ?? null;
            }
            $this->applyUsdSettlement($row, $record->status, $exchange);
            $orderCurrency = strtoupper(trim((string) ($row['订单币种'] ?? '')));
            $orderAmount = $this->settlements->number($row['订单金额'] ?? null);
            $usdAmount = $this->settlements->number($row['交易币种'] === 'USD' ? $row['交易金额'] : $row['结算-美元']);
            $row['汇率'] = match (true) {
                $orderCurrency === 'USD' && $row['交易币种'] === 'USD' => '1',
                $orderCurrency !== '' && $orderAmount !== null && $usdAmount !== null && bccomp($orderAmount, '0', 8) > 0
                    => bcdiv($usdAmount, $orderAmount, 8),
                default => '',
            };
            $overrides = self::reportOverrides($record->payload);
            $receiptLabel = FinancePayPalSettlementOrders::USD_RECEIPT_LABEL;
            if (array_key_exists($receiptLabel, $overrides)) {
                $row['结算-美元'] = $overrides[$receiptLabel];
                $receipt = $this->settlements->number($row['结算-美元']);
                if ($receipt !== null && $orderAmount !== null && bccomp($orderAmount, '0', 8) > 0) {
                    $row['汇率'] = bcdiv($receipt, $orderAmount, 8);
                }
            } elseif (isset($overrides['汇率']) && $orderAmount !== null && $row['结算-美元'] !== ''
                && ($rate = $this->settlements->number($overrides['汇率'])) !== null) {
                $row['结算-美元'] = bcmul($orderAmount, $rate, 8);
            }
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
            $row['状态'] = $record->match_status;
            $row['原始支付状态'] = $record->payload['_original_payment_status'] ?? ($record->status === '失败' ? 'FAILED' : ($record->status ?? ''));
            $row[self::PLATFORM_RESULT] = self::platformResult($record->payload);
            if (array_key_exists('备注', $overrides)) {
                $row['备注'] = $overrides['备注'];
                unset($overrides['备注']);
            }
            if ($record->match_status === '失败') {
                $note = trim((string) ($row['备注'] ?? ''));
                $row['备注'] = $note === '' ? '失败'
                    : ($note === '失败' || str_starts_with($note, '失败；') ? $note : '失败；'.$note);
            }

            if ($imported && $order === null) {
                $notes = array_filter(explode('；', (string) $row['备注']), fn ($note) => trim($note) !== '');
                if (! in_array('系统无匹配订单', $notes, true)) {
                    $notes[] = '系统无匹配订单';
                }
                $row['备注'] = implode('；', $notes);
            }

            return [$record->id => array_replace($row, $overrides)];
        });
    }

    private static function platformResult(array $payload): string
    {
        $code = strtoupper(trim((string) ($payload[self::PLATFORM_CODE] ?? '')));
        $message = trim((string) ($payload[self::PLATFORM_MESSAGE] ?? $payload[self::PLATFORM_RESULT] ?? ''));
        if (isset(self::PLATFORM_RESULTS[$code])) {
            return self::PLATFORM_RESULTS[$code][1];
        }

        $normalized = strtolower(rtrim($message, '. '));
        foreach (self::PLATFORM_RESULTS as [$source, $label]) {
            if ($source === $normalized) {
                return $label;
            }
        }
        if (preg_match('/\p{Han}/u', $message)) {
            return $message;
        }

        return $code === '' && $message === '' ? '' : '平台返回结果暂无法识别';
    }

    private function applyUsdSettlement(array &$row, ?string $status, ?array $exchange): void
    {
        $row['结算-美元'] = '';
        if (! FinanceSettlementService::isSuccessfulTransaction($status) || $row['交易金额'] === '') {
            return;
        }

        $currency = $row['交易币种'];
        $amount = $this->settlements->number($row['交易金额']);
        if ($currency === 'USD') {
            $row['结算-美元'] = $row['交易金额'];
        } elseif ($currency !== '' && $amount !== null && $exchange !== null && $exchange['currency'] === $currency) {
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

    public static function formatCell(string $header, mixed $value): mixed
    {
        $field = array_search($header, self::DISPLAY_HEADERS, true) ?: $header;
        if (is_numeric($value) && (FinanceXuzongCreditMatches::isAmount($field)
            || in_array($field, ['订单金额', '交易金额', '结算-美元', '应结算（USD）', FinanceSettlementService::SHOP_DIFFERENCE_LABEL], true))) {
            $amount = trim((string) $value);
            if (! preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $amount)) {
                return number_format((float) $value, 2, '.', '');
            }

            return bcadd($amount, str_starts_with($amount, '-') ? '-0.005' : '0.005', 2);
        }

        return $value;
    }

    private const STATEMENT_EXPORT_AMOUNTS = [
        '结算金额', '退款金额', '退款费用', '拒付金额', '拒付费用', '拒付预警ethoca',
        'RDR费用', 'CDRN费用', '保证金', '保证金返还', '拒付返还', '单笔费用', '交易手续费', '争议处理费', '应结算',
    ];

    private static function reportOverrides(array $payload): array
    {
        $overrides = $payload['_edited_report_fields'] ?? [];
        if (array_key_exists('应结算（USD）', $overrides) || ! is_numeric($payload['应结算'] ?? null)) {
            return $overrides;
        }

        $net = (string) $payload['应结算'];
        $adjusted = false;
        foreach (array_diff(self::STATEMENT_EXPORT_AMOUNTS, ['应结算']) as $field) {
            $value = $overrides[self::DISPLAY_HEADERS[$field] ?? $field] ?? null;
            if (! is_numeric($value)) {
                continue;
            }
            $base = $payload[$field] ?? '0';
            $delta = bcsub((string) $value, is_numeric($base) ? (string) $base : '0', 8);
            $net = in_array($field, ['结算金额', '保证金返还', '拒付返还'], true) ? bcadd($net, $delta, 8) : bcsub($net, $delta, 8);
            $adjusted = true;
        }
        if ($adjusted) {
            $overrides['应结算（USD）'] = $net;
        }

        return $overrides;
    }
    public static function statementExportRows(iterable $records): iterable
    {
        foreach ($records as $record) {
            $payload = $record->payload;
            $statement = $payload['_xuzong_statement_import'] ?? null;
            $adjustments = [];
            $overrides = self::reportOverrides($payload);
            foreach (self::STATEMENT_EXPORT_AMOUNTS as $field) {
                $label = $field === '应结算' ? '应结算（USD）' : (self::DISPLAY_HEADERS[$field] ?? $field);
                $value = $overrides[$label] ?? null;
                if (is_numeric($value)) {
                    $base = $payload[$field] ?? '0';
                    $adjustments[$field] = bcsub((string) $value, is_numeric($base) ? (string) $base : '0', 8);
                }
            }
            if (! $statement && $adjustments === []) {
                continue;
            }

            $legacy = $statement['legacy'] ?? null;
            if ($statement && ! array_key_exists('events', $statement)) {
                $legacy = ['times' => $statement['settlement_times'], 'amounts' => $payload];
            }
            $events = array_values($statement['events'] ?? []);
            usort($events, fn ($left, $right) => strcmp($left['time'], $right['time']));
            if ($legacy !== null) {
                array_unshift($events, ['time' => implode('、', $legacy['times']), 'amounts' => $legacy['amounts'], 'legacy' => true]);
            }
            if ($adjustments !== []) {
                $events[] = ['time' => '', 'amounts' => $adjustments, 'adjustment' => true];
            }
            foreach ($events as $event) {
                $cells = array_map(fn ($value) => ['value' => $value], [
                    $record->account, $record->reference, $payload['商户订单号'] ?? '',
                    $event['time'], 'USD', ! empty($event['adjustment']) ? '手工调整' : (empty($event['legacy']) ? '逐笔' : '历史汇总'),
                ]);
                foreach (self::STATEMENT_EXPORT_AMOUNTS as $field) {
                    $value = $event['amounts'][$field] ?? '';
                    $cells[] = ['value' => $value, 'type' => $value === '' ? 'text' : 'number'];
                }
                yield ['cells' => $cells];
            }
        }
    }


    public static function downloadXlsx(array $headers, iterable $rows, string $filename, ?callable $statementRows = null): StreamedResponse
    {
        $columns = array_map(static fn ($header) => [
            'label' => $header,
            'width' => match ($header) {
                '商户订单号', '平台订单号', '交易 ID', '消费者邮箱', '备注' => 32,
                '创建时间', '完成时间', '时间点', '域名', '通途订单' => 23,
                default => max(11, mb_strwidth($header) + 5),
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
            }, plain: true, fontName: '宋体', additionalSheets: $statementRows === null ? [] : [[
                '结算变动明细',
                array_map(fn ($header) => ['label' => $header, 'width' => max(16, mb_strwidth($header) + 4)],
                    ['收款账户', '交易流水号', '商户订单号', '结算时间', '结算币种', '记录类型', ...self::STATEMENT_EXPORT_AMOUNTS]),
                $statementRows,
            ]], streamDirectly: true);
    }
}
