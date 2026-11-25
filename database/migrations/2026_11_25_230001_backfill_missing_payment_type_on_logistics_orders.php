<?php

use App\Models\LogisticsOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $orders = DB::connection($connection)->table('logistics_orders');

        (clone $orders)->select(['id', 'shopify_payload'])
            ->whereNull('payment_type')
            ->whereNotNull('shopify_payload')
            ->chunkById(500, function ($batch) use ($orders): void {
                foreach ($batch as $order) {
                    $payload = json_decode((string) $order->shopify_payload, true);
                    $type = LogisticsOrder::paymentTypeFromTags($payload['tags'] ?? []);

                    if ($type === null) {
                        continue;
                    }

                    (clone $orders)->where('id', $order->id)
                        ->whereNull('payment_type')
                        ->where('shopify_payload', $order->shopify_payload)
                        ->update(['payment_type' => $type]);
                }
            });
    }

    public function down(): void
    {
        // A later sync may have refreshed these values, so leave them intact.
    }
};
