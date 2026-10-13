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

        if (!$schema->hasTable('assistant_domain_stores')
            || $schema->hasTable('assistant_shopify_store_metric_histories')) {
            return;
        }

        $schema->create('assistant_shopify_store_metric_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->string('shop_timezone', 100)->nullable()->index();
            $table->date('report_date');
            $table->string('currency_code', 3)->default('USD');
            $table->decimal('total_sales', 20, 4)->nullable();
            $table->unsignedInteger('orders_count')->nullable();
            $table->decimal('returning_customer_rate', 12, 6)->nullable();
            $table->unsignedInteger('fulfilled_orders_count')->nullable();
            $table->decimal('refund_amount', 20, 4)->nullable();
            $table->decimal('net_sales', 20, 4)->nullable();
            $table->decimal('average_order_value', 20, 4)->nullable();
            $table->string('sync_status', 30)->default('success')->index();
            $table->dateTime('synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['assistant_domain_store_id', 'report_date'], 'assistant_shopify_metric_history_store_date_unique');
            $table->index(['report_date', 'assistant_domain_store_id'], 'assistant_shopify_metric_history_date_store_index');
        });

        // Preserve the one existing successful latest snapshot for each store
        // as the first available historical day.
        if ($schema->hasTable('assistant_shopify_store_metrics')) {
            $database = DB::connection($connection);
            $database->table('assistant_shopify_store_metric_histories')->insertUsing(
                [
                    'assistant_domain_store_id',
                    'shop_timezone',
                    'report_date',
                    'currency_code',
                    'total_sales',
                    'orders_count',
                    'returning_customer_rate',
                    'fulfilled_orders_count',
                    'refund_amount',
                    'net_sales',
                    'average_order_value',
                    'sync_status',
                    'synced_at',
                    'created_at',
                    'updated_at',
                ],
                $database->table('assistant_shopify_store_metrics')
                    ->select([
                        'assistant_domain_store_id',
                        'shop_timezone',
                        'report_date',
                        'currency_code',
                        'total_sales',
                        'orders_count',
                        'returning_customer_rate',
                        'fulfilled_orders_count',
                        'refund_amount',
                        'net_sales',
                        'average_order_value',
                        'sync_status',
                        'synced_at',
                        'created_at',
                        'updated_at',
                    ])
                    ->where('sync_status', 'success')
                    ->whereNotNull('report_date'),
            );
        }
    }

    public function down(): void
    {
        // Historical metrics are operational data and are intentionally retained on rollback.
    }
};
