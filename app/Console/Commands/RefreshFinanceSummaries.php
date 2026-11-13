<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceSummaryProjection;
use App\Portals\Finance\FinanceSummaryDependencies;
use App\Portals\Finance\FinanceReportSnapshots;
use App\Portals\Finance\Http\Controllers\FinanceController;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshFinanceSummaries extends Command
{
    protected $signature = 'finance:summaries:refresh';

    protected $description = 'Publish pending monthly finance summary projections';

    public function handle(FinanceWorkbookService $workbooks, FinanceSummaryProjection $projection, FinanceSummaryDependencies $dependencies, FinanceReportSnapshots $reports, FinanceController $controller): int
    {
        $db = $workbooks->database();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return self::SUCCESS;
        }

        $lock = Cache::lock('finance:summaries:refresh:'.$db->getDatabaseName(), 3600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $dependencies->resolve($db);
            $periods = $db->table('finance_summary_periods')->whereColumn('published', '<', 'revision')->orderByDesc('month')->get();
            foreach ($periods as $period) {
                $projection->refresh($period);
            }
            foreach ($periods->pluck('month')->unique() as $month) {
                $reports->refreshMonth($month, $controller);
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
