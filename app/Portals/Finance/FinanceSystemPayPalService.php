<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use Carbon\CarbonImmutable;

class FinanceSystemPayPalService
{
    public function sync(): void
    {
        // Legacy entry point: PayPal transactions are obtained only through the finance relay API.
    }

    public function matchOrders(): int
    {
        $db = app(FinanceWorkbookService::class)->database();
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasColumn('finance_payment_accounts', 'system_paypal_account_id')
            || ! $schema->hasTable('logistics_orders') || ! $schema->hasTable('logistics_paypal_transactions')
            || ! $schema->hasTable('assistant_domain_stores')) {
            return 0;
        }
        $locked = $db->table('finance_period_locks')->whereNotNull('report_snapshot')->pluck('month')->all();
        $matches = $db->table('finance_records as f')
            ->join('finance_payment_accounts as a', 'a.activity_account', '=', 'f.account')
            ->join('logistics_paypal_transactions as t', function ($join): void {
                $join->on('t.logistics_paypal_account_id', '=', 'a.system_paypal_account_id')
                    ->on('t.payment_transaction_id', '=', 'f.reference')->on('t.event_code', '=', 'f.raw_type');
            })->join('logistics_orders as o', 'o.id', '=', 't.logistics_order_id')
            ->leftJoin('assistant_domain_stores as s', 's.id', '=', 'o.assistant_domain_store_id')
            ->where('f.dataset', 'paypal-orders')->where('a.account_type', 'paypal')->whereNull('a.deleted_at')
            ->where(fn ($query) => $query->whereNull('f.domain')->orWhere('f.domain', ''))
            ->select(['f.id', 'o.subscription_type', 'o.is_add_on', 'o.tongtu_order_number'])
            ->selectRaw("COALESCE(NULLIF(s.domain, ''), NULLIF(o.source_shop_domain, '')) as matched_domain")
            ->whereRaw("COALESCE(NULLIF(s.domain, ''), NULLIF(o.source_shop_domain, '')) IS NOT NULL")->get();
        $count = 0;
        foreach ($matches as $match) {
            $record = FinanceRecord::find($match->id);
            if (! $record || in_array($record->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m'), $locked, true)) {
                continue;
            }
            $payload = $record->payload;
            foreach (['订阅类型' => $match->subscription_type, '是否加购' => $match->is_add_on, '通途订单' => $match->tongtu_order_number] as $key => $value) {
                if (blank($payload[$key] ?? null)) {
                    $payload[$key] = $value ?? '';
                }
            }
            $record->forceFill(['domain' => strtolower(trim($match->matched_domain)), 'payload' => $payload])->save();
            $count++;
        }

        return $count;
    }
}
