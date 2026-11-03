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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AdvertisingSpendAnalysisController extends Controller
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
        ]);
        $today = Carbon::now(OperationReportMetricsService::REPORT_TIMEZONE)->format('Y-m-d');
        $dateFrom = (string) ($validated['date_from'] ?? $today);
        $dateTo = (string) ($validated['date_to'] ?? $today);
        abort_if($dateFrom > $dateTo, 422, '开始日期不能晚于结束日期。');

        $reports = OperationReport::query()
            ->where('status', OperationReport::STATUS_APPROVED)
            ->whereIn('operation_department_id', $this->assistantStoreOwners->visibleOperationDepartmentIds($context['user']))
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->get(['id', 'report_date', 'advertising_rows']);

        $allRows = $reports->flatMap(function (OperationReport $report): array {
            return collect($report->advertising_rows ?: [])->map(function (array $advertising) use ($report): array {
                $bookSpend = (float) ($advertising['book_spend'] ?? 0);
                $coefficient = (float) ($advertising['settlement_coefficient'] ?? 0);
                $handlingFee = (float) ($advertising['handling_fee'] ?? ($bookSpend * $coefficient));

                return [
                    'date' => $report->report_date->format('Y-m-d'),
                    'agency' => trim((string) ($advertising['agency'] ?? '')) ?: '未填写代理',
                    'media' => trim((string) ($advertising['media_source'] ?? '')) ?: '其他',
                    'book_spend' => $bookSpend,
                    'actual_spend' => (float) ($advertising['actual_spend'] ?? ($bookSpend + $handlingFee)),
                ];
            })->all();
        })->values();

        $agencyOptions = $allRows->pluck('agency')->unique()->sort()->values();
        $mediaOptions = $allRows->pluck('media')->unique()->sort()->values();
        $selectedAgency = trim((string) ($validated['agency'] ?? ''));
        $selectedMedia = trim((string) ($validated['media'] ?? ''));
        $rows = $allRows->filter(function (array $row) use ($selectedAgency, $selectedMedia): bool {
            if ($selectedAgency !== '' && mb_strtolower($row['agency']) !== mb_strtolower($selectedAgency)) return false;
            if ($selectedMedia !== '' && mb_strtolower($row['media']) !== mb_strtolower($selectedMedia)) return false;
            return true;
        })->values();

        $totalSpend = (float) $rows->sum('actual_spend');
        $summarize = static function ($items, string $field) use ($totalSpend) {
            return $items->groupBy(fn (array $row): string => mb_strtolower($row[$field]))
                ->map(function ($group) use ($field, $totalSpend): array {
                    $actual = (float) $group->sum('actual_spend');
                    return [
                        'name' => $group->first()[$field],
                        'book_spend' => (float) $group->sum('book_spend'),
                        'actual_spend' => $actual,
                        'share' => $totalSpend > 0 ? $actual / $totalSpend * 100 : 0,
                    ];
                })->sortByDesc('actual_spend')->values();
        };
        $agencyRows = $summarize($rows, 'agency');
        $mediaRows = $summarize($rows, 'media');
        $allDetailRows = $rows->groupBy(fn (array $row): string => mb_strtolower($row['agency']).'|'.mb_strtolower($row['media']))
            ->map(function ($group): array {
                $first = $group->first();
                return [
                    'agency' => $first['agency'],
                    'media' => $first['media'],
                    'book_spend' => (float) $group->sum('book_spend'),
                    'actual_spend' => (float) $group->sum('actual_spend'),
                ];
            })->sortByDesc('actual_spend')->values();
        $page = max(1, (int) $request->query('page', 1));
        $detailRows = new LengthAwarePaginator(
            $allDetailRows->forPage($page, 20)->values(),
            $allDetailRows->count(),
            20,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('portals.assistant.advertising-spend-analysis.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'agencyOptions' => $agencyOptions,
            'mediaOptions' => $mediaOptions,
            'selectedAgency' => $selectedAgency,
            'selectedMedia' => $selectedMedia,
            'agencyRows' => $agencyRows,
            'mediaRows' => $mediaRows,
            'detailRows' => $detailRows,
            'totalSpend' => $totalSpend,
            'totalBookSpend' => (float) $rows->sum('book_spend'),
        ]);
    }
}
