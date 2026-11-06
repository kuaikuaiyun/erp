<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->getConnection()->getDriverName() !== 'mysql' || ! $schema->hasTable('logistics_orders')) return;
        if (! $schema->hasColumn('logistics_orders', 'finance_is_non_paypal')) {
            $schema->table('logistics_orders', function (Blueprint $table): void {
                $table->boolean('finance_is_non_paypal')->storedAs("LOWER(TRIM(COALESCE(payment_gateway, ''))) <> 'paypal'");
            });
        }
        if (! $schema->hasColumn('logistics_orders', 'finance_credit_occurred_at')) {
            $schema->table('logistics_orders', fn (Blueprint $table) => $table->dateTime('finance_credit_occurred_at')->storedAs('COALESCE(order_created_at, paid_at)'));
        }
        if (! $schema->hasIndex('logistics_orders', 'finance_non_paypal_date_idx')) {
            $schema->table('logistics_orders', fn (Blueprint $table) => $table->index(['finance_is_non_paypal', 'finance_credit_occurred_at', 'id'], 'finance_non_paypal_date_idx'));
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasTable('logistics_orders') && $schema->hasIndex('logistics_orders', 'finance_non_paypal_date_idx')) {
            $schema->table('logistics_orders', fn (Blueprint $table) => $table->dropIndex('finance_non_paypal_date_idx'));
        }
    }
};
