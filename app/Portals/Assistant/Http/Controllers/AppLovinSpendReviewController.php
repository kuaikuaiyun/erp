<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppLovinSpendScreenshot;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AppLovinSpendReviewController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        $visibleIds = $this->storeOwners->visibleOperationDepartmentIds($context['user']);
        $filters = $request->validate(['operator' => ['nullable', 'string', 'max:100'], 'department_id' => ['nullable', 'integer', Rule::in($visibleIds)], 'over_threshold' => ['nullable', 'in:1']]);
        $query = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED);
        if (! $context['user']->isAdministrator()) {
            $visibleIds === [] ? $query->whereRaw('1=0') : $query->whereIn('operation_department_id', $visibleIds);
        }
        $query->when($filters['department_id'] ?? null, fn ($q,$id) => $q->where('operation_department_id',$id));
        $query->when($filters['operator'] ?? null, fn ($q, $name) => $q->where('operator_name', 'like', '%'.$name.'%'));
        $rows = $query->orderBy('report_date')->get()->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])
            ->filter(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? ''))) === 'applovin')
            ->map(fn (array $row) => [...$row, 'report_id' => $report->getKey(), 'date' => $report->report_date->format('Y-m-d'), 'operator_user_id' => (int)$report->operator_user_id, 'operator_name' => $report->operator_name, 'department' => $report->operation_department_name]));
        $accounts = $rows->groupBy(fn (array $row) => implode('|', [$row['operator_name'], $row['agency'] ?? '', $row['account_name'] ?? '', $row['account_number'] ?? '']))
            ->map(function ($group): array { $first=$group->first(); $agency=(string)($first['agency']??'');$media=(string)($first['media_source']??'');$name=(string)($first['account_name']??'');$number=(string)($first['account_number']??''); return ['account_key'=>AppLovinSpendScreenshot::accountKey((int)$first['operator_user_id'],$agency,$media,$name,$number),'operator_name'=>$first['operator_name'],'department'=>$first['department'],'agency'=>$agency,'media'=>$media,'account_name'=>$name,'account_number'=>$number,'total'=>(float)$group->sum('actual_spend'),'details'=>$group->values()]; })->sortByDesc('total')->values();
        $screenshots = AppLovinSpendScreenshot::query()->whereIn('account_key', $accounts->pluck('account_key'))->get()->keyBy('account_key');
        $accounts = $accounts->map(fn (array $account) => [...$account, 'uploaded_screenshot' => $screenshots->get($account['account_key'])]);
        if (($filters['over_threshold'] ?? '') === '1') $accounts = $accounts->where('total', '>=', 5000)->values();
        $page=max(1,(int)$request->query('page',1));
        $accounts = new LengthAwarePaginator($accounts->forPage($page,20)->values(),$accounts->count(),20,$page,['path'=>$request->url(),'query'=>$request->query()]);
        $departmentOptions = $this->storeOwners->operationDepartments()->whereIn('id',$visibleIds)->values();

        return view('portals.assistant.daily-submissions.applovin-spend', [
            'portal'=>$context['portal']->loadMissing('menus'),'user'=>$context['user'],
            'operator'=>$filters['operator']??'','selectedDepartmentId'=>$filters['department_id']??null,'overThreshold'=>$filters['over_threshold']??'','departmentOptions'=>$departmentOptions,'accounts'=>$accounts,'threshold'=>5000,
        ]);
    }

    public function screenshot(Request $request, AppLovinSpendScreenshot $screenshot): BinaryFileResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        if (! $context['user']->isAdministrator()) abort_unless(in_array((int)$screenshot->operation_department_id, $this->storeOwners->visibleOperationDepartmentIds($context['user']), true), 403);
        abort_unless(Storage::disk('local')->exists($screenshot->screenshot_path), 404);
        return response()->file(Storage::disk('local')->path($screenshot->screenshot_path));
    }
}
