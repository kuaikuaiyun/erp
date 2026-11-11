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
        if (! $schema->hasTable('aikaipin_products') || $schema->hasColumn('aikaipin_products', 'is_published')) return;

        $schema->table('aikaipin_products', function (Blueprint $table): void {
            $table->boolean('is_published')->default(true)->index()->after('visible_department_ids');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('aikaipin_products') || ! $schema->hasColumn('aikaipin_products', 'is_published')) return;

        $schema->table('aikaipin_products', function (Blueprint $table): void {
            $table->dropColumn('is_published');
        });
    }
};
