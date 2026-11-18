<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceIndexMigrationDeferred;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

class ApplyFinanceIndexes extends Command
{
    public const MIGRATIONS = [
        '2026_11_18_000002_index_finance_statement_notices',
        '2026_11_18_000003_index_finance_conflux_order_matches',
        '2026_11_18_000004_index_finance_filter_choices',
        '2026_11_18_000005_index_finance_paypal_payment_candidates',
        '2026_11_18_000006_index_finance_xuzong_merchant_numbers',
        '2026_11_18_000007_index_finance_payment_account_events',
    ];

    protected $signature = 'finance:indexes:apply {--status : Only list pending optional finance indexes}';

    protected $description = 'Apply one pending optional finance index migration when the database is idle';

    public function handle(FinanceWorkbookService $workbooks, Migrator $migrator): int
    {
        if (! in_array($workbooks->database()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->info('当前数据库无需执行 MySQL 财务性能索引迁移。');
            return self::SUCCESS;
        }
        $repository = $migrator->getRepository();
        if (! $repository->repositoryExists()) {
            $this->warn('尚未初始化迁移记录，请先执行正常部署迁移。');
            return self::SUCCESS;
        }
        $pending = array_values(array_diff(self::MIGRATIONS, $repository->getRan()));
        if ($pending === []) {
            $this->info('财务性能索引迁移已全部完成。');
            return self::SUCCESS;
        }
        if ($this->option('status')) {
            foreach ($pending as $name) {
                $this->line('待执行：'.$name);
            }
            return self::SUCCESS;
        }
        $name = $pending[0];
        try {
            // Never run unrelated pending application migrations from a scheduled task.
            $migrator->setOutput($this->output)->run([database_path('migrations/'.$name.'.php')], ['step' => true]);
        } catch (FinanceIndexMigrationDeferred $exception) {
            $this->warn($exception->getMessage());
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('财务索引未完成：'.$exception->getMessage());
            return self::FAILURE;
        }
        if (in_array($name, $repository->getRan(), true)) {
            $this->info('已完成：'.$name.'。其余待执行项将在后续调度逐个处理。');
        } else {
            $this->warn('数据库繁忙，本次未执行索引迁移，将在后续调度重试。');
        }
        return self::SUCCESS;
    }
}
