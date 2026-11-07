<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantAdvertisingAgency;
use App\Models\AssistantAdvertisingMedia;
use App\Models\AssistantDomainStore;
use App\Models\AdminUser;
use App\Models\DailyAdvertisingRecharge;
use App\Models\DailyAccountOpening;
use App\Models\DailyStoreLaunch;
use App\Models\OperationReport;
use App\Models\AssistantProject;
use App\Models\AssistantWebsiteSystem;
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
    private const DAILY_REPORT_PORTAL_CODE = 'daily-report';

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

        $this->assertDailyReportPortal($context['portal']);

        if ($type === 'advertising-recharge') {
            return $this->advertisingRecharge($request, $context);
        }
        if ($type === 'account-opening') {
            return $this->accountOpening($request, $context);
        }
        if ($type === 'store-launch') {
            return $this->storeLaunch($request, $context);
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
        $isBoss = $this->isBoss($user);
        $operatorOptions = $isBoss ? $this->operatorOptions() : collect([$user]);
        $operatorId = $isBoss ? $request->integer('operator_user_id') : (int) $user->getKey();
        if ($isBoss) {
            abort_unless($operatorId > 0 && $operatorOptions->contains('id', $operatorId), 422, '请选择有效的运营账号。');
        }
        /** @var AdminUser $operator */
        $operator = $operatorOptions->firstWhere('id', $operatorId) ?: $user;
        $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
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
            'entries' => ['required', 'array', 'min:1', 'max:50'],
            'entries.*.agency_id' => ['required', 'integer', Rule::in($agencies->keys()->all())],
            'entries.*.account_name' => ['required', 'string', 'max:191'],
            'entries.*.account_number' => ['required', 'string', 'max:191'],
            'entries.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
        ], [
            'entries.required' => '请至少填写一条广告充值信息。',
            'entries.*.agency_id.required' => '请选择广告代理。',
            'entries.*.account_name.required' => '请填写广告账户名称。',
            'entries.*.account_number.required' => '请填写广告账户编号。',
            'entries.*.amount.required' => '请填写充值金额。',
        ]);

        $accountMatches = $this->advertisingRechargeAccountMatches($agencies->keys()->map(fn ($id) => (int) $id)->all());
        foreach ($validated['entries'] as $index => $entry) {
            $agencyId = (string) (int) $entry['agency_id'];
            $name = mb_strtolower(trim((string) $entry['account_name']));
            $number = mb_strtolower(trim((string) $entry['account_number']));
            $match = $accountMatches[$agencyId] ?? ['by_number' => []];
            $expectedNames = $match['by_number'][$number] ?? [];
            if ($expectedNames !== [] && ! collect($expectedNames)->contains(fn ($value) => mb_strtolower($value) === $name)) {
                throw ValidationException::withMessages(['entries.'.$index.'.account_name' => '广告账户编号“'.$entry['account_number'].'”对应的历史账户名称是“'.implode('、', $expectedNames).'”，当前填写的“'.$entry['account_name'].'”不一致。']);
            }
        }

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
            'report_date' => now()->toDateString(),
            'operator_user_id' => (int) $operator->getKey(),
            'operator_name' => (string) ($operator->name ?: $operator->username),
            'operation_department_id' => $operationDepartment?->getKey(),
            'operation_department_name' => $operationDepartment?->name,
            'entries' => $entries,
            'total_amount' => collect($entries)->sum('amount'),
            'status' => DailyAdvertisingRecharge::STATUS_PENDING,
        ]);

        return redirect()->route('operation.daily-submissions.index', [
            'type' => 'advertising-recharge',
            'operator_user_id' => $isBoss ? $operator->getKey() : null,
        ])
            ->with('success', '广告充值提报已提交。');
    }

    public function storeAccountOpening(Request $request): RedirectResponse
    {
        $context = $this->operationContext($request);
        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $operatorOptions = $isBoss ? $this->operatorOptions() : collect([$user]);
        $operatorId = $isBoss ? $request->integer('operator_user_id') : (int) $user->getKey();
        if ($isBoss) abort_unless($operatorId > 0 && $operatorOptions->contains('id', $operatorId), 422, '请选择有效的运营账号。');
        /** @var AdminUser $operator */
        $operator = $operatorOptions->firstWhere('id', $operatorId) ?: $user;
        $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
        abort_unless($operationDepartment !== null || $user->isAdministrator(), 422, '该运营账号未配置运营部门，无法提交开户需求。');
        $assistantDepartmentIds = $operationDepartment
            ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $operationDepartment->getKey())
            : null;
        $mediaOptions = $this->accountOpeningMediaOptions($assistantDepartmentIds);
        $mediaType = $this->accountOpeningMediaType((string) $request->input('media'));

        $validated = $request->validate([
            'media' => ['required', 'string', Rule::in($mediaOptions)],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'email' => [Rule::requiredIf(in_array($mediaType, ['facebook', 'email'], true)), 'nullable', 'string', 'max:191'],
            'profile_link' => [Rule::requiredIf($mediaType === 'facebook'), 'nullable', 'string', 'max:2000'],
            'bcid' => [Rule::requiredIf($mediaType === 'tiktok'), 'nullable', 'string', 'max:191'],
        ], [
            'media.required' => '请选择社媒。',
            'quantity.required' => '请填写开户数量。',
            'email.required' => '该社媒必须填写邮箱。',
            'profile_link.required' => 'Facebook 必须填写个号链接。',
            'bcid.required' => 'TikTok 必须填写 BCID。',
        ]);

        DailyAccountOpening::query()->create([
            'operator_user_id' => (int) $operator->getKey(),
            'operator_name' => (string) ($operator->name ?: $operator->username),
            'operation_department_id' => $operationDepartment?->getKey(),
            'operation_department_name' => $operationDepartment?->name,
            'media' => $validated['media'],
            'quantity' => (int) $validated['quantity'],
            'email' => isset($validated['email']) ? trim((string) $validated['email']) : null,
            'profile_link' => isset($validated['profile_link']) ? trim((string) $validated['profile_link']) : null,
            'bcid' => isset($validated['bcid']) ? trim((string) $validated['bcid']) : null,
            'status' => DailyAccountOpening::STATUS_PENDING,
        ]);

        return redirect()->route('operation.daily-submissions.index', [
            'type' => 'account-opening',
            'operator_user_id' => $isBoss ? $operator->getKey() : null,
        ])->with('success', '开户需求已提交。');
    }

    public function storeStoreLaunch(Request $request): RedirectResponse
    {
        $context = $this->operationContext($request);
        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $operatorOptions = $isBoss ? $this->operatorOptions() : collect([$user]);
        $operatorId = $isBoss ? $request->integer('operator_user_id') : (int) $user->getKey();
        if ($isBoss) abort_unless($operatorId > 0 && $operatorOptions->contains('id', $operatorId), 422, '请选择有效的运营账号。');
        $operator = $operatorOptions->firstWhere('id', $operatorId) ?: $user;
        $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
        abort_unless($operationDepartment !== null || $user->isAdministrator(), 422, '该运营账号未配置运营部门，无法提交下店需求。');
        $assistantDepartmentIds = $operationDepartment
            ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $operationDepartment->getKey())
            : null;
        $projects = AssistantProject::query()->where('is_enabled', true)
            ->when($assistantDepartmentIds !== null, fn ($q) => $q->whereIn('assistant_department_id', $assistantDepartmentIds))
            ->with(['websiteSystems' => fn ($q) => $q->where('is_enabled', true)])
            ->get()->keyBy('id');
        $systems = AssistantWebsiteSystem::query()->where('is_enabled', true)
            ->when($assistantDepartmentIds !== null, fn ($q) => $q->whereIn('assistant_department_id', $assistantDepartmentIds))
            ->get()->keyBy('id');
        $system = $systems->get($request->integer('website_system_id'));
        $requiresEmail = $system && str_contains(mb_strtolower(trim($system->name)), 'shopline');
        $validated = $request->validate([
            'project_id' => ['required', 'integer', Rule::in($projects->keys()->all())],
            'website_system_id' => ['required', 'integer', Rule::in($systems->keys()->all())],
            'email' => [Rule::requiredIf($requiresEmail), 'nullable', 'string', 'max:191'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ], ['email.required' => '选择 Shopline 建站系统时必须填写邮箱。']);
        $project = $projects->get((int) $validated['project_id']);
        $system = $systems->get((int) $validated['website_system_id']);
        abort_unless($project && $system && (int) $system->project_id === (int) $project->id, 422, '建站系统与所选项目不匹配。');
        DailyStoreLaunch::query()->create([
            'operator_user_id' => $operator->id, 'operator_name' => $operator->name ?: $operator->username,
            'operation_department_id' => $operationDepartment?->id, 'operation_department_name' => $operationDepartment?->name,
            'assistant_department_id' => $project->assistant_department_id,
            'project_id' => $project->id, 'project_name' => $project->name,
            'website_system_id' => $system->id, 'website_system_name' => $system->name,
            'email' => $requiresEmail ? trim((string) $validated['email']) : null,
            'remark' => trim((string) ($validated['remark'] ?? '')), 'status' => DailyStoreLaunch::STATUS_PENDING,
        ]);
        return redirect()->route('operation.daily-submissions.index', ['type' => 'store-launch', 'operator_user_id' => $isBoss ? $operator->id : null])->with('success', '下店需求已提交。');
    }

    /** @param array{portal:mixed,user:\App\Models\AdminUser} $context */
    private function advertisingRecharge(Request $request, array $context): View
    {
        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $operatorOptions = $isBoss ? $this->operatorOptions() : collect([$user]);
        $operatorId = $isBoss
            ? (int) ($request->integer('operator_user_id') ?: $operatorOptions->first()?->getKey())
            : (int) $user->getKey();
        $operator = $operatorOptions->firstWhere('id', $operatorId) ?: $user;
        $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
        $assistantDepartmentIds = $operationDepartment
            ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $operationDepartment->getKey())
            : ($user->isAdministrator() ? null : []);
        $advertisingAgencyGroups = AssistantAdvertisingAgency::enabledGroups($assistantDepartmentIds);

        return view('portals.operation.daily-submissions.advertising-recharge', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $user,
            'isBoss' => $isBoss,
            'operatorOptions' => $operatorOptions,
            'selectedOperator' => $operator,
            'advertisingAgencyGroups' => $advertisingAgencyGroups,
            'accountHistoryMatches' => $this->advertisingRechargeAccountMatches(
                collect($advertisingAgencyGroups)->flatten(1)->pluck('key')->map(fn ($id) => (int) $id)->all()
            ),
            'history' => DailyAdvertisingRecharge::query()
                ->where('operator_user_id', $operator->getKey())
                ->latest('report_date')->latest('id')->paginate(15)->withQueryString(),
        ]);
    }

    /** @param array{portal:mixed,user:\App\Models\AdminUser} $context */
    private function accountOpening(Request $request, array $context): View
    {
        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $operatorOptions = $isBoss ? $this->operatorOptions() : collect([$user]);
        $operatorId = $isBoss ? (int) ($request->integer('operator_user_id') ?: $operatorOptions->first()?->getKey()) : (int) $user->getKey();
        $operator = $operatorOptions->firstWhere('id', $operatorId) ?: $user;
        $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
        $assistantDepartmentIds = $operationDepartment
            ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $operationDepartment->getKey())
            : ($user->isAdministrator() ? null : []);

        return view('portals.operation.daily-submissions.account-opening', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $user,
            'isBoss' => $isBoss,
            'operatorOptions' => $operatorOptions,
            'selectedOperator' => $operator,
            'mediaOptions' => $this->accountOpeningMediaOptions($assistantDepartmentIds),
            'history' => DailyAccountOpening::query()->where('operator_user_id', $operator->getKey())->latest('id')->paginate(15)->withQueryString(),
        ]);
    }

    private function storeLaunch(Request $request, array $context): View
    {
        $user = $context['user']; $isBoss = $this->isBoss($user);
        $operatorOptions = $isBoss ? $this->operatorOptions() : collect([$user]);
        $operatorId = $isBoss ? (int) ($request->integer('operator_user_id') ?: $operatorOptions->first()?->id) : (int) $user->id;
        $operator = $operatorOptions->firstWhere('id', $operatorId) ?: $user;
        $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
        $assistantDepartmentIds = $operationDepartment ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $operationDepartment->id) : ($user->isAdministrator() ? null : []);
        $projects = AssistantProject::query()->where('is_enabled', true)
            ->when($assistantDepartmentIds !== null, fn ($q) => $q->whereIn('assistant_department_id', $assistantDepartmentIds))
            ->with(['websiteSystems' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort')->orderBy('id')])
            ->orderBy('sort')->orderBy('id')->get();
        return view('portals.operation.daily-submissions.store-launch', [
            'portal'=>$context['portal']->loadMissing('menus'),'user'=>$user,'isBoss'=>$isBoss,
            'operatorOptions'=>$operatorOptions,'selectedOperator'=>$operator,'projects'=>$projects,
            'history'=>DailyStoreLaunch::query()->where('operator_user_id',$operator->id)->latest('id')->paginate(15)->withQueryString(),
        ]);
    }

    /** @return list<string> */
    private function accountOpeningMediaOptions(?array $assistantDepartmentIds): array
    {
        return collect(['Facebook', 'TikTok', 'Google', 'Bigo', 'MediaGo'])
            ->merge(AssistantAdvertisingMedia::enabledNames($assistantDepartmentIds))
            ->filter()->unique(fn ($name) => mb_strtolower(trim((string) $name)))->values()->all();
    }

    private function accountOpeningMediaType(string $media): string
    {
        $normalized = mb_strtolower((string) preg_replace('/[\s_-]+/u', '', trim($media)));
        if (str_contains($normalized, 'facebook') || str_contains($normalized, '脸书')) return 'facebook';
        if (str_contains($normalized, 'tiktok') || str_contains($normalized, '抖音')) return 'tiktok';
        if (str_contains($normalized, 'google') || str_contains($normalized, '谷歌') || str_contains($normalized, 'bigo') || str_contains($normalized, 'mediago')) return 'email';
        return 'other';
    }

    /** @return array<string, array{by_number: array<string, list<string>>}> */
    private function advertisingRechargeAccountMatches(array $agencyIds): array
    {
        $allowed = array_fill_keys(array_map(fn ($id) => (string) (int) $id, $agencyIds), true);
        $matches = [];
        $agencyIdsByName = AssistantAdvertisingAgency::query()
            ->whereIn('id', $agencyIds)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (AssistantAdvertisingAgency $agency) => [mb_strtolower(trim($agency->name)) => (string) $agency->id]);

        // The advertising rows in operation reports are the authoritative account source.
        // They take precedence over recharge history, which may itself contain a typo.
        OperationReport::query()->orderBy('id')->get(['advertising_rows'])->each(function (OperationReport $report) use (&$matches, $agencyIdsByName): void {
            foreach ($report->advertising_rows ?? [] as $entry) {
                $agencyId = $agencyIdsByName->get(mb_strtolower(trim((string) ($entry['agency'] ?? ''))));
                $name = trim((string) ($entry['account_name'] ?? ''));
                $number = trim((string) ($entry['account_number'] ?? ''));
                if (! $agencyId || $name === '' || $number === '') continue;
                $matches[$agencyId]['by_number'][mb_strtolower($number)][] = $name;
            }
        });

        DailyAdvertisingRecharge::query()->orderBy('id')->get(['entries'])->each(function (DailyAdvertisingRecharge $submission) use (&$matches, $allowed): void {
            foreach ($submission->entries ?? [] as $entry) {
                $agencyId = (string) (int) ($entry['agency_id'] ?? 0);
                $name = trim((string) ($entry['account_name'] ?? ''));
                $number = trim((string) ($entry['account_number'] ?? ''));
                if (! isset($allowed[$agencyId]) || $name === '' || $number === '') continue;
                $numberKey = mb_strtolower($number);
                if (! isset($matches[$agencyId]['by_number'][$numberKey])) {
                    $matches[$agencyId]['by_number'][$numberKey][] = $name;
                }
            }
        });
        foreach ($matches as &$match) {
            foreach ($match['by_number'] as &$values) $values = array_values(array_unique($values));
            unset($values);
        }
        unset($match);
        return $matches;
    }

    private function isBoss(AdminUser $user): bool
    {
        return $user->isAdministrator() || strcasecmp((string) $user->username, 'kky_boss') === 0;
    }

    private function operatorOptions()
    {
        $ids = AssistantDomainStore::query()->whereNotNull('owner_user_id')->distinct()->pluck('owner_user_id');

        return AdminUser::query()->whereIn('id', $ids)->orderBy('name')->orderBy('username')->get(['id', 'name', 'username']);
    }

    /** @return array{portal:mixed,user:\App\Models\AdminUser} */
    private function operationContext(Request $request): array
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            abort($context->getStatusCode());
        }
        $this->assertDailyReportPortal($context['portal']);

        return $context;
    }

    private function assertDailyReportPortal($portal): void
    {
        abort_unless((string) $portal->code === self::DAILY_REPORT_PORTAL_CODE, 403);
    }
}
