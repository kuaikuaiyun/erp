<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceOverviewJobs;
use App\Portals\Finance\FinanceQueryDiagnostics;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Console\Command;

class BuildFinanceOverviews extends Command
{
    protected $signature = 'finance:overviews:build';
    protected $description = 'Calculate only requested summary and loss overview pages';

    public function handle(FinanceOverviewJobs $jobs, FinanceWorkbookService $workbooks): int
    {
        if (! config('finance.overviews.background', true)) return self::SUCCESS;
        if (! $jobs->installed()) { $this->error('请执行财务辅助表迁移。'); return self::FAILURE; }
        $db = $workbooks->database();
        $key = hash('sha256', 'finance:overviews:'.$db->getName().':'.$db->getDatabaseName());
        $mysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        $lock = $mysql ? null : \Illuminate\Support\Facades\Cache::store('file')->lock($key, 1800);
        if ($mysql ? (int) $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$key])->acquired !== 1 : ! $lock->get()) return self::SUCCESS;
        $trace = FinanceQueryDiagnostics::start(request());
        $db->listen(fn (\Illuminate\Database\Events\QueryExecuted $query) => $query->connection === $db ? $trace->query($query->sql, $query->time) : null);
        $execution = (int) ini_get('max_execution_time');
        set_time_limit(900);
        try {
            $stats = $jobs->run();
            $trace->event('overview.worker.summary', $stats, true);
            $this->line(json_encode($stats, JSON_UNESCAPED_UNICODE));
            return $stats['failed'] ? self::FAILURE : self::SUCCESS;
        } finally {
            $trace->finish('overview.worker.end');
            set_time_limit($execution);
            if ($mysql) $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$key]);
            else $lock->release();
        }
    }
}
