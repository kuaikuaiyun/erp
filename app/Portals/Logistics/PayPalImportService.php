<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use App\Models\LogisticsPayPalImport;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PayPalImportService
{
    private const REFUND_EVENT_CODES = ['T1107', 'T1115', 'T1120'];

    public function __construct(private readonly PayPalOrderAssociationService $associations)
    {
    }

    /** @return array<string,int|string> */
    public function import(
        LogisticsPayPalAccount $account,
        LogisticsPayPalImport $import,
        array $payload,
    ): array {
        $transactions = $this->records($payload['transactions'] ?? [], 'transaction_details');
        $disputes = $this->records($payload['disputes'] ?? [], 'items');
        $errors = $this->sourceErrors($payload['errors'] ?? []);
        $refundCount = 0;
        $disputeCount = 0;
        $matched = 0;
        $unmatched = 0;
        $itemErrors = [];

        foreach ($transactions as $index => $detail) {
            if (!in_array((string) Arr::get($detail, 'transaction_info.transaction_event_code'), self::REFUND_EVENT_CODES, true)) {
                continue;
            }

            try {
                $wasMatched = $this->persistRefund($account, $detail);
                $refundCount++;
                $wasMatched ? $matched++ : $unmatched++;
            } catch (\Throwable $exception) {
                $itemErrors[] = '退款记录 '.($index + 1).'：'.Str::limit($exception->getMessage(), 300);
            }
        }

        foreach ($disputes as $index => $detail) {
            if (blank($detail['dispute_id'] ?? null)) {
                continue;
            }

            try {
                $wasMatched = $this->persistDispute($account, $detail);
                $disputeCount++;
                $wasMatched ? $matched++ : $unmatched++;
            } catch (\Throwable $exception) {
                $itemErrors[] = '纠纷记录 '.($index + 1).'：'.Str::limit($exception->getMessage(), 300);
            }
        }

        $allErrors = [...$errors, ...$itemErrors];
        $status = $allErrors === [] ? 'success' : (($refundCount + $disputeCount) > 0 ? 'partial' : 'failed');
        $message = sprintf(
            '文件接收%s：交易 %d 条，退款 %d 条，纠纷 %d 条，已匹配 %d 条，未匹配 %d 条，异常 %d 条。',
            $status === 'success' ? '成功' : ($status === 'partial' ? '部分完成' : '失败'),
            count($transactions),
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
        $this->associations->associateExistingOrders($account);

        return [
            'status' => $status,
            'transactions' => count($transactions),
            'refunds' => $refundCount,
            'disputes' => $disputeCount,
            'matched' => $matched,
            'unmatched' => $unmatched,
            'errors' => count($allErrors),
            'message' => $message,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function records(mixed $value, string $nestedKey): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (isset($value[$nestedKey]) && is_array($value[$nestedKey])) {
            $value = $value[$nestedKey];
        }

        return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row)));
    }

    /** @return list<string> */
    private function sourceErrors(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $errors = [];
        foreach ($value as $source => $error) {
            $error = trim((string) ($error ?? ''));
            if ($error !== '') {
                $errors[] = ((string) $source).'：'.Str::limit($error, 500);
            }
        }

        return $errors;
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

        LogisticsPayPalRefund::query()->updateOrCreate([
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

        LogisticsPayPalDispute::query()->updateOrCreate([
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

        return (bool) $order;
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
}
