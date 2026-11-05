<?php

use App\Portals\Finance\FinanceAccountingService;
use App\Portals\Finance\FinancePayPalEventTypes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $db->transaction(function () use ($db): void {
            foreach (FinancePayPalEventTypes::TYPES as $code => [$label, $category]) {
                $db->table('finance_transaction_types')->insertOrIgnore([
                    'raw_type' => $code, 'category' => $category, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $db->table('finance_transaction_types')->where('raw_type', $code)
                    ->where(fn ($query) => $query->whereNull('category')->orWhere('category', ''))
                    ->update(['category' => $category, 'updated_at' => now()]);
            }
            // Fill missing classifications only; keep closed periods and existing accounting choices.
            $locked = $db->table('finance_period_locks')->whereNotNull('report_snapshot')->pluck('month');
            $rows = $db->table('finance_payment_transactions')->whereNull('category')
                ->whereNotIn('accounting_month', $locked)->orderBy('id');
            $rows->chunkById(200, function ($transactions) use ($db): void {
                foreach ($transactions as $transaction) {
                    $raw = json_decode($transaction->raw_payload, true, 512, JSON_THROW_ON_ERROR);
                    $record = $db->table('finance_records')->find($transaction->finance_record_id);
                    if (! $record) {
                        continue;
                    }
                    $attributes = app(FinanceAccountingService::class)->attributes($raw, CarbonImmutable::parse($record->occurred_at, 'UTC'));
                    if ($attributes['category'] !== null) {
                        $db->table('finance_payment_transactions')->where('id', $transaction->id)
                            ->update(['category' => $attributes['category'], 'updated_at' => now()]);
                    }
                }
            });
        });
    }

    public function down(): void {}
};
