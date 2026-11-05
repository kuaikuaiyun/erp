<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('logistics_orders')) return;
        foreach (['bill_number', 'payment_gateway_order_id'] as $column) {
            $name = 'finance_match_'.$column.'_idx';
            if ($schema->hasColumn('logistics_orders', $column) && ! $schema->hasIndex('logistics_orders', $name)) {
                $schema->table('logistics_orders', fn (Blueprint $table) => $table->index($column, $name));
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('logistics_orders')) return;
        foreach (['bill_number', 'payment_gateway_order_id'] as $column) {
            $name = 'finance_match_'.$column.'_idx';
            if ($schema->hasIndex('logistics_orders', $name)) {
                $schema->table('logistics_orders', fn (Blueprint $table) => $table->dropIndex($name));
            }
        }
    }
};
