<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OperationReport;
use App\Models\FashionAdvertisingReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Operation\OperationReportMetricsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AdvertisingSpendSummaryController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $assistantStoreOwners,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'agency' => ['nullable', 'string', 'max:100'],
            'media' => ['nullable', 'string', 'max:100'],
            'operator' => ['nullable', 'string', 'max:100'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:150'],
            'project' => ['nullable', 'in:shopify,fashion'],
        ]);
        $now = Carbon::now(OperationReportMetricsService::REPORT_TIMEZONE);
        $dateFrom = (string) ($validated['date_from'] ?? $now->copy()->subDays(6)->format('Y-m-d'));
        $dateTo = (string) ($validated['date_to'] ?? $now->format('Y-m-d'));
        abort_if($dateFrom > $dateTo, 422, '开始日期不能晚于结束日期。');

        $visibleDepartmentIds = $this->assistantStoreOwners->visibleOperationDepartmentIds($context['user']);
        $selectedProject = (string) ($validated['project'] ?? '');
        $reports = OperationReport::query()
            ->where('status', OperationReport::STATUS_APPROVED)
            ->whereIn('operation_department_id', $visibleDepartmentIds)
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->orderBy('report_date')
            ->orderBy('id')
            ->get(['id', 'report_date', 'operator_user_id', 'operator_name', 'advertising_rows']);

        $shopifyRows = $reports->flatMap(function (OperationReport $report): array {
            return collect($report->advertising_rows ?: [])->map(function (array $advertising) use ($report): array {
                $bookSpend = (float) ($advertising['book_spend'] ?? 0);
                $coefficient = (float) ($advertising['settlement_coefficient'] ?? 0);
                $handlingFee = (float) ($advertising['handling_fee'] ?? ($bookSpend * $coefficient));

                return [
                    'project' => 'shopify',
                    'project_label' => 'Shopify订阅项目',
                    'date' => $report->report_date->format('Y-m-d'),
                    'agency' => trim((string) ($advertising['agency'] ?? '')) ?: '未填写代理',
                    'media' => trim((string) ($advertising['media_source'] ?? '')) ?: '其他',
                    'account_name' => trim((string) ($advertising['account_name'] ?? '')),
                    'account_number' => trim((string) ($advertising['account_number'] ?? '')),
                    'book_spend' => $bookSpend,
                    'settlement_coefficient' => $coefficient,
                    'handling_fee' => $handlingFee,
                    'actual_spend' => (float) ($advertising['actual_spend'] ?? ($bookSpend + $handlingFee)),
                    'screenshot' => trim((string) ($advertising['screenshot'] ?? '')),
                    'report_id' => (int) $report->getKey(),
                    'operator_user_id' => (int) $report->operator_user_id,
                    'operator_name' => trim((string) $report->operator_name) ?: '未填写运营',
                    'entry_id' => null,
                ];
            })->all();
        })->values();
        $fashionReports = FashionAdvertisingReport::query()
            ->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->whereHas('submitter.departments', fn ($query) => $query->whereIn('admin_roles.id', $visibleDepartmentIds))
            ->with('adSpendEntries')->get();
        $fashionRows = $fashionReports->flatMap(function (FashionAdvertisingReport $report): array {
            return $report->adSpendEntries->map(function ($entry) use ($report): array {
                $bookSpend = (float) $entry->book_spend_usd;
                $actualSpend = (float) $entry->actual_spend_usd;
                return [
                    'project' => 'fashion', 'project_label' => '服装项目',
                    'date' => $report->report_date->format('Y-m-d'),
                    'agency' => trim((string) $entry->agency) ?: '未填写代理',
                    'media' => trim((string) $entry->media) ?: '其他',
                    'account_name' => trim((string) $entry->account_name),
                    'account_number' => trim((string) $entry->account_number),
                    'book_spend' => $bookSpend,
                    'settlement_coefficient' => (float) $entry->settlement_coefficient,
                    'handling_fee' => max(0, $actualSpend - $bookSpend),
                    'actual_spend' => $actualSpend,
                    'screenshot' => trim((string) $entry->screenshot_path),
                    'report_id' => (int) $report->getKey(),
                    'operator_user_id' => (int) $report->submitter_id,
                    'operator_name' => trim((string) $report->submitter_name) ?: '未填写运营',
                    'entry_id' => (int) $entry->getKey(),
                ];
            })->all();
        })->values();
        $allRows = collect()
            ->when($selectedProject !== 'fashion', fn ($rows) => $rows->concat($shopifyRows))
            ->when($selectedProject !== 'shopify', fn ($rows) => $rows->concat($fashionRows))
            ->sortBy(fn (array $row): string => implode('|', [
            $row['date'], $row['operator_name'], $row['agency'], $row['media'], $row['account_name'], $row['account_number'],
        ]))->values();

        $agencyOptions = $allRows->pluck('agency')->filter()->unique()->sort()->values();
        $mediaOptions = $allRows->pluck('media')->filter()->unique()->sort()->values();
        $filters = [
            'agency' => trim((string) ($validated['agency'] ?? '')),
            'media' => trim((string) ($validated['media'] ?? '')),
            'operator' => trim((string) ($validated['operator'] ?? '')),
            'account_name' => trim((string) ($validated['account_name'] ?? '')),
            'account_number' => trim((string) ($validated['account_number'] ?? '')),
            'project' => $selectedProject,
        ];
        $filteredRows = $allRows->filter(function (array $row) use ($filters): bool {
            if ($filters['agency'] !== '' && mb_strtolower($row['agency']) !== mb_strtolower($filters['agency'])) return false;
            if ($filters['media'] !== '' && mb_strtolower($row['media']) !== mb_strtolower($filters['media'])) return false;
            if ($filters['operator'] !== '' && mb_stripos($row['operator_name'], $filters['operator']) === false) return false;
            if ($filters['account_name'] !== '' && mb_stripos($row['account_name'], $filters['account_name']) === false) return false;
            if ($filters['account_number'] !== '' && mb_stripos($row['account_number'], $filters['account_number']) === false) return false;

            return true;
        })->values();
        $page = max(1, (int) $request->query('page', 1));
        $rows = new LengthAwarePaginator(
            $filteredRows->forPage($page, 20)->values(),
            $filteredRows->count(),
            20,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('portals.assistant.advertising-spend-summary.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'rows' => $rows,
            'agencyOptions' => $agencyOptions,
            'mediaOptions' => $mediaOptions,
            'filters' => $filters,
            'totals' => [
                'book_spend' => (float) $filteredRows->sum('book_spend'),
                'handling_fee' => (float) $filteredRows->sum('handling_fee'),
                'actual_spend' => (float) $filteredRows->sum('actual_spend'),
                'report_count' => $filteredRows->map(fn (array $row): string => $row['project'].'|'.$row['report_id'])->unique()->count(),
            ],
        ]);
    }
}
