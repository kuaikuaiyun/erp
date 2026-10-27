<?php

use App\Models\LogisticsOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (!Schema::connection($connection)->hasColumn('logistics_orders', 'media_source')) {
            return;
        }

        DB::connection($connection)->table('logistics_orders')
            ->select(['id', 'shopify_payload'])
            ->orderBy('id')
            ->chunkById(500, function ($orders) use ($connection): void {
                foreach ($orders as $order) {
                    $payload = json_decode((string) $order->shopify_payload, true);
                    DB::connection($connection)->table('logistics_orders')->where('id', $order->id)->update([
                        'media_source' => LogisticsOrder::mediaSourceFromTags(is_array($payload) ? ($payload['tags'] ?? []) : []),
                    ]);
                }
            });
    }

    public function down(): void
    {
    }
};
