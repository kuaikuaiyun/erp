<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppLovinSpendScreenshot;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\ProductRequests\ProductRequestAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppLovinSpendController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly ProductRequestAccessService $access,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->access->assertPortal($context['portal'], ProductRequestAccessService::OPERATION_PORTAL_CODE);
        $user = $context['user'];
        $filters = $request->validate([
            'over_threshold' => ['nullable', 'in:1'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:150'],
        ]);
        $canSeeAll = $user->isAdministrator() || strcasecmp((string) $user->username, 'kky_boss') === 0;
        $reports = OperationReport::query()
            ->where('status', '!=', OperationReport::STATUS_REJECTED)
            ->when(! $canSeeAll, fn ($query) => $query->where('operator_user_id', $user->getKey()))
            ->orderBy('report_date')->get();

        $accounts = $this->withScreenshots($this->summarize($reports));
        if (($filters['over_threshold'] ?? '') === '1') $accounts = $accounts->where('total', '>=', 5000)->values();
        $accountName = trim((string) ($filters['account_name'] ?? ''));
        $accountNumber = trim((string) ($filters['account_number'] ?? ''));
        if ($accountName !== '') $accounts = $accounts->filter(fn (array $account) => mb_stripos((string) $account['account_name'], $accountName) !== false)->values();
        if ($accountNumber !== '') $accounts = $accounts->filter(fn (array $account) => mb_stripos((string) $account['account_number'], $accountNumber) !== false)->values();
        return view('portals.operation.daily-submissions.applovin-spend', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'],
            'accounts' => $this->paginate($accounts, $request), 'threshold' => 5000, 'overThreshold' => $filters['over_threshold'] ?? '',
            'accountName' => $accountName, 'accountNumber' => $accountNumber,
        ]);
    }

    public function uploadScreenshot(Request $request): RedirectResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $this->access->assertPortal($context['portal'], ProductRequestAccessService::OPERATION_PORTAL_CODE);
        $validated = $request->validate(['account_key' => ['required', 'string', 'size:64'], 'screenshot' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $user = $context['user'];
        $canSeeAll = $user->isAdministrator() || strcasecmp((string) $user->username, 'kky_boss') === 0;
        $reports = OperationReport::query()
            ->where('status', '!=', OperationReport::STATUS_REJECTED)
            ->when(! $canSeeAll, fn ($query) => $query->where('operator_user_id', $user->getKey()))
            ->get();
        $account = $this->summarize($reports)->firstWhere('account_key', $validated['account_key']);
        if (! $account || $account['total'] < 5000) throw ValidationException::withMessages(['screenshot' => '只有累计消耗大于等于 5000 的账户可以上传图片。']);
        $path = $validated['screenshot']->store('applovin-spend-screenshots', 'local');
        $existing = AppLovinSpendScreenshot::query()->where('account_key', $account['account_key'])->first();
        if ($existing && Storage::disk('local')->exists($existing->screenshot_path)) Storage::disk('local')->delete($existing->screenshot_path);
        AppLovinSpendScreenshot::query()->updateOrCreate(['account_key' => $account['account_key']], [
            'operator_user_id'=>$account['operator_user_id'],'operation_department_id'=>$account['operation_department_id'],'agency'=>$account['agency'],'media'=>$account['media'],
            'account_name'=>$account['account_name'],'account_number'=>$account['account_number'],'screenshot_path'=>$path,'uploaded_by'=>$user->getKey(),
        ]);
        return back()->with('success', '账户消耗图片已上传。');
    }

    public function pendingCount(Request $request): JsonResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            abort($context->getStatusCode());
        }

        $this->access->assertPortal($context['portal'], ProductRequestAccessService::OPERATION_PORTAL_CODE);
        $user = $context['user'];
        $canSeeAll = $user->isAdministrator() || strcasecmp((string) $user->username, 'kky_boss') === 0;
        $reports = OperationReport::query()
            ->where('status', '!=', OperationReport::STATUS_REJECTED)
            ->when(! $canSeeAll, fn ($query) => $query->where('operator_user_id', $user->getKey()))
            ->get();
        $accounts = $this->summarize($reports)->where('total', '>=', 5000)->values();
        $uploadedKeys = AppLovinSpendScreenshot::query()
            ->whereIn('account_key', $accounts->pluck('account_key'))
            ->pluck('account_key');

        return response()->json([
            'count' => $accounts->whereNotIn('account_key', $uploadedKeys)->count(),
        ]);
    }

    public function screenshot(Request $request, AppLovinSpendScreenshot $screenshot): BinaryFileResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $this->access->assertPortal($context['portal'], ProductRequestAccessService::OPERATION_PORTAL_CODE);
        $user=$context['user'];
        $canSeeAll=$user->isAdministrator()||strcasecmp((string)$user->username,'kky_boss')===0;
        abort_unless($canSeeAll||(int)$screenshot->operator_user_id===(int)$user->getKey(),403);
        abort_unless(Storage::disk('local')->exists($screenshot->screenshot_path),404);
        return response()->file(Storage::disk('local')->path($screenshot->screenshot_path));
    }

    private function summarize($reports)
    {
        return $reports->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])
            ->filter(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? ''))) === 'applovin')
            ->map(fn (array $row) => [...$row, 'report_id' => $report->getKey(), 'date' => $report->report_date->format('Y-m-d'), 'operator_user_id' => (int)$report->operator_user_id, 'operation_department_id' => $report->operation_department_id, 'operator_name' => $report->operator_name, 'department' => $report->operation_department_name]))
            ->groupBy(fn (array $row) => implode('|', [(int)($row['operator_user_id']??0), mb_strtolower(trim((string)($row['agency']??''))), trim((string)($row['account_number']??''))]))
            ->map(function ($rows): array {
                $first = $rows->first();
                $agency=(string)($first['agency']??''); $media=(string)($first['media_source']??''); $number=trim((string)($first['account_number']??''));
                $names=$rows->pluck('account_name')->map(fn($name)=>trim((string)$name))->filter()->values();
                $name=$names->filter(fn($candidate)=>$candidate!==$number&&!ctype_digit($candidate))->last() ?: $names->last() ?: $number;
                return ['account_key'=>AppLovinSpendScreenshot::accountKey((int)$first['operator_user_id'],$agency,$media,$name,$number),'operator_user_id'=>(int)$first['operator_user_id'],'operation_department_id'=>$first['operation_department_id'],'operator_name' => $first['operator_name'], 'department' => $first['department'], 'agency' => $agency, 'media' => $media, 'account_name' => $name, 'account_number' => $number,
                    'total' => (float) $rows->sum('actual_spend'), 'details' => $rows->values()];
            })->sortByDesc('total')->values();
    }

    private function withScreenshots($accounts)
    {
        $screenshots = AppLovinSpendScreenshot::query()->whereIn('account_key', $accounts->pluck('account_key'))->get()->keyBy('account_key');
        return $accounts->map(fn (array $account) => [...$account, 'uploaded_screenshot' => $screenshots->get($account['account_key'])]);
    }

    private function paginate($items, Request $request): LengthAwarePaginator
    {
        $page=max(1,(int)$request->query('page',1));
        return new LengthAwarePaginator($items->forPage($page,20)->values(),$items->count(),20,$page,['path'=>$request->url(),'query'=>$request->query()]);
    }

}
