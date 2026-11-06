<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('aikaipin_products')) return;
        $schema->table('aikaipin_products', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'aikaipin_products_created_id_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('aikaipin_products')) return;
        $schema->table('aikaipin_products', function (Blueprint $table): void {
            $table->dropIndex('aikaipin_products_created_id_idx');
        });
    }
};
