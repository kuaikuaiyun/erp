<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'logistics_orders_renewal_history_idx';

    private const INDEX_COLUMNS = ['subscription_origin_order_id', 'paid_at', 'id'];

    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (!$schema->hasColumns('logistics_orders', self::INDEX_COLUMNS)
            || $schema->hasIndex('logistics_orders', self::INDEX_NAME)
            || $schema->hasIndex('logistics_orders', self::INDEX_COLUMNS)) {
            return;
        }

        $schema->table('logistics_orders', function (Blueprint $table): void {
            $table->index(self::INDEX_COLUMNS, self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasTable('logistics_orders') && $schema->hasIndex('logistics_orders', self::INDEX_NAME)) {
            $schema->table('logistics_orders', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_NAME);
            });
        }
    }
};
