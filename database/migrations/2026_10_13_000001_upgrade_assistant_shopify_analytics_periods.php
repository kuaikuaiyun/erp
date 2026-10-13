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

        foreach (['assistant_shopify_store_metrics', 'assistant_shopify_store_metric_histories'] as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $missing = [];
            foreach (['original_currency_code', 'original_total_sales'] as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    $missing[] = $column;
                }
            }

            if ($missing !== []) {
                $schema->table($table, function (Blueprint $table) use ($missing): void {
                    if (in_array('original_currency_code', $missing, true)) {
                        $table->string('original_currency_code', 3)->nullable()->after('currency_code');
                    }
                    if (in_array('original_total_sales', $missing, true)) {
                        $table->decimal('original_total_sales', 20, 4)->nullable()->after('total_sales');
                    }
                });
            }
        }

        if ($schema->hasTable('assistant_shopify_store_metric_histories')
            && !$schema->hasColumn('assistant_shopify_store_metric_histories', 'report_timezone')) {
            $schema->table('assistant_shopify_store_metric_histories', function (Blueprint $table): void {
                $table->string('report_timezone', 100)->nullable()->after('shop_timezone');
            });
        }

        if ($schema->hasTable('assistant_shopify_store_metric_histories')) {
            $indexes = collect(DB::connection($connection)->select('SHOW INDEX FROM assistant_shopify_store_metric_histories'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            if (in_array('assistant_shopify_metric_history_store_date_unique', $indexes, true)) {
                DB::connection($connection)->statement(
                    'ALTER TABLE assistant_shopify_store_metric_histories DROP INDEX assistant_shopify_metric_history_store_date_unique'
                );
            }

            if (!in_array('assistant_shopify_metric_history_store_timezone_date_unique', $indexes, true)) {
                $schema->table('assistant_shopify_store_metric_histories', function (Blueprint $table): void {
                    $table->unique(
                        ['assistant_domain_store_id', 'report_timezone', 'report_date'],
                        'assistant_shopify_metric_history_store_timezone_date_unique',
                    );
                });
            }
        }

        if ($schema->hasTable('assistant_domain_stores')
            && !$schema->hasTable('assistant_shopify_store_metric_periods')) {
            $schema->create('assistant_shopify_store_metric_periods', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assistant_domain_store_id');
                $table->string('shop_timezone', 100)->nullable()->index();
                $table->string('report_timezone', 100)->index();
                $table->date('from_date');
                $table->date('to_date');
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
                $table->string('sync_status', 30)->default('not_synced')->index();
                $table->string('sync_message', 80)->nullable();
                $table->dateTime('synced_at')->nullable()->index();
                $table->timestamps();

                $table->unique(
                    ['assistant_domain_store_id', 'report_timezone', 'from_date', 'to_date'],
                    'assistant_shopify_metric_period_unique',
                );
                $table->index(
                    ['report_timezone', 'from_date', 'to_date'],
                    'assistant_shopify_metric_period_scope_index',
                );
            });
        }

        if ($schema->hasTable('assistant_shopify_analytics_sync_batches')) {
            $missingBatchColumns = [];
            foreach (['report_timezone', 'from_date', 'to_date'] as $column) {
                if (!$schema->hasColumn('assistant_shopify_analytics_sync_batches', $column)) {
                    $missingBatchColumns[] = $column;
                }
            }

            if ($missingBatchColumns !== []) {
                $schema->table('assistant_shopify_analytics_sync_batches', function (Blueprint $table) use ($missingBatchColumns): void {
                    if (in_array('report_timezone', $missingBatchColumns, true)) {
                    $table->string('report_timezone', 100)->nullable()->after('actor_name');
                    }
                    if (in_array('from_date', $missingBatchColumns, true)) {
                    $table->date('from_date')->nullable()->after('report_timezone');
                    }
                    if (in_array('to_date', $missingBatchColumns, true)) {
                    $table->date('to_date')->nullable()->after('from_date');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // Existing operational metrics remain intentionally retained on rollback.
    }
};
