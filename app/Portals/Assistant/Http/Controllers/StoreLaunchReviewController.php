<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DailyStoreLaunch;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Assistant\DailyReportDepartmentScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class StoreLaunchReviewController extends Controller
{
    public function __construct(private readonly PortalEntryController $portalEntries, private readonly AssistantPortalAuthorizationService $authorization, private readonly AssistantStoreOwnerService $storeOwners, private readonly DailyReportDepartmentScopeService $departmentScopes) {}

    public function index(Request $request): Response|View
    {
        $context=$this->context($request); if($context instanceof Response)return $context;
        $filters=$request->validate(['operation_department_ids'=>['nullable','array'],'operation_department_ids.*'=>['integer','distinct'],'status'=>['nullable','in:pending,processed'],'operator'=>['nullable','string','max:100']]);
        $query=DailyStoreLaunch::query(); $all=$this->storeOwners->boundBusinessDepartments(); $visible=null;
        $visible=$this->departmentScopes->visibleIdsForReview($context['user']);if($visible!==null){$visible===[]?$query->whereRaw('1=0'):$query->whereIn('operation_department_id',$visible);}
        $departments=$visible===null?$all:$all->whereIn('id',$visible)->values();
        $query->when($filters['operation_department_ids']??[],fn($q,$ids)=>$q->whereIn('operation_department_id',$ids))->when($filters['status']??null,fn($q,$v)=>$q->where('status',$v))->when($filters['operator']??null,fn($q,$v)=>$q->where('operator_name','like','%'.$v.'%'));
        return view('portals.assistant.daily-submissions.store-launch',['portal'=>$context['portal']->loadMissing('menus'),'user'=>$context['user'],'filters'=>$filters,'departments'=>$departments,'submissions'=>$query->orderByRaw("CASE WHEN status='pending' THEN 0 ELSE 1 END")->latest('id')->paginate(20)->withQueryString()]);
    }

    public function process(Request $request, DailyStoreLaunch $submission): RedirectResponse
    {
        $context=$this->context($request); if($context instanceof Response)abort($context->getStatusCode());
        $visible=$this->departmentScopes->visibleIdsForReview($context['user']);if($visible!==null)abort_unless(in_array((int)$submission->operation_department_id,$visible,true),403);
        $data=$request->validate(['response_text'=>['required','string','max:5000']],['response_text.required'=>'请填写回复内容。']);
        $submission->update(['status'=>DailyStoreLaunch::STATUS_PROCESSED,'response_text'=>trim($data['response_text']),'processed_by'=>$context['user']->id,'processed_by_name'=>$context['user']->name?:$context['user']->username,'processed_at'=>now()]);
        return back()->with('success','下店需求已回复并标记为已处理。');
    }

    private function context(Request $request): array|Response
    {
        $context=$this->portalEntries->portalContext($request);if($context instanceof Response)return $context;
        $this->authorization->assertAssistantPortal($context['portal']);abort_unless($this->authorization->isAssistantDepartmentMember($context['user']),403);return $context;
    }
}
