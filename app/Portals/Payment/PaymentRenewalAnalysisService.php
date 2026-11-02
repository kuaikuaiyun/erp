<?php

namespace App\Portals\Payment;

use App\Models\LogisticsCreditCardRefund;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderPaymentReference;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use App\Portals\Logistics\LogisticsCreditCardRefundReason;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PaymentRenewalAnalysisService
{
    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function dashboard(array $filters): array
    {
        $dataset = $this->dataset($filters);
        $rows = collect($dataset['rows']);

        if (($filters['stage'] ?? '') !== '') {
            $rows = $rows->where('stage_key', (string) $filters['stage'])->values();
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = in_array((int) ($filters['per_page'] ?? 50), [50, 100], true) ? (int) $filters['per_page'] : 50;
        $total = $rows->count();

        return [
            ...$dataset,
            'filters' => $filters,
            'rows' => $rows->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'pagination' => [
                'page' => $page,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'from' => $total ? (($page - 1) * $perPage) + 1 : 0,
                'to' => min($page * $perPage, $total),
            ],
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function exportData(array $filters): array
    {
        $dataset = $this->dataset($filters);
        if (($filters['stage'] ?? '') !== '') {
            $dataset['rows'] = collect($dataset['rows'])->where('stage_key', (string) $filters['stage'])->values()->all();
        }
        return $dataset;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function dataset(array $filters): array
    {
        $cacheFilters = $filters;
        unset($cacheFilters['page'], $cacheFilters['per_page'], $cacheFilters['stage']);
        $cacheKey = 'payment:renewal-analysis:'.sha1(json_encode($cacheFilters));

        return Cache::remember($cacheKey, now()->addMinutes(5), fn (): array => $this->buildDataset($cacheFilters));
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function buildDataset(array $filters): array
    {
        $from = Carbon::parse((string) $filters['date_from'])->startOfDay();
        $to = Carbon::parse((string) $filters['date_to'])->endOfDay();
        $maturedAt = now()->subDays(30)->endOfDay();

        $initialQuery = LogisticsOrder::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->where('paid_at', '<=', $maturedAt)
            ->where(function ($query): void {
                $query->whereNull('subscription_origin_order_id')->orWhere('subscription_origin_order_id', '');
            })
            // The cohort must contain only true first subscription charges.  A
            // malformed historical renewal without an origin reference must
            // not silently become a new cohort chain.
            ->where('subscription_type', '订阅首次购买')
            ->where(function ($query): void {
                $query->whereNull('operator_name')->orWhere('operator_name', 'not like', '%刷单%');
            });

        if (($filters['store'] ?? '') !== '') {
            $initialQuery->where('store_name', (string) $filters['store']);
        }
        if (($filters['keyword'] ?? '') !== '') {
            $keyword = '%'.trim((string) $filters['keyword']).'%';
            $initialQuery->where(function ($query) use ($keyword): void {
                $query->where('shopify_order_number', 'like', $keyword)
                    ->orWhere('transaction_id', 'like', $keyword)
                    ->orWhere('buyer_email', 'like', $keyword)
                    ->orWhere('subscription_origin_order_id', 'like', $keyword);
            });
        }

        $initials = $initialQuery->orderBy('paid_at')->orderBy('id')->get();
        $initials = $initials->filter(function (LogisticsOrder $order) use ($filters): bool {
            $channel = $this->channelFor($order);
            return ($filters['channel'] ?? '') === '' || $channel === $filters['channel'];
        })->values();

        $referenceMap = [];
        foreach ($initials as $initial) {
            foreach ($this->chainReferences($initial) as $reference) {
                $referenceMap[$reference] = (int) $initial->id;
            }
        }

        $renewals = empty($referenceMap)
            ? collect()
            : LogisticsOrder::query()
                ->whereIn('subscription_origin_order_id', array_keys($referenceMap))
                ->whereNotNull('paid_at')
                ->orderBy('paid_at')->orderBy('id')->get();

        $byInitial = [];
        foreach ($renewals as $renewal) {
            $initialId = $referenceMap[trim((string) $renewal->subscription_origin_order_id)] ?? null;
            if ($initialId) {
                $byInitial[$initialId][] = $renewal;
            }
        }

        $renewalOrderIds = $renewals->pluck('id')->map(fn ($id) => (int) $id)->all();
        $risk = $this->riskMaps($renewalOrderIds, $filters, $from, $to);
        $stages = [];
        $rows = [];
        $actualChannelTotals = ['paypal' => $this->emptyRiskTotal(), 'credit_card' => $this->emptyRiskTotal()];

        foreach ($initials as $initial) {
            $chain = collect($byInitial[(int) $initial->id] ?? [])->values();
            foreach ($chain as $index => $renewal) {
                // 阶段以同一首扣链的真实付款顺序为准，避免旧数据中的错误复购标签造成跳级或重复。
                $number = $index + 1;
                $key = 'renewal_'.$number;
                $channel = $this->channelFor($renewal);
                $refund = $risk['refunds'][(int) $renewal->id] ?? ['count' => 0, 'amount' => 0.0];
                $dispute = $risk['disputes'][(int) $renewal->id] ?? ['count' => 0, 'amount' => 0.0];

                $stages[$key] ??= ['key' => $key, 'number' => $number, 'completed_chains' => [], 'orders' => 0, 'revenue' => 0.0, 'refund_count' => 0, 'refund_amount' => 0.0, 'dispute_count' => 0, 'dispute_amount' => 0.0];
                $stages[$key]['completed_chains'][(int) $initial->id] = true;
                $stages[$key]['orders']++;
                $stages[$key]['revenue'] += (float) $renewal->paid_amount_usd;
                $stages[$key]['refund_count'] += $refund['count'];
                $stages[$key]['refund_amount'] += $refund['amount'];
                $stages[$key]['dispute_count'] += $dispute['count'];
                $stages[$key]['dispute_amount'] += $dispute['amount'];

                $actualChannelTotals[$channel]['orders']++;
                $actualChannelTotals[$channel]['revenue'] += (float) $renewal->paid_amount_usd;
                $actualChannelTotals[$channel]['refund_count'] += $refund['count'];
                $actualChannelTotals[$channel]['refund_amount'] += $refund['amount'];
                $actualChannelTotals[$channel]['dispute_count'] += $dispute['count'];
                $actualChannelTotals[$channel]['dispute_amount'] += $dispute['amount'];

                $rows[] = [
                    'stage_key' => $key,
                    'stage_number' => $number,
                    'initial_channel' => $this->channelFor($initial),
                    'actual_channel' => $channel,
                    'initial_order' => (string) ($initial->shopify_order_number ?: $initial->id),
                    'renewal_order' => (string) ($renewal->shopify_order_number ?: $renewal->id),
                    'store_name' => (string) ($renewal->store_name ?: $initial->store_name),
                    'transaction_id' => (string) $renewal->transaction_id,
                    'paid_at' => optional($renewal->paid_at)->format('Y-m-d H:i:s'),
                    'amount' => round((float) $renewal->paid_amount_usd, 2),
                    'refund_count' => $refund['count'],
                    'refund_amount' => round($refund['amount'], 2),
                    'dispute_count' => $dispute['count'],
                    'dispute_amount' => round($dispute['amount'], 2),
                ];
            }
        }

        ksort($stages, SORT_NATURAL);
        $eligible = $initials->count();
        $previousCompleted = $eligible;
        foreach ($stages as &$stage) {
            $completed = count($stage['completed_chains']);
            unset($stage['completed_chains']);
            $stage['eligible_chains'] = $previousCompleted;
            $stage['completed_chains'] = $completed;
            $stage['rate'] = $this->rate($completed, $previousCompleted);
            $stage['loss_count'] = max(0, $previousCompleted - $completed);
            $stage['loss_rate'] = $this->rate($stage['loss_count'], $previousCompleted);
            $stage['refund_rate'] = $this->rate($stage['refund_amount'], $stage['revenue']);
            $stage['dispute_rate'] = $this->rate($stage['dispute_count'], $stage['orders']);
            $previousCompleted = $completed;
        }
        unset($stage);

        $channelSummary = [];
        foreach (['paypal', 'credit_card'] as $channel) {
            $channelInitials = $initials->filter(fn (LogisticsOrder $order) => $this->channelFor($order) === $channel);
            $renewed = $channelInitials->filter(fn (LogisticsOrder $order) => ! empty($byInitial[(int) $order->id] ?? []))->count();
            $channelSummary[$channel] = ['eligible' => $channelInitials->count(), 'renewed' => $renewed, 'rate' => $this->rate($renewed, $channelInitials->count())];
        }
        $renewedChains = collect($byInitial)->filter(fn ($chain) => count($chain) > 0)->count();

        return [
            'summary' => [
                'eligible' => $eligible,
                'renewed' => $renewedChains,
                'rate' => $this->rate($renewedChains, $eligible),
                'paypal' => $channelSummary['paypal'],
                'credit_card' => $channelSummary['credit_card'],
                'renewal_orders' => count($rows),
                'renewal_revenue' => round(array_sum(array_column($rows, 'amount')), 2),
            ],
            'stages' => array_values($stages),
            'risk_by_channel' => $actualChannelTotals,
            'upsell' => $this->upsellSummary($renewalOrderIds, $risk),
            'rows' => $rows,
            'stores' => $initials->pluck('store_name')->filter()->unique()->sort()->values()->all(),
            'stage_options' => array_map(fn ($stage) => $stage['key'], array_values($stages)),
            'observation_days' => 30,
        ];
    }

    /** @return array<string,array<int,array{count:int,amount:float}>> */
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
            $cardQuery->whereBetween('last_imported_at', [$from, $to]);
        }

        foreach ($paypalRefundQuery->get() as $record) $this->accumulate($refunds, (int) $record->logistics_order_id, (float) $record->refund_amount_usd);
        foreach ($paypalDisputeQuery->get() as $record) $this->accumulate($disputes, (int) $record->logistics_order_id, (float) $record->dispute_amount_usd);
        foreach ($cardQuery->get() as $record) {
            $reason = LogisticsCreditCardRefundReason::normalize((string) $record->refund_reason);
            if (in_array($reason, LogisticsCreditCardRefundReason::DISPUTE, true)) {
                $this->accumulate($disputes, (int) $record->logistics_order_id, (float) $record->refund_amount_usd);
            } elseif (in_array($reason, LogisticsCreditCardRefundReason::CUSTOMER_SERVICE, true)) {
                $this->accumulate($refunds, (int) $record->logistics_order_id, (float) $record->refund_amount_usd);
            }
        }
        return compact('refunds', 'disputes');
    }

    private function accumulate(array &$map, int $orderId, float $amount): void
    {
        $map[$orderId] ??= ['count' => 0, 'amount' => 0.0];
        $map[$orderId]['count']++;
        $map[$orderId]['amount'] += $amount;
    }

    /** @return array<string,mixed> */
    private function upsellSummary(array $renewalOrderIds, array $risk): array
    {
        if ($renewalOrderIds === []) return ['linked_orders' => 0, 'references' => 0, 'unmeasured' => 0, 'measured_orders' => 0, 'revenue' => 0.0, 'refund_count' => 0, 'refund_amount' => 0.0, 'dispute_count' => 0, 'dispute_amount' => 0.0];
        $references = LogisticsOrderPaymentReference::query()->whereIn('logistics_order_id', $renewalOrderIds)->where('reference_type', LogisticsOrderPaymentReference::TYPE_UPSELL)->get();
        $taggedOrders = LogisticsOrder::query()->whereIn('id', $renewalOrderIds)->where('is_add_on', '已加购')->get();
        $refundCount = $refundAmount = $disputeCount = $disputeAmount = 0;
        foreach ($taggedOrders as $order) {
            $refund = $risk['refunds'][(int) $order->id] ?? ['count' => 0, 'amount' => 0.0];
            $dispute = $risk['disputes'][(int) $order->id] ?? ['count' => 0, 'amount' => 0.0];
            $refundCount += $refund['count']; $refundAmount += $refund['amount'];
            $disputeCount += $dispute['count']; $disputeAmount += $dispute['amount'];
        }
        return [
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
        $text = mb_strtolower((string) $order->payment_gateway.' '.(string) $order->payment_method);
        return $order->paypal_account_id || str_contains($text, 'paypal') ? 'paypal' : 'credit_card';
    }

    private function rate(float|int $numerator, float|int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : 0.0;
    }

    /** @return array<string,int|float> */
    private function emptyRiskTotal(): array
    {
        return ['orders' => 0, 'revenue' => 0.0, 'refund_count' => 0, 'refund_amount' => 0.0, 'dispute_count' => 0, 'dispute_amount' => 0.0];
    }
}
