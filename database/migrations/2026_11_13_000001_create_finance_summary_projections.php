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
        if (! $schema->hasTable('finance_summary_periods')) $schema->create('finance_summary_periods', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('month', 7);
            $table->string('channel', 12);
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('published')->default(0);
            $table->primary(['month', 'channel']);
        });
        if (! $schema->hasTable('finance_summary_rows')) $schema->create('finance_summary_rows', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('month', 7);
            $table->string('channel', 12);
            $table->string('timezone', 32)->default('Asia/Shanghai');
            $table->unsignedBigInteger('revision');
            $table->boolean('transactions');
            $table->unsignedBigInteger('position');
            $table->string('account', 191);
            $table->string('currency', 10)->nullable();
            $table->json('payload');
            $table->primary(['month', 'channel', 'timezone', 'revision', 'transactions', 'position'], 'finance_summary_rows_pk');
            $table->index(['month', 'channel', 'timezone', 'revision', 'transactions', 'account'], 'finance_summary_account_idx');
            $table->index(['month', 'channel', 'timezone', 'revision', 'transactions', 'currency'], 'finance_summary_currency_idx');
        });
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) return;
        $db->statement("INSERT IGNORE INTO finance_summary_periods (month, channel)
            SELECT DISTINCT DATE_FORMAT(DATE_ADD(occurred_at, INTERVAL 8 HOUR), '%Y-%m'),
                IF(dataset = 'paypal-orders', 'paypal', 'credit_card')
            FROM finance_records WHERE dataset IN ('paypal-orders','xuzong2-orders','xuzong2-details','xuzong1-details','xuzong-credit-source-1','xuzong-credit-source-2','xuzong-credit-imported-orders')");
    }

    public function down(): void
    {
        // Preserve ordinary financial tables and data.
    }
};
