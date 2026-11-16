<?php

namespace App\Portals\Fashion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppLovinSpendScreenshot;
use App\Models\FashionAdvertisingReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Services\DepartmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AppLovinSpendController extends Controller
{
    private const PORTAL_CODE = 'fuzhuang';
    private const GIFT_THRESHOLD = 5000;

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly DepartmentAuthorizationService $departmentAuthorization,
    )
    {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $filters = $request->validate([
            'over_threshold' => ['nullable', 'in:1'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:150'],
            'department_id' => ['nullable', 'integer'],
            'operator_id' => ['nullable', 'integer'],
        ]);
        $accounts = $this->withScreenshots($this->summarize($this->reportsFor($context['user'])));
        $departmentId = (int) ($filters['department_id'] ?? 0);
        $operatorId = (int) ($filters['operator_id'] ?? 0);
        $departments = $accounts->filter(fn (array $account): bool => ! empty($account['operation_department_id']))
            ->mapWithKeys(fn (array $account): array => [(int) $account['operation_department_id'] => (string) ($account['department'] ?: '未命名部门')])
            ->sort();
        $operators = $accounts->filter(fn (array $account): bool => ! $departmentId || (int) $account['operation_department_id'] === $departmentId)
            ->mapWithKeys(fn (array $account): array => [(int) $account['operator_user_id'] => (string) $account['operator_name']])
            ->sort();
        if ($departmentId) {
            $accounts = $accounts->where('operation_department_id', $departmentId)->values();
        }
        if ($operatorId) {
            $accounts = $accounts->where('operator_user_id', $operatorId)->values();
        }
        if (($filters['over_threshold'] ?? '') === '1') {
            $accounts = $accounts->where('total', '>=', self::GIFT_THRESHOLD)->values();
        }
        $accountName = trim((string) ($filters['account_name'] ?? ''));
        $accountNumber = trim((string) ($filters['account_number'] ?? ''));
        if ($accountName !== '') {
            $accounts = $accounts->filter(fn (array $account): bool => mb_stripos((string) $account['account_name'], $accountName) !== false)->values();
        }
        if ($accountNumber !== '') {
            $accounts = $accounts->filter(fn (array $account): bool => mb_stripos((string) $account['account_number'], $accountNumber) !== false)->values();
        }

        return view('portals.fashion.applovin-spend.index', [
            ...$context,
            'portal' => $context['portal']->loadMissing('menus'),
            'accounts' => $this->paginate($accounts, $request),
            'threshold' => self::GIFT_THRESHOLD,
            'overThreshold' => $filters['over_threshold'] ?? '',
            'accountName' => $accountName,
            'accountNumber' => $accountNumber,
            'departments' => $departments,
            'operators' => $operators,
            'departmentId' => $departmentId,
            'operatorId' => $operatorId,
        ]);
    }

    public function uploadScreenshot(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $validated = $request->validate([
            'account_key' => ['required', 'string', 'size:64'],
            'screenshot' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $account = $this->summarize($this->reportsFor($context['user'], false))
            ->firstWhere('account_key', $validated['account_key']);
        if (! $account || $account['total'] < self::GIFT_THRESHOLD) {
            throw ValidationException::withMessages(['screenshot' => '只有累计消耗大于等于 $5,000 的账户可以上传礼金图。']);
        }

        $path = $validated['screenshot']->store('applovin-spend-screenshots', 'local');
        $existing = AppLovinSpendScreenshot::query()->where('account_key', $account['account_key'])->first();
        if ($existing && Storage::disk('local')->exists($existing->screenshot_path)) {
            Storage::disk('local')->delete($existing->screenshot_path);
        }
        AppLovinSpendScreenshot::query()->updateOrCreate(['account_key' => $account['account_key']], [
            'operator_user_id' => $account['operator_user_id'],
            'operation_department_id' => $account['operation_department_id'],
            'agency' => $account['agency'],
            'media' => $account['media'],
            'account_name' => $account['account_name'],
            'account_number' => $account['account_number'],
            'screenshot_path' => $path,
            'uploaded_by' => $context['user']->getKey(),
        ]);

        return back()->with('success', '账户礼金图片已上传。');
    }

    public function pendingCount(Request $request): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $accounts = $this->summarize($this->reportsFor($context['user']))
            ->where('total', '>=', self::GIFT_THRESHOLD)->values();
        $uploadedKeys = AppLovinSpendScreenshot::query()->whereIn('account_key', $accounts->pluck('account_key'))->pluck('account_key');

        return response()->json(['count' => $accounts->whereNotIn('account_key', $uploadedKeys)->count()]);
    }

    public function screenshot(Request $request, AppLovinSpendScreenshot $screenshot): BinaryFileResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($this->canViewOperator($context['user'], (int) $screenshot->operator_user_id), 403);
        abort_unless(Storage::disk('local')->exists($screenshot->screenshot_path), 404);

        return response()->file(Storage::disk('local')->path($screenshot->screenshot_path));
    }

    private function reportsFor($user, bool $includeManaged = true): Collection
    {
        $query = FashionAdvertisingReport::query()
            ->with(['adSpendEntries', 'submitter.departments'])
            ->where('status', '!=', FashionAdvertisingReport::STATUS_REJECTED);
        if (! $this->canSeeAll($user)) {
            $departmentIds = $includeManaged ? $this->departmentAuthorization->managedDepartmentIds($user) : collect();
            $query->where(function ($scope) use ($user, $departmentIds): void {
                $scope->where('submitter_id', $user->getKey());
                if ($departmentIds->isNotEmpty()) {
                    $scope->orWhereHas('submitter.departments', fn ($departments) => $departments->whereIn('admin_roles.id', $departmentIds->all()));
                }
            });
        }

        return $query->orderBy('report_date')->get();
    }

    private function summarize(Collection $reports): Collection
    {
        return $reports->flatMap(function (FashionAdvertisingReport $report): Collection {
            $departments = $report->submitter?->departments ?? collect();
            $department = $departments->first(fn ($item) => (bool) ($item->pivot->is_primary ?? false)) ?: $departments->first();

            return $report->adSpendEntries
                ->filter(fn ($row) => mb_strtolower(trim((string) $row->media)) === 'applovin')
                ->map(fn ($row): array => [
                    'operator_user_id' => (int) $report->submitter_id,
                    'operation_department_id' => $department?->getKey(),
                    'operator_name' => $report->submitter_name,
                    'department' => $department?->name,
                    'agency' => (string) $row->agency,
                    'media' => (string) $row->media,
                    'account_name' => (string) $row->account_name,
                    'account_number' => trim((string) $row->account_number),
                    'actual_spend' => (float) $row->actual_spend_usd,
                ]);
        })->groupBy(fn (array $row): string => implode('|', [
            $row['operator_user_id'], mb_strtolower(trim($row['agency'])), $row['account_number'],
        ]))->map(function (Collection $rows): array {
            $first = $rows->first();
            $number = $first['account_number'];
            $names = $rows->pluck('account_name')->map(fn ($name) => trim((string) $name))->filter()->values();
            $name = $names->filter(fn ($candidate) => $candidate !== $number && ! ctype_digit($candidate))->last()
                ?: $names->last() ?: $number;

            return [
                ...$first,
                'account_name' => $name,
                'account_key' => AppLovinSpendScreenshot::accountKey(
                    $first['operator_user_id'], $first['agency'], $first['media'], $name, $number
                ),
                'total' => (float) $rows->sum('actual_spend'),
            ];
        })->sortByDesc('total')->values();
    }

    private function withScreenshots(Collection $accounts): Collection
    {
        $screenshots = AppLovinSpendScreenshot::query()->whereIn('account_key', $accounts->pluck('account_key'))->get()->keyBy('account_key');

        return $accounts->map(fn (array $account): array => [
            ...$account, 'uploaded_screenshot' => $screenshots->get($account['account_key']),
        ]);
    }

    private function paginate(Collection $items, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator($items->forPage($page, 20)->values(), $items->count(), 20, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless($context['portal']->code === self::PORTAL_CODE, 404);

        return $context;
    }

    private function canSeeAll($user): bool
    {
        return $user->isAdministrator() || strcasecmp((string) $user->username, 'kky_boss') === 0;
    }

    private function canViewOperator($user, int $operatorId): bool
    {
        if ($this->canSeeAll($user) || $operatorId === (int) $user->getKey()) return true;
        $departmentIds = $this->departmentAuthorization->managedDepartmentIds($user);
        return $departmentIds->isNotEmpty() && FashionAdvertisingReport::query()
            ->where('submitter_id', $operatorId)
            ->whereHas('submitter.departments', fn ($departments) => $departments->whereIn('admin_roles.id', $departmentIds->all()))
            ->exists();
    }
}
