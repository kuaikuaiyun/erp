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

        if (!$schema->hasColumn('logistics_orders', 'media_source')) {
            $schema->table('logistics_orders', function (Blueprint $table): void {
                $table->string('media_source', 32)->default(LogisticsOrder::MEDIA_SOURCE_OTHER)->after('staff_status')->index();
            });
        }

        DB::connection($connection)->table('logistics_orders')
            ->select(['id', 'shopify_payload'])
            ->orderBy('id')
            ->chunkById(500, function ($orders) use ($connection): void {
                foreach ($orders as $order) {
                    $payload = json_decode((string) $order->shopify_payload, true);
                    $source = LogisticsOrder::mediaSourceFromTags(is_array($payload) ? ($payload['tags'] ?? []) : []);
                    DB::connection($connection)->table('logistics_orders')->where('id', $order->id)->update(['media_source' => $source]);
                }
            });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if ($schema->hasColumn('logistics_orders', 'media_source')) {
            $schema->table('logistics_orders', function (Blueprint $table): void {
                $table->dropIndex(['media_source']);
                $table->dropColumn('media_source');
            });
        }
    }
};
