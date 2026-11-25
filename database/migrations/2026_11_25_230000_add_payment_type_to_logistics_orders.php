<?php

use App\Models\LogisticsOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (!$schema->hasColumn('logistics_orders', 'payment_type')) {
            $schema->table('logistics_orders', function (Blueprint $table): void {
                $table->string('payment_type', 16)->nullable();
            });
        }

        $orders = DB::connection($connection)->table('logistics_orders');
        (clone $orders)->select(['id', 'shopify_payload'])
            ->whereNull('payment_type')
            ->whereNotNull('shopify_payload')
            ->chunkById(500, function ($batch) use ($orders): void {
                foreach ($batch as $order) {
                    $payload = json_decode((string) $order->shopify_payload, true);
                    $type = LogisticsOrder::paymentTypeFromTags($payload['tags'] ?? []);
                    if ($type !== null) {
                        (clone $orders)->where('id', $order->id)
                            ->whereNull('payment_type')
                            ->where('shopify_payload', $order->shopify_payload)
                            ->update(['payment_type' => $type]);
                    }
                }
            });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->table('logistics_orders', function (Blueprint $table): void {
            $table->dropColumn('payment_type');
        });
    }
};
