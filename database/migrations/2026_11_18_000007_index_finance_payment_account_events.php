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
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)
            || $schema->hasIndex('finance_payment_transactions', 'finance_payment_account_events_idx')) {
            return;
        }
        $summaryLock = hash('sha256', 'finance:summaries:refresh:'.$db->getDatabaseName());
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 3) AS acquired', [$summaryLock])->acquired !== 1) {
            throw new RuntimeException('Finance summary refresh is still running. Retry this migration after it finishes.');
        }
        $previousTimeout = (int) $db->selectOne('SELECT @@SESSION.lock_wait_timeout AS timeout')->timeout;
        $db->statement('SET SESSION lock_wait_timeout = 3');
        try {
            $schema->table('finance_payment_transactions', fn (Blueprint $table) => $table->index(
                ['payment_account_id', 'event_code', 'finance_record_id', 'transaction_id'], 'finance_payment_account_events_idx'
            ));
        } finally {
            $db->statement('SET SESSION lock_wait_timeout = '.$previousTimeout);
            $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$summaryLock]);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasIndex('finance_payment_transactions', 'finance_payment_account_events_idx')) {
            $schema->table('finance_payment_transactions', fn (Blueprint $table) => $table->dropIndex('finance_payment_account_events_idx'));
        }
    }
};
