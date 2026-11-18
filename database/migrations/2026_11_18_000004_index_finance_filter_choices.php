<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $db = $schema->getConnection();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $summaryLock = hash('sha256', 'finance:summaries:refresh:'.$db->getDatabaseName());
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 3) AS acquired', [$summaryLock])->acquired !== 1) {
            throw new RuntimeException('Finance summary refresh is still running. Retry this migration after it finishes.');
        }
        $previousTimeout = (int) $db->selectOne('SELECT @@SESSION.lock_wait_timeout AS timeout')->timeout;
        $db->statement('SET SESSION lock_wait_timeout = 3');
        try {
            if (! $schema->hasIndex('finance_records', 'finance_record_currency_account_idx')) {
                $schema->table('finance_records', fn (Blueprint $table) => $table->index(
                    ['dataset', 'currency', 'account'], 'finance_record_currency_account_idx'
                ));
            }
            if ($schema->hasColumn('logistics_orders', 'finance_is_non_paypal')
                && ! $schema->hasIndex('logistics_orders', 'finance_credit_filter_choices_idx')) {
                $schema->table('logistics_orders', fn (Blueprint $table) => $table->index(
                    ['finance_is_non_paypal', 'collection_account', 'payment_status'], 'finance_credit_filter_choices_idx'
                ));
            }
        } finally {
            $db->statement('SET SESSION lock_wait_timeout = '.$previousTimeout);
            $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$summaryLock]);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasIndex('logistics_orders', 'finance_credit_filter_choices_idx')) {
            $schema->table('logistics_orders', fn (Blueprint $table) => $table->dropIndex('finance_credit_filter_choices_idx'));
        }
        if ($schema->hasIndex('finance_records', 'finance_record_currency_account_idx')) {
            $schema->table('finance_records', fn (Blueprint $table) => $table->dropIndex('finance_record_currency_account_idx'));
        }
    }
};
