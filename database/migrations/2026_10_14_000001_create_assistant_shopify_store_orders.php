<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('assistant_domain_stores')
            || $schema->hasTable('assistant_shopify_store_orders')) {
            return;
        }

        $schema->create('assistant_shopify_store_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->string('shopify_order_id', 100);
            $table->string('shopify_legacy_resource_id', 40)->nullable();
            $table->string('shopify_order_number', 80)->nullable();
            $table->dateTime('shopify_created_at');
            $table->dateTime('shopify_processed_at')->nullable();
            $table->dateTime('shopify_updated_at')->nullable();
            $table->dateTime('shopify_cancelled_at')->nullable();
            $table->boolean('is_test')->default(false);
            $table->string('original_currency_code', 3)->nullable();
            $table->decimal('original_total_price', 20, 4)->nullable();
            $table->string('current_currency_code', 3)->nullable();
            $table->decimal('current_total_price', 20, 4)->nullable();
            $table->string('refunded_currency_code', 3)->nullable();
            $table->decimal('refunded_total_amount', 20, 4)->nullable();
            $table->string('financial_status', 40)->nullable();
            $table->string('fulfillment_status', 40)->nullable();
            $table->dateTime('synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['assistant_domain_store_id', 'shopify_order_id'],
                'as_shopify_order_store_order_unique',
            );
            $table->index(
                ['assistant_domain_store_id', 'shopify_created_at'],
                'as_shopify_order_store_created_index',
            );
            $table->index(
                ['assistant_domain_store_id', 'shopify_updated_at'],
                'as_shopify_order_store_updated_index',
            );
        });
    }

    public function down(): void
    {
        // Source order records are retained intentionally on rollback.
    }
};
