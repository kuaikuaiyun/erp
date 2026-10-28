<?php

namespace App\Portals\Payment;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalImport;
use App\Models\LogisticsPayPalTransaction;
use App\Models\PaymentPayPalLedgerEntry;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentPayPalLedgerImportService
{
    /**
     * @param list<array<string,mixed>> $transactions
     * @return array{count:int,errors:list<string>}
     */
    public function persist(
        LogisticsPayPalAccount $account,
        LogisticsPayPalImport $import,
        array $transactions,
    ): array {
        $count = 0;
        $errors = [];

        foreach ($transactions as $index => $detail) {
            try {
                $info = is_array($detail['transaction_info'] ?? null) ? $detail['transaction_info'] : [];
                $transactionId = trim((string) ($info['transaction_id'] ?? ''));
                if ($transactionId === '') {
                    continue;
                }

                $transactionAmount = $this->decimal(Arr::get($info, 'transaction_amount.value'));
                $feeAmount = $this->decimal(Arr::get($info, 'fee_amount.value'));
                $netAmount = $transactionAmount === null && $feeAmount === null
                    ? null
                    : round(($transactionAmount ?? 0.0) + ($feeAmount ?? 0.0), 4);
                $currency = strtoupper(trim((string) (
                    Arr::get($info, 'transaction_amount.currency_code')
                    ?: Arr::get($info, 'fee_amount.currency_code')
                )));

                PaymentPayPalLedgerEntry::query()->updateOrCreate([
                    'logistics_paypal_account_id' => $account->getKey(),
                    'transaction_id' => $transactionId,
                ], [
                    'logistics_paypal_import_id' => $import->getKey(),
                    'upload_id' => $import->upload_id,
                    'merchant_id' => $account->merchant_id,
                    'reference_id' => $this->nullableString($info['paypal_reference_id'] ?? null),
                    'reference_id_type' => $this->nullableString($info['paypal_reference_id_type'] ?? null),
                    'event_code' => $this->nullableString($info['transaction_event_code'] ?? null),
                    'status' => $this->nullableString($info['transaction_status'] ?? null),
                    'currency_code' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null,
                    'transaction_amount' => $transactionAmount,
                    'fee_amount' => $feeAmount,
                    'net_amount' => $netAmount,
                    'invoice_id' => $this->nullableString($info['invoice_id'] ?? null),
                    'subject' => $this->nullableString($info['transaction_subject'] ?? $info['transaction_note'] ?? null),
                    'event_at' => $this->dateTime($info['transaction_initiation_date'] ?? null),
                    'source_updated_at' => $this->dateTime($info['transaction_updated_date'] ?? null),
                    'raw_payload' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $count++;
            } catch (\Throwable $exception) {
                $errors[] = __('payment.account_risk.monthly_report.import_entry_failed', [
                    'index' => $index + 1,
                    'message' => Str::limit($exception->getMessage(), 240),
                ]);
            }
        }

        try {
            $this->resolveOrigins($account, $import);
        } catch (\Throwable $exception) {
            $errors[] = __('payment.account_risk.monthly_report.import_origin_failed', [
                'message' => Str::limit($exception->getMessage(), 240),
            ]);
        }

        DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('payment_paypal_ledger_import_runs')
            ->updateOrInsert([
                'logistics_paypal_import_id' => $import->getKey(),
            ], [
                'logistics_paypal_account_id' => $account->getKey(),
                'entry_count' => $count,
                'error_count' => count($errors),
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return ['count' => $count, 'errors' => $errors];
    }

    private function resolveOrigins(LogisticsPayPalAccount $account, LogisticsPayPalImport $import): void
    {
        $entries = PaymentPayPalLedgerEntry::query()
            ->where('logistics_paypal_account_id', $account->getKey())
            ->where('upload_id', $import->upload_id)
            ->get(['id', 'transaction_id', 'reference_id', 'event_code']);
        if ($entries->isEmpty()) {
            return;
        }

        $candidates = $entries->pluck('reference_id')->filter()->unique()->values();
        $paymentIds = collect();
        $disputeOrigins = collect();
        $ledgerLinks = collect();

        for ($depth = 0; $depth < 6 && $candidates->isNotEmpty(); $depth++) {
            $next = collect();
            foreach ($candidates->chunk(1000) as $chunk) {
                $candidateValues = $chunk->values()->all();
                LogisticsPayPalTransaction::query()
                    ->where('logistics_paypal_account_id', $account->getKey())
                    ->whereIn('payment_transaction_id', $candidateValues)
                    ->pluck('payment_transaction_id')
                    ->each(fn (string $id) => $paymentIds->put($id, $id));

                LogisticsPayPalDispute::query()
                    ->where('logistics_paypal_account_id', $account->getKey())
                    ->where(function ($query) use ($candidateValues): void {
                        $query->whereIn('dispute_id', $candidateValues)
                            ->orWhereIn('disputed_transaction_id', $candidateValues);
                    })
                    ->get(['dispute_id', 'disputed_transaction_id'])
                    ->each(function (LogisticsPayPalDispute $dispute) use ($disputeOrigins): void {
                        $paymentId = trim((string) $dispute->disputed_transaction_id);
                        if ($paymentId === '') {
                            return;
                        }
                        $disputeOrigins->put((string) $dispute->dispute_id, $paymentId);
                        $disputeOrigins->put($paymentId, $paymentId);
                    });

                $linked = PaymentPayPalLedgerEntry::query()
                    ->where('logistics_paypal_account_id', $account->getKey())
                    ->whereIn('transaction_id', $candidateValues)
                    ->get(['transaction_id', 'reference_id', 'origin_payment_transaction_id']);
                foreach ($linked as $link) {
                    $ledgerLinks->put((string) $link->transaction_id, [
                        'reference_id' => $link->reference_id,
                        'origin' => $link->origin_payment_transaction_id,
                    ]);
                    if (!blank($link->reference_id)) {
                        $next->push((string) $link->reference_id);
                    }
                }
            }
            $candidates = $next->filter()->unique()->values();
        }

        foreach ($entries as $entry) {
            $eventCode = strtoupper(trim((string) $entry->event_code));
            $origin = str_starts_with($eventCode, 'T00') ? (string) $entry->transaction_id : null;
            $cursor = trim((string) $entry->reference_id);

            for ($depth = 0; !$origin && $cursor !== '' && $depth < 6; $depth++) {
                if ($paymentIds->has($cursor)) {
                    $origin = $cursor;
                    break;
                }
                if ($disputeOrigins->has($cursor)) {
                    $origin = (string) $disputeOrigins->get($cursor);
                    break;
                }
                $link = $ledgerLinks->get($cursor);
                if (!is_array($link)) {
                    break;
                }
                if (!blank($link['origin'] ?? null)) {
                    $origin = (string) $link['origin'];
                    break;
                }
                $cursor = trim((string) ($link['reference_id'] ?? ''));
            }

            if ($origin) {
                PaymentPayPalLedgerEntry::query()->whereKey($entry->getKey())->update([
                    'origin_payment_transaction_id' => $origin,
                ]);
            }
        }
    }

    private function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
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
}
