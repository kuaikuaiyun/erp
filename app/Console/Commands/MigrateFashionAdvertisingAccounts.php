<?php

namespace App\Console\Commands;

use App\Models\FashionAdvertisingReport;
use App\Portals\Fashion\OperationProjectCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateFashionAdvertisingAccounts extends Command
{
    protected $signature = 'fashion-advertising:migrate-accounts
        {--operator= : 运营人员用户 ID}
        {--from= : 原项目代码}
        {--to= : 目标项目代码}
        {--account=* : 要迁移的广告账号编号，可重复传入}
        {--execute : 实际执行；不传时仅预览}';

    protected $description = '在运营项目之间迁移指定人员的广告账号历史明细，并重算两边日报汇总';

    public function handle(): int
    {
        $operatorId = (int) $this->option('operator');
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $accounts = collect($this->option('account'))
            ->map(fn ($number): string => trim((string) $number))
            ->filter()
            ->unique()
            ->values();

        if ($operatorId < 1 || $from === '' || $to === '' || $from === $to || $accounts->isEmpty()) {
            $this->error('必须提供有效的 --operator、不同的 --from/--to，以及至少一个 --account。');

            return self::INVALID;
        }

        $projects = OperationProjectCatalog::all();
        if (! isset($projects[$from], $projects[$to])) {
            $this->error('项目代码不存在。');

            return self::INVALID;
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        $db = DB::connection($connection);
        $entries = $db->table('fashion_ad_spend_entries as entries')
            ->join('fashion_advertising_reports as reports', 'reports.id', '=', 'entries.fashion_advertising_report_id')
            ->where('reports.project_code', $from)
            ->where('reports.submitter_id', $operatorId)
            ->whereNull('reports.deleted_at')
            ->whereIn('entries.account_number', $accounts->all())
            ->select([
                'entries.id', 'entries.account_number', 'entries.account_name', 'entries.actual_spend_usd',
                'reports.id as source_report_id', 'reports.report_date', 'reports.submitter_name', 'reports.status',
            ])
            ->orderBy('reports.report_date')
            ->orderBy('entries.id')
            ->get();

        if ($entries->isEmpty()) {
            $this->warn('没有找到符合条件的源项目广告明细，无需迁移。');

            return self::SUCCESS;
        }

        $this->table(
            ['日期', '账号编号', '账号名称', '实际广告费', '源日报 ID'],
            $entries->map(fn ($entry): array => [
                $entry->report_date,
                $entry->account_number,
                $entry->account_name,
                number_format((float) $entry->actual_spend_usd, 2, '.', ''),
                $entry->source_report_id,
            ])->all(),
        );
        $this->info(sprintf(
            '共 %d 条，涉及 %d 天，实际广告费合计 $%s。',
            $entries->count(),
            $entries->pluck('report_date')->unique()->count(),
            number_format((float) $entries->sum('actual_spend_usd'), 2, '.', ''),
        ));

        if (! $this->option('execute')) {
            $this->comment('当前为预览模式；确认无误后增加 --execute 执行。');

            return self::SUCCESS;
        }

        $moved = $db->transaction(function () use ($db, $operatorId, $from, $to, $accounts): int {
            $sourceReports = $db->table('fashion_advertising_reports')
                ->where('project_code', $from)
                ->where('submitter_id', $operatorId)
                ->whereNull('deleted_at')
                ->whereExists(function ($query) use ($accounts): void {
                    $query->selectRaw('1')
                        ->from('fashion_ad_spend_entries')
                        ->whereColumn('fashion_ad_spend_entries.fashion_advertising_report_id', 'fashion_advertising_reports.id')
                        ->whereIn('fashion_ad_spend_entries.account_number', $accounts->all());
                })
                ->lockForUpdate()
                ->get();

            $moved = 0;
            $affectedReportIds = [];
            foreach ($sourceReports as $source) {
                $target = $db->table('fashion_advertising_reports')
                    ->where('project_code', $to)
                    ->where('report_date', $source->report_date)
                    ->where('submitter_id', $operatorId)
                    ->lockForUpdate()
                    ->first();

                if (! $target) {
                    $targetId = $db->table('fashion_advertising_reports')->insertGetId([
                        'project_code' => $to,
                        'report_date' => $source->report_date,
                        'submitter_id' => $source->submitter_id,
                        'submitter_name' => $source->submitter_name,
                        'total_revenue_usd' => 0,
                        'total_order_count' => 0,
                        'total_ad_spend_usd' => 0,
                        'roi' => 0,
                        'status' => $source->status,
                        'reviewed_by' => $source->reviewed_by,
                        'reviewer_name' => $source->reviewer_name,
                        'reviewed_at' => $source->reviewed_at,
                        'review_reason' => $source->review_reason,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $targetId = (int) $target->id;
                    if ($target->deleted_at !== null) {
                        $db->table('fashion_advertising_reports')->where('id', $targetId)->update([
                            'deleted_at' => null,
                            'deleted_by' => null,
                            'deleter_name' => null,
                            'updated_at' => now(),
                        ]);
                    }
                }

                $moved += $db->table('fashion_ad_spend_entries')
                    ->where('fashion_advertising_report_id', $source->id)
                    ->whereIn('account_number', $accounts->all())
                    ->update([
                        'fashion_advertising_report_id' => $targetId,
                        'updated_at' => now(),
                    ]);
                $affectedReportIds[] = (int) $source->id;
                $affectedReportIds[] = $targetId;
            }

            foreach (array_unique($affectedReportIds) as $reportId) {
                $report = $db->table('fashion_advertising_reports')->where('id', $reportId)->lockForUpdate()->first();
                if (! $report) {
                    continue;
                }
                $spend = round((float) $db->table('fashion_ad_spend_entries')
                    ->where('fashion_advertising_report_id', $reportId)
                    ->sum('actual_spend_usd'), 2);
                $db->table('fashion_advertising_reports')->where('id', $reportId)->update([
                    'total_ad_spend_usd' => $spend,
                    'roi' => $spend > 0 ? round((float) $report->total_revenue_usd / $spend, 4) : 0,
                    'updated_at' => now(),
                ]);
            }

            return $moved;
        });

        $this->info("迁移完成，共移动 {$moved} 条广告明细；源项目和目标项目日报汇总已重新计算。");

        return self::SUCCESS;
    }
}
