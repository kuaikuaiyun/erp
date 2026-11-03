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
            ->get(['id', 'report_date', 'operator_user_id', 'advertising_rows']);

        $rows = $reports->flatMap(function (OperationReport $report): array {
            return collect($report->advertising_rows ?: [])->map(function (array $advertising) use ($report): array {
                $bookSpend = (float) ($advertising['book_spend'] ?? 0);
                $coefficient = (float) ($advertising['settlement_coefficient'] ?? 0);
                $handlingFee = (float) ($advertising['handling_fee'] ?? ($bookSpend * $coefficient));

                return [
                    'date' => $report->report_date->format('Y-m-d'),
                    'agency' => trim((string) ($advertising['agency'] ?? '')) ?: '未填写代理',
                    'media' => trim((string) ($advertising['media_source'] ?? '')) ?: '其他',
                    'book_spend' => $bookSpend,
                    'handling_fee' => $handlingFee,
                    'actual_spend' => (float) ($advertising['actual_spend'] ?? ($bookSpend + $handlingFee)),
                    'report_id' => (int) $report->getKey(),
                    'operator_user_id' => (int) $report->operator_user_id,
                ];
            })->all();
        })->groupBy(fn (array $row): string => implode('|', [
            $row['date'], mb_strtolower($row['agency']), mb_strtolower($row['media']),
        ]))->map(function ($items): array {
            $first = $items->first();

            return [
                'date' => $first['date'],
                'agency' => $first['agency'],
                'media' => $first['media'],
                'book_spend' => (float) $items->sum('book_spend'),
                'handling_fee' => (float) $items->sum('handling_fee'),
                'actual_spend' => (float) $items->sum('actual_spend'),
                'report_count' => $items->pluck('report_id')->unique()->count(),
                'operator_count' => $items->pluck('operator_user_id')->unique()->count(),
            ];
        })->sortBy(fn (array $row): string => $row['date'].'|'.$row['agency'].'|'.$row['media'])->values();

        $chartDays = $rows->groupBy('date')->map(function ($items, string $date): array {
            return [
                'date' => $date,
                'total' => (float) $items->sum('actual_spend'),
                'items' => $items->sortByDesc('actual_spend')->values(),
            ];
        })->sortKeys()->values();
        $chartMax = max(1, (float) $rows->max('actual_spend'));

        return view('portals.assistant.advertising-spend-summary.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'rows' => $rows,
            'chartDays' => $chartDays,
            'chartMax' => $chartMax,
            'totals' => [
                'book_spend' => (float) $rows->sum('book_spend'),
                'handling_fee' => (float) $rows->sum('handling_fee'),
                'actual_spend' => (float) $rows->sum('actual_spend'),
                'report_count' => $reports->count(),
                'operator_count' => $reports->pluck('operator_user_id')->unique()->count(),
            ],
        ]);
    }
}
