<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DailyAdvertisingRecharge;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AdvertisingRechargeReviewController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $assistantStoreOwners,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $filters = $request->validate([
            'operation_department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:pending,processed'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'operator' => ['nullable', 'string', 'max:100'],
        ]);

        $query = DailyAdvertisingRecharge::query();
        $allDepartments = $this->assistantStoreOwners->boundBusinessDepartments();
        $departmentIds = null;
        if (! $context['user']->isAdministrator()) {
            $departmentIds = $this->assistantStoreOwners->visibleOperationDepartmentIds($context['user']);
            $departmentIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('operation_department_id', $departmentIds);
        }
        $departmentOptions = $departmentIds === null
            ? $allDepartments
            : $allDepartments->whereIn('id', $departmentIds)->values();

        $today = now('Asia/Shanghai')->toDateString();
        $todaySubmissions = (clone $query)->whereDate('report_date', $today)->orderBy('id')->get();
        $todayRechargeGroups = [];
        foreach ($todaySubmissions as $submission) {
            foreach ($submission->entries ?? [] as $entry) {
                $agencyName = trim((string) ($entry['agency'] ?? '')) ?: '未设置代理';
                $mediaName = trim((string) ($entry['media'] ?? '')) ?: '未设置平台';
                $key = mb_strtolower($mediaName).'|'.mb_strtolower($agencyName);
                if (! isset($todayRechargeGroups[$key])) {
                    $todayRechargeGroups[$key] = [
                        'agency_id' => (int) ($entry['agency_id'] ?? 0),
                        'agency' => $agencyName,
                        'media' => $mediaName,
                        'details' => [],
                    ];
                }
                $todayRechargeGroups[$key]['details'][] = [
                    'operator_user_id' => (int) $submission->operator_user_id,
                    'operator_name' => (string) $submission->operator_name,
                    'operation_department_name' => (string) ($submission->operation_department_name ?? ''),
                    'account_name' => (string) ($entry['account_name'] ?? ''),
                    'account_number' => (string) ($entry['account_number'] ?? ''),
                    'amount' => round((float) ($entry['amount'] ?? 0), 2),
                    'submitted_at' => $submission->created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                ];
            }
        }
        $todayTotalAmount = collect($todayRechargeGroups)->sum(fn ($group) => collect($group['details'])->sum('amount'));
        $todayRechargeGroups = collect($todayRechargeGroups)->map(function (array $group) use ($todayTotalAmount): array {
            $details = collect($group['details']);
            $amount = round((float) $details->sum('amount'), 2);
            return [...$group,
                'entry_count' => $details->count(),
                'people_count' => $details->pluck('operator_user_id')->unique()->count(),
                'amount' => $amount,
                'percentage' => $todayTotalAmount > 0 ? round($amount / $todayTotalAmount * 100, 1) : 0,
            ];
        })->values();

        $query->when($filters['operation_department_id'] ?? null, fn ($q, $id) => $q->where('operation_department_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('report_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('report_date', '<=', $date))
            ->when($filters['operator'] ?? null, fn ($q, $name) => $q->where('operator_name', 'like', '%'.$name.'%'));

        return view('portals.assistant.daily-submissions.advertising-recharge', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'filters' => $filters,
            'departmentOptions' => $departmentOptions,
            'today' => $today,
            'todayRechargeGroups' => $todayRechargeGroups,
            'todayRechargeSummary' => [
                'group_count' => $todayRechargeGroups->count(),
                'entry_count' => $todayRechargeGroups->sum('entry_count'),
                'people_count' => $todaySubmissions->pluck('operator_user_id')->unique()->count(),
                'amount' => $todayTotalAmount,
            ],
            'submissions' => $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->latest('report_date')->latest('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function process(Request $request, DailyAdvertisingRecharge $submission): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        if (! $context['user']->isAdministrator()) {
            abort_unless(in_array((int) $submission->operation_department_id, $this->assistantStoreOwners->visibleOperationDepartmentIds($context['user']), true), 403);
        }

        $validated = $request->validate([
            'entry_index' => ['required', 'integer', 'min:0'],
        ], ['entry_index.required' => '请选择要处理的广告充值明细。']);
        $entryIndex = (int) $validated['entry_index'];
        $processorId = (int) $context['user']->getKey();
        $processorName = (string) ($context['user']->name ?: $context['user']->username);

        $submission->getConnection()->transaction(function () use ($submission, $entryIndex, $processorId, $processorName): void {
            /** @var DailyAdvertisingRecharge $locked */
            $locked = DailyAdvertisingRecharge::query()->lockForUpdate()->findOrFail($submission->getKey());
            $entries = array_values($locked->entries ?? []);
            abort_unless(isset($entries[$entryIndex]), 422, '该广告充值明细不存在，请刷新页面后重试。');
            $currentStatus = (string) ($entries[$entryIndex]['status'] ?? ($locked->status === DailyAdvertisingRecharge::STATUS_PROCESSED ? DailyAdvertisingRecharge::STATUS_PROCESSED : DailyAdvertisingRecharge::STATUS_PENDING));
            abort_if($currentStatus === DailyAdvertisingRecharge::STATUS_PROCESSED, 422, '该广告充值明细已经处理，请勿重复操作。');

            $processedAt = now();
            $entries[$entryIndex]['status'] = DailyAdvertisingRecharge::STATUS_PROCESSED;
            $entries[$entryIndex]['processed_by'] = $processorId;
            $entries[$entryIndex]['processed_by_name'] = $processorName;
            $entries[$entryIndex]['processed_at'] = $processedAt->toDateTimeString();
            $allProcessed = collect($entries)->every(fn (array $entry) => ($entry['status'] ?? null) === DailyAdvertisingRecharge::STATUS_PROCESSED);

            $locked->update([
                'entries' => $entries,
                'status' => $allProcessed ? DailyAdvertisingRecharge::STATUS_PROCESSED : DailyAdvertisingRecharge::STATUS_PENDING,
                'processed_by' => $allProcessed ? $processorId : null,
                'processed_by_name' => $allProcessed ? $processorName : null,
                'processed_at' => $allProcessed ? $processedAt : null,
            ]);
        });

        return back()->with('success', '该条广告充值明细已标记为已处理。');
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        return $context;
    }
}
