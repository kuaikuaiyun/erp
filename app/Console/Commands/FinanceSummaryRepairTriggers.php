<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceSummarySourceChanges;
use App\Portals\Finance\FinanceSummaryTriggers;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Console\Command;

class FinanceSummaryRepairTriggers extends Command
{
    protected $signature = 'finance:summaries:repair-triggers';
    protected $description = 'Restore missing finance tracking triggers after an interrupted deployment';

    public function handle(FinanceWorkbookService $workbooks): int
    {
        $db = $workbooks->database();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('此命令仅用于 MySQL/MariaDB。');
            return self::FAILURE;
        }
        if (FinanceSummaryTriggers::available($db) && FinanceSummarySourceChanges::available($db)) {
            $this->info('财务触发器完整，无需修复；已有缓存版本保持不变。');
            return self::SUCCESS;
        }
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable(FinanceSummarySourceChanges::TABLE)
            || ! $schema->hasColumn('finance_summary_changes', 'scopes_resolved')) {
            $this->error('请先修复迁移账号权限并执行 php artisan migrate --force，再运行此命令。');
            return self::FAILURE;
        }
        // These additive migrations are idempotent and preserve business records.
        // The final scoped migration publishes one new epoch to cover the tracking gap.
        FinanceSummaryTriggers::assertCanInstall($db);
        foreach ([
            '2026_11_13_000001_create_finance_summary_projections',
            '2026_11_13_000002_track_finance_summary_source_changes',
            '2026_11_13_000005_track_finance_statement_month_changes',
            '2026_11_20_000002_track_finance_live_source_updates',
            '2026_11_20_000003_scope_finance_source_invalidations',
        ] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        if (! FinanceSummaryTriggers::available($db) || ! FinanceSummarySourceChanges::available($db)) {
            $this->error('仍有触发器缺失，请执行 php artisan finance:summaries:status 检查，勿启用旧缓存复用。');
            return self::FAILURE;
        }
        $this->info('财务变更追踪已修复，已更新缓存版本以覆盖中断期间的变更。订单和财务原始记录保留。');
        return self::SUCCESS;
    }
}
