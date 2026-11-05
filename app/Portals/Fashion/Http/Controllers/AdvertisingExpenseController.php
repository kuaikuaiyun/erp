<?php

namespace App\Portals\Fashion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FashionAdvertisingReport;
use App\Models\AssistantAdvertisingAgency;
use App\Models\AssistantAdvertisingMedia;
use App\Models\AssistantDomainStore;
use App\Portal\Http\Controllers\PortalEntryController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AdvertisingExpenseController extends Controller
{
    private const PORTAL_CODE = 'fuzhuang';

    public function __construct(private readonly PortalEntryController $portalEntries)
    {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $from = $this->dateFilter($request->input('from'));
        $to = $this->dateFilter($request->input('to'));
        $query = FashionAdvertisingReport::query()->with(['performanceEntries', 'adSpendEntries']);
        if (!$context['user']->isAdministrator()) $query->where('submitter_id', $context['user']->getKey());
        if ($from) $query->whereDate('report_date', '>=', $from);
        if ($to) $query->whereDate('report_date', '<=', $to);

        $reports = $query->orderByDesc('report_date')->orderByDesc('id')->paginate(20)->withQueryString();
        $editing = null;
        if ($request->filled('edit')) {
            $editing = FashionAdvertisingReport::query()->with(['performanceEntries', 'adSpendEntries'])->findOrFail((int) $request->input('edit'));
            $this->authorizeReport($context['user'], $editing);
        }

        $context['portal']->loadMissing('menus');
        $storeOptions = $this->storeOptions($context['user']);
        $advertisingOptions = $this->advertisingOptions();
        return view('portals.fashion.advertising-expenses.index', [
            ...$context,
            'reports' => $reports,
            'editing' => $editing,
            'filters' => ['from' => $from ?? '', 'to' => $to ?? ''],
            'storeOptions' => $storeOptions,
            'agencyOptions' => $advertisingOptions['agencies'],
            'mediaOptions' => $advertisingOptions['media'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort(403);
        $data = $this->validatePayload($request);

        $duplicate = FashionAdvertisingReport::query()
            ->whereDate('report_date', $data['report_date'])
            ->where('submitter_id', $context['user']->getKey())
            ->exists();
        if ($duplicate) return back()->withInput()->withErrors(['report_date' => '该日期已经填写过报表，可在下方记录中点击编辑。']);

        $this->persist($request, $data, $context['user']);
        return redirect()->route('fashion.advertising-expenses.index')->with('success', '广告费用及营业额数据保存成功。');
    }

    public function update(Request $request, FashionAdvertisingReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort(403);
        $this->authorizeReport($context['user'], $report);
        $data = $this->validatePayload($request);

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
        $report->load(['performanceEntries', 'adSpendEntries']);
        $paths = $report->performanceEntries->pluck('screenshot_path')->merge($report->adSpendEntries->pluck('screenshot_path'))->filter()->all();
        DB::connection($report->getConnectionName())->transaction(function () use ($report): void {
            $report->performanceEntries()->delete();
            $report->adSpendEntries()->delete();
            $report->delete();
        });
        Storage::disk('local')->delete($paths);
        return redirect()->route('fashion.advertising-expenses.index')->with('success', '报表已删除。');
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

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'report_date' => ['required', 'date'],
            'performance' => ['required', 'array', 'min:1', 'max:30'],
            'performance.*.store_name' => ['required', 'string', 'max:191'],
            'performance.*.domain' => ['nullable', 'string', 'max:255'],
            'performance.*.revenue_usd' => ['required', 'numeric', 'min:0'],
            'performance.*.order_count' => ['required', 'integer', 'min:0'],
            'performance.*.remark' => ['nullable', 'string', 'max:1000'],
            'performance.*.existing_screenshot' => ['nullable', 'string', 'max:500'],
            'performance.*.screenshot' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'advertising' => ['required', 'array', 'min:1', 'max:50'],
            'advertising.*.agency' => ['required', 'string', 'max:100'],
            'advertising.*.media' => ['required', 'string', 'max:100'],
            'advertising.*.account_name' => ['required', 'string', 'max:191'],
            'advertising.*.account_number' => ['required', 'string', 'max:191'],
            'advertising.*.book_spend_usd' => ['required', 'numeric', 'min:0'],
            'advertising.*.actual_spend_usd' => ['required', 'numeric', 'min:0'],
            'advertising.*.remark' => ['nullable', 'string', 'max:1000'],
            'advertising.*.existing_screenshot' => ['nullable', 'string', 'max:500'],
            'advertising.*.screenshot' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'performance.*.store_name.required' => '请填写店铺名称。',
            'advertising.*.agency.required' => '请填写广告代理。',
            'advertising.*.account_name.required' => '请填写广告账户。',
            'advertising.*.account_number.required' => '请填写广告账户编号。',
            '*.screenshot.max' => '截图不能超过 5MB。',
        ]);
    }

    private function persist(Request $request, array $data, $user, ?FashionAdvertisingReport $report = null): void
    {
        $oldPaths = [];
        if ($report) {
            $report->load(['performanceEntries', 'adSpendEntries']);
            $oldPaths = $report->performanceEntries->pluck('screenshot_path')->merge($report->adSpendEntries->pluck('screenshot_path'))->filter()->all();
        }

        $performance = $this->prepareRows($request, $data['performance'], 'performance');
        $advertising = $this->prepareRows($request, $data['advertising'], 'advertising');
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

        $newPaths = collect($performance)->pluck('screenshot_path')->merge(collect($advertising)->pluck('screenshot_path'))->filter()->all();
        Storage::disk('local')->delete(array_values(array_diff($oldPaths, $newPaths)));
    }

    private function prepareRows(Request $request, array $rows, string $group): array
    {
        return collect($rows)->map(function (array $row, int|string $index) use ($request, $group): array {
            $path = Arr::get($row, 'existing_screenshot');
            if ($file = $request->file("{$group}.{$index}.screenshot")) {
                $path = $file->storeAs('fashion-advertising/'.now()->format('Ym'), Str::uuid().'.'.$file->extension(), 'local');
            }
            unset($row['screenshot'], $row['existing_screenshot']);
            foreach (['revenue_usd', 'book_spend_usd', 'actual_spend_usd'] as $field) {
                if (array_key_exists($field, $row)) $row[$field] = round((float) $row[$field], 2);
            }
            if (array_key_exists('order_count', $row)) $row['order_count'] = (int) $row['order_count'];
            $row['screenshot_path'] = $path ?: null;
            return $row;
        })->values()->all();
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
        abort_unless($user->isAdministrator() || (int) $report->submitter_id === (int) $user->getKey(), 403);
    }

    private function dateFilter(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function storeOptions($user): array
    {
        $query = AssistantDomainStore::query()
            ->where(function ($stores): void {
                $stores->whereNotNull('shop_name')->where('shop_name', '!=', '')
                    ->orWhere(function ($domain): void {
                        $domain->whereNotNull('domain')->where('domain', '!=', '');
                    });
            });

        if (!$user->isAdministrator()) {
            $names = collect([$user->name, $user->username])->filter()->map(fn ($name) => trim((string) $name))->unique()->all();
            $query->where(function ($scope) use ($user, $names): void {
                $scope->where('owner_user_id', $user->getKey());
                if ($names !== []) $scope->orWhereIn('user_name', $names);
            });
        }

        return $query->orderBy('shop_name')->orderBy('domain')->get(['id', 'shop_name', 'domain', 'user_name', 'owner_user_id'])
            ->map(fn (AssistantDomainStore $store): array => [
                'id' => (int) $store->id,
                'store_name' => trim((string) $store->shop_name) ?: trim((string) $store->domain),
                'domain' => trim((string) $store->domain),
            ])->unique(fn (array $store): string => mb_strtolower($store['store_name'].'|'.$store['domain']))->values()->all();
    }

    private function advertisingOptions(): array
    {
        $agencies = AssistantAdvertisingAgency::query()->where('is_enabled', true)->with('advertisingMedia')
            ->orderBy('sort')->orderBy('id')->get()
            ->map(fn (AssistantAdvertisingAgency $agency): array => [
                'name' => trim((string) $agency->name),
                'media' => trim((string) ($agency->advertisingMedia?->name ?? '')),
            ])->filter(fn (array $agency): bool => $agency['name'] !== '')
            ->unique(fn (array $agency): string => mb_strtolower($agency['name'].'|'.$agency['media']))->values();

        $media = AssistantAdvertisingMedia::query()->where('is_enabled', true)->orderBy('sort')->orderBy('id')
            ->pluck('name')->map(fn ($name): string => trim((string) $name))->filter()->unique()->values();
        $media = $media->merge($agencies->pluck('media'))->filter()->unique()->values();

        return ['agencies' => $agencies->all(), 'media' => $media->all()];
    }
}
