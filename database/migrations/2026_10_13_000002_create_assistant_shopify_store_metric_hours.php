<?php

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

        if ($schema->hasTable('assistant_domain_stores')
            && !$schema->hasTable('assistant_shopify_store_metric_hours')) {
            $schema->create('assistant_shopify_store_metric_hours', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assistant_domain_store_id');
                $table->dateTime('report_hour_utc');
                $table->string('shop_timezone', 100)->nullable()->index();
                $table->string('currency_code', 3)->default('USD');
                $table->string('original_currency_code', 3)->nullable();
                $table->decimal('total_sales', 20, 4)->nullable();
                $table->decimal('original_total_sales', 20, 4)->nullable();
                $table->unsignedInteger('orders_count')->nullable();
                $table->decimal('returning_customer_rate', 12, 6)->nullable();
                $table->unsignedInteger('fulfilled_orders_count')->nullable();
                $table->decimal('refund_amount', 20, 4)->nullable();
                $table->decimal('net_sales', 20, 4)->nullable();
                $table->decimal('average_order_value', 20, 4)->nullable();
                $table->dateTime('synced_at')->nullable()->index();
                $table->timestamps();

                $table->unique(
                    ['assistant_domain_store_id', 'report_hour_utc'],
                    'as_shopify_hour_store_hour_u',
                );
                $table->index(
                    ['report_hour_utc', 'assistant_domain_store_id'],
                    'as_shopify_hour_range_store_i',
                );
            });
        }

        if ($schema->hasTable('assistant_shopify_analytics_backfills')) {
            // Legacy tasks used a reporting timezone. They must not run after
            // this migration, because the first successful store-local sync
            // will create a fresh 90-day task using Shopify's own timezone.
            DB::connection($connection)
                ->table('assistant_shopify_analytics_backfills')
                ->delete();
        }
    }

    public function down(): void
    {
        // UTC hourly metrics are retained on rollback to avoid erasing operational history.
    }
};
