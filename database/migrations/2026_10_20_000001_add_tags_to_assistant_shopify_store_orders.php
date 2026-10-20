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

        if (!$schema->hasTable('assistant_shopify_store_orders')) {
            return;
        }

        $hasTags = $schema->hasColumn('assistant_shopify_store_orders', 'shopify_tags');
        $hasCategory = $schema->hasColumn('assistant_shopify_store_orders', 'subscription_category');
        if ($hasTags && $hasCategory) {
            return;
        }

        if (!$hasTags) {
            $schema->table('assistant_shopify_store_orders', function (Blueprint $table): void {
                $table->json('shopify_tags')->nullable()->after('is_test');
            });
        }

        if (!$hasCategory) {
            $schema->table('assistant_shopify_store_orders', function (Blueprint $table): void {
                $table->string('subscription_category', 24)->nullable()->after('shopify_tags');
            });
        }
    }

    public function down(): void
    {
        // Shopify source tags are retained intentionally on rollback.
    }
};
