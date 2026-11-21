<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionLogisticsOrder;
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

        $targetReportIds = $db->table('fashion_ad_spend_entries as entries')
            ->join('fashion_advertising_reports as reports', 'reports.id', '=', 'entries.fashion_advertising_report_id')
            ->where('reports.project_code', $to)
            ->where('reports.submitter_id', $operatorId)
            ->whereNull('reports.deleted_at')
            ->whereIn('entries.account_number', $accounts->all())
            ->distinct()
            ->pluck('reports.id');

        if ($entries->isEmpty() && $targetReportIds->isEmpty()) {
            $this->warn('没有找到符合条件的源项目广告明细，无需迁移。');

            return self::SUCCESS;
        }

        if ($entries->isNotEmpty()) {
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
                '待迁移 %d 条，涉及 %d 天，实际广告费合计 $%s。',
                $entries->count(),
                $entries->pluck('report_date')->unique()->count(),
                number_format((float) $entries->sum('actual_spend_usd'), 2, '.', ''),
            ));
        }
        $this->info(sprintf('将重新计算目标项目 %d 份日报的订单量、营业额和 ROI。', $targetReportIds->count() + $entries->pluck('report_date')->unique()->count()));

        if (! $this->option('execute')) {
            $this->comment('当前为预览模式；确认无误后增加 --execute 执行。');

            return self::SUCCESS;
        }

        [$moved, $refreshed] = $db->transaction(function () use ($db, $operatorId, $from, $to, $accounts): array {
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

            $targetReports = $db->table('fashion_advertising_reports as reports')
                ->where('reports.project_code', $to)
                ->where('reports.submitter_id', $operatorId)
                ->whereNull('reports.deleted_at')
                ->whereExists(function ($query) use ($accounts): void {
                    $query->selectRaw('1')
                        ->from('fashion_ad_spend_entries')
                        ->whereColumn('fashion_ad_spend_entries.fashion_advertising_report_id', 'reports.id')
                        ->whereIn('fashion_ad_spend_entries.account_number', $accounts->all());
                })
                ->lockForUpdate()
                ->get();

            foreach ($targetReports as $targetReport) {
                $performance = $this->calculatedPerformanceRows((string) $targetReport->report_date, $operatorId, $to);
                $db->table('fashion_performance_entries')
                    ->where('fashion_advertising_report_id', $targetReport->id)
                    ->delete();
                if ($performance !== []) {
                    $now = now();
                    $db->table('fashion_performance_entries')->insert(array_map(
                        fn (array $row): array => [
                            'fashion_advertising_report_id' => (int) $targetReport->id,
                            ...$row,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        $performance,
                    ));
                }
                $revenue = round((float) collect($performance)->sum('revenue_usd'), 2);
                $orders = (int) collect($performance)->sum('order_count');
                $spend = round((float) $db->table('fashion_ad_spend_entries')
                    ->where('fashion_advertising_report_id', $targetReport->id)
                    ->sum('actual_spend_usd'), 2);
                $db->table('fashion_advertising_reports')->where('id', $targetReport->id)->update([
                    'total_revenue_usd' => $revenue,
                    'total_order_count' => $orders,
                    'total_ad_spend_usd' => $spend,
                    'roi' => $spend > 0 ? round($revenue / $spend, 4) : 0,
                    'updated_at' => now(),
                ]);
            }

            return [$moved, $targetReports->count()];
        });

        $this->info("处理完成：移动 {$moved} 条广告明细，重新计算 {$refreshed} 份目标日报的订单量、营业额和 ROI。");

        return self::SUCCESS;
    }

    private function calculatedPerformanceRows(string $reportDate, int $operatorId, string $projectCode): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $orderTable = (new FashionLogisticsOrder)->getTable();
        $owner = AdminUser::query()->find($operatorId);
        $ownerNames = collect([$owner?->name, $owner?->username])
            ->filter()->map(fn ($name): string => trim((string) $name))->unique()->values()->all();
        $project = OperationProjectCatalog::get($projectCode);

        return DB::connection($connection)->table($orderTable.' as orders')
            ->join('assistant_domain_stores as stores', 'stores.id', '=', 'orders.assistant_domain_store_id')
            ->where(function ($binding) use ($operatorId, $ownerNames): void {
                $binding->where('stores.owner_user_id', $operatorId);
                if ($ownerNames !== []) {
                    $binding->orWhereIn('stores.user_name', $ownerNames);
                }
            })
            ->whereIn(DB::raw('TRIM(stores.project)'), OperationProjectCatalog::storeProjectNames($project))
            ->whereRaw("LOWER(TRIM(orders.source_platform)) = CASE WHEN LOWER(TRIM(stores.site_builder)) = 'xshopy' THEN 'xshoppy' ELSE LOWER(TRIM(stores.site_builder)) END")
            ->where(function ($paid): void {
                $paid->whereNotIn(DB::raw('LOWER(TRIM(orders.source_platform))'), ['nbc', 'shopyy'])
                    ->orWhere('orders.payment_status', '230');
            })
            ->whereBetween('orders.order_created_at', [$reportDate.' 00:00:00', $reportDate.' 23:59:59'])
            ->where(function ($paid): void {
                $paid->whereNotNull('orders.paid_at')->orWhereIn('orders.status', ['processing', 'completed']);
            })
            ->select(['stores.id', 'stores.shop_name', 'stores.domain'])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(orders.total_amount), 0) as revenue_usd')
            ->groupBy('stores.id', 'stores.shop_name', 'stores.domain')
            ->orderBy('stores.shop_name')
            ->get()
            ->map(static fn (object $row): array => [
                'store_name' => trim((string) $row->shop_name) ?: trim((string) $row->domain),
                'domain' => trim((string) $row->domain),
                'revenue_usd' => round((float) $row->revenue_usd, 2),
                'order_count' => (int) $row->order_count,
                'screenshot_path' => null,
                'remark' => '系统根据店铺已支付订单自动统计',
            ])->values()->all();
    }
}
