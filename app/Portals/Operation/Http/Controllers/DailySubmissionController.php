<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantAdvertisingAgency;
use App\Models\DailyAdvertisingRecharge;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\ProductRequests\ProductRequestAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class DailySubmissionController extends Controller
{
    private const TYPES = [
        'advertising-recharge' => ['title' => '广告充值', 'icon' => 'fa-credit-card'],
        'account-opening' => ['title' => '开户需求', 'icon' => 'fa-user-plus'],
        'store-launch' => ['title' => '下店需求', 'icon' => 'fa-shopping-bag'],
        'advertising-account-authorization' => ['title' => '广告账号授权', 'icon' => 'fa-key'],
        'account-store-transfer' => ['title' => '账号/店铺弃用转让', 'icon' => 'fa-exchange'],
    ];

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly ProductRequestAccessService $access,
        private readonly AssistantStoreOwnerService $assistantStoreOwners,
    ) {}

    public function index(Request $request, string $type): Response|View
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $this->access->assertPortal($context['portal'], ProductRequestAccessService::OPERATION_PORTAL_CODE);

        if ($type === 'advertising-recharge') {
            return $this->advertisingRecharge($context);
        }

        return view('portals.operation.daily-submissions.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'type' => $type,
            'page' => self::TYPES[$type],
        ]);
    }

    public function storeAdvertisingRecharge(Request $request): RedirectResponse
    {
        $context = $this->operationContext($request);
        $user = $context['user'];
        $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($user);
        abort_unless($operationDepartment !== null || $user->isAdministrator(), 422, '当前账号未配置运营部门，无法提交广告充值。');

        $assistantDepartmentIds = $operationDepartment
            ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $operationDepartment->getKey())
            : null;
        $agencies = AssistantAdvertisingAgency::query()
            ->where('is_enabled', true)
            ->when($assistantDepartmentIds !== null, fn ($query) => $query->whereIn('assistant_department_id', $assistantDepartmentIds))
            ->with('advertisingMedia')
            ->get()
            ->keyBy(fn (AssistantAdvertisingAgency $agency): string => (string) $agency->getKey());

        $validated = $request->validate([
            'report_date' => ['required', 'date_format:Y-m-d'],
            'entries' => ['required', 'array', 'min:1', 'max:50'],
            'entries.*.agency_id' => ['required', 'integer', Rule::in($agencies->keys()->all())],
            'entries.*.account_name' => ['required', 'string', 'max:191'],
            'entries.*.account_number' => ['required', 'string', 'max:191'],
            'entries.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
        ], [
            'report_date.required' => '请选择提报日期。',
            'entries.required' => '请至少填写一条广告充值信息。',
            'entries.*.agency_id.required' => '请选择广告代理。',
            'entries.*.account_name.required' => '请填写广告账户名称。',
            'entries.*.account_number.required' => '请填写广告账户编号。',
            'entries.*.amount.required' => '请填写充值金额。',
        ]);

        $entries = collect($validated['entries'])->map(function (array $entry) use ($agencies): array {
            /** @var AssistantAdvertisingAgency|null $agency */
            $agency = $agencies->get((string) $entry['agency_id']);
            if (! $agency) {
                throw ValidationException::withMessages(['entries' => '广告代理不属于当前运营部门可用范围。']);
            }

            return [
                'agency_id' => (int) $agency->getKey(),
                'agency' => (string) $agency->name,
                'media' => (string) ($agency->advertisingMedia?->name ?? ''),
                'account_name' => trim((string) $entry['account_name']),
                'account_number' => trim((string) $entry['account_number']),
                'amount' => round((float) $entry['amount'], 2),
            ];
        })->values()->all();

        DailyAdvertisingRecharge::query()->create([
            'report_date' => $validated['report_date'],
            'operator_user_id' => (int) $user->getKey(),
            'operator_name' => (string) ($user->name ?: $user->username),
            'operation_department_id' => $operationDepartment?->getKey(),
            'operation_department_name' => $operationDepartment?->name,
            'entries' => $entries,
            'total_amount' => collect($entries)->sum('amount'),
            'status' => DailyAdvertisingRecharge::STATUS_PENDING,
        ]);

        return redirect()->route('operation.daily-submissions.index', ['type' => 'advertising-recharge'])
            ->with('success', '广告充值提报已提交。');
    }

    /** @param array{portal:mixed,user:\App\Models\AdminUser} $context */
    private function advertisingRecharge(array $context): View
    {
        $user = $context['user'];
        $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($user);
        $assistantDepartmentIds = $operationDepartment
            ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $operationDepartment->getKey())
            : ($user->isAdministrator() ? null : []);
        $agencies = AssistantAdvertisingAgency::query()
            ->where('is_enabled', true)
            ->when($assistantDepartmentIds !== null, fn ($query) => $query->whereIn('assistant_department_id', $assistantDepartmentIds))
            ->with('advertisingMedia')
            ->orderBy('sort')->orderBy('id')->get();

        return view('portals.operation.daily-submissions.advertising-recharge', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $user,
            'agencies' => $agencies,
            'history' => DailyAdvertisingRecharge::query()
                ->where('operator_user_id', $user->getKey())
                ->latest('report_date')->latest('id')->paginate(15)->withQueryString(),
        ]);
    }

    /** @return array{portal:mixed,user:\App\Models\AdminUser} */
    private function operationContext(Request $request): array
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            abort($context->getStatusCode());
        }
        $this->access->assertPortal($context['portal'], ProductRequestAccessService::OPERATION_PORTAL_CODE);

        return $context;
    }
}
