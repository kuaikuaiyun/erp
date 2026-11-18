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
        if (! $reports->runsInBackground()) {
            return self::SUCCESS;
        }

        $lockName = 'finance:summaries:refresh:'.$db->getDatabaseName();
        $mysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        $lock = $mysql ? null : Cache::lock($lockName, 300);
        $pdo = $mysql ? $db->getPdo() : null;
        $sessionLock = hash('sha256', $lockName);
        if ($pdo !== null) {
            // A session lock disappears when the worker or MySQL connection exits.
            $acquire = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $acquire->execute([$sessionLock]);
            $acquired = (int) $acquire->fetchColumn() === 1;
            $acquire->closeCursor();
        } else {
            $acquired = $lock->get();
        }
        if (! $acquired) {
            return self::SUCCESS;
        }
        $executionLimit = (int) ini_get('max_execution_time');
        if ($executionLimit === 0 || $executionLimit > 900) {
            set_time_limit(900);
        }
        $memoryLimit = (string) ini_get('memory_limit');
        $memoryBytes = ini_parse_quantity($memoryLimit);
        $raisedLimit = $memoryBytes > 0 && $memoryBytes < 256 * 1024 ** 2
            && ini_set('memory_limit', '256M') !== false;
        try {
            $tracksRevisions = \App\Portals\Finance\FinanceSummaryTriggers::available($db);
            $failed = false;
            if ($tracksRevisions) {
                $dependencies->resolve($db);
            }
            // Publish requested reports before rebuilding historical projections.
            $reportsRefreshed = $reports->refresh($controller, $tracksRevisions);
            if ($tracksRevisions) {
                try {
                    // Source revisions do not change when cached reference FX becomes available.
                    // refresh() also checks its FX fingerprint before reusing a publication.
                    $periods = $db->table('finance_summary_periods')->orderByDesc('month')->get();
                    foreach ($periods as $period) {
                        $projection->refresh($period);
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                    $failed = true;
                }
            }
            return $reportsRefreshed && ! $failed ? self::SUCCESS : self::FAILURE;
        } finally {
            if ($pdo !== null) {
                try {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$sessionLock]);
                    $release->closeCursor();
                } catch (\PDOException $exception) {
                    report($exception);
                }
            } else {
                $lock->release();
            }
            if ($raisedLimit && memory_get_usage(true) < $memoryBytes) {
                ini_set('memory_limit', $memoryLimit);
            }
            set_time_limit($executionLimit);
        }
    }
}
