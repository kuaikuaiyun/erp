<?php

namespace App\Portals\Payment;

use App\Portals\Logistics\LogisticsCreditCardRefundReason;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentCreditCardRiskService
{
    /** @var list<string> */
    private const GATEWAYS = ['Fintrix', 'FintrixV2', 'manual'];

    /** @return array<string,mixed> */
    public function dashboard(array $filters): array
    {
        $cacheKey = 'payment:credit-card-risk:v1:'.sha1(json_encode($filters));

        return Cache::flexible($cacheKey, [60, 300], fn (): array => $this->buildDashboard($filters));
    }

    /** @return array<string,mixed> */
    private function buildDashboard(array $filters): array
    {
        $connection = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $orders = $connection->table('logistics_orders as orders')
            ->whereIn('orders.payment_gateway', self::GATEWAYS)
            ->whereNotNull('orders.paid_at');
        $this->applyRange($orders, 'orders.paid_at', $filters);

        $summary = (clone $orders)->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(orders.paid_amount_usd), 0) as paid_amount')->first();
        $allRefunds = $connection->table('logistics_credit_card_refunds as refunds')
            ->joinSub((clone $orders)->select('orders.id'), 'selected_orders', fn ($join) => $join->on('selected_orders.id', '=', 'refunds.logistics_order_id'));
        $reasonOptions = (clone $allRefunds)
            ->whereNotNull('refunds.refund_reason')
            ->where('refunds.refund_reason', '<>', '')
            ->distinct()
            ->orderBy('refunds.refund_reason')
            ->pluck('refunds.refund_reason')
            ->map(static fn ($reason): string => (string) $reason)
            ->all();
        $refunds = clone $allRefunds;
        $selectedReasons = $filters['reasons'] ?? [];
        if ($selectedReasons !== []) {
            $refunds->whereIn('refunds.refund_reason', $selectedReasons);
        }
        $disputeReasons = array_map(fn (string $reason): string => mb_strtolower(trim($reason)), LogisticsCreditCardRefundReason::DISPUTE);
        $refundRows = (clone $refunds)->select('refunds.refund_reason')
            ->selectRaw('COALESCE(SUM(refunds.refund_amount_usd), 0) as amount')
            ->groupBy('refunds.refund_reason')->get();
        $refundAmount = $disputeAmount = 0.0;
        foreach ($refundRows as $row) {
            $isDispute = in_array(mb_strtolower(trim((string) $row->refund_reason)), $disputeReasons, true);
            if ($isDispute) {
                $disputeAmount += (float) $row->amount;
            } else {
                $refundAmount += (float) $row->amount;
            }
        }
        $isNonDispute = static function ($query): void {
            $query->whereNull('refunds.refund_reason')->orWhereNotIn('refunds.refund_reason', LogisticsCreditCardRefundReason::DISPUTE);
        };
        $refundOrders = (clone $refunds)->where($isNonDispute)->distinct()->count('refunds.logistics_order_id');
        $disputeOrders = (clone $refunds)->whereIn('refunds.refund_reason', LogisticsCreditCardRefundReason::DISPUTE)->distinct()->count('refunds.logistics_order_id');
        $orderCount = (int) ($summary->orders ?? 0);
        $paidAmount = round((float) ($summary->paid_amount ?? 0), 2);
        $riskPageQuery = $connection->table('logistics_credit_card_refunds as refunds')
            ->joinSub((clone $orders)->select(['orders.id', 'orders.shopify_order_number', 'orders.tongtu_order_number', 'orders.store_name', 'orders.payment_gateway', 'orders.paid_at', 'orders.paid_amount_usd']), 'risk_orders', fn ($join) => $join->on('risk_orders.id', '=', 'refunds.logistics_order_id'))
            ->select(['risk_orders.id', 'risk_orders.shopify_order_number', 'risk_orders.tongtu_order_number', 'risk_orders.store_name', 'risk_orders.payment_gateway', 'risk_orders.paid_at', 'risk_orders.paid_amount_usd'])
            ->groupBy('risk_orders.id', 'risk_orders.shopify_order_number', 'risk_orders.tongtu_order_number', 'risk_orders.store_name', 'risk_orders.payment_gateway', 'risk_orders.paid_at', 'risk_orders.paid_amount_usd')
            ->orderByDesc('risk_orders.paid_at')->orderByDesc('risk_orders.id');
        if ($selectedReasons !== []) {
            $riskPageQuery->whereIn('refunds.refund_reason', $selectedReasons);
        }
        $riskPage = $riskPageQuery->paginate((int) $filters['per_page'], ['*'], 'page', (int) $filters['page']);
        $pageOrderIds = collect($riskPage->items())->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $riskByOrder = [];
        if ($pageOrderIds !== []) foreach ((clone $refunds)->whereIn('refunds.logistics_order_id', $pageOrderIds)->get(['refunds.logistics_order_id', 'refunds.refund_reason', 'refunds.refund_amount_usd']) as $row) {
            $id = (int) $row->logistics_order_id;
            $isDispute = in_array(mb_strtolower(trim((string) $row->refund_reason)), $disputeReasons, true);
            $kind = $isDispute ? 'dispute' : 'refund';
            $riskByOrder[$id][$kind.'_amount'] = ($riskByOrder[$id][$kind.'_amount'] ?? 0.0) + (float) $row->refund_amount_usd;
            $riskByOrder[$id][$kind.'_reasons'][] = (string) $row->refund_reason;
        }
        $rows = collect($riskPage->items())
            ->map(function (object $order) use ($riskByOrder): array {
                $risk = $riskByOrder[(int) $order->id];
                return [
                    'order_number' => (string) ($order->shopify_order_number ?: '-'), 'erp_order' => (string) ($order->tongtu_order_number ?: '-'),
                    'store' => (string) ($order->store_name ?: '-'), 'gateway' => (string) $order->payment_gateway, 'paid_at' => (string) $order->paid_at,
                    'paid_amount' => (float) $order->paid_amount_usd, 'refund_amount' => round((float) ($risk['refund_amount'] ?? 0), 2),
                    'dispute_amount' => round((float) ($risk['dispute_amount'] ?? 0), 2),
                    'refund_reasons' => array_values(array_unique($risk['refund_reasons'] ?? [])), 'dispute_reasons' => array_values(array_unique($risk['dispute_reasons'] ?? [])),
                ];
            })->values();

        return [
            'module' => 'credit_card', 'period_start' => $filters['period_start'], 'period_end' => $filters['period_end'], 'filters' => $filters,
            'gateways' => self::GATEWAYS,
            'reason_options' => $reasonOptions,
            'kpis' => [
                ['key' => 'paid_orders', 'value' => number_format($orderCount).' / $'.number_format($paidAmount, 2), 'note_key' => 'paid_orders_note'],
                ['key' => 'refund', 'value' => '$'.number_format($refundAmount, 2).' / '.number_format($paidAmount > 0 ? $refundAmount / $paidAmount * 100 : 0, 2).'%', 'note_key' => 'refund_note', 'tone' => 'warning'],
                ['key' => 'full_refund', 'value' => number_format($disputeOrders).' / '.number_format($orderCount > 0 ? $disputeOrders / $orderCount * 100 : 0, 2).'%', 'note_key' => 'full_refund_note', 'tone' => 'danger'],
                ['key' => 'risk_cases', 'value' => number_format($refundOrders).' / '.number_format($orderCount > 0 ? $refundOrders / $orderCount * 100 : 0, 2).'%', 'note_key' => 'risk_cases_note'],
            ],
            'summary' => ['orders' => $orderCount, 'paid_amount' => $paidAmount, 'refund_orders' => $refundOrders, 'refund_amount' => round($refundAmount, 2), 'dispute_orders' => $disputeOrders, 'dispute_amount' => round($disputeAmount, 2)],
            'rows' => $rows,
            'pagination' => ['current_page' => $riskPage->currentPage(), 'last_page' => $riskPage->lastPage(), 'per_page' => $riskPage->perPage(), 'total' => $riskPage->total(), 'from' => $riskPage->firstItem() ?? 0, 'to' => $riskPage->lastItem() ?? 0],
        ];
    }

    private function applyRange(object $query, string $column, array $filters): void
    {
        if ($filters['period_start'] !== '') $query->where($column, '>=', Carbon::parse($filters['period_start'], 'Asia/Shanghai')->startOfDay()->utc());
        if ($filters['period_end'] !== '') $query->where($column, '<=', Carbon::parse($filters['period_end'], 'Asia/Shanghai')->endOfDay()->utc());
    }
}
