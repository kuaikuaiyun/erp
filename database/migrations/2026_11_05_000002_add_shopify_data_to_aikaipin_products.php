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

        if (! $schema->hasColumn('aikaipin_products', 'shopify_data')) {
            $schema->table('aikaipin_products', function (Blueprint $table): void {
                $table->json('shopify_data')->nullable()->after('visible_department_ids');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasColumn('aikaipin_products', 'shopify_data')) {
            $schema->table('aikaipin_products', function (Blueprint $table): void {
                $table->dropColumn('shopify_data');
            });
        }
    }
};
