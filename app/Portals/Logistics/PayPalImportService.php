<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalDisputeRefund;
use App\Models\LogisticsPayPalRefund;
use App\Models\LogisticsPayPalImport;
use App\Models\LogisticsPayPalTransaction;
use App\Models\LogisticsPayPalBalanceSnapshot;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PayPalImportService
{
    private const REFUND_EVENT_CODES = ['T1107', 'T1115', 'T1120'];

    public function __construct(
        private readonly PayPalOrderAssociationService $associations,
        private readonly PayPalSourceResponseParser $sourceResponses,
    ) {
    }

    /** @return array<string,int|string> */
    public function import(
        LogisticsPayPalAccount $account,
        LogisticsPayPalImport $import,
        array $payload,
        ?callable $progress = null,
    ): array {
        $transactions = $this->sourceResponses->transactions($payload);
        $disputes = $this->sourceResponses->disputes($payload);
        $errors = $this->sourceResponses->errors($payload['errors'] ?? []);
        $balanceCount = $this->persistBalances($account, $import, $payload);
        $paymentCount = 0;
        $paymentMatched = 0;
        $paymentUnmatched = 0;
        $refundCount = 0;
        $disputeCount = 0;
        $matched = 0;
        $unmatched = 0;
        $itemErrors = [];
        $transactionTotal = count($transactions);

        $this->progress($progress, '正在处理 PayPal 付款与退款交易', 0, $transactionTotal);

        foreach ($transactions as $index => $detail) {
            $eventCode = strtoupper(trim((string) Arr::get(
                $detail,
                'transaction_info.transaction_event_code',
                '',
            )));

            if (str_starts_with($eventCode, 'T00')) {
                $paymentCount++;

                try {
                    $transactionId = trim((string) Arr::get($detail, 'transaction_info.transaction_id', ''));
                    if ($transactionId === '') {
                        throw new \RuntimeException('缺少付款交易号。');
                    }

                    $order = $this->persistPayment($account, $detail);
                    if ($order) {
                        $paymentMatched++;
                        $matched++;
                    } else {
                        $paymentUnmatched++;
                        $unmatched++;
                    }
                } catch (\Throwable $exception) {
                    $paymentUnmatched++;
                    $unmatched++;
                    $itemErrors[] = '付款记录 '.($index + 1).'：'.Str::limit($exception->getMessage(), 300);
                }
            }

            if (in_array($eventCode, self::REFUND_EVENT_CODES, true)) {
                try {
                    $wasMatched = $this->persistRefund($account, $detail);
                    $refundCount++;
                    $wasMatched ? $matched++ : $unmatched++;
                } catch (\Throwable $exception) {
                    $itemErrors[] = '退款记录 '.($index + 1).'：'.Str::limit($exception->getMessage(), 300);
                }
            }

            if (($index + 1) % 100 === 0 || ($index + 1) === $transactionTotal) {
                $this->progress(
                    $progress,
                    '正在处理 PayPal 付款与退款交易',
                    $index + 1,
                    $transactionTotal,
                );
            }
        }

        $disputeTotal = count($disputes);
        $this->progress($progress, '正在处理 PayPal 纠纷记录', 0, $disputeTotal);
        foreach ($disputes as $index => $detail) {
            if (!blank($detail['dispute_id'] ?? null)) {
                try {
                    $wasMatched = $this->persistDispute($account, $detail);
                    $disputeCount++;
                    $wasMatched ? $matched++ : $unmatched++;
                } catch (\Throwable $exception) {
                    $itemErrors[] = '纠纷记录 '.($index + 1).'：'.Str::limit($exception->getMessage(), 300);
                }
            }

            if (($index + 1) % 10 === 0 || ($index + 1) === $disputeTotal) {
                $this->progress(
                    $progress,
                    '正在处理 PayPal 纠纷记录',
                    $index + 1,
                    $disputeTotal,
                );
            }
        }

        $allErrors = [...$errors, ...$itemErrors];
        $processedCount = $paymentCount + $refundCount + $disputeCount;
        $status = $allErrors === [] ? 'success' : ($processedCount > 0 ? 'partial' : 'failed');
        $message = sprintf(
            '文件接收%s：交易 %d 条，付款事件 %d 条（已匹配 %d、未匹配 %d），退款 %d 条，纠纷 %d 条，全部业务记录已匹配 %d 条、未匹配 %d 条，异常 %d 条。',
            $status === 'success' ? '成功' : ($status === 'partial' ? '部分完成' : '失败'),
            count($transactions),
            $paymentCount,
            $paymentMatched,
            $paymentUnmatched,
            $refundCount,
            $disputeCount,
            $matched,
            $unmatched,
            count($allErrors),
        );
        if ($allErrors !== []) {
            $message .= ' '.implode('；', array_slice($allErrors, 0, 8));
        }
        $message = Str::limit($message, 1800);

        $import->forceFill([
            'status' => $status,
            'transaction_count' => count($transactions),
            'refund_count' => $refundCount,
            'dispute_count' => $disputeCount,
            'matched_count' => $matched,
            'unmatched_count' => $unmatched,
            'error_count' => count($allErrors),
            'message' => $message,
        ])->save();

        $period = is_array($payload['period'] ?? null) ? $payload['period'] : [];
        $account->forceFill([
            'last_imported_at' => now(),
            'last_import_from' => $this->dateTime($period['from'] ?? null),
            'last_import_to' => $this->dateTime($period['to'] ?? null),
            'last_import_status' => $status,
            'last_import_message' => $message,
        ])->save();

        return [
            'status' => $status,
            'transactions' => count($transactions),
            'payments' => $paymentCount,
            'payment_matched' => $paymentMatched,
            'payment_unmatched' => $paymentUnmatched,
            'refunds' => $refundCount,
            'disputes' => $disputeCount,
            'matched' => $matched,
            'unmatched' => $unmatched,
            'errors' => count($allErrors),
            'balances' => $balanceCount,
            'message' => $message,
        ];
    }

    private function persistBalances(LogisticsPayPalAccount $account, LogisticsPayPalImport $import, array $payload): int
    {
        $snapshot = Arr::get($payload, 'source_responses.balance_snapshot');
        if (!is_array($snapshot) || !is_array($snapshot['balances'] ?? null)) {
            return 0;
        }

        $count = 0;
        foreach ($snapshot['balances'] as $balance) {
            if (!is_array($balance)) {
                continue;
            }
            $currency = strtoupper(trim((string) ($balance['currency'] ?? Arr::get($balance, 'available_balance.currency_code', ''))));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                continue;
            }
            LogisticsPayPalBalanceSnapshot::query()->updateOrCreate([
                'upload_id' => $import->upload_id,
                'currency_code' => $currency,
            ], [
                'logistics_paypal_account_id' => $account->getKey(),
                'logistics_paypal_import_id' => $import->getKey(),
                'merchant_id' => $account->merchant_id,
                'total_balance' => $this->money(Arr::get($balance, 'total_balance.value')),
                'available_balance' => $this->money(Arr::get($balance, 'available_balance.value')),
                'withheld_balance' => $this->money(Arr::get($balance, 'withheld_balance.value')),
                'as_of_time' => $this->dateTime($snapshot['as_of_time'] ?? null),
                'last_refresh_time' => $this->dateTime($snapshot['last_refresh_time'] ?? null),
                'raw_payload' => $balance,
            ]);
            $count++;
        }

        return $count;
    }

    private function money(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 8, '.', '') : null;
    }

    private function persistPayment(LogisticsPayPalAccount $account, array $detail): bool
    {
        $info = is_array($detail['transaction_info'] ?? null) ? $detail['transaction_info'] : [];
        $transactionId = trim((string) ($info['transaction_id'] ?? ''));
        if ($transactionId === '') {
            throw new \RuntimeException(__('logistics.paypal_risk.messages.payment_transaction_missing'));
        }

        $amount = abs((float) Arr::get($info, 'transaction_amount.value', 0));
        $currency = strtoupper(trim((string) Arr::get($info, 'transaction_amount.currency_code')));
        $payment = LogisticsPayPalTransaction::query()->updateOrCreate([
            'logistics_paypal_account_id' => $account->getKey(),
            'payment_transaction_id' => $transactionId,
        ], [
            'invoice_id' => $info['invoice_id'] ?? null,
            'event_code' => $info['transaction_event_code'] ?? null,
            'status' => $info['transaction_status'] ?? null,
            'payment_amount_usd' => $currency === 'USD' ? $amount : null,
            'original_amount' => $amount,
            'currency_code' => $currency ?: null,
            'payment_subject' => $info['transaction_subject'] ?? $info['transaction_note'] ?? null,
            'paid_at' => $this->dateTime($info['transaction_initiation_date'] ?? null),
            'raw_payload' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => now(),
        ]);

        return $this->associations->reconcileTransaction($payment) !== null;
    }

    private function persistRefund(LogisticsPayPalAccount $account, array $detail): bool
    {
        $info = is_array($detail['transaction_info'] ?? null) ? $detail['transaction_info'] : [];
        $refundId = trim((string) ($info['transaction_id'] ?? ''));
        if ($refundId === '') {
            throw new \RuntimeException('缺少退款交易号。');
        }

        $originalTransactionId = trim((string) ($info['paypal_reference_id'] ?? ''));
        $order = $this->associations->orderForTransaction($account, $originalTransactionId);
        $amount = abs((float) Arr::get($info, 'transaction_amount.value', 0));
        $currency = strtoupper(trim((string) Arr::get($info, 'transaction_amount.currency_code')));

        $refund = LogisticsPayPalRefund::query()->updateOrCreate([
            'logistics_paypal_account_id' => $account->getKey(),
            'refund_transaction_id' => $refundId,
        ], [
            'logistics_order_id' => $order?->getKey(),
            'original_transaction_id' => $originalTransactionId ?: null,
            'event_code' => $info['transaction_event_code'] ?? null,
            'status' => $info['transaction_status'] ?? null,
            'refund_amount_usd' => $currency === 'USD' ? $amount : null,
            'original_amount' => $amount,
            'currency_code' => $currency ?: null,
            'invoice_id' => $info['invoice_id'] ?? null,
            'refund_reason' => $info['transaction_subject'] ?? $info['transaction_note'] ?? null,
            'refunded_at' => $this->dateTime($info['transaction_initiation_date'] ?? null),
            'raw_payload' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => now(),
        ]);
        $this->linkRefundToDisputes($refund);

        return (bool) $order;
    }

    private function persistDispute(LogisticsPayPalAccount $account, array $detail): bool
    {
        $disputeId = trim((string) ($detail['dispute_id'] ?? ''));
        if ($disputeId === '') {
            throw new \RuntimeException('缺少纠纷编号。');
        }

        $transactions = is_array($detail['disputed_transactions'] ?? null) ? $detail['disputed_transactions'] : [];
        $transaction = is_array($transactions[0] ?? null) ? $transactions[0] : [];
        $transactionId = $this->firstFilled([
            $transaction['seller_transaction_id'] ?? null,
            $transaction['transaction_id'] ?? null,
            $transaction['buyer_transaction_id'] ?? null,
        ]);
        $order = $this->associations->orderForTransaction($account, $transactionId);
        $amount = abs((float) Arr::get($detail, 'dispute_amount.value', 0));
        $currency = strtoupper(trim((string) Arr::get($detail, 'dispute_amount.currency_code')));

        $dispute = LogisticsPayPalDispute::query()->updateOrCreate([
            'logistics_paypal_account_id' => $account->getKey(),
            'dispute_id' => $disputeId,
        ], [
            'logistics_order_id' => $order?->getKey(),
            'disputed_transaction_id' => $transactionId ?: null,
            'reason' => $detail['reason'] ?? null,
            'status' => $detail['status'] ?? null,
            'dispute_state' => $detail['dispute_state'] ?? null,
            'life_cycle_stage' => $detail['dispute_life_cycle_stage'] ?? null,
            'channel' => $detail['dispute_channel'] ?? null,
            'dispute_amount_usd' => $currency === 'USD' ? $amount : null,
            'original_amount' => $amount,
            'currency_code' => $currency ?: null,
            'dispute_created_at' => $this->dateTime($detail['create_time'] ?? null),
            'dispute_updated_at' => $this->dateTime($detail['update_time'] ?? null),
            'seller_response_due_at' => $this->dateTime($detail['seller_response_due_date'] ?? null),
            'outcome_code' => Arr::get($detail, 'dispute_outcome.outcome_code'),
            'raw_payload' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => now(),
        ]);
        $this->linkDisputeToRefunds($dispute);

        return (bool) $order;
    }

    private function linkRefundToDisputes(LogisticsPayPalRefund $refund): void
    {
        $originalTransactionId = trim((string) $refund->original_transaction_id);
        if ($originalTransactionId === '') {
            return;
        }

        LogisticsPayPalDispute::query()
            ->where('logistics_paypal_account_id', $refund->logistics_paypal_account_id)
            ->where('disputed_transaction_id', $originalTransactionId)
            ->get(['id'])
            ->each(function (LogisticsPayPalDispute $dispute) use ($refund, $originalTransactionId): void {
                LogisticsPayPalDisputeRefund::query()->updateOrCreate([
                    'logistics_paypal_dispute_id' => $dispute->getKey(),
                    'logistics_paypal_refund_id' => $refund->getKey(),
                ], [
                    'match_source' => 'original_transaction',
                    'raw_reference' => $originalTransactionId,
                    'matched_at' => now(),
                ]);
            });
    }

    private function linkDisputeToRefunds(LogisticsPayPalDispute $dispute): void
    {
        $transactionId = trim((string) $dispute->disputed_transaction_id);
        if ($transactionId === '') {
            return;
        }

        LogisticsPayPalRefund::query()
            ->where('logistics_paypal_account_id', $dispute->logistics_paypal_account_id)
            ->where('original_transaction_id', $transactionId)
            ->get(['id'])
            ->each(function (LogisticsPayPalRefund $refund) use ($dispute, $transactionId): void {
                LogisticsPayPalDisputeRefund::query()->updateOrCreate([
                    'logistics_paypal_dispute_id' => $dispute->getKey(),
                    'logistics_paypal_refund_id' => $refund->getKey(),
                ], [
                    'match_source' => 'original_transaction',
                    'raw_reference' => $transactionId,
                    'matched_at' => now(),
                ]);
            });
    }

    private function dateTime(mixed $value): ?CarbonInterface
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<mixed> $values */
    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) ($value ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function progress(
        ?callable $callback,
        string $message,
        int $current = 0,
        int $total = 0,
    ): void {
        if ($callback) {
            $callback($message, $current, $total);
        }
    }
}
