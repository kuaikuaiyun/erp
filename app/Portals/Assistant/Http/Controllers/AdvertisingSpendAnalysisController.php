<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantAdvertisingAgency;
use App\Models\OperationReport;
use App\Models\FashionAdvertisingReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Fashion\OperationProjectCatalog;
use App\Portals\Operation\OperationReportMetricsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
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
            'project' => ['nullable', Rule::in(['shopify', ...array_keys(OperationProjectCatalog::all())])],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ]);
        $now = Carbon::now(OperationReportMetricsService::REPORT_TIMEZONE);
        $dateFrom = (string) ($validated['date_from'] ?? $now->copy()->subDays(6)->format('Y-m-d'));
        $dateTo = (string) ($validated['date_to'] ?? $now->format('Y-m-d'));
        abort_if($dateFrom > $dateTo, 422, '开始日期不能晚于结束日期。');

        $selectedProject = (string) ($validated['project'] ?? '');
        $visibleDepartmentIds = $this->assistantStoreOwners->visibleOperationDepartmentIds($context['user']);
        $reports = OperationReport::query()
            ->where('status', OperationReport::STATUS_APPROVED)
            ->whereIn('operation_department_id', $visibleDepartmentIds)
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->get(['id', 'report_date', 'advertising_rows']);
        $assistantDepartmentIds = collect($visibleDepartmentIds)
            ->flatMap(fn ($departmentId) => $this->assistantStoreOwners
                ->assistantDepartmentIdsForOperationDepartment((int) $departmentId))
            ->map(fn ($id) => (int) $id)->unique()->values();
        $agencies = AssistantAdvertisingAgency::query()->with('advertisingMedia')
            ->whereIn('assistant_department_id', $assistantDepartmentIds)->get()
            ->keyBy(fn (AssistantAdvertisingAgency $agency) => (int) $agency->getKey());

        $shopifyRows = $reports->flatMap(function (OperationReport $report) use ($agencies): array {
            return collect($report->advertising_rows ?: [])->map(function (array $advertising) use ($report, $agencies): array {
                $bookSpend = (float) ($advertising['book_spend'] ?? 0);
                $coefficient = (float) ($advertising['settlement_coefficient'] ?? 0);
                $handlingFee = (float) ($advertising['handling_fee'] ?? ($bookSpend * $coefficient));
                $storedAgencyName = (string) ($advertising['agency'] ?? '');
                $storedMediaName = (string) ($advertising['media_source'] ?? '');
                $agencyId = (int) ($advertising['agency_id'] ?? 0);
                $agency = $agencies->get($agencyId) ?: $this->matchConfiguredAgency($agencies, $storedAgencyName, $storedMediaName);
                $agencyId = (int) ($agency?->getKey() ?? 0);
                $mediaId = (int) ($advertising['media_id'] ?? $agency?->advertising_media_id ?? 0);
                $agencyName = $this->normalizeDimension((string) ($agency?->name ?? $storedAgencyName), '未填写代理');
                $mediaName = $this->normalizeDimension((string) ($agency?->advertisingMedia?->name ?? $storedMediaName), '其他');

                return [
                    'project' => 'shopify',
                    'project_label' => 'Shopify订阅项目',
                    'date' => $report->report_date->format('Y-m-d'),
                    'agency_key' => $this->dimensionKey($agencyId, $agencyName),
                    'media_key' => $this->dimensionKey($mediaId, $mediaName),
                    'agency' => $agencyName,
                    'media' => $mediaName,
                    'book_spend' => $bookSpend,
                    'actual_spend' => (float) ($advertising['actual_spend'] ?? ($bookSpend + $handlingFee)),
                ];
            })->all();
        })->values();
        $fashionReports = FashionAdvertisingReport::query()
            ->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->whereIn('project_code', array_keys(OperationProjectCatalog::all()))
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->whereHas('submitter.departments', fn ($query) => $query->whereIn('admin_roles.id', $visibleDepartmentIds))
            ->with('adSpendEntries')->get();
        $fashionRows = $fashionReports->flatMap(function (FashionAdvertisingReport $report) use ($agencies): array {
            return $report->adSpendEntries->map(function ($entry) use ($report, $agencies): array {
                $agency = $this->matchConfiguredAgency($agencies, (string) $entry->agency, (string) $entry->media);
                $agencyName = $this->normalizeDimension((string) ($agency?->name ?? $entry->agency), '未填写代理');
                $mediaName = $this->normalizeDimension((string) ($agency?->advertisingMedia?->name ?? $entry->media), '其他');
                $projectCode = (string) $report->project_code;

                return [
                    'project' => $projectCode,
                    'project_label' => OperationProjectCatalog::shortName($projectCode).'项目',
                    'date' => $report->report_date->format('Y-m-d'),
                    'agency_key' => $this->dimensionKey($agency?->getKey(), $agencyName),
                    'media_key' => $this->dimensionKey($agency?->advertising_media_id, $mediaName),
                    'agency' => $agencyName,
                    'media' => $mediaName,
                    'book_spend' => (float) $entry->book_spend_usd,
                    'actual_spend' => (float) $entry->actual_spend_usd,
                ];
            })->all();
        })->values();
        $projectOptions = collect()
            ->when($reports->isNotEmpty(), fn ($options) => $options->put('shopify', 'Shopify订阅项目'))
            ->merge($fashionReports->pluck('project_code')->unique()->mapWithKeys(
                fn (string $code): array => [$code => OperationProjectCatalog::shortName($code).'项目']
            ))
            ->sortBy(fn (string $label, string $code): int => (int) array_search($code, ['shopify', 'fuzhuang', 'danbao-general', 'baokuan-fashion', 'baokuan-general'], true))
            ->all();
        $submittedRows = collect()->concat($shopifyRows)->concat($fashionRows)->values();
        $allRows = $submittedRows
            ->when($selectedProject !== '', fn ($rows) => $rows->where('project', $selectedProject))
            ->map(fn (array $row): array => [
                ...$row,
                'agency_group_key' => $this->displayGroupKey((string) $row['agency']),
                'media_group_key' => $this->displayGroupKey((string) $row['media']),
            ])
            ->values();

        $agencyOptions = $allRows->sortBy('agency')->mapWithKeys(fn (array $row): array => [$row['agency_group_key'] => $row['agency']]);
        $mediaOptions = $allRows->sortBy('media')->mapWithKeys(fn (array $row): array => [$row['media_group_key'] => $row['media']]);
        $selectedAgency = $this->resolveSelectedKey((string) ($validated['agency'] ?? ''), $agencyOptions);
        $selectedMedia = $this->resolveSelectedKey((string) ($validated['media'] ?? ''), $mediaOptions);
        $rows = $allRows->filter(function (array $row) use ($selectedAgency, $selectedMedia): bool {
            if ($selectedAgency !== '' && $row['agency_group_key'] !== $selectedAgency) return false;
            if ($selectedMedia !== '' && $row['media_group_key'] !== $selectedMedia) return false;
            return true;
        })->values();

        $totalSpend = (float) $rows->sum('actual_spend');
        $summarize = static function ($items, string $field) use ($totalSpend) {
            return $items->groupBy($field.'_group_key')
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
        $allDetailRows = $rows->groupBy(fn (array $row): string => $row['project'].'|'.$row['date'].'|'.$row['agency_group_key'].'|'.$row['media_group_key'])
            ->map(function ($group): array {
                $first = $group->first();
                return [
                    'project' => $first['project'],
                    'project_label' => $first['project_label'],
                    'date' => $first['date'],
                    'agency' => $first['agency'],
                    'media' => $first['media'],
                    'book_spend' => (float) $group->sum('book_spend'),
                    'actual_spend' => (float) $group->sum('actual_spend'),
                ];
            })->sortBy([['date', 'asc'], ['actual_spend', 'desc']])->values();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($validated['per_page'] ?? 20);
        $detailRows = new LengthAwarePaginator(
            $allDetailRows->forPage($page, $perPage)->values(),
            $allDetailRows->count(),
            $perPage,
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
            'selectedProject' => $selectedProject,
            'projectOptions' => $projectOptions,
            'agencyRows' => $agencyRows,
            'mediaRows' => $mediaRows,
            'detailRows' => $detailRows,
            'totalSpend' => $totalSpend,
            'totalBookSpend' => (float) $rows->sum('book_spend'),
        ]);
    }

    private function normalizeDimension(string $value, string $fallback): string
    {
        $value = preg_replace('/[\p{Z}\s\p{Cf}\p{Cc}]+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return $value !== '' ? $value : $fallback;
    }

    private function dimensionKey(?int $id, string $name): string
    {
        return $id ? 'id:'.$id : 'name:'.mb_strtolower($this->normalizeDimension($name, ''));
    }

    private function displayGroupKey(string $name): string
    {
        return 'label:'.mb_strtolower($this->normalizeDimension($name, ''));
    }

    private function matchConfiguredAgency($agencies, string $agencyName, string $mediaName): ?AssistantAdvertisingAgency
    {
        $agencyKey = mb_strtolower($this->normalizeDimension($agencyName, ''));
        $mediaKey = mb_strtolower($this->normalizeDimension($mediaName, ''));
        $matches = $agencies->filter(fn (AssistantAdvertisingAgency $agency): bool =>
            mb_strtolower($this->normalizeDimension((string) $agency->name, '')) === $agencyKey
            && mb_strtolower($this->normalizeDimension((string) $agency->advertisingMedia?->name, '')) === $mediaKey
        );
        if ($matches->count() === 1) return $matches->first();

        // Historical JSON only stored names. If its media name was entered incorrectly
        // but the agency has one unambiguous current configuration, use that agency's
        // configured media ID/name for reporting without rewriting the historical row.
        $agencyMatches = $agencies->filter(fn (AssistantAdvertisingAgency $agency): bool =>
            mb_strtolower($this->normalizeDimension((string) $agency->name, '')) === $agencyKey
        );

        return $agencyMatches->count() === 1 ? $agencyMatches->first() : null;
    }

    private function resolveSelectedKey(string $selected, $options): string
    {
        $selected = trim($selected);
        if ($selected === '' || $options->has($selected)) return $selected;

        $normalized = mb_strtolower($this->normalizeDimension($selected, ''));
        $matched = $options->search(fn (string $name): bool => mb_strtolower($name) === $normalized);

        return $matched === false ? '' : (string) $matched;
    }
}
