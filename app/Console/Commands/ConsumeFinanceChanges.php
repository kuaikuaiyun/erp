<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceSummaryDependencies;
use App\Portals\Finance\FinanceWorkbookService;
use App\Portals\Finance\FinanceQueryDiagnostics;
use Illuminate\Console\Command;

class ConsumeFinanceChanges extends Command
{
    protected $signature = 'finance:changes:consume';
    protected $description = 'Import and resolve source changes independently of finance report calculations';

    public function handle(FinanceWorkbookService $workbooks, FinanceSummaryDependencies $dependencies): int
    {
        $db = $workbooks->database();
        if (! \App\Portals\Finance\FinanceReadModelVersions::installed($db)) return self::SUCCESS;
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) return self::SUCCESS;
        $key = hash('sha256', 'finance:changes:'.$db->getName().':'.$db->getDatabaseName());
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$key])->acquired !== 1) return self::SUCCESS;
        $trace = FinanceQueryDiagnostics::start(request());
        $trace->event('changes.worker.start');
        $db->listen(fn (\Illuminate\Database\Events\QueryExecuted $query) => $query->connection === $db ? $trace->query($query->sql, $query->time) : null);
        try {
            $deadline = hrtime(true) + 45 * 1e9;
            $ready = false;
            for ($pass = 0; $pass < 50 && hrtime(true) < $deadline; $pass++) {
                if ($ready = $dependencies->resolve($db)) break;
            }
            $trace->event('changes.worker.summary', ['ready' => $ready, 'passes' => $pass], true);
            $this->line($ready ? '来源变更已处理完。' : '本轮处理结束，剩余变更下轮继续；报表计算独立运行。');
            return self::SUCCESS;
        } finally {
            $trace->finish('changes.worker.end');
            $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$key]);
        }
    }
}
