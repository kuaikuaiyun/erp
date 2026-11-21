<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceSummaryProjection;
use App\Portals\Finance\FinanceSummaryDependencies;
use App\Portals\Finance\FinancePayPalSettlementProjection;
use App\Portals\Finance\FinanceWorkbookService;
use App\Portals\Finance\FinanceQueryDiagnostics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshFinanceSummaries extends Command
{
    protected $signature = 'finance:summaries:refresh';

    protected $description = 'Maintain changed finance order calculations without generating report snapshots';

    public function handle(FinanceWorkbookService $workbooks, FinanceSummaryProjection $projection, FinanceSummaryDependencies $dependencies, FinancePayPalSettlementProjection $paypalSettlement): int
    {
        $db = $workbooks->database();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
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
        $ownsTrace = FinanceQueryDiagnostics::current() === null && config('finance.diagnostics.enabled', true);
        $trace = $ownsTrace ? FinanceQueryDiagnostics::start(request()) : FinanceQueryDiagnostics::current();
        $trace?->event('worker.start', ['command' => 'finance:summaries:refresh']);
        if ($trace !== null) $db->listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($db, $trace): void {
            if ($query->connection === $db) $trace->query($query->sql, $query->time);
        });
        $executionLimit = (int) ini_get('max_execution_time');
        if ($executionLimit === 0 || $executionLimit > 900) {
            set_time_limit(900);
        }
        $memoryLimit = (string) ini_get('memory_limit');
        $memoryBytes = ini_parse_quantity($memoryLimit);
        $raisedLimit = $memoryBytes > 0 && $memoryBytes < 256 * 1024 ** 2
            && ini_set('memory_limit', '256M') !== false;
        try {
            $this->recordStage('处理依赖变更');
            $tracksRevisions = \App\Portals\Finance\FinanceSummarySourceChanges::available($db);
            $failed = false;
            if ($tracksRevisions) {
                $dependencies->resolve($db);
            }
            $this->recordStage('检查变更月份的结算计算行');
            if ($tracksRevisions && ! $projection->usesSourceFiles()) {
                try {
                    // Maintain canonical order calculations only after source changes.
                    // Reports themselves are calculated on demand, never pre-generated.
                    $pending = $db->table('finance_summary_periods')
                        ->whereColumn('published', '<>', 'revision')
                        ->orderBy('month')->orderBy('channel')->limit(1)->get();
                    foreach ($pending as $period) {
                        $this->recordStage('更新 '.$period->month.' / '.$period->channel.' 结算计算行');
                        $projection->refresh($period);
                    }
                } catch (\Throwable $exception) {
                    $this->recordStage('月份投影执行异常（'.$exception::class.'）');
                    report($exception);
                    $failed = true;
                }
            }
            $paypalRefreshed = true;
            if ($tracksRevisions && ! $failed) {
                try {
                    $this->recordStage('生成 PayPal 结算明细投影');
                    $paypalRefreshed = $paypalSettlement->refresh();
                    if (! $paypalRefreshed) $this->recordStage('PayPal 结算计算行待重试或处于冷却期');
                } catch (\Throwable $exception) {
                    $this->recordStage('PayPal 结算投影执行异常（'.$exception::class.'）');
                    report($exception);
                    $failed = true;
                }
            }
            if (! $failed && $paypalRefreshed) $this->recordStage('本轮执行完成');
            return ! $failed && $paypalRefreshed ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->recordStage('执行异常（'.$exception::class.'）');
            $trace?->event('worker.failed', ['exception' => $exception::class]);
            throw $exception;
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
            if ($ownsTrace) $trace?->finish('worker.end');
            if ($raisedLimit && memory_get_usage(true) < $memoryBytes) {
                ini_set('memory_limit', $memoryLimit);
            }
            set_time_limit($executionLimit);
        }
    }

    private function recordStage(string $stage): void
    {
        FinanceQueryDiagnostics::current()?->phase('worker.stage', ['name' => $stage], true);
        try {
            Cache::put('finance:summaries:worker-status', [
                'stage' => $stage, 'at' => now('Asia/Shanghai')->format('Y-m-d H:i:s'),
            ], now()->addDays(2));
        } catch (\Throwable) {
            // Diagnostics must never prevent publication.
        }
    }
}
