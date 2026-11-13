<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (['insert' => ['NEW'], 'update' => ['OLD', 'NEW'], 'delete' => ['OLD']] as $event => $images) {
            $writes = array_map(fn ($image) => "INSERT INTO finance_summary_periods (month, channel)
                SELECT DATE_FORMAT({$image}.settlement_date, '%Y-%m'), 'credit_card'
                WHERE {$image}.platform = 'ye'
                ON DUPLICATE KEY UPDATE revision = revision + 1", $images);
            \App\Portals\Finance\FinanceSummaryTriggers::install($db, "CREATE TRIGGER finance_summary_statement_{$event} AFTER {$event} ON finance_credit_settlement_entries
                FOR EACH ROW BEGIN ".implode('; ', $writes)."; END");
        }
        $db->statement("INSERT INTO finance_summary_periods (month, channel)
            SELECT DISTINCT DATE_FORMAT(settlement_date, '%Y-%m'), 'credit_card'
            FROM finance_credit_settlement_entries WHERE platform = 'ye'
            ON DUPLICATE KEY UPDATE revision = revision + 1");
        $db->table('finance_summary_periods')->where('channel', 'credit_card')->increment('revision');
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['insert', 'update', 'delete'] as $event) {
                $db->unprepared("DROP TRIGGER IF EXISTS finance_summary_statement_{$event}");
            }
        }
    }
};
