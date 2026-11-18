<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'finance_credit_usd_rates',
        'finance_report_scopes',
        'finance_report_snapshots',
        'finance_report_snapshot_rows',
        'finance_summary_buckets',
        'finance_summary_changes',
        'finance_worldpay_releases',
    ];

    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $schema = $db->getSchemaBuilder();
        foreach (self::TABLES as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            $engine = $db->selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$db->getDatabaseName(), $db->getTablePrefix().$table]);
            if (strtoupper($engine->engine ?? '') !== 'INNODB') {
                $db->statement('ALTER TABLE '.$db->getQueryGrammar()->wrapTable($table).' ENGINE=InnoDB');
            }
        }
    }

    public function down(): void
    {
        // InnoDB is an application requirement; do not reintroduce table locks.
    }
};
