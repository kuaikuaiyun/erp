<?php

namespace App\Portals\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FinancePayPalSettlementOrders
{
    public const COLUMNS = [
        'shopify_order_number' => 'Shopify订单号', 'order_status' => '当前订单状态',
        'open_hold' => '未解除冻结（USD）', 'payable' => '应结算（USD）',
        'review' => '核对说明',
        'paypal_reference_type' => '关联号类型',
    ];

    private const EVENTS = [
        'T2101' => 'hold', 'T2103' => 'hold', 'T2102' => 'release', 'T2104' => 'release',
        'T1110' => 'dispute_hold', 'T1111' => 'dispute_release',
        'T1106' => 'refund', 'T1107' => 'refund', 'T1201' => 'refund', 'T1202' => 'refund',
        'T0106' => 'refund_fee', 'T1109' => 'fee_return', 'T0114' => 'dispute_fee', 'T1108' => 'dispute_fee',
    ];

    private const COSTS = ['fee', 'hold', 'dispute_hold', 'refund', 'refund_fee', 'dispute_refund', 'dispute_fee'];
    private const RETURNS = ['fee_return', 'dispute_release', 'release'];
    private const SCALE = 8;

    private const DETAIL_FIELDS = [
        '交易信息' => [
            0 => '日期', 1 => '时间', 2 => '时区', 4 => '类型', 5 => '状态', 11 => '交易 ID',
            12 => '偏好设置 Txn ID', 13 => '账单号码', 27 => '自订编号', 29 => '收据 ID',
            'source_category' => '类型-分类', 'source_added_date' => '新增日期',
        ],
        '资金信息' => [
            6 => '货币', 7 => '总额', 8 => '费用', 9 => '净额', 30 => '余额', 14 => '余额影响',
            20 => '运费与处理费金额', 21 => '保险金额', 22 => '销售税',
        ],
        '联系人与配送' => [
            3 => '姓名', 10 => '寄件人电邮地址', 15 => '收件人电邮地址', 37 => '联络电话号码',
            16 => '运送地址', 17 => '地址状态', 31 => '地址行 1', 32 => '地址行 2 / 区 / 邻',
            33 => '镇或县市', 34 => '省、市、自治区、直辖市或特别行政区', 35 => '邮递区号',
            36 => '国家或地区', 40 => '国家或地区代码',
        ],
        '商品信息' => [
            18 => '物品标题', 19 => '物品 ID', 28 => '数量',
            23 => '选项 1 名称', 24 => '选项 1 的值', 25 => '选项 2 名称', 26 => '选项 2 的值',
        ],
        '交易备注' => [38 => '主旨', 39 => '附注'],
    ];

    public function __construct(
        private readonly FinanceWorkbookService $workbooks,
        private readonly FinanceSettlementService $settlements,
    ) {}

    public static function listColumns(array $columns): array
    {
        $columns = [...$columns, ...self::COLUMNS, 'settlement' => '收款金额-美元'];
        $primary = array_flip(['shopify_order_number', 'reference', 'account', 'order_status', 'payable',
            'open_hold', 'review', 'paypal_12', 'paypal_reference_type']);

        return array_replace($primary, array_intersect_key($columns, $primary)) + $columns;
    }

    public static function sourceExportColumns(): array
    {
        $headers = FinanceCatalog::dataset('paypal-orders')['headers'];
        $source = [];
        foreach (range(15, 40) as $position) {
            $source['paypal_'.$position] = match ($position) {
                18 => '商品大类',
                27 => '自订编号',
                37 => '联络电话号码',
                default => FinanceCatalog::simplified($headers[$position]),
            };
        }
        return $source + ['source_category' => '类型-分类', 'source_added_date' => '新增日期'];
    }

    public function detailRow(int $recordId, string $timezone): ?array
    {
        $row = $this->rows(['timezone' => $timezone], recordId: $recordId)->first();
        if ($row === null) {
            return null;
        }

        $db = $this->workbooks->database();
        $records = $db->table('finance_records')->whereIntegerInRaw('id', array_column($row['events'], 'id'))
            ->get(['id', 'payload', 'created_at'])->keyBy('id');
        $ids = array_column($row['events'], 'transaction_row_id');
        $payloads = $ids === [] ? collect() : $db->table('finance_payment_transactions')
            ->whereIntegerInRaw('id', $ids)->pluck('raw_payload', 'id');
        $headers = FinanceCatalog::dataset('paypal-orders')['headers'];
        foreach ($row['events'] as &$event) {
            $record = $records->get($event['id']);
            $payload = json_decode($record->payload ?? '{}', true) ?? [];
            $normalized = [];
            foreach ($payload as $key => $value) {
                $normalized[FinanceCatalog::simplified((string) $key)] = $value;
            }
            $raw = json_decode($payloads[$event['transaction_row_id'] ?? 0] ?? '{}', true) ?? [];
            $info = $raw['transaction_info'] ?? [];
            $event['invoice'] = $info['invoice_id'] ?? $event['invoice'] ?? $normalized['账单号码'] ?? '';
            $event['status_label'] = match ($event['status']) {
                'S' => '已完成（S）', 'P' => '待处理（P）', 'D' => '已拒绝（D）', 'V' => '已冲销（V）',
                default => FinanceCatalog::simplified($event['status']),
            };
            [$date, $time] = array_pad(explode(' ', $event['time'], 2), 2, '');
            $overrides = [
                0 => $date, 1 => $time, 2 => $event['timezone'], 4 => $event['label'],
                5 => $event['status_label'], 11 => $event['reference'],
                12 => $event['related_id'] ?? '', 13 => $event['invoice'],
                30 => $this->reportedMoney($info['ending_balance'] ?? []),
            ];
            $fallbacks = [
                6 => $event['currency'], 7 => $event['amount'], 8 => $event['fee'], 9 => $event['net'],
                'source_category' => $event['category'],
                'source_added_date' => isset($record->created_at)
                    ? CarbonImmutable::parse($record->created_at)->setTimezone($event['timezone'])->toDateString() : '',
            ];
            $event['fields'] = [];
            foreach (self::DETAIL_FIELDS as $group => $fields) {
                foreach ($fields as $key => $label) {
                    $header = FinanceCatalog::simplified($headers[$key] ?? $label);
                    $value = filled($overrides[$key] ?? null)
                        ? $overrides[$key] : ($normalized[$header] ?? $normalized[$label] ?? null);
                    $value = filled($value) ? $value : ($fallbacks[$key] ?? '');
                    if ($key === 14) {
                        $value = match ($value) { 'Credit' => '贷记', 'Debit' => '借记', default => $value };
                    }
                    $event['fields'][$group][$label] = is_scalar($value) && filled($value)
                        ? (in_array($key, [5, 14, 17, 'source_category'], true) ? FinanceCatalog::simplified((string) $value) : $value)
                        : '—';
                }
            }

            $updated = $info['transaction_updated_date'] ?? null;
            $event['metadata'] = [
                '交易更新时间' => is_string($updated) && strtotime($updated) !== false
                    ? CarbonImmutable::parse($updated, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s') : '',
                '可用余额' => $this->reportedMoney($info['available_balance'] ?? []),
            ];
        }
        unset($event);

        return $row;
    }

    private function reportedMoney(mixed $money): string
    {
        $value = $money['value'] ?? null;
        $amount = is_scalar($value) ? $this->settlements->number($value) : null;
        $currency = $money['currency_code'] ?? '';

        return $amount === null ? '' : trim((is_string($currency) ? $currency : '').' '.FinanceCatalog::reportAmount($amount));
    }

    public function rows(array $filters, ?int $pageOffset = null, int $pageLimit = 10, ?int $recordId = null): Collection
    {
        $db = $this->workbooks->database();
        $mysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        $paymentQuery = $this->settlements->query('paypal', []);
        $orders = $byId = [];
        $detailAccountIds = null;
        if ($recordId !== null) {
            $target = (clone $paymentQuery)->find($recordId);
            if ($target) {
                $orders['detail'] = ['id' => $recordId, 'time' => 0];
            }
            $detailTransactions = $db->table('finance_payment_transactions')->where('finance_record_id', $recordId)
                ->get(['payment_account_id', 'transaction_id']);
            $detailAccountIds = $detailTransactions->pluck('payment_account_id')->unique()->all();
            if ($detailAccountIds === [] && ! $target) {
                return collect();
            }
            if ($detailAccountIds !== []) {
                $byId = (clone $paymentQuery)->pluck('id')->flip()->all();
            }
        } else {
            $paymentQuery->when($mysql, fn ($query) => $query->forceIndex('PRIMARY'))
                ->select('id', 'occurred_at')
                ->chunkById(700, function ($records) use (&$orders, &$byId): void {
                    foreach ($records as $record) {
                        $orders['payment:'.$record->id] = ['id' => $record->id, 'time' => $record->occurred_at->getTimestamp()];
                        $byId[$record->id] = true;
                    }
                });
        }
        $index = $invoiceRoots = $events = $extraEvents = $crossAccount = [];
        $removed = $db->table('finance_settlement_supplements')->whereNotNull('payload->_deleted_at')
            ->pluck('finance_record_id')->flip()->all();
        $transactions = $db->table('finance_payment_transactions as txn')
            ->join('finance_records as record', 'record.id', '=', 'txn.finance_record_id')
            ->join('finance_payment_accounts as account', 'account.id', '=', 'txn.payment_account_id')
            ->whereNull('account.deleted_at');
        $settlementCodes = [...array_keys(self::EVENTS), 'T0200'];
        $settlementTransactions = (clone $transactions)->where(function ($query) use ($settlementCodes): void {
            $query->whereIn('txn.event_code', $settlementCodes)
                ->orWhereIn('txn.event_code', function ($types): void {
                    $types->select('raw_type')->from('finance_transaction_types')->where('category', '结账付款');
                });
        });
        if ($detailAccountIds !== null) {
            // Parent links are account-scoped, but duplicate payment IDs must be checked across accounts.
            $matchingRoots = $settlementTransactions->whereIn('txn.transaction_id', $detailTransactions->pluck('transaction_id')->unique())
                ->get(['txn.finance_record_id', 'txn.payment_account_id', 'txn.transaction_id']);
            foreach ($matchingRoots as $txn) {
                if (isset($byId[$txn->finance_record_id])) {
                    $crossAccount[$txn->transaction_id][$txn->payment_account_id] = true;
                }
            }
            // Read additional event types for the detail only. The settlement rules use the original event set.
            $transactions->leftJoin('finance_transaction_types as event_type', 'event_type.raw_type', '=', 'txn.event_code')
                ->whereIntegerInRaw('txn.payment_account_id', $detailAccountIds)
                ->where(fn ($query) => $query->whereIn('txn.event_code', $settlementCodes)
                    ->orWhere('event_type.category', '结账付款')
                    ->orWhere('txn.raw_payload->transaction_info->paypal_reference_id_type', 'TXN'));
        } else {
            $transactions = $settlementTransactions;
        }
        // Read in primary-key order before joining to avoid sorting the remaining history on each page.
        if ($mysql) {
            $transactions->forceIndex('PRIMARY')->selectRaw('STRAIGHT_JOIN txn.id');
        } else {
            $transactions->select('txn.id');
        }
        $transactions->addSelect('txn.finance_record_id', 'txn.payment_account_id', 'txn.transaction_id',
            'txn.event_code', 'txn.raw_payload->transaction_info as transaction_info', 'record.occurred_at',
            'account.account_name', 'account.activity_account');
        if ($recordId !== null) {
            $transactions->addSelect('event_type.category as configured_category');
        }
        foreach ($transactions->lazyById(1500, 'txn.id', 'id') as $txn) {
            $payment = isset($byId[(int) $txn->finance_record_id]);
            $deleted = isset($removed[(int) $txn->finance_record_id]);
            $settlementEvent = in_array($txn->event_code, $settlementCodes, true);
            $known = $settlementEvent || (($payment || $deleted)
                && ($recordId === null || $txn->configured_category === '结账付款'));
            if (! $known && ($recordId === null || $deleted)) {
                continue;
            }
            $info = json_decode((string) $txn->transaction_info, true) ?? [];
            $relatedId = $info['paypal_reference_id'] ?? '';
            $relatedType = $relatedId !== '' ? ($info['paypal_reference_id_type'] ?? '') : '';
            $event = [
                'id' => (int) $txn->finance_record_id, 'account_id' => (int) $txn->payment_account_id,
                'account' => $txn->account_name, 'account_key' => $txn->activity_account,
                'code' => $txn->event_code, 'reference' => $txn->transaction_id,
                'related_id' => $relatedId, 'related_type' => $relatedType,
                'invoice' => trim((string) ($info['invoice_id'] ?? '')),
                'parent' => $relatedType === 'TXN' ? $relatedId : '',
                'time' => CarbonImmutable::parse($txn->occurred_at, 'UTC')->getTimestamp(),
                'amount' => $this->settlements->number($info['transaction_amount']['value'] ?? null),
                'currency' => $info['transaction_amount']['currency_code'] ?? '',
                'fee' => $this->settlements->number($info['fee_amount']['value'] ?? null),
                'status' => $info['transaction_status'] ?? '',
            ];
            if ($recordId !== null) {
                $event['transaction_row_id'] = (int) $txn->id;
                $event['configured_category'] = $txn->configured_category;
            }
            if (! $known) {
                if ($event['parent'] !== '') {
                    $extraEvents[$txn->id] = $event;
                }
                continue;
            }
            $events[$txn->id] = $event;
            $index[$event['account_id']][$event['reference']][] = $txn->id;
            if ($event['invoice'] !== '' && ($payment || ($deleted && ! $settlementEvent))) {
                $invoiceRoots[$event['account_id']][$event['invoice']][$event['id']] = true;
            }
            if ($payment) {
                $crossAccount[$event['reference']][$event['account_id']] = true;
            }
        }

        $roots = $children = $conversions = $unlinked = $ambiguous = [];
        $paymentIds = $byId + $removed;
        foreach ($events as $event) {
            if (isset($byId[$event['id']])) {
                $roots[$event['id']] = $event;
                continue;
            }
            if (isset($removed[$event['id']])) {
                continue;
            }
            $candidates = $this->paymentRoots($event, $events, $index, $paymentIds, $invoiceRoots);
            if (count($candidates) === 1) {
                $rootId = array_key_first($candidates);
                if (! isset($removed[$rootId])) {
                    $children[$rootId][] = $event;
                    if ($event['code'] === 'T0200') {
                        $conversions[$rootId][$event['parent']][] = $event;
                    }
                }
            } elseif (isset(self::EVENTS[$event['code']]) || $event['code'] === 'T0200') {
                if ($recordId === null || $event['id'] === $recordId) {
                    $unlinked[] = $event;
                }
                foreach ($candidates as $rootId => $found) {
                    $ambiguous[$rootId] = true;
                }
            }
        }
        if ($extraEvents !== []) {
            foreach ($extraEvents as $id => $event) {
                $index[$event['account_id']][$event['reference']][] = $id;
            }
            $events += $extraEvents;
            foreach ($extraEvents as $event) {
                $candidates = $this->paymentRoots($event, $events, $index, $paymentIds);
                $rootId = array_key_first($candidates);
                if (count($candidates) === 1 && $rootId === $recordId) {
                    $children[$rootId][] = $event;
                }
            }
        }
        unset($events, $index, $byId, $paymentIds, $invoiceRoots);

        foreach ($unlinked as $event) {
            $orders['event:'.$event['id']] = ['id' => $event['id'], 'time' => $event['time'], 'event' => $event];
        }
        if ($recordId === null) {
            uasort($orders, fn ($a, $b) => [$b['time'], $b['id']] <=> [$a['time'], $a['id']]);
        }
        $total = null;
        $scope = array_intersect_key($filters, array_flip(['account', 'from', 'to', 'reference', 'team', 'domain', 'subscription', 'order_status', 'conditions']));
        if ($pageOffset !== null && ! array_filter($scope)) {
            $total = count($orders);
            $orders = array_slice($orders, $pageOffset, $pageLimit);
        }
        $rows = collect($total === null ? [] : array_fill(0, min($total, $pageOffset), []));
        $exportFields = $this->settlements->exportColumns('paypal') + ['gross' => '原币总额'];
        $numericConditionFields = $this->settlements->numericConditionFields('paypal');
        foreach (array_chunk($orders, 250) as $batch) {
            $ids = array_column(array_filter($batch, fn ($row) => ! isset($row['event'])), 'id');
            $records = $this->settlements->query('paypal', [])->whereIntegerInRaw('id', $ids)
                ->orderByDesc('occurred_at')->orderByDesc('id')->get();
            $payments = $this->settlements->rows('paypal', ['timezone' => $filters['timezone'], 'order_view' => true], $records)->keyBy('id');
            foreach ($batch as $order) {
                if (isset($order['event'])) {
                    $row = $this->unlinkedRow($order['event'], $filters['timezone'], $recordId !== null);
                } else {
                    $payment = $payments->get($order['id']);
                    if ($payment === null) {
                        continue;
                    }
                    $row = $this->paymentRow($payment, $roots, $children, $conversions, $crossAccount, $ambiguous, $filters['timezone'], $recordId !== null);
                }
                if ($this->matches($row, $filters, $children, $numericConditionFields)) {
                    if ($recordId === null && $pageOffset === null) {
                        $row = array_intersect_key($row, $exportFields);
                        $row = array_combine(array_keys($row), array_values($row));
                    } elseif ($pageOffset !== null && ($rows->count() < $pageOffset || $rows->count() >= $pageOffset + $pageLimit)) {
                        // Off-page matches count toward pagination without retaining their summaries.
                        $row = ['subscription' => $row['subscription'] ?? ''];
                    }
                    $rows->push($row);
                }
            }
        }
        if ($total !== null && $rows->count() < $total) {
            $rows = $rows->concat(array_fill(0, $total - $rows->count(), []));
        }
        return $rows;
    }

    private function paymentRow(array $payment, array $roots, array $children, array $conversions, array $crossAccount, array $ambiguous, string $zone, bool $includeDetails): array
    {
        $id = $payment['id'];
        $root = $roots[$id] ?? null;
        $result = $this->calculate($payment, $root, $children[$id] ?? [], $conversions[$id] ?? [],
            $root && count($crossAccount[$root['reference']] ?? []) > 1, $zone, $includeDetails);
        $result['order_status'] = $this->paymentStatus($result);
        if (isset($ambiguous[$id])) {
            $result['payable'] = $result['open_hold'] = null;
            $result['review'] = trim($result['review'].'；存在无法唯一归属的资金流水', '；');
            $result['order_status'] .= ' · 资金待核对';
        }
        if ($includeDetails) {
            $result['events'] = collect($result['events'])->sortBy('timestamp')->values()->all();
        }
        $payment['filter_date'] = $payment['source_date'];
        unset($payment['manual_values'], $payment['source_date'], $result['manual_values'], $result['source_date']);
        $result['payments'] = [$payment];
        $result['source_category'] = '结账付款';
        $result['paypal_12'] = $root['related_id'] ?? $payment['paypal_12'];
        $result['paypal_reference_type'] = $root['related_type'] ?? '';

        return $result;
    }

    private function unlinkedRow(array $event, string $zone, bool $includeDetails): array
    {
        $date = CarbonImmutable::createFromTimestamp($event['time'], 'UTC')->setTimezone($zone);
        return [
            'id' => $event['id'], 'account' => $event['account'], 'reference' => $event['reference'],
            'paypal_12' => $event['related_id'], 'paypal_reference_type' => $event['related_type'],
            'source_category' => FinancePayPalEventTypes::TYPES[$event['code']][1] ?? '',
            'date' => $date->format('Y-m-d H:i:s'), 'month' => $date->format('Y-m'), 'timezone' => $zone,
            'order_status' => '待关联交易', 'review' => '未找到唯一的原付款，未计入应结算',
            ...($includeDetails ? ['events' => [$this->detail($event, $zone)]] : []),
            'payments' => [], 'locked' => true,
            'unlinked_account' => $event['account_key'], 'unlinked_date' => $date,
        ];
    }

    private function paymentRoots(array $event, array $events, array $index, array $payments, array $invoiceRoots = []): array
    {
        $pending = [$event];
        $seen = $roots = $invoiceMatches = [];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (isset($seen[$current['id']])) {
                continue;
            }
            $seen[$current['id']] = true;
            if (isset($payments[$current['id']])) {
                $roots[$current['id']] = true;
                continue;
            }
            if (isset(self::EVENTS[$current['code']]) && $current['invoice'] !== '') {
                $invoiceMatches += $invoiceRoots[$current['account_id']][$current['invoice']] ?? [];
            }
            foreach ($index[$current['account_id']][$current['parent']] ?? [] as $id) {
                $pending[] = $events[$id];
            }
        }
        // Use the invoice only when the transaction links did not identify a payment.
        return $roots ?: $invoiceMatches;
    }

    private function matches(array $row, array $filters, array $children, array $numericConditionFields): bool
    {
        $zone = $filters['timezone'];
        $matchesScope = static function ($account, $date) use ($filters, $zone): bool {
            return (! filled($filters['account'] ?? null) || $account === $filters['account'])
                && (! filled($filters['from'] ?? null) || $date >= CarbonImmutable::parse($filters['from'], $zone))
                && (! filled($filters['to'] ?? null) || $date < CarbonImmutable::parse($filters['to'], $zone)->addDay());
        };
        $payments = $row['payments'];
        $inScope = $payments === []
            ? $matchesScope($row['unlinked_account'], $row['unlinked_date'])
            : collect($payments)->contains(fn ($payment) => $matchesScope($payment['source_account_key'],
                $payment['filter_date']));
        if (! $inScope) {
            return false;
        }
        if (filled($filters['reference'] ?? null)) {
            $reference = $filters['reference'];
            $matched = $reference === $row['reference']
                || collect($payments)->contains(fn ($payment) => $payment['reference'] === $reference
                    || in_array($reference, array_column($children[$payment['id']] ?? [], 'reference'), true));
            if (! $matched) {
                return false;
            }
        }
        return (! filled($filters['order_status'] ?? null) || str_contains($row['order_status'], $filters['order_status']))
            && (! filled($filters['team'] ?? null) || ($row['team'] ?? '') === $filters['team'])
            && (! filled($filters['subscription'] ?? null) || ($row['subscription'] ?? '') === FinanceSettlementService::subscriptionLabel($filters['subscription']))
            && (! filled($filters['domain'] ?? null) || str_contains($row['domain'] ?? '', $filters['domain']))
            && $this->settlements->matches($row, $filters['conditions'] ?? [], $numericConditionFields);
    }

    private function calculate(array $payment, ?array $root, array $events, array $conversions, bool $duplicate, string $zone, bool $includeDetails): array
    {
        $result = $payment;
        $manual = $payment['manual_values'];
        $money = array_fill_keys([...self::COSTS, ...self::RETURNS], $root ? '0' : null);
        $details = [];
        $review = [];
        $holds = [];
        $usedExchanges = [];
        $manualHold = false;
        $refundOriginal = '0';
        $refundPending = false;
        $complete = $payment['success'];
        $hasHold = false;
        $manualFields = $this->settlements->importFields('paypal');

        if ($root) {
            $result['reference'] = $root['reference'];
            $complete = $root['status'] === 'V' || $complete;
            $result['success'] = $complete;
            $gross = $root['amount'];
            $fee = $root['fee'] ?? '0';
            $net = $gross === null ? null : bcadd($gross, $fee, self::SCALE);
            $converted = $this->exchange($root, $net, $conversions, $usedExchanges);
            $manualNet = $this->settlements->number($manual['converted_usd'] ?? $manual['转换美元'] ?? null);
            $manualRate = $this->settlements->number($manual['usd_rate'] ?? $manual['汇率'] ?? null);
            $rate = $root['currency'] === 'USD' ? '1' : null;
            if ($rate === null && $net !== null && bccomp($net, '0', self::SCALE) !== 0
                && ($converted ?? $manualNet) !== null) {
                $rate = bcdiv($converted ?? $manualNet, $net, self::SCALE);
            }
            if ($rate === null && $manualRate !== null && bccomp($manualRate, '0', self::SCALE) > 0) {
                $rate = $manualRate;
            }
            $result['usd_rate'] = $rate;
            $result['converted_usd'] = $converted ?? $manualNet
                ?? ($rate !== null && $net !== null ? bcmul($net, $rate, self::SCALE) : null);
            $money['fee'] = $rate === null ? null : bcmul($fee, '-'.$rate, self::SCALE);
            $result['settlement'] = $complete && $result['converted_usd'] !== null && $money['fee'] !== null
                ? bcadd($result['converted_usd'], $money['fee'], self::SCALE) : null;
            $source = $root['currency'] === 'USD' ? '接口USD' : ($converted !== null ? '实际换汇' : ($rate !== null ? '财务折算' : '待折算'));
            if ($includeDetails) {
                $details[] = [...$this->detail($root, $zone, $result['converted_usd'], $source),
                    'rate' => $rate, 'category' => '结账付款', 'usd_label' => '美元净额'];
            }

            $disputeHolds = collect($events)->where('code', 'T1110')->keyBy('reference');
            foreach ($events as $event) {
                if ($event['code'] === 'T0200') {
                    if ($includeDetails) {
                        $details[] = $this->detail($event, $zone, $event['currency'] === 'USD' ? $event['amount'] : null, '接口换汇流水');
                    }
                    continue;
                }
                $field = self::EVENTS[$event['code']] ?? null;
                if ($field === null) {
                    if ($includeDetails) {
                        $details[] = $this->detail($event, $zone, source: '仅供核对');
                    }
                    continue;
                }
                $refund = in_array($field, ['refund', 'dispute_refund'], true);
                $hasHold = $hasHold || in_array($field, ['hold', 'dispute_hold'], true);
                if ($refund && $event['status'] === 'P') {
                    $refundPending = true;
                }
                $effective = $event['status'] === 'S' || ($field === 'dispute_hold' && $event['status'] === 'P');
                $rejectedRelease = $field === 'dispute_release' && $event['status'] === 'D';
                $amount = $effective || $rejectedRelease
                    ? $this->exchange($event, $event['amount'], $conversions, $usedExchanges, $rejectedRelease) : null;
                if ($rejectedRelease) {
                    $effective = $event['currency'] === 'USD'
                        ? $this->matchesDisputeHold($event, $disputeHolds->get($event['reference']))
                        : $amount !== null;
                    if (! $effective) {
                        $money[$field] = null;
                        $review[$field] = '争议返还待核对';
                    }
                }
                if (! $effective) {
                    if ($includeDetails) {
                        $details[] = $this->detail($event, $zone, null, $review[$field] ?? '未确认入账');
                    }
                    continue;
                }
                $amount = $event['currency'] === 'USD' ? $event['amount'] : $amount;
                $source = match (true) {
                    $event['currency'] !== 'USD' => $amount !== null ? '实际换汇' : '待折算',
                    $rejectedRelease => '同交易号争议冻结返还',
                    default => '接口USD',
                };
                if ($includeDetails) {
                    $details[] = $this->detail($event, $zone, $amount, $source);
                }
                if ($refund && $event['currency'] === $root['currency'] && $event['amount'] !== null) {
                    $refundOriginal = bcsub($refundOriginal, $event['amount'], self::SCALE);
                } elseif ($refund) {
                    $refundPending = true;
                }
                $this->addMoney($money, $review, $field, $amount,
                    in_array($field, self::RETURNS, true) ? 1 : -1);
                if (in_array($field, ['hold', 'release', 'dispute_hold', 'dispute_release'], true)) {
                    $kind = str_starts_with($field, 'dispute_') ? 'dispute' : 'reserve';
                    $holds[$kind.'|'.$event['currency']][] = ['amount' => $event['amount'], 'usd' => $amount];
                }

                if ($refund && $event['fee'] !== null && bccomp($event['fee'], '0', self::SCALE) !== 0) {
                    $feeField = bccomp($event['fee'], '0', self::SCALE) > 0 ? 'fee_return' : 'refund_fee';
                    $this->addMoney($money, $review, $feeField, $event['currency'] === 'USD' ? $event['fee'] : null,
                        $feeField === 'fee_return' ? 1 : -1);
                }
            }
        } else {
            if ($includeDetails) {
                $details[] = [
                    'id' => $payment['id'], 'reference' => $payment['reference'], 'code' => '',
                    'label' => $payment['paypal_4'] ?: '付款', 'category' => '结账付款',
                    'status' => $payment['status'], 'account' => $payment['account'],
                    'related_id' => $payment['paypal_12'], 'invoice' => $payment['paypal_13'],
                    'currency' => $payment['currency'], 'amount' => $payment['gross'], 'usd' => $payment['converted_usd'],
                    'fee' => $this->settlements->number($payment['paypal_8']),
                    'net' => $this->settlements->number($payment['paypal_9']),
                    'rate' => $payment['usd_rate'], 'usd_label' => '美元净额',
                    'time' => $payment['date'], 'timestamp' => $payment['source_date']->getTimestamp(),
                    'timezone' => $payment['timezone'], 'source' => '财务记录',
                ];
            }
        }

        foreach (array_keys($money) as $field) {
            $override = $this->settlements->number($manual[$field] ?? $manual[$manualFields[$field] ?? ''] ?? null);
            if ($override !== null) {
                $manualHold = $manualHold || in_array($field, ['hold', 'release', 'dispute_hold', 'dispute_release'], true);
                $money[$field] = $override;
                unset($review[$field]);
            } elseif ($root === null) {
                $money[$field] = $payment[$field];
            }
        }
        if ($root === null || isset($manual['settlement']) || isset($manual['结算金额-美元'])) {
            $result['settlement'] = $payment['settlement'];
        }
        if ($duplicate) {
            $review['duplicate'] = '跨账户存在相同交易号，待核对是否重复入账';
        }
        if ($payment['business_order_id'] === null) {
            $review['order'] = '待关联业务订单';
        }
        if ($result['settlement'] === null && $complete) {
            $review['rate'] = '收款美元金额待折算';
        }
        $result = [...$result, ...$money];
        $open = $this->balance($money, ['hold', 'dispute_hold'], ['release', 'dispute_release']);
        if ($open !== null && ! $manualHold && $holds !== []) {
            $open = $this->remainingHold($holds);
        }
        if ($open !== null && bccomp($open, '0', self::SCALE) < 0) {
            $open = null;
            $review['hold'] = '返还超过已知冻结，待核对';
        }
        $result['open_hold'] = $open;
        $result['had_hold'] = $hasHold || isset($review['dispute_release']) || bccomp($money['hold'] ?? '0', '0', self::SCALE) > 0
            || bccomp($money['dispute_hold'] ?? '0', '0', self::SCALE) > 0;
        $result['payable'] = $duplicate || $result['settlement'] === null || $open === null
            ? null : $this->balance($money, self::RETURNS, self::COSTS, $result['settlement']);
        if ($complete && $result['payable'] === null) {
            $review['payable'] = '应结算待核对';
        }
        $result['refund_pending'] = $refundPending;
        $result['refund_state'] = 'none';
        $comparisonAmount = $root['amount'] ?? $result['settlement'];
        if ($root === null || isset($manual['refund']) || isset($manual['退款金额'])
            || isset($manual['dispute_refund']) || isset($manual['争议退款'])) {
            $refundOriginal = bcadd($money['refund'] ?? '0', $money['dispute_refund'] ?? '0', self::SCALE);
            $comparisonAmount = $result['settlement'];
        }
        if (bccomp($refundOriginal, '0', self::SCALE) > 0) {
            $result['refund_state'] = $comparisonAmount !== null && bccomp($refundOriginal, $comparisonAmount, self::SCALE) >= 0 ? 'full' : 'partial';
        }
        if (($root['status'] ?? '') === 'V' && $result['refund_state'] !== 'full') {
            $result['refund_pending'] = true;
            $result['payable'] = null;
            $review[] = '付款已冲销，退款流水待核对';
        }
        $result['review'] = implode('；', array_unique($review));
        if ($includeDetails) {
            $result['events'] = $details;
        }
        return $result;
    }

    private function matchesDisputeHold(array $release, ?array $hold): bool
    {
        // PayPal can use the same transaction ID for a pending hold and its cancellation.
        return $hold !== null && $hold['currency'] === $release['currency']
            && in_array($hold['status'], ['P', 'S'], true) && $hold['time'] <= $release['time']
            && $hold['amount'] !== null && $release['amount'] !== null
            && bccomp($release['amount'], '0', self::SCALE) > 0
            && bccomp(bcadd($hold['amount'], $release['amount'], self::SCALE), '0', self::SCALE) === 0;
    }

    private function addMoney(array &$money, array &$review, string $field, ?string $value, int $sign): void
    {
        if ($value === null) {
            $money[$field] = null;
            $review[$field] = '部分资金流水待折算或核对';
        } elseif ($money[$field] !== null) {
            $money[$field] = bcadd($money[$field], bcmul($value, (string) $sign, self::SCALE), self::SCALE);
        }
    }

    private function balance(array $money, array $plus, array $minus, string $sum = '0'): ?string
    {
        foreach ([1 => $plus, -1 => $minus] as $sign => $fields) {
            foreach ($fields as $field) {
                if ($money[$field] === null) {
                    return null;
                }
                $sum = bcadd($sum, bcmul($money[$field], (string) $sign, self::SCALE), self::SCALE);
            }
        }
        return $sum;
    }

    private function remainingHold(array $holds): ?string
    {
        $total = '0';
        foreach ($holds as $entries) {
            $remaining = '0';
            $frozen = [];
            $released = false;
            foreach ($entries as $entry) {
                $remaining = bcsub($remaining, $entry['amount'], self::SCALE);
                if (bccomp($entry['amount'], '0', self::SCALE) < 0) {
                    $frozen[] = $entry;
                } else {
                    $released = true;
                }
            }
            $sign = bccomp($remaining, '0', self::SCALE);
            if ($sign === 0) {
                continue;
            }
            if ($sign < 0 || ($released && count($frozen) !== 1)) {
                return null;
            }
            foreach ($frozen as $entry) {
                $usd = $released ? bcdiv(bcmul($remaining, $entry['usd'], self::SCALE), $entry['amount'], self::SCALE)
                    : bcsub('0', $entry['usd'], self::SCALE);
                $total = bcadd($total, $usd, self::SCALE);
            }
        }
        return $total;
    }

    private function exchange(array $event, ?string $amount, array $conversions, array &$usedExchanges, bool $release = false): ?string
    {
        if ($amount === null || $event['currency'] === 'USD' || bccomp($amount, '0', self::SCALE) === 0) {
            return $amount;
        }
        $groups = [];
        $direction = bccomp($amount, '0', self::SCALE);
        $references = $release ? [$event['reference']] : array_unique([$event['reference'], $event['parent']]);
        foreach ($references as $reference) {
            foreach ($conversions[$reference] ?? [] as $conversion) {
                if ($conversion['amount'] === null || $conversion['status'] !== 'S'
                    || ($release ? $conversion['time'] < $event['time'] : $conversion['time'] !== $event['time'])) {
                    continue;
                }
                // Each exchange pair must share its reference and posting time.
                $groups[$reference][$conversion['time']][] = $conversion;
            }
        }
        $matches = [];
        foreach ($groups as $times) {
            foreach ($times as $group) {
                $original = array_filter($group, fn ($item) => $item['currency'] === $event['currency']
                    && bccomp(bcadd($item['amount'], $amount, self::SCALE), '0', self::SCALE) === 0);
                $usd = array_values(array_filter($group, fn ($item) => $item['currency'] === 'USD'
                    && bccomp($item['amount'], '0', self::SCALE) === $direction));
                if (count($group) === 2 && count($original) === 1 && count($usd) === 1) {
                    $matches[$usd[0]['id']] = $usd[0]['amount'];
                }
            }
        }
        $id = array_key_first($matches);
        if (count($matches) !== 1 || isset($usedExchanges[$id])) {
            return null;
        }
        $usedExchanges[$id] = true;
        return $matches[$id];
    }

    private function paymentStatus(array $payment): string
    {
        $status = [$payment['success'] ? '已付款' : ($payment['failed'] ? '付款失败' : '付款待确认')];
        if ($payment['refund_state'] !== 'none') {
            $status[] = $payment['refund_state'] === 'full' ? '全额退款' : '部分退款';
        }
        if ($payment['refund_pending']) {
            $status[] = '退款待确认';
        }
        if ($payment['open_hold'] === null && $payment['had_hold']) {
            $status[] = '返还待核对';
        } elseif (bccomp($payment['open_hold'] ?? '0', '0', self::SCALE) > 0) {
            $status[] = '仍有冻结';
        } elseif ($payment['had_hold']) {
            $status[] = '冻结已解除';
        }

        return implode(' · ', $status);
    }

    private function detail(array $event, string $zone, ?string $usd = null, string $source = '接口原始流水'): array
    {
        return [
            ...$event,
            'label' => match ($event['code']) {
                'T1201' => '撤销付款', 'T1202' => '撤销返还', default => FinancePayPalEventTypes::label($event['code']),
            },
            'category' => ($event['configured_category'] ?? '') ?: (FinancePayPalEventTypes::TYPES[$event['code']][1]
                ?? (in_array($event['code'], ['T1201', 'T1202'], true) ? '退款' : '其他事件')),
            'timestamp' => $event['time'],
            'time' => CarbonImmutable::createFromTimestamp($event['time'], $zone)->format('Y-m-d H:i:s'),
            'timezone' => $zone,
            'net' => $event['amount'] === null ? null : bcadd($event['amount'], $event['fee'] ?? '0', self::SCALE),
            'usd' => $usd, 'source' => $source,
            'rate' => $usd !== null && $event['amount'] !== null && bccomp($event['amount'], '0', self::SCALE) !== 0
                ? bcdiv($usd, $event['amount'], self::SCALE) : null,
        ];
    }
}
