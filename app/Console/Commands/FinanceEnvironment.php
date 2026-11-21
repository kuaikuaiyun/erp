<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceApplicationChanges;
use App\Portals\Finance\FinanceSourceStorage;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Console\Command;

class FinanceEnvironment extends Command
{
    protected $signature = 'finance:environment';
    protected $description = 'Print non-secret finance runtime and database settings for local/production comparison';

    public function handle(FinanceWorkbookService $workbooks): int
    {
        $db = $workbooks->database();
        $result = ['php' => PHP_VERSION, 'laravel' => app()->version(), 'driver' => $db->getDriverName(),
            'database' => $db->getDatabaseName(), 'app_timezone' => config('app.timezone'),
            'application_tracking' => FinanceApplicationChanges::available($db)];
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $result['server'] = (array) $db->selectOne('SELECT VERSION() AS version, @@SESSION.sql_mode AS sql_mode,
                @@SESSION.character_set_connection AS charset, @@SESSION.collation_connection AS collation,
                @@SESSION.time_zone AS timezone, @@GLOBAL.log_bin AS log_bin, @@GLOBAL.binlog_format AS binlog_format');
            $result['row_formats'] = $db->table('information_schema.TABLES')->where('TABLE_SCHEMA', $db->getDatabaseName())
                ->whereIn('TABLE_NAME', ['finance_records', 'finance_payment_transactions', 'finance_credit_settlement_entries', 'finance_transactions'])
                ->pluck('ROW_FORMAT', 'TABLE_NAME')->all();
            $result['finance_trigger_count'] = $db->table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $db->getDatabaseName())
                ->where('TRIGGER_NAME', 'like', 'finance\_%')->count();
        }
        if (app(FinanceSourceStorage::class)->installed()) $result['sources'] = $db->table('finance_source_storage')->orderBy('kind')->pluck('state', 'kind')->all();
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
