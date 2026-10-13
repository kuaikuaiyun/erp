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
        $database = DB::connection($connection);

        if ($schema->hasTable('logistics_shopify_connections')) {
            $missing = [
                'webhook_secret' => fn (Blueprint $table) => $table->text('webhook_secret')->nullable(),
                'webhook_status' => fn (Blueprint $table) => $table->string('webhook_status', 30)->default('not_registered'),
                'webhook_registered_at' => fn (Blueprint $table) => $table->dateTime('webhook_registered_at')->nullable(),
                'webhook_last_error' => fn (Blueprint $table) => $table->text('webhook_last_error')->nullable(),
                'webhook_subscription_ids' => fn (Blueprint $table) => $table->text('webhook_subscription_ids')->nullable(),
            ];
            $missing = array_filter($missing, static fn ($definition, string $column): bool => !$schema->hasColumn('logistics_shopify_connections', $column), ARRAY_FILTER_USE_BOTH);

            if ($missing !== []) {
                $schema->table('logistics_shopify_connections', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $definition) {
                        $definition($table);
                    }
                });
            }

            if (!$schema->hasIndex('logistics_shopify_connections', 'logistics_shopify_connection_domain_index')) {
                $schema->table('logistics_shopify_connections', function (Blueprint $table): void {
                    $table->index('shop_domain', 'logistics_shopify_connection_domain_index');
                });
            }
        }

        if ($schema->hasTable('logistics_orders')) {
            if (!$schema->hasColumn('logistics_orders', 'source_shop_domain')) {
                $schema->table('logistics_orders', function (Blueprint $table): void {
                    $table->string('source_shop_domain', 255)->nullable()->after('assistant_domain_store_id');
                });
            }

            $schema->table('logistics_orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('assistant_domain_store_id')->nullable()->change();
            });

            if (!$schema->hasIndex('logistics_orders', 'logistics_order_source_domain_index')) {
                $schema->table('logistics_orders', function (Blueprint $table): void {
                    $table->index('source_shop_domain', 'logistics_order_source_domain_index');
                });
            }

            if ($schema->hasTable('logistics_shopify_connections')) {
                $database->table('logistics_orders')
                    ->select(['id', 'assistant_domain_store_id'])
                    ->whereNull('source_shop_domain')
                    ->orderBy('id')
                    ->chunkById(200, function ($orders) use ($database): void {
                        $storeIds = $orders->pluck('assistant_domain_store_id')->filter()->unique()->all();
                        $domains = $database->table('logistics_shopify_connections')
                            ->whereIn('assistant_domain_store_id', $storeIds)
                            ->pluck('shop_domain', 'assistant_domain_store_id');

                        foreach ($orders as $order) {
                            $domain = $domains[$order->assistant_domain_store_id] ?? null;
                            if ($domain) {
                                $database->table('logistics_orders')->where('id', $order->id)->update(['source_shop_domain' => $domain]);
                            }
                        }
                    });
            }

            if (!$schema->hasIndex('logistics_orders', 'logistics_order_source_shopify_unique')) {
                $hasDuplicates = $database->table('logistics_orders')
                    ->whereNotNull('source_shop_domain')
                    ->select('source_shop_domain', 'shopify_order_id', DB::raw('COUNT(*) as aggregate'))
                    ->groupBy('source_shop_domain', 'shopify_order_id')
                    ->having('aggregate', '>', 1)
                    ->exists();

                if (!$hasDuplicates) {
                    $schema->table('logistics_orders', function (Blueprint $table): void {
                        $table->unique(['source_shop_domain', 'shopify_order_id'], 'logistics_order_source_shopify_unique');
                    });
                }
            }
        }

        if (!$schema->hasTable('logistics_webhook_deliveries')) {
            $schema->create('logistics_webhook_deliveries', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('webhook_id', 100)->unique();
                $table->string('shop_domain', 255)->index();
                $table->string('topic', 100)->nullable();
                $table->string('status', 30)->default('processing');
                $table->unsignedBigInteger('logistics_order_id')->nullable()->index();
                $table->text('message')->nullable();
                $table->dateTime('received_at')->nullable();
                $table->dateTime('processed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Keep operational webhook records and source traceability on rollback.
    }
};
