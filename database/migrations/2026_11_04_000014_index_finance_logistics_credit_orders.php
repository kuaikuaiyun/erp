<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        if ($db->getDriverName() !== 'mysql' || ! $schema->hasTable('logistics_orders')) {
            return;
        }
        $schema->table('logistics_orders', function (Blueprint $table): void {
            $table->boolean('finance_is_credit_card')->storedAs("CASE WHEN JSON_VALID(shopify_payload) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(shopify_payload, '$.tags')) LIKE '%CreditCardBy%', 0) ELSE 0 END");
            $table->dateTime('finance_credit_occurred_at')->storedAs('COALESCE(order_created_at, paid_at)');
            $table->index(['finance_is_credit_card', 'finance_credit_occurred_at', 'id'], 'logistics_finance_credit_date_idx');
        });
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        if ($db->getDriverName() === 'mysql' && $db->getSchemaBuilder()->hasColumn('logistics_orders', 'finance_is_credit_card')) {
            $db->getSchemaBuilder()->table('logistics_orders', function (Blueprint $table): void {
                $table->dropIndex('logistics_finance_credit_date_idx');
                $table->dropColumn(['finance_is_credit_card', 'finance_credit_occurred_at']);
            });
        }
    }
};
