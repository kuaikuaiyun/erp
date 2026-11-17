<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('ai_product_listing_tasks')) return;

        $schema->table('ai_product_listing_tasks', function (Blueprint $table) use ($schema): void {
            if ($schema->hasIndex('ai_product_listing_tasks', 'ai_product_listing_product_department_unique')) {
                $table->dropUnique('ai_product_listing_product_department_unique');
            }
            if (! $schema->hasIndex('ai_product_listing_tasks', 'ai_product_listing_product_department_idx')) {
                $table->index(['aikaipin_product_id', 'department_id'], 'ai_product_listing_product_department_idx');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('ai_product_listing_tasks')) return;

        $schema->table('ai_product_listing_tasks', function (Blueprint $table) use ($schema): void {
            if ($schema->hasIndex('ai_product_listing_tasks', 'ai_product_listing_product_department_idx')) {
                $table->dropIndex('ai_product_listing_product_department_idx');
            }
        });
    }
};
