<?php

namespace App\Portals\Fashion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionLogisticsOrder;
use App\Models\AdminUser;
use App\Models\AssistantAdvertisingAgency;
use App\Models\AssistantAdvertisingMedia;
use App\Models\AssistantDomainStore;
use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinSpendScreenshot;
use App\Models\Department;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AdvertisingExpenseController extends Controller
{
    private const PORTAL_CODE = 'fuzhuang';

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantStoreOwnerService $assistantStoreOwners,
    )
    {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $operatorOptions = $isBoss ? $this->operatorOptions() : collect([$user]);

        $from = $this->dateFilter($request->input('from'));
        $to = $this->dateFilter($request->input('to'));
        $query = FashionAdvertisingReport::query()->with(['performanceEntries', 'adSpendEntries']);
        if (!$isBoss) $query->where('submitter_id', $user->getKey());
        if ($from) $query->whereDate('report_date', '>=', $from);
        if ($to) $query->whereDate('report_date', '<=', $to);

        $reports = $query->orderByDesc('report_date')->orderByDesc('id')->paginate(20)->withQueryString();
        $editing = null;
        if ($request->filled('edit')) {
            $editing = FashionAdvertisingReport::query()->with(['performanceEntries', 'adSpendEntries'])->findOrFail((int) $request->input('edit'));
            $this->authorizeReport($user, $editing);
            if ($isBoss && ! $operatorOptions->contains('id', (int) $editing->submitter_id)) {
                $editingOperator = AdminUser::query()->find((int) $editing->submitter_id);
                if ($editingOperator) $operatorOptions->push($editingOperator);
            }
        }

        $selectedOperatorId = $editing?->submitter_id
            ?: ($isBoss ? (int) ($request->integer('operator_user_id') ?: $operatorOptions->first()?->id) : (int) $user->id);
        $selectedOperator = $operatorOptions->firstWhere('id', $selectedOperatorId) ?: $user;
        $operatorStores = AssistantDomainStore::query()->where('owner_user_id', $selectedOperator->getKey())
            ->whereNotNull('domain')->where('domain', '!=', '')->orderBy('domain')->get(['id', 'domain', 'shop_name']);

        $context['portal']->loadMissing('menus');
        $advertisingOptions = $this->advertisingOptions($this->assistantDepartmentIdsForUser($selectedOperator));
        return view('portals.fashion.advertising-expenses.index', [
            ...$context,
            'reports' => $reports,
            'editing' => $editing,
            'filters' => ['from' => $from ?? '', 'to' => $to ?? ''],
            'agencyOptions' => $advertisingOptions['agencies'],
            'agencyMediaOptions' => $advertisingOptions['agency_media'],
            'agencyRateOptions' => $advertisingOptions['agency_rates'],
            'mediaOptions' => $advertisingOptions['media'],
            'isBoss' => $isBoss,
            'operatorOptions' => $operatorOptions,
            'selectedOperator' => $selectedOperator,
            'operatorStores' => $operatorStores,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort(403);
        $submitter = $this->resolveSubmitter($request, $context['user']);
        $data = $this->validatePayload($request, (int) $submitter->getKey());

        $duplicate = FashionAdvertisingReport::query()
            ->whereDate('report_date', $data['report_date'])
            ->where('submitter_id', $submitter->getKey())
            ->exists();
        if ($duplicate) return back()->withInput()->withErrors(['report_date' => '该日期已经填写过报表，可在下方记录中点击编辑。']);

        $this->persist($request, $data, $submitter);
        return redirect()->route('fashion.advertising-expenses.index')->with('success', '广告费用保存成功，营业额数据已由系统自动统计。');
    }

    public function update(Request $request, FashionAdvertisingReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort(403);
        $this->authorizeReport($context['user'], $report);
        abort_unless(in_array($report->status, [
            FashionAdvertisingReport::STATUS_PENDING,
            FashionAdvertisingReport::STATUS_APPROVED,
            FashionAdvertisingReport::STATUS_REJECTED,
        ], true), 422, '该报表当前不能修改。');
        $data = $this->validatePayload($request, (int) $report->submitter_id);

        $duplicate = FashionAdvertisingReport::query()
            ->whereKeyNot($report->getKey())
            ->whereDate('report_date', $data['report_date'])
            ->where('submitter_id', $report->submitter_id)
            ->exists();
        if ($duplicate) return back()->withInput()->withErrors(['report_date' => '该日期已经存在另一份报表。']);

        $this->persist($request, $data, $context['user'], $report);
        return redirect()->route('fashion.advertising-expenses.index')->with('success', '报表修改成功。');
    }

    public function destroy(Request $request, FashionAdvertisingReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort(403);
        $this->authorizeReport($context['user'], $report);
        $report->forceFill([
            'deleted_by' => $context['user']->getKey(),
            'deleter_name' => $context['user']->name ?: $context['user']->username,
        ])->save();
        $report->delete();
        return redirect()->route('fashion.advertising-expenses.index')->with('success', '报表已删除，可重新填写该日期的广告费。');
    }

    public function screenshot(Request $request, string $type, int $entry): BinaryFileResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort(403);
        $relation = $type === 'performance' ? 'performanceEntries' : ($type === 'advertising' ? 'adSpendEntries' : null);
        abort_unless($relation, 404);
        $report = FashionAdvertisingReport::query()->whereHas($relation, fn ($query) => $query->whereKey($entry))->firstOrFail();
        $this->authorizeReport($context['user'], $report);
        $item = $report->{$relation}()->whereKey($entry)->firstOrFail();
        abort_unless($item->screenshot_path && Storage::disk('local')->exists($item->screenshot_path), 404);
        return response()->file(Storage::disk('local')->path($item->screenshot_path));
    }

    private function validatePayload(Request $request, int $operatorId): array
    {
        $data = $request->validate([
            'report_date' => ['required', 'date'],
            'advertising' => ['required', 'array', 'min:1', 'max:50'],
            'advertising.*.agency' => ['required', 'string', 'max:100'],
            'advertising.*.media' => ['required', 'string', 'max:100'],
            'advertising.*.account_name' => ['required', 'string', 'max:191'],
            'advertising.*.account_number' => ['required', 'string', 'max:191'],
            'advertising.*.assistant_domain_store_id' => ['nullable', 'integer'],
            'advertising.*.book_spend_usd' => ['required', 'numeric', 'min:0'],
            'advertising.*.settlement_coefficient' => ['required', 'numeric', 'min:0'],
            'advertising.*.actual_spend_usd' => ['required', 'numeric', 'min:0'],
            'advertising.*.remark' => ['nullable', 'string', 'max:1000'],
            'advertising.*.existing_screenshot' => ['nullable', 'string', 'max:500'],
            'advertising.*.remove_screenshot' => ['nullable', 'boolean'],
            'advertising.*.screenshot' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'advertising.*.agency.required' => '请填写广告代理。',
            'advertising.*.account_name.required' => '请填写广告账户。',
            'advertising.*.account_number.required' => '请填写广告账户编号。',
            '*.screenshot.max' => '截图不能超过 5MB。',
        ]);
        foreach ($data['advertising'] as $index => &$row) {
            if (mb_strtolower(trim((string) $row['media'])) !== 'applovin') {
                $row['assistant_domain_store_id'] = null; $row['store_domain'] = null; continue;
            }
            $store = AssistantDomainStore::query()->whereKey((int) ($row['assistant_domain_store_id'] ?? 0))->where('owner_user_id', $operatorId)->first();
            if (! $store) throw ValidationException::withMessages(["advertising.{$index}.assistant_domain_store_id" => '选择 AppLovin 时必须选择该运营已绑定的店铺域名。']);
            $row['store_domain'] = (string) $store->domain;
        }
        unset($row);
        return $data;
    }

    private function persist(Request $request, array $data, $user, ?FashionAdvertisingReport $report = null): void
    {
        $oldPaths = [];
        if ($report) {
            $report->load(['performanceEntries', 'adSpendEntries']);
            $oldPaths = $report->performanceEntries->pluck('screenshot_path')->merge($report->adSpendEntries->pluck('screenshot_path'))->filter()->all();
        }

        $performanceOwnerId = $report?->submitter_id ?: $user->getKey();
        $performance = $this->calculatedPerformanceRows($data['report_date'], (int) $performanceOwnerId);
        $advertising = $this->prepareRows($request, $data['advertising'], 'advertising', (int) $performanceOwnerId);
        $revenue = round(collect($performance)->sum('revenue_usd'), 2);
        $orders = (int) collect($performance)->sum('order_count');
        $spend = round(collect($advertising)->sum('actual_spend_usd'), 2);

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($report, $data, $user, $performance, $advertising, $revenue, $orders, $spend): void {
            $report ??= new FashionAdvertisingReport();
            $report->fill([
                'report_date' => $data['report_date'],
                'submitter_id' => $report->exists ? $report->submitter_id : $user->getKey(),
                'submitter_name' => $report->exists ? $report->submitter_name : $user->name,
                'total_revenue_usd' => $revenue,
                'total_order_count' => $orders,
                'total_ad_spend_usd' => $spend,
                'roi' => $spend > 0 ? round($revenue / $spend, 4) : 0,
                'status' => FashionAdvertisingReport::STATUS_PENDING,
                'reviewed_by' => null,
                'reviewer_name' => null,
                'reviewed_at' => null,
                'review_reason' => null,
            ])->save();
            $report->performanceEntries()->delete();
            $report->adSpendEntries()->delete();
            $report->performanceEntries()->createMany($performance);
            $report->adSpendEntries()->createMany($advertising);
        });
        foreach (collect($advertising)->where(fn ($row) => mb_strtolower(trim((string) ($row['media'] ?? ''))) === 'applovin') as $row) {
            AppLovinAccountStoreBinding::query()->updateOrCreate([
                'account_key' => AppLovinSpendScreenshot::accountKey((int) $performanceOwnerId, (string) $row['agency'], (string) $row['media'], (string) $row['account_name'], (string) $row['account_number']),
            ], ['assistant_domain_store_id' => (int) $row['assistant_domain_store_id'], 'bound_by' => $user->getKey()]);
        }

        $newPaths = collect($performance)->pluck('screenshot_path')->merge(collect($advertising)->pluck('screenshot_path'))->filter()->all();
        Storage::disk('local')->delete(array_values(array_diff($oldPaths, $newPaths)));
    }

    private function prepareRows(Request $request, array $rows, string $group, ?int $operatorId = null): array
    {
        return collect($rows)->map(function (array $row, int|string $index) use ($request, $group, $operatorId): array {
            $path = Arr::get($row, 'existing_screenshot');
            if ((bool) Arr::get($row, 'remove_screenshot', false)) $path = null;
            if ($file = $request->file("{$group}.{$index}.screenshot")) {
                $path = $file->storeAs('fashion-advertising/'.now()->format('Ym'), Str::uuid().'.'.$file->extension(), 'local');
            }
            unset($row['screenshot'], $row['existing_screenshot'], $row['remove_screenshot']);
            if ($group === 'advertising') {
                $configuredCoefficient = AssistantAdvertisingAgency::query()
                    ->when($operatorId !== null, fn ($query) => $query->whereIn('assistant_department_id', $this->assistantDepartmentIdsForUser(AdminUser::query()->findOrFail($operatorId))))
                    ->where('name', trim((string) ($row['agency'] ?? '')))
                    ->whereHas('advertisingMedia', fn ($query) => $query->where('name', trim((string) ($row['media'] ?? ''))))
                    ->value('settlement_coefficient');
                $coefficient = round((float) ($configuredCoefficient ?? $row['settlement_coefficient'] ?? 0), 4);
                $bookSpend = round((float) ($row['book_spend_usd'] ?? 0), 2);
                $row['settlement_coefficient'] = $coefficient;
                $row['actual_spend_usd'] = round($bookSpend + ($bookSpend * $coefficient), 2);
            }
            foreach (['revenue_usd', 'book_spend_usd', 'actual_spend_usd'] as $field) {
                if (array_key_exists($field, $row)) $row[$field] = round((float) $row[$field], 2);
            }
            if (array_key_exists('order_count', $row)) $row['order_count'] = (int) $row['order_count'];
            $row['screenshot_path'] = $path ?: null;
            return $row;
        })->values()->all();
    }

    /** @return list<array{store_name:string,domain:string,revenue_usd:float,order_count:int,screenshot_path:null,remark:string}> */
    private function calculatedPerformanceRows(string $reportDate, int $ownerUserId): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $orderTable = (new FashionLogisticsOrder())->getTable();
        $owner = AdminUser::query()->find($ownerUserId);
        $ownerNames = collect([$owner?->name, $owner?->username])
            ->filter()->map(fn ($name): string => trim((string) $name))->unique()->values()->all();

        return DB::connection($connection)->table($orderTable.' as orders')
            ->join('assistant_domain_stores as stores', 'stores.id', '=', 'orders.assistant_domain_store_id')
            ->where(function ($binding) use ($ownerUserId, $ownerNames): void {
                $binding->where('stores.owner_user_id', $ownerUserId);
                if ($ownerNames !== []) $binding->orWhereIn('stores.user_name', $ownerNames);
            })
            ->whereRaw("LOWER(TRIM(stores.site_builder)) IN ('xshoppy','xshopy')")
            ->whereBetween('orders.order_created_at', [$reportDate.' 00:00:00', $reportDate.' 23:59:59'])
            ->where(function ($paid): void {
                $paid->whereNotNull('orders.paid_at')
                    ->orWhereIn('orders.status', ['processing', 'completed']);
            })
            ->select(['stores.id', 'stores.shop_name', 'stores.domain'])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(orders.total_amount), 0) as revenue_usd')
            ->groupBy('stores.id', 'stores.shop_name', 'stores.domain')
            ->orderBy('stores.shop_name')
            ->get()
            ->map(static fn (object $row): array => [
                'store_name' => trim((string) $row->shop_name) ?: trim((string) $row->domain),
                'domain' => trim((string) $row->domain),
                'revenue_usd' => round((float) $row->revenue_usd, 2),
                'order_count' => (int) $row->order_count,
                'screenshot_path' => null,
                'remark' => '系统根据 XShoppy 已支付订单自动统计',
            ])->values()->all();
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless($context['portal']->code === self::PORTAL_CODE, 404);
        return $context;
    }

    private function authorizeReport($user, FashionAdvertisingReport $report): void
    {
        abort_unless($this->isBoss($user) || (int) $report->submitter_id === (int) $user->getKey(), 403);
    }

    private function isBoss(AdminUser $user): bool
    {
        return $user->isAdministrator() || strcasecmp((string) $user->username, 'kky_boss') === 0;
    }

    private function operatorOptions()
    {
        $root = Department::business()->where('name', '服装运营部门')->first(['id']);
        if (! $root) return collect();
        $departments = Department::business()->get(['id', 'parent_id']);
        $departmentIds = [(int) $root->id];
        do {
            $children = $departments->whereIn('parent_id', $departmentIds)->pluck('id')->map(fn ($id) => (int) $id)->diff($departmentIds)->values()->all();
            $before = count($departmentIds);
            $departmentIds = array_values(array_unique([...$departmentIds, ...$children]));
        } while (count($departmentIds) > $before);

        return AdminUser::query()->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query->whereIn('id', $departmentIds))
            ->orderBy('name')->orderBy('username')->get(['id', 'name', 'username']);
    }

    private function resolveSubmitter(Request $request, AdminUser $actor): AdminUser
    {
        if (! $this->isBoss($actor)) return $actor;
        $operatorId = $request->integer('operator_user_id');
        abort_unless($operatorId > 0 && $this->operatorOptions()->contains('id', $operatorId), 422, '请选择有效的服装运营账号。');
        return AdminUser::query()->findOrFail($operatorId);
    }

    private function dateFilter(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    /** @param list<int> $assistantDepartmentIds */
    private function advertisingOptions(array $assistantDepartmentIds): array
    {
        $rawAgencies = AssistantAdvertisingAgency::query()->where('is_enabled', true)
            ->whereIn('assistant_department_id', $assistantDepartmentIds)
            ->with('advertisingMedia')
            ->orderBy('sort')->orderBy('id')->get()
            ->map(fn (AssistantAdvertisingAgency $agency): array => [
                'name' => trim((string) $agency->name),
                'media' => $this->canonicalAdvertisingMedia((string) ($agency->advertisingMedia?->name ?? '')),
                'coefficient' => (float) $agency->settlement_coefficient,
            ])->filter(fn (array $agency): bool => $agency['name'] !== '')->values();

        $canonicalAgencyNames = $rawAgencies
            ->groupBy(fn (array $agency): string => $this->advertisingNameKey($agency['name']))
            ->map(fn ($items): string => (string) $items->countBy('name')->sortDesc()->keys()->first());

        $agencies = $rawAgencies->map(function (array $agency) use ($canonicalAgencyNames): array {
            $agency['name'] = $canonicalAgencyNames->get($this->advertisingNameKey($agency['name']), $agency['name']);

            return $agency;
        })->unique(fn (array $agency): string => $this->advertisingNameKey($agency['name']).'|'.mb_strtolower($agency['media']))->values();

        $media = AssistantAdvertisingMedia::query()->where('is_enabled', true)
            ->whereIn('assistant_department_id', $assistantDepartmentIds)
            ->orderBy('sort')->orderBy('id')
            ->pluck('name')->map(fn ($name): string => $this->canonicalAdvertisingMedia((string) $name))->filter()->unique()->values();
        $media = $media->merge($agencies->pluck('media'))->filter()->unique()->values();

        $agencyMedia = $agencies->groupBy('name')->map(fn ($items) => $items->pluck('media')->filter()->unique()->values()->all())->all();
        $agencyRates = $agencies->mapWithKeys(fn (array $agency): array => [
            mb_strtolower($agency['name'].'|'.$agency['media']) => $agency['coefficient'],
        ])->all();

        return [
            'agencies' => $agencies->pluck('name')->filter()->unique()->values()->all(),
            'agency_media' => $agencyMedia,
            'agency_rates' => $agencyRates,
            'media' => $media->all(),
        ];
    }

    private function advertisingNameKey(string $name): string
    {
        return str_replace('藍', '蓝', mb_strtolower(trim($name)));
    }

    private function canonicalAdvertisingMedia(string $media): string
    {
        $media = trim($media);

        return mb_strtolower($media) === 'applovin' ? 'AppLovin' : $media;
    }

    /** @return list<int> */
    private function assistantDepartmentIdsForUser(AdminUser $user): array
    {
        $department = $this->assistantStoreOwners->boundBusinessDepartmentForUser($user);

        return $department
            ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $department->id)
            : [];
    }
}
