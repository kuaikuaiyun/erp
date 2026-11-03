<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Operation\OperationReportMetricsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
        ]);
        $now = Carbon::now(OperationReportMetricsService::REPORT_TIMEZONE);
        $dateFrom = (string) ($validated['date_from'] ?? $now->format('Y-m-d'));
        $dateTo = (string) ($validated['date_to'] ?? $now->format('Y-m-d'));
        abort_if($dateFrom > $dateTo, 422, '开始日期不能晚于结束日期。');

        $visibleDepartmentIds = $this->assistantStoreOwners->visibleOperationDepartmentIds($context['user']);
        $reports = OperationReport::query()
            ->where('status', OperationReport::STATUS_APPROVED)
            ->whereIn('operation_department_id', $visibleDepartmentIds)
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->orderBy('report_date')
            ->orderBy('id')
            ->get(['id', 'report_date', 'operator_user_id', 'operator_name', 'advertising_rows']);

        $allRows = $reports->flatMap(function (OperationReport $report): array {
            return collect($report->advertising_rows ?: [])->map(function (array $advertising) use ($report): array {
                $bookSpend = (float) ($advertising['book_spend'] ?? 0);
                $coefficient = (float) ($advertising['settlement_coefficient'] ?? 0);
                $handlingFee = (float) ($advertising['handling_fee'] ?? ($bookSpend * $coefficient));

                return [
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
                ];
            })->all();
        })->sortBy(fn (array $row): string => implode('|', [
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
        ];
        $rows = $allRows->filter(function (array $row) use ($filters): bool {
            if ($filters['agency'] !== '' && mb_strtolower($row['agency']) !== mb_strtolower($filters['agency'])) return false;
            if ($filters['media'] !== '' && mb_strtolower($row['media']) !== mb_strtolower($filters['media'])) return false;
            if ($filters['operator'] !== '' && mb_stripos($row['operator_name'], $filters['operator']) === false) return false;
            if ($filters['account_name'] !== '' && mb_stripos($row['account_name'], $filters['account_name']) === false) return false;
            if ($filters['account_number'] !== '' && mb_stripos($row['account_number'], $filters['account_number']) === false) return false;

            return true;
        })->values();

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
                'book_spend' => (float) $rows->sum('book_spend'),
                'handling_fee' => (float) $rows->sum('handling_fee'),
                'actual_spend' => (float) $rows->sum('actual_spend'),
                'report_count' => $rows->pluck('report_id')->unique()->count(),
            ],
        ]);
    }
}
