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
            $table->string('month', 7);
            $table->string('channel', 12);
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('published')->default(0);
            $table->primary(['month', 'channel']);
        });
        if (! $schema->hasTable('finance_summary_rows')) $schema->create('finance_summary_rows', function (Blueprint $table): void {
            $table->string('month', 7);
            $table->string('channel', 12);
            $table->unsignedBigInteger('revision');
            $table->boolean('transactions');
            $table->unsignedBigInteger('position');
            $table->string('account', 191);
            $table->string('currency', 10)->nullable();
            $table->json('payload');
            $table->primary(['month', 'channel', 'revision', 'transactions', 'position'], 'finance_summary_rows_pk');
            $table->index(['month', 'channel', 'revision', 'transactions', 'account'], 'finance_summary_account_idx');
            $table->index(['month', 'channel', 'revision', 'transactions', 'currency'], 'finance_summary_currency_idx');
        });
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['insert' => ['NEW'], 'update' => ['OLD', 'NEW'], 'delete' => ['OLD']] as $event => $images) {
            foreach (['finance_records' => 'record', 'finance_settlement_supplements' => 'supplement', 'finance_payment_transactions' => 'payment'] as $table => $name) {
                $statements = [];
                foreach ($images as $image) {
                    $record = $name === 'record' ? $image : 'f';
                    $from = $name === 'record' ? '' : " FROM finance_records f";
                    $where = $name === 'record' ? '' : " AND f.id = {$image}.finance_record_id";
                    $statements[] = "INSERT INTO finance_summary_periods (month, channel)
                        SELECT DATE_FORMAT(DATE_ADD({$record}.occurred_at, INTERVAL 8 HOUR), '%Y-%m'),
                            IF({$record}.dataset = 'paypal-orders', 'paypal', 'credit_card'){$from}
                        WHERE {$record}.dataset IN ('paypal-orders','xuzong2-orders','xuzong2-details','xuzong1-details','xuzong-credit-source-1','xuzong-credit-source-2','xuzong-credit-imported-orders'){$where}
                        ON DUPLICATE KEY UPDATE revision = revision + 1";
                }
                \App\Portals\Finance\FinanceSummaryTriggers::install($db, "CREATE TRIGGER finance_summary_{$name}_{$event} AFTER {$event} ON {$table} FOR EACH ROW BEGIN ".implode('; ', $statements)."; END");
            }
            $statements = [];
            foreach ($images as $image) {
                $statements[] = "UPDATE finance_summary_periods SET revision = revision + 1
                    WHERE channel = 'credit_card' AND month IN (
                        SELECT DATE_FORMAT(DATE_ADD(f.occurred_at, INTERVAL 8 HOUR), '%Y-%m')
                        FROM finance_records f JOIN finance_payment_accounts a ON a.activity_account = f.account
                        WHERE a.id = {$image}.payment_account_id AND f.dataset = 'xuzong2-details'
                        AND (f.reference = {$image}.reference OR {$image}.kind = 'summary')
                    )";
            }
            \App\Portals\Finance\FinanceSummaryTriggers::install($db, "CREATE TRIGGER finance_summary_ledger_{$event} AFTER {$event} ON finance_credit_settlement_entries
                FOR EACH ROW BEGIN ".implode('; ', $statements)."; END");
            foreach ([
                'finance_payment_accounts' => ['account', "channel IN (".implode(',', array_map(fn ($image) => "{$image}.account_type", $images)).")"],
                'finance_transaction_types' => ['type', "channel = 'paypal'"],
                'finance_credit_usd_rates' => ['rate', "channel = 'credit_card' AND (".implode(' OR ', array_map(
                    fn ($image) => "month BETWEEN DATE_FORMAT({$image}.starts_on, '%Y-%m') AND DATE_FORMAT({$image}.ends_on, '%Y-%m')", $images
                )).")"],
            ] as $table => [$name, $condition]) {
                \App\Portals\Finance\FinanceSummaryTriggers::install($db, "CREATE TRIGGER finance_summary_{$name}_{$event} AFTER {$event} ON {$table}
                    FOR EACH ROW UPDATE finance_summary_periods SET revision = revision + 1 WHERE {$condition}");
            }
        }
        $db->statement("INSERT IGNORE INTO finance_summary_periods (month, channel)
            SELECT DISTINCT DATE_FORMAT(DATE_ADD(occurred_at, INTERVAL 8 HOUR), '%Y-%m'),
                IF(dataset = 'paypal-orders', 'paypal', 'credit_card')
            FROM finance_records WHERE dataset IN ('paypal-orders','xuzong2-orders','xuzong2-details','xuzong1-details','xuzong-credit-source-1','xuzong-credit-source-2','xuzong-credit-imported-orders')");
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['record', 'supplement', 'payment', 'ledger', 'account', 'type', 'rate'] as $name) {
                foreach (['insert', 'update', 'delete'] as $event) {
                    $db->unprepared("DROP TRIGGER IF EXISTS finance_summary_{$name}_{$event}");
                }
            }
        }
        $db->getSchemaBuilder()->dropIfExists('finance_summary_rows');
        $db->getSchemaBuilder()->dropIfExists('finance_summary_periods');
    }
};
