<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceReadModelBuilds;
use App\Portals\Finance\FinanceQueryDiagnostics;
use Illuminate\Console\Command;

class BuildFinanceReadModels extends Command
{
    protected $signature = 'finance:read-models:build {--retry-failed : Retry failed background calculations}';
    protected $description = 'Build requested finance query inputs independently of source notification processing';

    public function handle(FinanceReadModelBuilds $builds): int
    {
        if (! $builds->installed()) { $this->error('请先执行财务读写分离迁移。'); return self::FAILURE; }
        $db = $builds->database();
        $mysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        $key = hash('sha256', 'finance:read-models:'.$db->getName().':'.$db->getDatabaseName());
        $lock = $mysql ? null : \Illuminate\Support\Facades\Cache::store('file')->lock($key, 1800);
        if ($mysql ? (int) $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$key])->acquired !== 1 : ! $lock->get()) return self::SUCCESS;
        $trace = FinanceQueryDiagnostics::start(request());
        $readOnly = request()->attributes->get('finance.report_read_only');
        request()->attributes->remove('finance.report_read_only');
        $executionLimit = (int) ini_get('max_execution_time');
        set_time_limit(900);
        $db->listen(fn (\Illuminate\Database\Events\QueryExecuted $query) => $query->connection === $db ? $trace->query($query->sql, $query->time) : null);
        $trace->event('read_model.worker.start');
        try {
            if ($this->option('retry-failed')) $db->table(FinanceReadModelBuilds::TABLE)->where('state', 'failed')
                ->update(['state' => 'pending', 'attempts' => 0, 'available_at' => now(), 'error_class' => null]);
            $stats = $builds->run();
            $trace->event('read_model.worker.summary', $stats, true);
            $this->line(json_encode($stats, JSON_UNESCAPED_UNICODE));
            return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            request()->attributes->set('finance.report_read_only', $readOnly);
            $trace->finish('read_model.worker.end');
            set_time_limit($executionLimit);
            if ($mysql) $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$key]);
            else $lock->release();
        }
    }
}
