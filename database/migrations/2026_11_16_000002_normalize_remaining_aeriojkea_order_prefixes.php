<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));

        $db->transaction(function () use ($db): void {
            $orders = $db->table('logistics_orders');
            (clone $orders)->where('tongtu_order_number', 'like', 'aeriojkea-%')
                ->lockForUpdate()->chunkById(500, function ($batch) use ($db, $orders): void {
                    foreach ($batch as $order) {
                        if (! preg_match('/^(aeriojkea-)[A-Z]+([0-9]+)$/iD', $order->tongtu_order_number, $matches)) {
                            continue;
                        }

                        $old = $order->tongtu_order_number;
                        $new = $matches[1].$matches[2];
                        if ((clone $orders)->where('tongtu_order_number', $new)->where('id', '!=', $order->id)->exists()) {
                            throw new RuntimeException("通途订单号 {$new} 已被其他订单使用，无法迁移 {$old}。");
                        }

                        (clone $orders)->where('id', $order->id)->update(['tongtu_order_number' => $new]);
                        foreach (['finance_records' => '通途订单', 'finance_settlement_supplements' => 'erp_order'] as $table => $key) {
                            $db->table($table)->where("payload->{$key}", $old)->update(["payload->{$key}" => $new]);
                        }
                    }
                }, 'id');
        });
    }

    public function down(): void
    {
        // The normalized number alone cannot determine whether it originally contained a letter prefix.
    }
};
