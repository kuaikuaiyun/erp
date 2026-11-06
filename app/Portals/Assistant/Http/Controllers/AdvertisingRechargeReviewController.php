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
            'status' => ['nullable', 'in:pending,processed'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'operator' => ['nullable', 'string', 'max:100'],
        ]);

        $query = DailyAdvertisingRecharge::query();
        if (! $context['user']->isAdministrator()) {
            $departmentIds = $this->assistantStoreOwners->visibleOperationDepartmentIds($context['user']);
            $departmentIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('operation_department_id', $departmentIds);
        }
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('report_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('report_date', '<=', $date))
            ->when($filters['operator'] ?? null, fn ($q, $name) => $q->where('operator_name', 'like', '%'.$name.'%'));

        return view('portals.assistant.daily-submissions.advertising-recharge', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'filters' => $filters,
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

        $submission->update([
            'status' => DailyAdvertisingRecharge::STATUS_PROCESSED,
            'processed_by' => (int) $context['user']->getKey(),
            'processed_by_name' => (string) ($context['user']->name ?: $context['user']->username),
            'processed_at' => now(),
        ]);

        return back()->with('success', '广告充值提报已标记为已处理。');
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
