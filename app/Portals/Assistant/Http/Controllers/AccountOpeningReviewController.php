<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DailyAccountOpening;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Assistant\DailyReportDepartmentScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AccountOpeningReviewController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
        private readonly DailyReportDepartmentScopeService $departmentScopes,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $filters = $request->validate([
            'operation_department_ids' => ['nullable', 'array'],
            'operation_department_ids.*' => ['integer', 'distinct'],
            'status' => ['nullable', 'in:pending,processing,processed,rejected'],
            'agency' => ['nullable', 'string', 'max:100'],
            'media' => ['nullable', 'string', 'max:100'],
            'operator' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ]);

        $query = DailyAccountOpening::query();
        $allDepartments = $this->storeOwners->boundBusinessDepartments();
        $visibleIds = null;
        $visibleIds = $this->departmentScopes->visibleIdsForReview($context['user']);
        if ($visibleIds !== null) {
            $visibleIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('operation_department_id', $visibleIds);
        }
        $departments = $visibleIds === null ? $allDepartments : $allDepartments->whereIn('id', $visibleIds)->values();
        $optionQuery = DailyAccountOpening::query()
            ->when($visibleIds !== null, fn ($q) => $visibleIds === [] ? $q->whereRaw('1 = 0') : $q->whereIn('operation_department_id', $visibleIds));
        $query->when($filters['operation_department_ids'] ?? [], fn ($q, $ids) => $q->whereIn('operation_department_id', $ids))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['agency'] ?? null, fn ($q, $agency) => $q->where('agency_name', $agency))
            ->when($filters['media'] ?? null, fn ($q, $media) => $q->where('media', $media))
            ->when($filters['operator'] ?? null, fn ($q, $name) => $q->where('operator_name', 'like', '%'.$name.'%'));

        return view('portals.assistant.daily-submissions.account-opening', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'filters' => $filters,
            'departments' => $departments,
            'agencyOptions' => (clone $optionQuery)->whereNotNull('agency_name')->where('agency_name', '!=', '')->distinct()->orderBy('agency_name')->pluck('agency_name'),
            'mediaOptions' => $optionQuery->distinct()->orderBy('media')->pluck('media'),
            'submissions' => $query->orderByRaw("CASE WHEN status IN ('pending', 'processing') THEN 0 ELSE 1 END")->latest('id')->paginate((int) ($filters['per_page'] ?? 20))->withQueryString(),
        ]);
    }

    public function markProcessing(Request $request, DailyAccountOpening $submission): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $visibleIds = $this->departmentScopes->visibleIdsForReview($context['user']);
        if ($visibleIds !== null) abort_unless(in_array((int) $submission->operation_department_id, $visibleIds, true), 403);
        abort_unless($submission->status === DailyAccountOpening::STATUS_PENDING, 422, '该开户需求已开始处理或已完成，请刷新页面查看最新状态。');

        $submission->update(['status' => DailyAccountOpening::STATUS_PROCESSING]);

        return back()->with('success', '开户需求已标记为处理中。');
    }

    public function process(Request $request, DailyAccountOpening $submission): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $visibleIds = $this->departmentScopes->visibleIdsForReview($context['user']);
        if ($visibleIds !== null) abort_unless(in_array((int) $submission->operation_department_id, $visibleIds, true), 403);
        abort_unless(in_array($submission->status, [DailyAccountOpening::STATUS_PENDING, DailyAccountOpening::STATUS_PROCESSING], true), 422, '该开户需求已经处理，请刷新页面查看最新状态。');
        $validated = $request->validate([
            'decision' => ['nullable', 'in:processed,rejected'],
            'response_text' => ['required', 'string', 'max:5000'],
        ], [
            'response_text.required' => '请填写处理结果或驳回原因。',
        ]);
        $status = ($validated['decision'] ?? DailyAccountOpening::STATUS_PROCESSED) === DailyAccountOpening::STATUS_REJECTED
            ? DailyAccountOpening::STATUS_REJECTED
            : DailyAccountOpening::STATUS_PROCESSED;
        $submission->update([
            'status' => $status,
            'response_text' => trim($validated['response_text']),
            'processed_by' => (int) $context['user']->getKey(),
            'processed_by_name' => (string) ($context['user']->name ?: $context['user']->username),
            'processed_at' => now(),
        ]);

        return back()->with('success', $status === DailyAccountOpening::STATUS_REJECTED ? '开户需求已驳回。' : '开户需求已标记为已处理。');
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
