<?php

use App\Models\AssistantDomainStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));

        $db->transaction(function () use ($db): void {
            $orders = $db->table('logistics_orders');
            (clone $orders)
                ->where(fn ($query) => $query->where('tongtu_order_number', 'like', 'staen-JS918%')
                    ->orWhere('tongtu_order_number', 'like', 'staen-918%'))
                ->select(['id', 'tongtu_order_number'])
                ->lockForUpdate()
                ->chunkById(500, function ($batch) use ($orders): void {
                    foreach ($batch as $order) {
                        $old = $order->tongtu_order_number;
                        $new = AssistantDomainStore::normalizeStaenTongtuOrderNumber($old);
                        if ($new === $old) {
                            continue;
                        }
                        if ((clone $orders)->where('tongtu_order_number', $new)->where('id', '!=', $order->id)->exists()) {
                            throw new RuntimeException("通途订单号 {$new} 已被另一条物流订单使用，无法将 {$old} 转换为该单号。");
                        }
                        (clone $orders)->where('id', $order->id)->update(['tongtu_order_number' => $new]);
                    }
                });

            foreach (['finance_records' => '通途订单', 'finance_settlement_supplements' => 'erp_order'] as $table => $key) {
                $column = "payload->{$key}";
                $value = $db->getQueryGrammar()->wrap($column);
                $db->table($table)
                    ->whereRaw("LOWER({$value}) REGEXP ?", ['^staen-(js)?918[0-9]{4}$'])
                    ->update([$column => $db->raw("CONCAT('staen-', RIGHT({$value}, 4))")]);
            }
        });
    }

    public function down(): void
    {
        // The canonical value cannot distinguish legacy prefixes from originally canonical orders.
    }
};
