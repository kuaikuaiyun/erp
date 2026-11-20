<?php

namespace App\Portals\Finance;

use App\Models\FinanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class FinancePayPalSettlementOrders
{
    public const USD_RECEIPT_LABEL = '收款金额-美元';
    public const COLUMNS = [
        'shopify_order_number' => 'Shopify订单号', 'order_status' => '当前订单状态',
        'open_hold' => '未解除冻结（USD）', 'payable' => '应结算（USD）',
        'review' => '核对说明',
        'paypal_reference_type' => '关联号类型',
    ];

    public const REFUND_SPLIT_COLUMNS = ['frozen_refund' => '冻结退款', 'refund_top_up' => '补差价退款'];
    public const ACCOUNT_RESERVE_NOTE = '预备金流水无原付款关联号，未计入订单应结算';
    private const LEGACY_REFUND_FIELDS = ['refund' => 'frozen_refund', 'refund_fee' => 'refund_top_up'];

    private const EVENTS = [
        'T2101' => 'hold', 'T2103' => 'hold', 'T2102' => 'release', 'T2104' => 'release',
        'T1110' => 'dispute_hold', 'T1111' => 'dispute_release',
        'T1106' => 'refund', 'T1107' => 'refund', 'T1201' => 'refund', 'T1202' => 'refund',
        'T0106' => 'refund_fee', 'T1109' => 'fee_return', 'T0114' => 'dispute_fee', 'T1108' => 'dispute_fee',
    ];

    private const COSTS = ['fee', 'hold', 'dispute_hold', 'refund', 'refund_fee', 'dispute_refund', 'dispute_fee'];
    private const RETURNS = ['fee_return', 'dispute_release', 'release'];
    private const SCALE = 8;
    private ?array $recordExportGraph = null;
    // Verified USD payments in the historical PayPal HKD statement before November 5.
    private const HISTORICAL_HKD_PAYMENT_REFERENCES = [
        '7M960508XY8098841', '8J190334VX070270G', '41880338N4901812T',
        '54A52495TC569712D', '1G461606GX8747721', '48B55181Y8669881R',
    ];

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
        $payablePosition = array_search('logistics', array_keys($columns), true);
        $columns = array_slice($columns, 0, $payablePosition, true)
            + ['payable' => self::COLUMNS['payable']] + array_slice($columns, $payablePosition, null, true);
        $columns = [...$columns, ...self::COLUMNS, 'settlement' => self::USD_RECEIPT_LABEL];
        $primary = array_flip(['shopify_order_number', 'reference', 'account', 'order_status',
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

    public static function splitRefundNote(array $row, string $field): ?string
    {
        $split = self::LEGACY_REFUND_FIELDS[$field] ?? null;
        if ($split === null || ! isset($row[$field], $row[$split])
            || bccomp($row[$field], '0', self::SCALE) !== 0 || bccomp($row[$split], '0', self::SCALE) <= 0) {
            return null;
        }

        return self::REFUND_SPLIT_COLUMNS[$split].'已列 '.FinanceCatalog::reportAmount($row[$split]).' USD';
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

    public function page(array $filters, int $offset, int $limit, bool $stopAtLimit = false): array
    {
        $db = $this->workbooks->database();
        $graph = in_array($db->getDriverName(), ['mysql', 'mariadb'], true)
            ? app(FinanceReportCache::class)->remember(
                'paypal-order-graph:v3', ['paypal'],
                fn () => $this->orderGraph(null),
            )
            : $this->orderGraph(null);
        if ($graph === null) {
            return [collect(), 0, collect(), true];
        }
        $orders = $this->ordersInDateRange($graph[0], $filters);

        $scope = array_intersect_key($filters, array_flip(['account', 'accounts', 'reference', 'team', 'teams', 'region', 'regions', 'domain', 'subscription', 'subscriptions', 'order_status', 'conditions']));
        if (! array_filter($scope)) {
            $rows = collect($this->matchingRows($filters, array_slice($orders, $offset, $limit), $graph, false));
            return [$rows, count($orders), $rows, true];
        }
        $status = $filters['order_status'] ?? '';
        if (in_array($status, ['已付款', '付款失败', '付款待确认', '待关联交易'], true)
            && ! array_filter(array_diff_key($scope, ['order_status' => true]))) {
            $orders = $this->ordersWithPostingStatus($orders, $graph[1], $status);
            $rows = collect($this->matchingRows($filters, array_slice($orders, $offset, $limit), $graph, false));

            return [$rows, count($orders), $rows, true];
        }
        $rows = collect();
        $subscriptions = [];
        $total = 0;
        foreach ($this->matchingRows($filters, $orders, $graph, false) as $row) {
            $subscription = $row['subscription'] ?? '';
            $subscriptions[$subscription] = ['subscription' => $subscription];
            if ($total >= $offset && $rows->count() < $limit) {
                $rows->push($row);
            }
            $total++;
            if ($stopAtLimit && $total >= $offset + $limit) {
                return [$rows, $total, collect($subscriptions), false];
            }
        }
        return [$rows, $total, collect($subscriptions), true];
    }

    private function ordersWithPostingStatus(array $orders, array $roots, string $status): array
    {
        if ($status === '待关联交易') {
            return array_filter($orders, fn (array $order) => isset($order['event']));
        }
        $statuses = $this->settlements->query('paypal', [])->toBase()->pluck('status', 'id');
        $edits = $this->workbooks->database()->table('finance_settlement_supplements')
            ->whereNotNull('payload->_edited_fields')->pluck('payload', 'finance_record_id')
            ->map(fn ($payload) => json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['_edited_fields'] ?? []);

        return array_filter($orders, function (array $order) use ($roots, $status, $statuses, $edits): bool {
            $id = $order['id'];
            if (isset($order['event']) || ! $statuses->has($id)) {
                return false;
            }
            $sourceStatus = $statuses->get($id);
            $flags = array_replace([
                'success' => FinanceSettlementService::isSuccessfulTransaction($sourceStatus),
                'failed' => in_array(strtoupper((string) $sourceStatus), FinanceSettlementService::CARD_FAILURE_STATUSES, true),
            ], $edits->get($id, []));

            // A voided root remains a completed payment for the list's posting status.
            $success = ($roots[$id]['status'] ?? null) === 'V' || $flags['success'];

            return $this->postingStatus($success, (bool) $flags['failed']) === $status;
        });
    }

    private function ordersInDateRange(array $orders, array $filters): array
    {
        if (! filled($filters['from'] ?? null) && ! filled($filters['to'] ?? null)) {
            return $orders;
        }

        $zone = $filters['timezone'];
        $from = filled($filters['from'] ?? null) ? CarbonImmutable::parse($filters['from'], $zone)->getTimestamp() : null;
        $until = filled($filters['to'] ?? null) ? CarbonImmutable::parse($filters['to'], $zone)->addDay()->getTimestamp() : null;

        // Graph timestamps are the same source dates used by the final row filter.
        return array_filter($orders, fn (array $order) => ($from === null || $order['time'] >= $from)
            && ($until === null || $order['time'] < $until));
    }

    public function rows(array $filters, ?int $recordId = null): Collection
    {
        if ($recordId === null) {
            return collect($this->exportRows($filters, matchCandidateDomains: false));
        }
        $graph = $this->orderGraph($recordId);
        return $graph === null ? collect() : collect($this->matchingRows($filters, $graph[0], $graph, true));
    }

    public function exportRows(array $filters, bool $matchCandidateDomains = true, ?array $recordIds = null, ?callable $onSourceRows = null): iterable
    {
        if ($recordIds === []) {
            return;
        }
        // Summary generation requests many small ID batches. Keep one graph for
        // that generator instead of rebuilding it for every page.
        $graph = $recordIds === null
            ? $this->orderGraph(null)
            : ($this->recordExportGraph ??= $this->orderGraph(null));
        if ($graph === null) {
            return;
        }
        $fields = $this->settlements->exportColumns('paypal') + ['gross' => '原币总额', 'success' => true];
        if ($recordIds !== null) {
            $orders = [];
            foreach ($recordIds as $recordId) {
                if (isset($graph[0]['payment:'.$recordId])) {
                    $orders[] = $graph[0]['payment:'.$recordId];
                }
            }
            $orders = $this->ordersInDateRange($orders, $filters);
        } else {
            $orders = $this->ordersInDateRange($graph[0], $filters);
        }
        foreach ($this->matchingRows($filters, $orders, $graph, false, $matchCandidateDomains, $onSourceRows) as $row) {
            yield array_intersect_key($row, $fields);
        }
    }

    public function releaseExportGraph(): void
    {
        $this->recordExportGraph = null;
    }

    /** Stream the fully calculated list rows for the background page projection. */
    public function projectionRows(): iterable
    {
        $graph = $this->recordExportGraph ??= $this->orderGraph(null);
        if ($graph === null) {
            return;
        }
        yield from $this->matchingRows(['timezone' => 'Asia/Shanghai'], $graph[0], $graph, false);
    }

    private function matchingRows(array $filters, array $orders, array $graph, bool $includeDetails, bool $matchCandidateDomains = false, ?callable $onSourceRows = null): iterable
    {
        if (! empty($filters['accounts'])) $orders = $this->accountCandidates($orders, $filters['accounts']);
        if (filled($filters['account'] ?? null)) {
            $orders = $this->accountCandidates($orders, $filters['account']);
        }
        [, $roots, $children, $conversions, $crossAccount, $ambiguous, $crossRates] = $graph;
        if (filled($filters['reference'] ?? null)) {
            $orders = $this->referenceCandidates($orders, $roots, $children, $filters['reference']);
        }
        $lockedMonths = $this->workbooks->database()->table('finance_period_locks')
            ->whereNotNull('report_snapshot')->pluck('month')->all();
        $numericConditionFields = $this->settlements->numericConditionFields('paypal');
        $count = count($orders);
        $context = null;
        $paymentQuery = null;
        for ($offset = 0; $offset < $count; $offset += 250) {
            $batch = array_slice($orders, $offset, 250);
            $ids = array_column(array_filter($batch, fn ($row) => ! isset($row['event'])), 'id');
            $paymentQuery ??= $this->settlements->query('paypal', []);
            $records = (clone $paymentQuery)->whereIntegerInRaw('id', $ids)
                ->orderByDesc('occurred_at')->orderByDesc('id')->get();
            $records->each(fn (FinanceRecord $record) => $record->withCachedReportCasts());
            $context ??= $this->settlements->rowContext('paypal');
            $payments = $this->settlements->rows('paypal', [
                'timezone' => $filters['timezone'], 'order_view' => true, 'match_candidate_domains' => $matchCandidateDomains,
            ], $records, $context)->keyBy('id');
            if ($onSourceRows !== null) $onSourceRows($payments);
            foreach ($batch as $order) {
                if (isset($order['event'])) {
                    $row = $this->unlinkedRow($order['event'], $filters['timezone'], $includeDetails, $lockedMonths);
                } else {
                    $payment = $payments->get($order['id']);
                    if ($payment === null || ! FinanceFilterSelection::rowMatches($payment, $filters, paypalPayment: true)
                        || (filled($filters['domain'] ?? null) && ! str_contains($payment['domain'] ?? '', $filters['domain']))
                        || (filled($filters['team'] ?? null) && $this->paymentTeam($payment) !== $filters['team'])
                        || (filled($filters['region'] ?? null) && FinanceSettlementService::operationRegion($this->paymentTeam($payment)) !== $filters['region'])
                        || (filled($filters['subscription'] ?? null) && (filled($payment['subscription'] ?? null) ? $payment['subscription'] : '未匹配')
                            !== FinanceSettlementService::subscriptionLabel($filters['subscription']))) {
                        continue;
                    }
                    $row = $this->paymentRow($payment, $roots, $children, $conversions, $crossAccount, $ambiguous, $crossRates, $filters['timezone'], $includeDetails);
                }
                if ($this->matches($row, $filters, $children, $numericConditionFields)) {
                    yield $row;
                }
            }
        }
    }

    private function referenceCandidates(array $orders, array $roots, array $children, string $reference): array
    {
        $db = $this->workbooks->database();
        $edited = $db->table('finance_settlement_supplements')->select('finance_record_id')
            ->where(fn ($query) => $query->where('payload->reference', $reference)
                ->orWhere('payload->_edited_fields->reference', $reference));
        $ids = $db->table('finance_records')->where('dataset', 'paypal-orders')->where('reference', $reference)
            ->select('id')->union($edited)->pluck('id')->flip()->all();

        return array_filter($orders, function (array $order) use ($roots, $children, $reference, $ids): bool {
            if (isset($order['event'])) {
                return $order['event']['reference'] === $reference;
            }
            $id = $order['id'];

            return isset($ids[$id]) || ($roots[$id]['reference'] ?? null) === $reference
                || in_array($reference, array_column($children[$id] ?? [], 'reference'), true);
        });
    }

    private function accountCandidates(array $orders, string|array $account): array
    {
        $selected = is_array($account) ? $account : [$account];
        $db = $this->workbooks->database();
        $linked = $db->table('finance_payment_transactions as payment')
            ->join('finance_payment_accounts as owner', 'owner.id', '=', 'payment.payment_account_id')
            ->where('owner.account_type', 'paypal')->whereNull('owner.deleted_at')
            ->select('payment.finance_record_id');
        FinanceFilterSelection::applyQuery($linked, 'owner.activity_account', $selected);
        $records = $db->table('finance_records')->where('dataset', 'paypal-orders');
        FinanceFilterSelection::applyQuery($records, 'account', $selected);
        $ids = $records->select('id')->union($linked)->pluck('id')->flip()->all();

        // Keep both potential sources of account ownership; matches() applies the final rule.
        return array_filter($orders, fn (array $order) => isset($order['event'])
            ? FinanceFilterSelection::matches($order['event']['account_key'], $selected)
            : isset($ids[$order['id']]));
    }

    public function forgetOrderGraph(): void
    {
        if (in_array($this->workbooks->database()->getDriverName(), ['mysql', 'mariadb'], true)) {
            app(FinanceReportCache::class)->forget('paypal-order-graph:v3');
            $this->orderGraphCache()->forget($this->orderGraphCacheKey());
        }
    }

    private function orderGraphCache(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('cache.default');

        // Avoid binding the full serialized graph as a SQL parameter.
        return Cache::store(config("cache.stores.{$store}.driver") === 'database' ? 'file' : $store);
    }

    public function isUnlinkedSettlement(int $recordId): bool
    {
        return isset($this->orderGraph($recordId)[0]['event:'.$recordId]);
    }

    private function orderGraphCacheKey(): string
    {
        $db = $this->workbooks->database();

        return 'finance:paypal-settlement:order-graph:v2:'.hash('sha256', $db->getName().':'.$db->getDatabaseName())
            .':'.app(FinanceSummaryDataVersion::class)->cacheRevision(['paypal']);
    }

    private function orderGraph(?int $recordId): ?array
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
                return null;
            }
            if ($detailAccountIds !== []) {
                $byId = (clone $paymentQuery)->pluck('id')->flip()->all();
            }
        } else {
            foreach ($paymentQuery->select('id', 'occurred_at')->toBase()->cursor() as $record) {
                $time = strtotime($record->occurred_at);
                $orders['payment:'.$record->id] = [
                    'id' => $record->id,
                    'time' => $time !== false ? $time : CarbonImmutable::parse($record->occurred_at)->getTimestamp(),
                ];
                $byId[$record->id] = true;
            }
        }
        $index = $invoiceRoots = $events = $extraEvents = $crossAccount = [];
        $removedSettlements = $db->table('finance_settlement_supplements')->whereNotNull('payload->_deleted_at');
        $removedEvents = (clone $removedSettlements)->where('payload->_deleted_unlinked_event', true)
            ->pluck('finance_record_id')->flip()->all();
        $removed = array_diff_key($removedSettlements->pluck('finance_record_id')->flip()->all(), $removedEvents);
        $accounts = $db->table('finance_payment_accounts')->whereNull('deleted_at')
            ->get(['id', 'account_name', 'activity_account'])->keyBy('id');
        $transactions = $db->table('finance_payment_transactions as txn')
            ->whereIntegerInRaw('txn.payment_account_id', $accounts->keys()->all());
        $withdrawals = (clone $transactions)->where('txn.event_code', 'like', 'T04%')
            ->join('finance_records as record', 'record.id', '=', 'txn.finance_record_id')
            ->get(['txn.payment_account_id', 'txn.transaction_id'])
            ->groupBy('payment_account_id')->map(fn ($rows) => $rows->pluck('transaction_id')->flip()->all())->all();
        $transactions->where('txn.event_code', 'not like', 'T04%');
        $settlementCodes = [...array_keys(self::EVENTS), 'T0200'];
        $settlementTransactions = (clone $transactions)->where(function ($query) use ($settlementCodes): void {
            $query->whereIn('txn.event_code', $settlementCodes)
                ->orWhereIn('txn.event_code', function ($types): void {
                    $types->select('raw_type')->from('finance_transaction_types')->where('category', '结账付款');
                });
        });
        if ($detailAccountIds !== null) {
            // Parent links are account-scoped, but duplicate payment IDs must be checked across accounts.
            $matchingRoots = $settlementTransactions->join('finance_records as record', 'record.id', '=', 'txn.finance_record_id')
                ->whereIn('txn.transaction_id', $detailTransactions->pluck('transaction_id')->unique())
                ->get(['txn.finance_record_id', 'txn.payment_account_id', 'txn.transaction_id']);
            foreach ($matchingRoots as $txn) {
                if (isset($byId[$txn->finance_record_id])) {
                    $this->addGraphMember($crossAccount, $txn->transaction_id, (int) $txn->payment_account_id);
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
        // Resolve IDs through the narrow event index before loading the wider JSON rows.
        $candidates = clone $transactions;
        if ($mysql) {
            $candidates->forceIndex($db->getSchemaBuilder()->hasIndex('finance_payment_transactions', 'finance_payment_account_events_idx')
                ? 'finance_payment_account_events_idx' : 'PRIMARY')->selectRaw('STRAIGHT_JOIN txn.id');
        } else {
            $candidates->select('txn.id');
        }
        $transactionIds = $candidates->pluck('id')->all();
        sort($transactionIds, SORT_NUMERIC);
        $transactions->join('finance_records as record', 'record.id', '=', 'txn.finance_record_id');
        if ($mysql) {
            $transactions->forceIndex('PRIMARY')->selectRaw('STRAIGHT_JOIN txn.id');
        } else {
            $transactions->select('txn.id');
        }
        $transactions->addSelect('txn.finance_record_id', 'txn.payment_account_id', 'txn.transaction_id',
            'txn.event_code', 'record.occurred_at', 'record.payload->'.FinancePayPalSourceEdits::KEY.'->payload as finance_source_edits');
        if ($mysql) {
            $source = $db->getQueryGrammar()->wrap('txn.raw_payload');
            $fields = ['paypal_reference_id', 'paypal_reference_id_type', 'invoice_id', 'transaction_amount',
                'fee_amount', 'transaction_status', 'transaction_updated_date'];
            $pairs = array_map(fn ($field) => "'{$field}', JSON_EXTRACT({$source}, '$.transaction_info.{$field}')", $fields);
            $transactions->selectRaw('JSON_OBJECT('.implode(', ', $pairs).') as transaction_info');
        } else {
            $transactions->addSelect('txn.raw_payload->transaction_info as transaction_info');
        }
        if ($recordId !== null) {
            $transactions->addSelect('event_type.category as configured_category');
        }
        $transactionRows = \Illuminate\Support\LazyCollection::make(function () use ($transactions, $transactionIds): iterable {
            foreach (array_chunk($transactionIds, 500) as $ids) {
                yield from (clone $transactions)->whereIntegerInRaw('txn.id', $ids)->orderBy('txn.id')->get();
            }
        });
        foreach ($transactionRows as $txn) {
            if (isset($removedEvents[(int) $txn->finance_record_id])) {
                continue;
            }
            $payment = isset($byId[(int) $txn->finance_record_id]);
            $deleted = isset($removed[(int) $txn->finance_record_id]);
            $settlementEvent = in_array($txn->event_code, $settlementCodes, true);
            $known = $settlementEvent || (($payment || $deleted)
                && ($recordId === null || $txn->configured_category === '结账付款'));
            if (! $known && ($recordId === null || $deleted)) {
                continue;
            }
            $info = json_decode((string) $txn->transaction_info, true) ?? [];
            $info = FinancePayPalSourceEdits::transactionInfo($info, json_decode((string) $txn->finance_source_edits, true) ?? []);
            $relatedId = $info['paypal_reference_id'] ?? '';
            $relatedType = $relatedId !== '' ? ($info['paypal_reference_id_type'] ?? '') : '';
            if (str_starts_with($txn->event_code, 'T02') && (isset($withdrawals[$txn->payment_account_id][$txn->transaction_id])
                || ($relatedType === 'TXN' && isset($withdrawals[$txn->payment_account_id][$relatedId])))) {
                continue;
            }
            $event = [
                'id' => (int) $txn->finance_record_id, 'account_id' => (int) $txn->payment_account_id,
                'account' => $accounts[$txn->payment_account_id]->account_name,
                'account_key' => $accounts[$txn->payment_account_id]->activity_account,
                'code' => $txn->event_code, 'reference' => $txn->transaction_id,
                'related_id' => $relatedId, 'related_type' => $relatedType,
                'invoice' => trim((string) ($info['invoice_id'] ?? '')),
                'parent' => $relatedType === 'TXN' ? $relatedId : '',
                'time' => strtotime($txn->occurred_at.' UTC') ?: CarbonImmutable::parse($txn->occurred_at, 'UTC')->getTimestamp(),
                'amount' => $this->settlements->number($info['transaction_amount']['value'] ?? null),
                'currency' => $info['transaction_amount']['currency_code'] ?? '',
                'fee' => $this->settlements->number($info['fee_amount']['value'] ?? null),
                'status' => $info['transaction_status'] ?? '',
            ];
            if ($event['code'] === 'T1111') {
                $updated = $info['transaction_updated_date'] ?? null;
                $event['updated_time'] = is_string($updated) ? strtotime($updated) : false;
            }
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
            $index[$event['account_id']] ??= [];
            $this->addGraphMember($index[$event['account_id']], $event['reference'], (int) $txn->id);
            if ($event['invoice'] !== '' && ($payment || ($deleted && ! $settlementEvent))) {
                $invoiceRoots[$event['account_id']] ??= [];
                $this->addGraphMember($invoiceRoots[$event['account_id']], $event['invoice'], $event['id']);
            }
            if ($payment) {
                $this->addGraphMember($crossAccount, $event['reference'], $event['account_id']);
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
                $index[$event['account_id']] ??= [];
                $this->addGraphMember($index[$event['account_id']], $event['reference'], (int) $id);
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
        // Only cross-account duplicates affect the result; unique references
        // need no per-reference array in the retained graph.
        $crossAccount = array_filter($crossAccount, 'is_array');

        $crossRates = [
            'monthly' => $db->table('finance_monthly_exchange_rates')->whereIn('channel', ['paypal', 'combined'])
                ->orderByRaw("CASE WHEN channel = 'paypal' THEN 0 ELSE 1 END")
                ->get(['month', 'currency', 'cny_rate'])->groupBy('month')
                ->map(fn ($rates) => $rates->unique('currency')->pluck('cny_rate', 'currency')->put('CNY', '1')),
            'paypal' => $this->referenceExchangeRates($roots, $conversions),
        ];
        foreach ($unlinked as $event) {
            $orders['event:'.$event['id']] = ['id' => $event['id'], 'time' => $event['time'], 'event' => $event];
        }
        if ($recordId === null) {
            uasort($orders, fn ($a, $b) => [$b['time'], $b['id']] <=> [$a['time'], $a['id']]);
        }
        return [$orders, $roots, $children, $conversions, $crossAccount, $ambiguous, $crossRates];
    }

    private function paymentRow(array $payment, array $roots, array $children, array $conversions, array $crossAccount, array $ambiguous, array $crossRates, string $zone, bool $includeDetails): array
    {
        $protected = $payment['editor_values'] ?? [];
        $id = $payment['id'];
        $root = $roots[$id] ?? null;
        $result = $this->calculate($payment, $root, $children[$id] ?? [], $conversions[$id] ?? [],
            $root && count($crossAccount[$root['reference']] ?? []) > 1, $crossRates, $zone, $includeDetails);
        $result['shop_difference_usd'] = $this->settlements->shopDifference(
            $result['settlement'], $payment['shop_paid_usd'] ?? null,
        );
        unset($result['shop_paid_usd'], $payment['shop_paid_usd']);
        $result['team'] = $this->paymentTeam($payment);
        $result['operation_region'] = FinanceSettlementService::operationRegion($result['team']);
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
        unset($payment['manual_values'], $payment['source_date'], $payment['editor_values'],
            $result['manual_values'], $result['source_date'], $result['editor_values']);
        $result['payments'] = [$payment];
        foreach (['subscription', 'upsell'] as $field) {
            if (blank($result[$field])) {
                $result[$field] = '未匹配';
            }
        }
        $result['source_category'] = '结账付款';
        $result['paypal_12'] = $root['related_id'] ?? $payment['paypal_12'];
        $result['paypal_reference_type'] = $root['related_type'] ?? '';

        return array_replace($result, $protected);
    }

    private function paymentTeam(array $payment): string
    {
        return $payment['subscription'] === '刷单' || str_contains((string) $payment['owner'], '刷单')
            ? '刷单' : $payment['operation_department'];
    }

    private function unlinkedRow(array $event, string $zone, bool $includeDetails, array $lockedMonths): array
    {
        $date = CarbonImmutable::createFromTimestamp($event['time'], 'UTC')->setTimezone($zone);
        $accountReserve = in_array($event['code'], ['T2103', 'T2104'], true)
            && $event['related_id'] === '' && $event['invoice'] === '';
        return [
            'id' => $event['id'], 'account' => $event['account'], 'reference' => $event['reference'],
            'paypal_12' => $event['related_id'], 'paypal_reference_type' => $event['related_type'],
            'source_category' => FinancePayPalEventTypes::TYPES[$event['code']][1] ?? '',
            'date' => $date->format('Y-m-d H:i:s'), 'month' => $date->format('Y-m'), 'timezone' => $zone,
            'subscription' => '未匹配', 'upsell' => '未匹配',
            'order_status' => '待关联交易',
            'review' => $accountReserve ? self::ACCOUNT_RESERVE_NOTE : '未找到唯一的原付款，未计入应结算',
            ...($includeDetails ? ['events' => [$this->detail($event, $zone)]] : []),
            'payments' => [],
            'locked' => in_array($date->setTimezone('Asia/Shanghai')->format('Y-m'), $lockedMonths, true),
            'unlinked_account' => $event['account_key'], 'unlinked_date' => $date,
        ];
    }

    private function addGraphMember(array &$map, string|int $key, int $id): void
    {
        // Most references have one member. Avoid one PHP hash table per event.
        if (! isset($map[$key])) $map[$key] = $id;
        elseif (is_array($map[$key])) $map[$key][$id] = true;
        elseif ($map[$key] !== $id) $map[$key] = [$map[$key] => true, $id => true];
    }

    private function graphMembers(int|array|null $members): array
    {
        return is_array($members) ? array_keys($members) : ($members === null ? [] : [$members]);
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
                $invoiceMatches += array_fill_keys($this->graphMembers($invoiceRoots[$current['account_id']][$current['invoice']] ?? null), true);
            }
            foreach ($this->graphMembers($index[$current['account_id']][$current['parent']] ?? null) as $id) {
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
                && FinanceFilterSelection::matches($account, $filters['accounts'] ?? [])
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
        return FinanceFilterSelection::rowMatches($row, $filters)
            && (! filled($filters['order_status'] ?? null) || str_contains($row['order_status'], $filters['order_status']))
            && (! filled($filters['team'] ?? null) || ($row['team'] ?? '') === $filters['team'])
            && (! filled($filters['region'] ?? null) || ($row['operation_region'] ?? '') === $filters['region'])
            && (! filled($filters['subscription'] ?? null) || ($row['subscription'] ?? '') === FinanceSettlementService::subscriptionLabel($filters['subscription']))
            && (! filled($filters['domain'] ?? null) || str_contains($row['domain'] ?? '', $filters['domain']))
            && $this->settlements->matches($row, $filters['conditions'] ?? [], $numericConditionFields);
    }

    private function calculate(array $payment, ?array $root, array $events, array $conversions, bool $duplicate, array $crossRates, string $zone, bool $includeDetails): array
    {
        $result = $payment;
        $manual = $payment['manual_values'];
        $protected = $payment['editor_values'] ?? [];
        $money = array_fill_keys([...self::COSTS, ...self::RETURNS], $root ? '0' : null);
        $refundSplit = array_fill_keys(array_keys(self::REFUND_SPLIT_COLUMNS), '0');
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
        $manualSplit = [];
        foreach (self::REFUND_SPLIT_COLUMNS as $field => $label) {
            $override = $this->settlements->number($manual[$field] ?? $manual[$label] ?? null);
            if ($override !== null) {
                $manualSplit[$field] = $override;
            }
        }
        $nonUsdReceipt = null;
        $crossRateSource = null;

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
            $historicalUsdReceipt = $root['currency'] === 'USD'
                && $complete
                && in_array($root['reference'], self::HISTORICAL_HKD_PAYMENT_REFERENCES, true);
            if ($net !== null && (($result['converted_usd'] === null && $root['currency'] !== 'USD') || $historicalUsdReceipt)) {
                $nonUsdReceipt = $this->exchangeLeg($root, $net, $conversions, $usedExchanges, false, null);
                if ($nonUsdReceipt !== null && $nonUsdReceipt['currency'] !== 'USD' && bccomp($net, '0', self::SCALE) > 0
                    && (! $historicalUsdReceipt || $nonUsdReceipt['currency'] === 'HKD')) {
                    [$crossRate, $crossRateSource] = $this->receiptCrossRate($nonUsdReceipt, $crossRates);
                    if ($crossRate !== null) {
                        $result['converted_usd'] = bcmul($nonUsdReceipt['amount'], $crossRate, self::SCALE);
                        $result['usd_rate'] = $rate = bcdiv($result['converted_usd'], $net, self::SCALE);
                    }
                }
            }
            $money['fee'] = $rate === null ? null : bcmul($fee, '-'.$rate, self::SCALE);
            $result['settlement'] = $complete && $result['converted_usd'] !== null && $money['fee'] !== null
                ? bcadd($result['converted_usd'], $money['fee'], self::SCALE) : null;
            $source = $crossRateSource ?? ($root['currency'] === 'USD' ? '接口USD' : ($converted !== null ? '实际换汇' : ($rate !== null ? '财务折算' : '待折算')));
            if ($includeDetails) {
                $details[] = [...$this->detail($root, $zone, $result['converted_usd'], $source),
                    'rate' => $rate, 'category' => '结账付款', 'usd_label' => '美元净额'];
            }

            $disputeHolds = collect($events)->where('code', 'T1110')->keyBy('reference');
            $pairedDisputes = [];
            foreach ($events as $event) {
                if ($event['code'] === 'T1111' && in_array($event['status'], ['S', 'D'], true)
                    && $this->matchesDisputeHold($event, $disputeHolds->get($event['reference']))) {
                    $pairedDisputes[$event['reference']] = true;
                }
            }
            $fundedRefund = $pairedDisputes !== [] && $root['currency'] !== 'USD'
                ? $this->refundFromReleasedHold($root, $events, $disputeHolds, $pairedDisputes, $conversions, $usedExchanges, $manualSplit['refund_top_up'] ?? null)
                : null;
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
                $fundedAmount = $fundedRefund['amounts'][$event['id']] ?? null;
                $refundFeeUsd = $fundedRefund['fees'][$event['id']] ?? null;
                $amount = $fundedAmount ?? ($effective || $rejectedRelease
                    ? $this->exchange($event, $event['amount'], $conversions, $usedExchanges, $rejectedRelease) : null);
                // Refund FX is often for the net debit after the fee is returned.
                if ($refund && $effective && $amount === null && $event['currency'] !== 'USD'
                    && $event['amount'] !== null && bccomp($event['fee'] ?? '0', '0', self::SCALE) > 0) {
                    $refundNet = bcadd($event['amount'], $event['fee'], self::SCALE);
                    if (bccomp($refundNet, '0', self::SCALE) < 0) {
                        $netUsd = $this->exchange($event, $refundNet, $conversions, $usedExchanges);
                        if ($netUsd !== null) {
                            $refundFeeUsd = bcmul($event['fee'], bcdiv($netUsd, $refundNet, self::SCALE), self::SCALE);
                            $amount = bcsub($netUsd, $refundFeeUsd, self::SCALE);
                        }
                    }
                }
                if ($rejectedRelease) {
                    $effective = $event['currency'] === 'USD'
                        ? isset($pairedDisputes[$event['reference']])
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
                    $fundedAmount !== null && $field !== 'dispute_hold' => '解冻资金用于退款',
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
                if ($fundedAmount !== null && $refund) {
                    $this->addMoney($refundSplit, $review, 'frozen_refund', $amount, -1);
                    $this->addMoney($refundSplit, $review, 'refund_top_up', $fundedRefund['shortfall_usd'], -1);
                } else {
                    $this->addMoney($money, $review, $field, $amount,
                        in_array($field, self::RETURNS, true) ? 1 : -1);
                }
                if (in_array($field, ['hold', 'release', 'dispute_hold', 'dispute_release'], true)) {
                    $kind = str_starts_with($field, 'dispute_') ? 'dispute' : 'reserve';
                    $key = $kind.'|'.$event['currency'];
                    if ($kind === 'dispute' && isset($pairedDisputes[$event['reference']])) {
                        $key .= '|'.$event['reference'];
                    }
                    $holds[$key][] = ['amount' => $event['amount'], 'usd' => $amount];
                }

                if ($refund && $event['fee'] !== null && bccomp($event['fee'], '0', self::SCALE) !== 0) {
                    $feeField = bccomp($event['fee'], '0', self::SCALE) > 0 ? 'fee_return' : 'refund_fee';
                    $this->addMoney($money, $review, $feeField, $event['currency'] === 'USD' ? $event['fee'] : $refundFeeUsd,
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
        $conflictingRefund = false;
        foreach (array_keys($money) as $field) {
            $splitField = self::LEGACY_REFUND_FIELDS[$field] ?? null;
            $override = $this->settlements->number($manual[$field] ?? $manual[$manualFields[$field] ?? ''] ?? null);
            if ($override !== null) {
                $manualHold = $manualHold || in_array($field, ['hold', 'release', 'dispute_hold', 'dispute_release'], true);
                $money[$field] = $override;
                unset($review[$field]);
                // A positive legacy amount may already cover the split deduction.
                if ($splitField !== null && bccomp($override, '0', self::SCALE) > 0) {
                    $refundSplit[$splitField] = '0';
                }
            } elseif ($root === null) {
                $money[$field] = $payment[$field];
            }
            if ($splitField !== null && isset($manualSplit[$splitField])
                && bccomp($money[$field] ?? '0', '0', self::SCALE) > 0
                && bccomp($manualSplit[$splitField], '0', self::SCALE) > 0) {
                $conflictingRefund = true;
            }
        }
        $money += array_replace($refundSplit, $manualSplit);
        foreach (array_intersect_key($protected, $money) as $field => $value) {
            $money[$field] = $this->settlements->number($value);
            $manualHold = $manualHold || in_array($field, ['hold', 'release', 'dispute_hold', 'dispute_release'], true);
        }
        if ($manualSplit !== []) {
            $review['manual_refund_split'] = '已人工补录'.implode('、', array_intersect_key(self::REFUND_SPLIT_COLUMNS, $manualSplit));
        }
        if ($conflictingRefund) {
            $review['refund_conflict'] = '旧退款列与人工补录的拆分退款同时有金额，待核对是否重复';
        }
        if ($root === null || isset($manual['settlement']) || isset($manual['结算金额-美元'])) {
            $result['settlement'] = $this->settlements->number($payment['settlement']);
        }
        if ($duplicate) {
            $review['duplicate'] = '跨账户存在相同交易号，待核对是否重复入账';
        }
        $candidateOrder = FinanceCatalog::displayTongtuOrder($payment['candidate_order']);
        if ($payment['candidate_domain_matched']) {
            $review['order'] = '已按域名匹配候选通途订单 '.$candidateOrder;
        } elseif ($payment['business_order_id'] === null) {
            $review['order'] = $payment['candidate_order'] === null
                ? '未匹配物流订单' : '未匹配物流订单，候选通途订单 '.$candidateOrder.'，待人工确认';
        }
        if ($crossRateSource !== null) {
            $review['rate'] = $crossRateSource;
        } elseif ($result['settlement'] === null && $complete) {
            $review['rate'] = $nonUsdReceipt === null || $nonUsdReceipt['currency'] === 'USD'
                ? '收款美元金额待折算'
                : '收款实际换入 '.$nonUsdReceipt['amount'].' '.$nonUsdReceipt['currency'].'，缺少美元折算依据';
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
        $result['payable'] = $duplicate || $conflictingRefund || $result['settlement'] === null || $open === null
            ? null : $this->balance($money, self::RETURNS, [...self::COSTS, ...array_keys($refundSplit)], $result['settlement']);
        if ($complete && $result['payable'] === null) {
            $review['payable'] = '应结算待核对';
        }
        $result['refund_pending'] = $refundPending;
        $result['refund_state'] = 'none';
        $comparisonAmount = $root['amount'] ?? $result['settlement'];
        if ($root === null || isset($manual['refund']) || isset($manual['退款金额'])
            || isset($manual['dispute_refund']) || isset($manual['争议退款'])) {
            $refundOriginal = bcadd(
                bcadd($money['refund'] ?? '0', $money['dispute_refund'] ?? '0', self::SCALE),
                bcadd($money['frozen_refund'] ?? '0', $money['refund_top_up'] ?? '0', self::SCALE),
                self::SCALE
            );
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
        return array_replace($result, $protected);
    }

    private function refundFromReleasedHold(array $root, array $events, Collection $holds, array $pairedDisputes,
        array $conversions, array &$usedExchanges, ?string $manualTopUp): ?array
    {
        $refunds = collect($events)->filter(fn ($event) => (self::EVENTS[$event['code']] ?? null) === 'refund'
            && $event['status'] === 'S' && $event['currency'] === $root['currency']);
        if ($refunds->count() !== 1 || $root['amount'] === null) {
            return null;
        }
        $refund = $refunds->first();
        if ($refund['amount'] === null || bccomp(bcadd($refund['amount'], $root['amount'], self::SCALE), '0', self::SCALE) !== 0
            || bccomp($refund['fee'] ?? '0', '0', self::SCALE) < 0) {
            return null;
        }

        // The release and full refund post together. The refund FX pair funds only the difference.
        $releases = collect($events)->filter(fn ($event) => $event['code'] === 'T1111' && $event['status'] === 'D'
            && $event['currency'] === $refund['currency'] && isset($pairedDisputes[$event['reference']])
            && $event['time'] <= $refund['time']
            // Some cancellations retain the hold's initiation time and record the release as an update.
            && ($event['updated_time'] === $refund['time']
                || ($event['time'] === $refund['time'] && $event['time'] > $holds->get($event['reference'])['time'])));
        if ($releases->count() !== 1) {
            return null;
        }
        $release = $releases->first();
        $refundNet = bcadd($refund['amount'], $refund['fee'] ?? '0', self::SCALE);
        $shortfall = bcadd($refundNet, $release['amount'], self::SCALE);
        if (bccomp($shortfall, '0', self::SCALE) > 0) {
            return null;
        }

        $probe = $usedExchanges;
        if ($this->exchange($release, $release['amount'], $conversions, $probe, true) !== null
            || $this->exchange($refund, $refundNet, $conversions, $probe) !== null) {
            return null;
        }
        $hold = $holds->get($release['reference']);
        $holdUsd = $this->exchange($hold, $hold['amount'], $conversions, $probe);
        if ($holdUsd === null) {
            return null;
        }
        $shortfallUsd = $this->exchange($refund, $shortfall, array_intersect_key($conversions, [$refund['reference'] => true]), $probe);
        if ($shortfallUsd === null && $manualTopUp === null) {
            return null;
        }

        $usedExchanges = $probe;
        $shortfallUsd ??= bcsub('0', $manualTopUp, self::SCALE);
        $refundRate = bcdiv(bcadd($holdUsd, $shortfallUsd, self::SCALE), $refundNet, self::SCALE);
        $feeUsd = bcmul($refund['fee'] ?? '0', $refundRate, self::SCALE);

        return [
            'amounts' => [$hold['id'] => $holdUsd, $release['id'] => bcsub('0', $holdUsd, self::SCALE), $refund['id'] => bcsub($holdUsd, $feeUsd, self::SCALE)],
            'fees' => [$refund['id'] => $feeUsd],
            'shortfall_usd' => $shortfallUsd,
        ];
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

    private function referenceExchangeRates(array $roots, array $conversions): array
    {
        $rates = [];
        foreach ($conversions as $id => $legs) {
            $root = $roots[$id] ?? null;
            if ($root === null || $root['currency'] !== 'USD' || $root['status'] !== 'S' || $root['amount'] === null) {
                continue;
            }
            $net = bcadd($root['amount'], $root['fee'] ?? '0', self::SCALE);
            if (bccomp($net, '0', self::SCALE) <= 0) {
                continue;
            }
            $destination = $this->exchangeLeg($root, $net, $legs, [], false, null);
            if ($destination !== null) {
                $month = CarbonImmutable::createFromTimestamp($destination['time'], 'Asia/Shanghai')->format('Y-m');
                $rates[$root['account_id']][$destination['currency']][$month][] = [
                    'time' => $destination['time'], 'reference' => $root['reference'],
                    'rate' => bcdiv($net, $destination['amount'], 16),
                ];
            }
        }

        return $rates;
    }

    private function receiptCrossRate(array $receipt, array $crossRates): array
    {
        $currency = $receipt['currency'];
        $month = CarbonImmutable::createFromTimestamp($receipt['time'], 'Asia/Shanghai')->format('Y-m');
        $monthly = $crossRates['monthly']->get($month);
        if (isset($monthly[$currency], $monthly['USD'])
            && bccomp((string) $monthly[$currency], '0', 16) > 0 && bccomp((string) $monthly['USD'], '0', 16) > 0) {
            return [bcdiv((string) $monthly[$currency], (string) $monthly['USD'], 16),
                '实际换入 '.$receipt['amount'].' '.$currency.'，按 '.$month.' 财务月汇率折算美元'];
        }

        $nearest = null;
        $nearestKey = null;
        foreach ($crossRates['paypal'][$receipt['account_id']][$currency][$month] ?? [] as $rate) {
            $key = [abs($rate['time'] - $receipt['time']), $rate['time'], $rate['reference']];
            if ($key[0] <= 2 * 86400 && ($nearestKey === null || $key < $nearestKey)) {
                $nearest = $rate;
                $nearestKey = $key;
            }
        }
        if ($nearest === null) {
            return [null, null];
        }

        return [$nearest['rate'], '实际换入 '.$receipt['amount'].' '.$currency.'，按同账户 PayPal 参考汇率折算美元（交易 '.$nearest['reference'].'）'];
    }

    private function exchange(array $event, ?string $amount, array $conversions, array &$usedExchanges, bool $release = false): ?string
    {
        if ($amount === null || $event['currency'] === 'USD' || bccomp($amount, '0', self::SCALE) === 0) {
            return $amount;
        }
        $leg = $this->exchangeLeg($event, $amount, $conversions, $usedExchanges, $release, 'USD');
        if ($leg === null) {
            return null;
        }
        $usedExchanges[$leg['id']] = true;

        return $leg['amount'];
    }

    private function exchangeLeg(array $event, string $amount, array $conversions, array $usedExchanges, bool $release, ?string $target): ?array
    {
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
                $destination = array_values(array_filter($group, fn ($item) => $item['currency'] !== $event['currency']
                    && ($target === null || $item['currency'] === $target)
                    && bccomp($item['amount'], '0', self::SCALE) === $direction));
                if (count($group) === 2 && count($original) === 1 && count($destination) === 1) {
                    $matches[$destination[0]['id']] = $destination[0];
                }
            }
        }
        $id = array_key_first($matches);
        if (count($matches) !== 1 || isset($usedExchanges[$id])) {
            return null;
        }
        return $matches[$id];
    }

    private function paymentStatus(array $payment): string
    {
        $status = [$this->postingStatus((bool) $payment['success'], (bool) $payment['failed'])];
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

    private function postingStatus(bool $success, bool $failed): string
    {
        return $success ? '已付款' : ($failed ? '付款失败' : '付款待确认');
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
