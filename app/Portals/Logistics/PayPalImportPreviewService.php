<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class PayPalImportPreviewService
{
    private const REFUND_EVENT_CODES = ['T1107', 'T1115', 'T1120'];

    public function __construct(private readonly PayPalSourceResponseParser $sourceResponses)
    {
    }

    /** @return array<string,mixed> */
    public function preview(array $payload): array
    {
        $merchantId = mb_strtoupper(trim((string) ($payload['merchant_id'] ?? '')));
        if ($merchantId === '') {
            throw new \RuntimeException('JSON 缺少 merchant_id。');
        }

        $account = LogisticsPayPalAccount::query()
            ->whereRaw('UPPER(merchant_id) = ?', [$merchantId])
            ->first();
        if (!$account) {
            throw new \RuntimeException('ERP 中不存在 Merchant ID '.$merchantId.' 对应的 PayPal 账号。');
        }

        $transactions = $this->sourceResponses->transactions($payload);
        $disputes = $this->sourceResponses->disputes($payload);
        $sourceErrors = $this->sourceResponses->errors($payload['errors'] ?? []);

        $paymentDetails = [];
        $refundDetails = [];
        $eventDistribution = [];
        $billNumbers = [];

        foreach ($transactions as $detail) {
            $info = is_array($detail['transaction_info'] ?? null) ? $detail['transaction_info'] : [];
            $eventCode = strtoupper(trim((string) ($info['transaction_event_code'] ?? '')));
            $eventKey = $eventCode !== '' ? $eventCode : 'UNKNOWN';
            $eventDistribution[$eventKey] = ($eventDistribution[$eventKey] ?? 0) + 1;

            if (str_starts_with($eventCode, 'T00')) {
                $paymentDetails[] = $detail;
                $billNumbers[] = trim((string) ($info['transaction_id'] ?? ''));
            }
            if (in_array($eventCode, self::REFUND_EVENT_CODES, true)) {
                $refundDetails[] = $detail;
                $billNumbers[] = trim((string) ($info['paypal_reference_id'] ?? ''));
            }
        }

        foreach ($disputes as $detail) {
            $billNumbers[] = $this->disputeTransactionId($detail);
        }

        $ordersByBillNumber = $this->ordersByBillNumber($billNumbers);
        $existingRefundIds = $this->existingRefundIds($account, $refundDetails);
        $existingDisputeIds = $this->existingDisputeIds($account, $disputes);

        $payments = collect($paymentDetails)
            ->map(fn (array $detail): array => $this->paymentRow($account, $ordersByBillNumber, $detail))
            ->values();
        $refunds = collect($refundDetails)
            ->map(fn (array $detail): array => $this->refundRow(
                $account,
                $ordersByBillNumber,
                $existingRefundIds,
                $detail,
            ))
            ->values();
        $disputeRows = collect($disputes)
            ->map(fn (array $detail): array => $this->disputeRow(
                $account,
                $ordersByBillNumber,
                $existingDisputeIds,
                $detail,
            ))
            ->values();

        arsort($eventDistribution);
        $eventRows = collect($eventDistribution)
            ->map(fn (int $count, string $code): array => [
                'code' => $code,
                'label' => $this->eventLabel($code),
                'count' => $count,
            ])
            ->values()
            ->all();

        return [
            'account' => [
                'id' => $account->getKey(),
                'account_name' => (string) $account->account_name,
                'merchant_id' => (string) $account->merchant_id,
                'account_email' => (string) ($account->account_email ?? ''),
                'environment' => (string) ($account->environment ?? ''),
                'is_enabled' => (bool) $account->is_enabled,
            ],
            'file' => [
                'upload_id' => (string) ($payload['upload_id'] ?? ''),
                'environment' => (string) ($payload['environment'] ?? ''),
                'generated_at' => (string) ($payload['generated_at'] ?? ''),
                'period_from' => (string) Arr::get($payload, 'period.from', ''),
                'period_to' => (string) Arr::get($payload, 'period.to', ''),
            ],
            'summary' => [
                'transactions' => count($transactions),
                'payment_events' => $payments->count(),
                'payment_matched' => $this->matchedCount($payments),
                'payment_unmatched' => $this->unmatchedCount($payments),
                'refunds' => $refunds->count(),
                'refund_matched' => $this->matchedCount($refunds),
                'refund_unmatched' => $this->unmatchedCount($refunds),
                'refund_existing' => $refunds->where('already_exists', true)->count(),
                'disputes' => $disputeRows->count(),
                'dispute_matched' => $this->matchedCount($disputeRows),
                'dispute_unmatched' => $this->unmatchedCount($disputeRows),
                'dispute_existing' => $disputeRows->where('already_exists', true)->count(),
                'source_errors' => count($sourceErrors),
            ],
            'source_errors' => $sourceErrors,
            'event_distribution' => $eventRows,
            'payments' => $payments->all(),
            'refunds' => $refunds->all(),
            'disputes' => $disputeRows->all(),
        ];
    }

    /** @param list<string> $billNumbers
     *  @return Collection<string,LogisticsOrder>
     */
    private function ordersByBillNumber(array $billNumbers): Collection
    {
        $billNumbers = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $billNumbers,
        ))));
        $orders = collect();

        foreach (array_chunk($billNumbers, 500) as $chunk) {
            LogisticsOrder::query()
                ->whereIn('bill_number', $chunk)
                ->get([
                    'id', 'shopify_order_number', 'bill_number', 'store_name',
                    'paypal_account_id', 'paypal_merchant_id',
                ])
                ->each(function (LogisticsOrder $order) use ($orders): void {
                    $key = trim((string) $order->bill_number);
                    if ($key !== '' && !$orders->has($key)) {
                        $orders->put($key, $order);
                    }
                });
        }

        return $orders;
    }

    /** @param list<array<string,mixed>> $details
     *  @return array<string,bool>
     */
    private function existingRefundIds(LogisticsPayPalAccount $account, array $details): array
    {
        $ids = collect($details)
            ->map(fn (array $detail): string => trim((string) Arr::get($detail, 'transaction_info.transaction_id', '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            return [];
        }

        return LogisticsPayPalRefund::query()
            ->where('logistics_paypal_account_id', $account->getKey())
            ->whereIn('refund_transaction_id', $ids)
            ->pluck('refund_transaction_id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
            ->all();
    }

    /** @param list<array<string,mixed>> $details
     *  @return array<string,bool>
     */
    private function existingDisputeIds(LogisticsPayPalAccount $account, array $details): array
    {
        $ids = collect($details)
            ->map(static fn (array $detail): string => trim((string) ($detail['dispute_id'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            return [];
        }

        return LogisticsPayPalDispute::query()
            ->where('logistics_paypal_account_id', $account->getKey())
            ->whereIn('dispute_id', $ids)
            ->pluck('dispute_id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
            ->all();
    }

    /** @return array<string,mixed> */
    private function paymentRow(
        LogisticsPayPalAccount $account,
        Collection $ordersByBillNumber,
        array $detail,
    ): array {
        $info = is_array($detail['transaction_info'] ?? null) ? $detail['transaction_info'] : [];
        $transactionId = trim((string) ($info['transaction_id'] ?? ''));

        return [
            'record_type' => 'payment',
            'event_code' => (string) ($info['transaction_event_code'] ?? ''),
            'event_label' => $this->eventLabel((string) ($info['transaction_event_code'] ?? '')),
            'transaction_id' => $transactionId,
            'reference_id' => (string) ($info['paypal_reference_id'] ?? ''),
            'amount' => (string) Arr::get($info, 'transaction_amount.value', ''),
            'currency' => (string) Arr::get($info, 'transaction_amount.currency_code', ''),
            'occurred_at' => (string) ($info['transaction_initiation_date'] ?? ''),
            'already_exists' => false,
            ...$this->matchOrder($account, $ordersByBillNumber, $transactionId),
        ];
    }

    /** @param array<string,bool> $existingIds
     *  @return array<string,mixed>
     */
    private function refundRow(
        LogisticsPayPalAccount $account,
        Collection $ordersByBillNumber,
        array $existingIds,
        array $detail,
    ): array {
        $info = is_array($detail['transaction_info'] ?? null) ? $detail['transaction_info'] : [];
        $refundId = trim((string) ($info['transaction_id'] ?? ''));
        $referenceId = trim((string) ($info['paypal_reference_id'] ?? ''));

        return [
            'record_type' => 'refund',
            'event_code' => (string) ($info['transaction_event_code'] ?? ''),
            'event_label' => $this->eventLabel((string) ($info['transaction_event_code'] ?? '')),
            'transaction_id' => $refundId,
            'reference_id' => $referenceId,
            'amount' => (string) Arr::get($info, 'transaction_amount.value', ''),
            'currency' => (string) Arr::get($info, 'transaction_amount.currency_code', ''),
            'occurred_at' => (string) ($info['transaction_initiation_date'] ?? ''),
            'already_exists' => isset($existingIds[$refundId]),
            ...$this->matchOrder($account, $ordersByBillNumber, $referenceId),
        ];
    }

    /** @param array<string,bool> $existingIds
     *  @return array<string,mixed>
     */
    private function disputeRow(
        LogisticsPayPalAccount $account,
        Collection $ordersByBillNumber,
        array $existingIds,
        array $detail,
    ): array {
        $disputeId = trim((string) ($detail['dispute_id'] ?? ''));
        $transactionId = $this->disputeTransactionId($detail);

        return [
            'record_type' => 'dispute',
            'event_code' => '',
            'event_label' => 'PayPal纠纷',
            'transaction_id' => $disputeId,
            'reference_id' => $transactionId,
            'amount' => (string) Arr::get($detail, 'dispute_amount.value', ''),
            'currency' => (string) Arr::get($detail, 'dispute_amount.currency_code', ''),
            'occurred_at' => (string) ($detail['create_time'] ?? ''),
            'status' => (string) ($detail['status'] ?? ''),
            'reason' => (string) ($detail['reason'] ?? ''),
            'already_exists' => isset($existingIds[$disputeId]),
            ...$this->matchOrder($account, $ordersByBillNumber, $transactionId),
        ];
    }

    private function disputeTransactionId(array $detail): string
    {
        $transactions = is_array($detail['disputed_transactions'] ?? null)
            ? $detail['disputed_transactions']
            : [];
        $transaction = is_array($transactions[0] ?? null) ? $transactions[0] : [];

        foreach (['seller_transaction_id', 'transaction_id', 'buyer_transaction_id'] as $field) {
            $value = trim((string) ($transaction[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @return array<string,mixed> */
    private function matchOrder(
        LogisticsPayPalAccount $account,
        Collection $ordersByBillNumber,
        string $billNumber,
    ): array {
        $billNumber = trim($billNumber);
        if ($billNumber === '') {
            return $this->unmatchedOrder('missing_reference', '缺少用于匹配的PayPal原交易号。');
        }

        /** @var LogisticsOrder|null $order */
        $order = $ordersByBillNumber->get($billNumber);
        if (!$order) {
            return $this->unmatchedOrder('not_found', '物流订单账单号中未找到 '.$billNumber.'。');
        }
        if ($order->paypal_account_id
            && (int) $order->paypal_account_id !== (int) $account->getKey()) {
            return [
                'match_status' => 'account_conflict',
                'match_reason' => '订单已经归属于其他PayPal账号。',
                ...$this->orderFields($order),
            ];
        }

        return [
            'match_status' => $order->paypal_account_id ? 'matched' : 'would_associate',
            'match_reason' => $order->paypal_account_id
                ? '账单号匹配成功，PayPal账号归属一致。'
                : '账单号匹配成功；正式导入时可关联当前PayPal账号。',
            ...$this->orderFields($order),
        ];
    }

    /** @return array<string,mixed> */
    private function unmatchedOrder(string $status, string $reason): array
    {
        return [
            'match_status' => $status,
            'match_reason' => $reason,
            'order_id' => null,
            'shopify_order_number' => '',
            'bill_number' => '',
            'store_name' => '',
            'order_paypal_account_id' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function orderFields(LogisticsOrder $order): array
    {
        return [
            'order_id' => $order->getKey(),
            'shopify_order_number' => (string) $order->shopify_order_number,
            'bill_number' => (string) $order->bill_number,
            'store_name' => (string) $order->store_name,
            'order_paypal_account_id' => $order->paypal_account_id,
        ];
    }

    private function matchedCount(Collection $rows): int
    {
        return $rows->whereIn('match_status', ['matched', 'would_associate'])->count();
    }

    private function unmatchedCount(Collection $rows): int
    {
        return $rows->count() - $this->matchedCount($rows);
    }

    private function eventLabel(string $code): string
    {
        return match (strtoupper(trim($code))) {
            'T0000' => '普通付款',
            'T0001' => '批量付款',
            'T0002' => '订阅付款',
            'T0003' => '周期账单预授权付款',
            'T0005' => '信用卡付款',
            'T0006' => 'Express Checkout付款',
            'T0007' => '标准网页付款',
            'T0017' => '连锁付款或退款',
            'T0023' => '企业付款',
            'T1106' => 'PayPal发起的付款撤销',
            'T1107' => '付款退款',
            'T1108' => '费用撤销',
            'T1109' => '手续费退款',
            'T1110' => '纠纷调查余额冻结',
            'T1111' => '纠纷余额冻结取消',
            'T1115' => '批量付款退款',
            'T1120' => '关联授权交易退款',
            'T1201' => '拒付',
            'T1202' => '拒付撤销',
            'T1205' => '拒付补偿',
            default => $code !== '' ? '其他PayPal事件' : '未提供事件代码',
        };
    }
}
