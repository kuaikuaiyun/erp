<?php

namespace App\Portals\Payment;

use App\Models\LogisticsCreditCardRefund;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderPaymentReference;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use App\Models\LogisticsPayPalTransaction;
use App\Portals\Logistics\LogisticsCreditCardRefundReason;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PaymentRenewalAnalysisService
{
    // Avoid loading large Shopify/Tongtu payloads when reconstructing historical stages.
    private const ORDER_FIELDS = [
        'id', 'paid_at', 'paid_amount_usd', 'subscription_origin_order_id', 'subscription_type',
        'is_add_on', 'operator_name', 'store_name', 'transaction_id', 'shopify_order_id',
        'shopify_order_number', 'payment_gateway_order_id', 'bill_number',
        'payment_gateway', 'payment_method', 'paypal_account_id', 'paypal_association_source', 'buyer_email',
    ];

    /** Ordered groups shared by the page and Excel export. */
    public static function breakdownGroups(): array
    {
        return [
            'total' => ['channel'=>'total', 'method'=>'all', 'level'=>'total'],
            'paypal' => ['channel'=>'paypal', 'method'=>'all', 'level'=>'channel'],
            'method_ap' => ['channel'=>'paypal', 'method'=>'ap', 'level'=>'method'],
            'method_gp' => ['channel'=>'paypal', 'method'=>'gp', 'level'=>'method'],
            'method_pp' => ['channel'=>'paypal', 'method'=>'pp', 'level'=>'method'],
            'credit_card' => ['channel'=>'credit_card', 'method'=>'all', 'level'=>'channel'],
            'method_cc' => ['channel'=>'credit_card', 'method'=>'cc', 'level'=>'method'],
            'unknown' => ['channel'=>'unknown', 'method'=>'unknown', 'level'=>'channel'],
        ];
    }

    private function matchesGroup(array $row, string $key): bool
    {
        return $key === 'total' || (str_starts_with($key, 'method_')
            ? $row['payment_method'] === substr($key, 7) : $row['actual_channel'] === $key);
    }

    private function methodFor(LogisticsOrder $order): string
    {
        $method = strtolower(trim((string) $order->payment_method));
        return in_array($method, ['ap', 'gp', 'pp', 'cc'], true) ? $method : 'unknown';
    }

    /** @return array<string,string> */
    public static function timezones(): array
    {
        $options = [];
        for ($hour = -12; $hour <= 14; $hour++) {
            $offset = sprintf('%+03d:00', $hour);
            $options[$offset] = 'UTC'.$offset;
        }
        return $options;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function dashboard(array $filters): array
    {
        unset($filters['channel']);
        if (($filters['stage'] ?? '') === 'initial') $filters['stage'] = '';
        $dataset = $this->combinedData($filters);
        $total = count($dataset['stages']);
        $lastPage = max(1, (int) ceil($total / 10));
        $page = min($lastPage, max(1, (int) ($filters['stage_page'] ?? 1)));
        return [
            ...$dataset,
            'filters' => $filters,
            'stages' => array_slice($dataset['stages'], ($page - 1) * 10, 10),
            'stage_pagination' => ['current_page' => $page, 'last_page' => $lastPage, 'total' => $total,
                'from' => $total ? ($page - 1) * 10 + 1 : 0, 'to' => min($page * 10, $total)],
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function exportData(array $filters): array
    {
        return $this->combinedData($filters);
    }

    /** Drill into the same cached first-purchase population used by the summary. */
    public function initialOrderDetails(array $filters, array $detail, bool $paginate = true): array
    {
        $module = $this->moduleFilters($filters, 'initial');
        $dataset = $this->filterStages($this->dataset($module), $module);
        $scopeRows = array_values(array_filter($dataset['initial_rows'], fn (array $row): bool =>
            $this->matchesGroup($row, $detail['detail_group'])
            && ($detail['detail_purchase'] === 'all' || $row['purchase_type'] === $detail['detail_purchase'])
            && ($detail['detail_kind'] !== 'refunds' || $row['refund_count'] > 0)
        ));
        $stores = array_values(array_unique(array_column($scopeRows, 'store_name')));
        sort($stores, SORT_NATURAL | SORT_FLAG_CASE);
        $keyword = trim((string) ($detail['detail_keyword'] ?? ''));
        $rows = array_values(array_filter($scopeRows, static function (array $row) use ($detail, $keyword): bool {
            if ($keyword !== '' && !str_contains(mb_strtolower(implode(' ', [
                $row['erp_order_id'], $row['initial_order'], $row['transaction_id'], $row['store_name'], $row['bill_number'], $row['buyer_email'],
            ])), mb_strtolower($keyword))) return false;
            if (($detail['detail_store'] ?? '') !== '' && $row['store_name'] !== $detail['detail_store']) return false;
            if (($detail['detail_method'] ?? '') !== '' && $row['payment_method'] !== $detail['detail_method']) return false;
            if (($detail['detail_type'] ?? '') !== '' && $row['purchase_type'] !== $detail['detail_type']) return false;
            if (($detail['detail_refund'] ?? '') === 'yes' && $row['refund_count'] === 0) return false;
            if (($detail['detail_refund'] ?? '') === 'no' && $row['refund_count'] > 0) return false;
            $paidDate = substr($row['paid_at'], 0, 10);
            if (($detail['detail_from'] ?? '') !== '' && $paidDate < $detail['detail_from']) return false;
            if (($detail['detail_to'] ?? '') !== '' && $paidDate > $detail['detail_to']) return false;
            return true;
        }));
        usort($rows, static fn (array $a, array $b): int => strcmp($b['paid_at'], $a['paid_at']) ?: $b['erp_order_id'] <=> $a['erp_order_id']);
        $total = count($rows);
        $perPage = (int) ($detail['detail_per_page'] ?? 20);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($lastPage, max(1, (int) ($detail['detail_page'] ?? 1)));
        return [
            'scope' => $this->riskTotal($scopeRows, true),
            'summary' => $this->riskTotal($rows, true),
            'rows' => $paginate ? array_slice($rows, ($page - 1) * $perPage, $perPage) : $rows,
            'stores' => $stores, 'parent_store' => $module['store'], 'calculation' => $dataset['calculation'],
            'filters' => $detail,
            'pagination' => ['current_page'=>$page, 'last_page'=>$lastPage, 'total'=>$total, 'per_page'=>$perPage,
                'from'=>$total ? ($page - 1) * $perPage + 1 : 0, 'to'=>min($page * $perPage, $total)],
        ];
    }

    private function moduleFilters(array $filters, string $module): array
    {
        $timezone = $filters[$module.'_timezone'] ?? $filters['timezone'] ?? '+08:00';
        $today = now($timezone);
        return [
            'date_from' => $filters[$module.'_date_from'] ?? $filters['date_from'] ?? $today->copy()->subDays(89)->toDateString(),
            'date_to' => $filters[$module.'_date_to'] ?? $filters['date_to'] ?? $today->toDateString(),
            'timezone' => $timezone, 'store' => $filters[$module.'_store'] ?? $filters['store'] ?? '',
            'channel' => '', 'risk_date_mode' => 'payment',
            'stage' => $module === 'renewal' ? ($filters['stage'] ?? '') : '',
            'first_only' => $module === 'initial',
        ];
    }

    private function combinedData(array $filters): array
    {
        $initialFilters = $this->moduleFilters($filters, 'initial');
        $renewalFilters = $this->moduleFilters($filters, 'renewal');
        $initial = $this->filterStages($this->dataset($initialFilters), $initialFilters);
        $renewal = $this->filterStages($this->dataset($renewalFilters), $renewalFilters);
        $renewal['summary']['subscription_rate'] = $initial['summary']['subscription_rate'];
        foreach (['initial_rows', 'initial_refunds', 'initial_refunds_by_type'] as $key) $renewal[$key] = $initial[$key];
        $renewal['initial_calculation'] = $initial['calculation'];
        $renewal['initial_stores'] = $initial['stores'];
        $renewal['renewal_stores'] = $renewal['stores'];
        return $renewal;
    }

    private function filterStages(array $dataset, array $filters): array
    {
        // First-purchase refunds remain independent of renewal-stage filtering and pagination.
        $dataset['initial_rows'] = array_merge(array_values(array_filter($dataset['rows'], fn (array $row): bool => $row['stage_number'] === 0)), $dataset['non_subscription_rows']);
        $dataset['initial_refunds'] = $dataset['initial_refunds_by_type'] = [];
        foreach (array_keys(self::breakdownGroups()) as $channel) {
            $channelRows = array_values(array_filter($dataset['initial_rows'], fn (array $row): bool => $this->matchesGroup($row, $channel)));
            foreach (['all', 'subscription', 'non_subscription'] as $type) {
                $dataset['initial_refunds_by_type'][$channel][$type] = $this->riskTotal(array_values(array_filter(
                    $channelRows, fn (array $row): bool => $type === 'all' || $row['purchase_type'] === $type,
                )), true);
            }
            $dataset['initial_refunds'][$channel] = $dataset['initial_refunds_by_type'][$channel]['all'];
        }
        $dataset['stages'] = array_values(array_filter($dataset['stages'], fn (array $stage): bool => $stage['number'] > 0));
        $dataset['rows'] = array_values(array_filter($dataset['rows'], fn (array $row): bool => $row['stage_number'] > 0));
        $dataset['stage_options'] = array_values(array_filter($dataset['stage_options'], fn (string $key): bool => $key !== 'initial'));
        if (($filters['stage'] ?? '') === 'initial') $filters['stage'] = '';

        if (($filters['stage'] ?? '') !== '') {
            $dataset['stages'] = array_values(array_filter($dataset['stages'], fn (array $stage): bool => $stage['key'] === $filters['stage']));
            $dataset['rows'] = array_values(array_filter($dataset['rows'], fn (array $row): bool => $row['stage_key'] === $filters['stage']));
        }
        $dataset['summary'] = [
            ...$this->riskTotal($dataset['rows']),
            'subscription_rate' => $dataset['summary']['subscription_rate'],
        ];
        $dataset['risk_by_channel'] = [];
        foreach (['paypal', 'credit_card', 'unknown'] as $channel) {
            $dataset['risk_by_channel'][$channel] = $this->riskTotal(array_values(array_filter(
                $dataset['rows'], fn (array $row): bool => $row['actual_channel'] === $channel,
            )));
        }
        return $dataset;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function dataset(array $filters): array
    {
        $cacheFilters = $filters;
        $cacheFilters['channel'] = '';
        $cacheFilters['timezone'] ??= '+08:00';
        unset($cacheFilters['stage_page'], $cacheFilters['stage']);
        $cacheKey = 'payment:renewal-analysis:v16:'.sha1(json_encode($cacheFilters));

        $expiresAt = now()->addMinutes(5);
        return Cache::remember($cacheKey, $expiresAt, function () use ($cacheFilters, $expiresAt): array {
            $dataset = $this->buildDataset($cacheFilters);
            $dataset['calculation']['expires_at'] = $expiresAt->copy()->setTimezone($cacheFilters['timezone'])->format('Y-m-d H:i:s');
            return $dataset;
        });
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function buildDataset(array $filters): array
    {
        $timezone = $filters['timezone'] ?? '+08:00';
        $localFrom = Carbon::parse((string) $filters['date_from'], $timezone)->startOfDay();
        $localTo = Carbon::parse((string) $filters['date_to'], $timezone)->endOfDay();
        $localComparisonFrom = $localFrom->copy()->subDays(30);
        $localComparisonTo = $localTo->copy()->subDays(30);
        $from = $localFrom->copy()->utc();
        $to = $localTo->copy()->utc();
        $comparisonFrom = $localComparisonFrom->copy()->utc();
        $comparisonTo = $localComparisonTo->copy()->utc();

        $candidates = LogisticsOrder::query()->select(self::ORDER_FIELDS)
            ->where(function ($query) use ($from, $to, $comparisonFrom, $comparisonTo): void {
                $query->whereBetween('paid_at', [$from, $to])
                    ->orWhereBetween('paid_at', [$comparisonFrom, $comparisonTo]);
            })
            ->get();
        $initials = $this->periodInitials($candidates, $filters, $to);
        $nonSubscription = $candidates->filter(fn (LogisticsOrder $order): bool =>
            $order->subscription_type === '无订阅'
            && trim((string) $order->subscription_origin_order_id) === ''
            && !str_contains((string) $order->operator_name, '刷单')
            && $order->paid_at->betweenIncluded($from, $to)
            && (($filters['store'] ?? '') === '' || $order->store_name === $filters['store'])
        );
        $referenceMap = [];
        foreach ($initials as $initial) {
            foreach ($this->chainReferences($initial) as $reference) {
                $referenceMap[$reference] = (int) $initial->id;
            }
        }

        // Read the complete paid history of relevant chains, not just the current window.
        // Otherwise a September second renewal could incorrectly become its first renewal.
        $renewals = collect();
        foreach (($filters['first_only'] ? [] : array_chunk(array_keys($referenceMap), 1000)) as $references) {
            $batch = LogisticsOrder::query()->select(self::ORDER_FIELDS)
                ->whereIn('subscription_origin_order_id', $references)
                ->whereNotNull('paid_at')->where('paid_at', '<=', $to)
                ->get();
            foreach ($batch as $order) $renewals->put((int) $order->id, $order);
        }
        $renewals = $renewals->sortBy([['paid_at', 'asc'], ['id', 'asc']])->values();
        $initialMap = $initials->keyBy('id');
        $entries = [];
        foreach ($initials as $initial) $entries[] = ['order' => $initial, 'initial' => $initial, 'number' => 0];
        $seen = [];
        foreach ($renewals as $order) {
            $initialId = $referenceMap[trim((string) $order->subscription_origin_order_id)] ?? null;
            if ($initialId === null) continue;
            $seen[$initialId] = ($seen[$initialId] ?? 0) + 1;
            $entries[] = ['order' => $order, 'initial' => $initialMap->get($initialId), 'number' => $seen[$initialId]];
        }

        $current = [];
        $previousOrders = [];
        $previousChannels = [];
        $previousMethods = [];
        foreach ($entries as $entry) {
            $order = $entry['order'];
            $number = $entry['number'];
            if ($order->paid_at->betweenIncluded($from, $to)) $current[$number][] = $entry;
            if ($order->paid_at->betweenIncluded($comparisonFrom, $comparisonTo)) {
                $previousOrders[$number] = ($previousOrders[$number] ?? 0) + 1;
                $method = $this->methodFor($order);
                $previousMethods[$number][$method] = ($previousMethods[$number][$method] ?? 0) + 1;
                $channel = $this->channelFor($order);
                $previousChannels[$number][$channel] = ($previousChannels[$number][$channel] ?? 0) + 1;
            }
        }
        $orderIds = collect($current)->flatten(1)->map(fn (array $entry): int => (int) $entry['order']->id)->all();
        $orderIds = array_values(array_unique(array_merge($orderIds, $nonSubscription->modelKeys())));
        $risk = $this->riskMaps($orderIds, ['risk_date_mode' => 'payment', 'timezone' => $timezone], $from, $to);
        $nonSubscriptionRows = $nonSubscription->map(fn (LogisticsOrder $order): array => $this->paymentRow($order, $order, 0, $timezone, $risk, 'non_subscription'))->values()->all();
        $numbers = [0 => true];
        foreach (array_keys($current) as $number) $numbers[$number] = true;
        foreach (array_keys($previousOrders) as $number) $numbers[$number + 1] = true;
        ksort($numbers, SORT_NUMERIC);
        $stages = [];
        $rows = [];
        $renewalOrderIds = [];
        foreach (array_keys($numbers) as $number) {
            $key = $number === 0 ? 'initial' : 'renewal_'.$number;
            $stageRows = [];
            $paidFrom = $paidTo = null;
            foreach ($current[$number] ?? [] as $entry) {
                $order = $entry['order'];
                $initial = $entry['initial'];
                $paid = $order->paid_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s');
                $paidFrom = $paidFrom === null ? $paid : min($paidFrom, $paid);
                $paidTo = $paidTo === null ? $paid : max($paidTo, $paid);
                if ($number > 0) $renewalOrderIds[] = (int) $order->id;
                $stageRows[] = $this->paymentRow($order, $initial, $number, $timezone, $risk);
            }
            // Stage numbers still use chain history. The rate compares period order
            // counts directly and does not intersect the two periods' customers.
            $previousCount = $number > 0 ? ($previousOrders[$number - 1] ?? 0) : null;
            $currentCount = count($stageRows);
            $byChannel = [];
            foreach (array_keys(self::breakdownGroups()) as $channel) {
                $channelRows = array_values(array_filter($stageRows, fn (array $row): bool => $this->matchesGroup($row, $channel)));
                $channelBase = $number === 0 ? null : ($channel === 'total' ? $previousCount
                    : (str_starts_with($channel, 'method_') ? ($previousMethods[$number - 1][substr($channel,7)] ?? 0)
                        : ($previousChannels[$number - 1][$channel] ?? 0)));
                $byChannel[$channel] = [
                    ...$this->riskTotal($channelRows, true),
                    'previous_orders' => $channelBase,
                    'rate' => $channelBase > 0 ? $this->rate(count($channelRows), $channelBase) : null,
                ];
            }
            $stages[] = [
                ...$this->riskTotal($stageRows, true),
                'key' => $key, 'number' => $number,
                'previous_orders' => $previousCount,
                'rate' => $previousCount > 0 ? $this->rate($currentCount, $previousCount) : null,
                'paid_from' => $paidFrom, 'paid_to' => $paidTo,
                'by_channel' => $byChannel,
            ];
            array_push($rows, ...$stageRows);
        }
        $stores = $candidates->pluck('store_name')->merge($initials->pluck('store_name'))->push($filters['store'] ?? '')->filter()->unique()->sort()->values()->all();
        // Output rows now contain scalars; release historical model graphs before other queries.
        unset($candidates, $initials, $initialMap, $renewals, $batch, $entries, $current,
            $entry, $order, $initial, $referenceMap, $stageRows);
        return [
            'calculation' => [
                'period_from' => $localFrom->format('Y-m-d H:i:s'), 'period_to' => $localTo->format('Y-m-d H:i:s'),
                'comparison_from' => $localComparisonFrom->format('Y-m-d H:i:s'), 'comparison_to' => $localComparisonTo->format('Y-m-d H:i:s'),
                'utc_period_from' => $from->format('Y-m-d H:i:s'), 'utc_period_to' => $to->format('Y-m-d H:i:s'),
                'utc_comparison_from' => $comparisonFrom->format('Y-m-d H:i:s'), 'utc_comparison_to' => $comparisonTo->format('Y-m-d H:i:s'),
                'timezone' => 'UTC'.$timezone,
                'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                'expires_at' => now()->addMinutes(5)->setTimezone($timezone)->format('Y-m-d H:i:s'),
            ],
            'summary' => ['subscription_rate' => $this->subscriptionRate($from, $to, $filters)],
            'stages' => $stages, 'rows' => $rows,
            'non_subscription_rows' => $nonSubscriptionRows,
            'upsell' => $this->upsellSummary($renewalOrderIds, $risk),
            'stores' => $stores,
            'stage_options' => array_column($stages, 'key'),
        ];
    }

    /** @return array<string,mixed> */
    private function paymentRow(LogisticsOrder $order, LogisticsOrder $initial, int $number, string $timezone, array $risk, string $purchaseType = 'subscription'): array
    {
        $refund = $risk['refunds'][(int) $order->id] ?? ['count' => 0, 'amount' => 0.0, 'details' => []];
        $dispute = $risk['disputes'][(int) $order->id] ?? ['count' => 0, 'amount' => 0.0, 'details' => []];
        $paid = $order->paid_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s');
        return [
            'erp_order_id' => (int) $order->id,
            'stage_key' => $number === 0 ? 'initial' : 'renewal_'.$number, 'stage_number' => $number, 'purchase_type' => $purchaseType,
            'initial_channel' => $this->channelFor($initial), 'actual_channel' => $this->channelFor($order), 'payment_method' => $this->methodFor($order),
            'initial_order' => (string) ($initial->shopify_order_number ?: $initial->id),
            'renewal_order' => (string) ($order->shopify_order_number ?: $order->id),
            'store_name' => (string) ($order->store_name ?: $initial->store_name),
            'transaction_id' => (string) $order->transaction_id, 'paid_at' => $paid,
            'bill_number' => (string) $order->bill_number, 'buyer_email' => (string) $order->buyer_email,
            'amount' => round((float) $order->paid_amount_usd, 2), 'raw_amount' => (float) $order->paid_amount_usd,
            'refund_count' => $refund['count'], 'refund_amount' => round($refund['amount'], 2), 'raw_refund_amount' => $refund['amount'], 'refund_details' => $refund['details'],
            'dispute_count' => $dispute['count'], 'dispute_amount' => round($dispute['amount'], 2), 'raw_dispute_amount' => $dispute['amount'], 'dispute_details' => $dispute['details'],
        ];
    }

    /** @return Collection<int,LogisticsOrder> */
    private function periodInitials(Collection $candidates, array $filters, Carbon $to): Collection
    {
        $references = $candidates->pluck('subscription_origin_order_id')->map(fn ($value): string => trim((string) $value))->filter()->all();
        foreach ($candidates as $order) {
            if (trim((string) $order->subscription_origin_order_id) === '' && $order->subscription_type === '订阅首次购买') {
                $references[] = (string) $order->id;
            }
        }
        $initials = collect();
        foreach (array_chunk(array_values(array_unique($references)), 300) as $chunk) {
            $numericIds = array_values(array_filter($chunk, fn ($value): bool => ctype_digit((string) $value)));
            $query = LogisticsOrder::query()->select(self::ORDER_FIELDS)
                ->where('subscription_type', '订阅首次购买')->whereNotNull('paid_at')->where('paid_at', '<=', $to)
                ->where(function ($query): void {
                    $query->whereNull('subscription_origin_order_id')->orWhere('subscription_origin_order_id', '');
                })->where(function ($query): void {
                    $query->whereNull('operator_name')->orWhere('operator_name', 'not like', '%刷单%');
                })->where(function ($query) use ($chunk, $numericIds): void {
                    $query->whereIn('transaction_id', $chunk)->orWhereIn('shopify_order_id', $chunk)
                        ->orWhereIn('shopify_order_number', $chunk)->orWhereIn('payment_gateway_order_id', $chunk)
                        ->orWhereIn('bill_number', $chunk);
                    if ($numericIds !== []) $query->orWhereIn('id', $numericIds);
                });
            if (($filters['store'] ?? '') !== '') $query->where('store_name', $filters['store']);
            foreach ($query->get() as $order) {
                if (($filters['channel'] ?? '') === '' || $this->channelFor($order) === $filters['channel']) {
                    $initials->put((int) $order->id, $order);
                }
            }
        }
        return $initials->sortBy('id')->values();
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int|float> */
    private function riskTotal(array $rows, bool $raw = false): array
    {
        $total = $this->emptyRiskTotal();
        foreach ($rows as $row) {
            $total['orders']++;
            $total['revenue'] += $row[$raw ? 'raw_amount' : 'amount'];
            $total['refund_count'] += $row['refund_count'];
            $total['refund_order_count'] += $row['refund_count'] > 0 ? 1 : 0;
            $total['refund_amount'] += $row[$raw ? 'raw_refund_amount' : 'refund_amount'];
            $total['dispute_count'] += $row['dispute_count'];
            $total['dispute_amount'] += $row[$raw ? 'raw_dispute_amount' : 'dispute_amount'];
        }
        $total['refund_count_rate'] = $this->rate($total['refund_order_count'], $total['orders']);
        $total['refund_amount_rate'] = $this->rate($total['refund_amount'], $total['revenue']);
        $total['dispute_rate'] = $this->rate($total['dispute_count'], $total['orders']);
        return $total;
    }

    /** @return array<string,array<int,array{count:int,amount:float,details:list<array<string,mixed>>}>> */
    private function riskMaps(array $orderIds, array $filters, Carbon $from, Carbon $to): array
    {
        $refunds = [];
        $disputes = [];
        if ($orderIds === []) return compact('refunds', 'disputes');

        $paypalRefundQuery = LogisticsPayPalRefund::query()->whereIn('logistics_order_id', $orderIds);
        $paypalDisputeQuery = LogisticsPayPalDispute::query()->whereIn('logistics_order_id', $orderIds);
        $cardQuery = LogisticsCreditCardRefund::query()->whereIn('logistics_order_id', $orderIds);
        if (($filters['risk_date_mode'] ?? 'payment') === 'event') {
            $paypalRefundQuery->whereBetween('refunded_at', [$from, $to]);
            $paypalDisputeQuery->whereBetween('dispute_created_at', [$from, $to]);
            $cardQuery->whereBetween('occurred_at', [$from, $to]);
        }

        foreach ($paypalRefundQuery->select(['id', 'logistics_order_id', 'refund_amount_usd', 'manual_reason', 'refund_reason', 'event_code', 'status', 'refunded_at'])->lazyById(500) as $record) {
            $orderId = (int) $record->logistics_order_id;
            $this->accumulate($refunds, $orderId, (float) $record->refund_amount_usd);
            $this->appendRiskDetail($refunds, $orderId, [
                'amount' => (float) $record->refund_amount_usd,
                'reason' => (string) ($record->manual_reason ?: $record->refund_reason ?: $record->eventLabel()),
                'status' => $record->statusLabel(),
                'time' => $record->refunded_at?->copy()->setTimezone($filters['timezone'] ?? '+08:00')->format('Y-m-d H:i:s') ?: '-',
            ]);
        }
        foreach ($paypalDisputeQuery->select(['id', 'logistics_order_id', 'dispute_amount_usd', 'original_amount', 'reason', 'status', 'dispute_created_at'])->get()->groupBy('logistics_order_id') as $orderId => $records) {
            $this->accumulate($disputes, (int) $orderId, (float) $records->max(fn ($record): float => (float) ($record->dispute_amount_usd ?? $record->original_amount ?? 0)));
            foreach ($records as $record) {
                $this->appendRiskDetail($disputes, (int) $orderId, [
                    'amount' => (float) ($record->dispute_amount_usd ?? $record->original_amount ?? 0),
                    'reason' => $record->reasonLabel(),
                    'status' => $record->statusLabel(),
                    'time' => $record->dispute_created_at?->copy()->setTimezone($filters['timezone'] ?? '+08:00')->format('Y-m-d H:i:s') ?: '-',
                ]);
            }
        }
        $cardDisputes = [];
        foreach ($cardQuery->select(['id', 'logistics_order_id', 'refund_amount_usd', 'refund_reason', 'occurred_at'])->lazyById(500) as $record) {
            $reason = LogisticsCreditCardRefundReason::normalize((string) $record->refund_reason);
            if (in_array($reason, LogisticsCreditCardRefundReason::DISPUTE, true)) {
                $orderId = (int) $record->logistics_order_id;
                $cardDisputes[$orderId] = max($cardDisputes[$orderId] ?? 0.0, (float) $record->refund_amount_usd);
                $this->appendRiskDetail($disputes, $orderId, [
                    'amount' => (float) $record->refund_amount_usd,
                    'reason' => (string) $record->refund_reason,
                    'status' => '-',
                    'time' => $record->occurred_at?->copy()->setTimezone($filters['timezone'] ?? '+08:00')->format('Y-m-d H:i:s') ?: '-',
                ]);
            } elseif (in_array($reason, LogisticsCreditCardRefundReason::CUSTOMER_SERVICE, true)) {
                $orderId = (int) $record->logistics_order_id;
                $this->accumulate($refunds, $orderId, (float) $record->refund_amount_usd);
                $this->appendRiskDetail($refunds, $orderId, [
                    'amount' => (float) $record->refund_amount_usd,
                    'reason' => (string) $record->refund_reason,
                    'status' => '-',
                    'time' => $record->occurred_at?->copy()->setTimezone($filters['timezone'] ?? '+08:00')->format('Y-m-d H:i:s') ?: '-',
                ]);
            }
        }
        foreach ($cardDisputes as $orderId => $amount) $this->accumulate($disputes, (int) $orderId, (float) $amount);
        return compact('refunds', 'disputes');
    }

    private function accumulate(array &$map, int $orderId, float $amount): void
    {
        $map[$orderId] ??= ['count' => 0, 'amount' => 0.0, 'details' => []];
        $map[$orderId]['count']++;
        $map[$orderId]['amount'] += $amount;
    }

    /** @param array<int,array{count:int,amount:float,details:list<array<string,mixed>>}> $map @param array<string,mixed> $detail */
    private function appendRiskDetail(array &$map, int $orderId, array $detail): void
    {
        $map[$orderId] ??= ['count' => 0, 'amount' => 0.0, 'details' => []];
        $map[$orderId]['details'][] = $detail;
    }

    /** @param Collection<int,array<string,mixed>> $rows @return Collection<int,array<string,mixed>> */
    private function prioritiseRiskRows(Collection $rows): Collection
    {
        return $rows->sort(function (array $left, array $right): int {
            $leftRisk = (int) ($left['refund_count'] ?? 0) + (int) ($left['dispute_count'] ?? 0);
            $rightRisk = (int) ($right['refund_count'] ?? 0) + (int) ($right['dispute_count'] ?? 0);
            if (($leftRisk > 0) !== ($rightRisk > 0)) {
                return ($rightRisk > 0) <=> ($leftRisk > 0);
            }

            $leftAmount = (float) ($left['refund_amount'] ?? 0) + (float) ($left['dispute_amount'] ?? 0);
            $rightAmount = (float) ($right['refund_amount'] ?? 0) + (float) ($right['dispute_amount'] ?? 0);
            if ($leftAmount !== $rightAmount) {
                return $rightAmount <=> $leftAmount;
            }

            return strcmp((string) ($right['paid_at'] ?? ''), (string) ($left['paid_at'] ?? ''));
        })->values();
    }

    /** @param array<string,mixed> $filters @return array<string,array{subscription_orders:int,total_orders:int,rate:float}> */
    private function subscriptionRate(Carbon $from, Carbon $to, array $filters): array
    {
        $query = LogisticsOrder::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->whereIn('subscription_type', ['订阅首次购买', '无订阅'])
            ->whereRaw("TRIM(COALESCE(tongtu_order_number, '')) <> ''")
            ->where(function ($query): void {
                $query->whereNull('operator_name')->orWhere('operator_name', 'not like', '%刷单%');
            });

        if (($filters['store'] ?? '') !== '') {
            $query->where('store_name', (string) $filters['store']);
        }
        if (($filters['keyword'] ?? '') !== '') {
            $keyword = '%'.trim((string) $filters['keyword']).'%';
            $query->where(function ($query) use ($keyword): void {
                $query->where('shopify_order_number', 'like', $keyword)
                    ->orWhere('transaction_id', 'like', $keyword)
                    ->orWhere('buyer_email', 'like', $keyword);
            });
        }

        $orders = $query->select(['id', 'subscription_type', 'payment_gateway', 'payment_method', 'paypal_account_id', 'paypal_association_source'])->get();
        $paypalOrderIds = $orders->filter(fn (LogisticsOrder $order): bool => $this->channelFor($order) === 'paypal')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $matchedPayPalOrders = $paypalOrderIds === [] ? [] : LogisticsPayPalTransaction::query()
            ->whereIn('logistics_order_id', $paypalOrderIds)
            ->where('event_code', 'like', 'T00%')
            ->whereIn('status', ['S', 'V'])
            ->pluck('logistics_order_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();

        $counts = array_fill_keys(array_keys(self::breakdownGroups()), ['subscription_orders'=>0, 'total_orders'=>0]);
        foreach ($orders as $order) {
            $channel = $this->channelFor($order);
            if (($filters['channel'] ?? '') !== '' && $channel !== $filters['channel']) {
                continue;
            }
            if ($channel === 'paypal' && (
                $order->paypal_account_id === null
                ||
                $order->paypal_association_source !== 'actual_transaction'
                || !isset($matchedPayPalOrders[(int) $order->id])
            )) {
                continue;
            }

            $row = ['actual_channel'=>$channel, 'payment_method'=>$this->methodFor($order)];
            foreach ($counts as $key => &$count) {
                if (!$this->matchesGroup($row, $key)) continue;
                $count['total_orders']++;
                if ($order->subscription_type === '订阅首次购买') $count['subscription_orders']++;
            }
            unset($count);
        }

        return array_map(fn (array $count): array => [
            ...$count, 'rate'=>$this->rate($count['subscription_orders'], $count['total_orders']),
        ], $counts);
    }

    /** @return array<string,mixed> */
    private function upsellSummary(array $renewalOrderIds, array $risk): array
    {
        if ($renewalOrderIds === []) return ['reference_orders' => 0, 'scope_orders' => 0, 'linked_orders' => 0, 'references' => 0, 'unmeasured' => 0, 'measured_orders' => 0, 'revenue' => 0.0, 'refund_count' => 0, 'refund_amount' => 0.0, 'dispute_count' => 0, 'dispute_amount' => 0.0];
        $references = LogisticsOrderPaymentReference::query()->whereIn('logistics_order_id', $renewalOrderIds)->where('reference_type', LogisticsOrderPaymentReference::TYPE_UPSELL)->get(['logistics_order_id', 'gateway_order_id']);
        $taggedOrders = LogisticsOrder::query()->whereIn('id', $renewalOrderIds)->where('is_add_on', '已加购')->get(['id', 'paid_amount_usd']);
        $refundCount = $refundAmount = $disputeCount = $disputeAmount = 0;
        foreach ($taggedOrders as $order) {
            $refund = $risk['refunds'][(int) $order->id] ?? ['count' => 0, 'amount' => 0.0];
            $dispute = $risk['disputes'][(int) $order->id] ?? ['count' => 0, 'amount' => 0.0];
            $refundCount += $refund['count']; $refundAmount += $refund['amount'];
            $disputeCount += $dispute['count']; $disputeAmount += $dispute['amount'];
        }
        return [
            'reference_orders' => $references->pluck('logistics_order_id')->unique()->count(),
            'scope_orders' => count(array_unique($renewalOrderIds)),
            'linked_orders' => max($taggedOrders->count(), $references->pluck('logistics_order_id')->unique()->count()),
            'references' => $references->count(),
            'unmeasured' => $references->whereNull('gateway_order_id')->count(),
            'measured_orders' => $taggedOrders->count(),
            'revenue' => round((float) $taggedOrders->sum('paid_amount_usd'), 2),
            'refund_count' => $refundCount,
            'refund_amount' => round($refundAmount, 2),
            'dispute_count' => $disputeCount,
            'dispute_amount' => round($disputeAmount, 2),
        ];
    }

    /** @return list<string> */
    private function chainReferences(LogisticsOrder $order): array
    {
        return PaymentSubscriptionLifecycle::chainReferences($order->transaction_id, $order->shopify_order_id, $order->shopify_order_number, $order->payment_gateway_order_id, $order->bill_number, $order->id);
    }

    private function channelFor(LogisticsOrder $order): string
    {
        return match ($this->methodFor($order)) {
            'cc' => 'credit_card',
            'ap', 'gp', 'pp' => 'paypal',
            default => 'unknown',
        };
    }

    private function rate(float|int $numerator, float|int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : 0.0;
    }

    /** @return array<string,int|float> */
    private function emptyRiskTotal(): array
    {
        return ['orders' => 0, 'revenue' => 0.0, 'refund_order_count' => 0, 'refund_count' => 0, 'refund_amount' => 0.0, 'dispute_count' => 0, 'dispute_amount' => 0.0];
    }
}
