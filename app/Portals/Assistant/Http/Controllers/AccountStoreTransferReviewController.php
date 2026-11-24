<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DailyAccountStoreTransfer;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Assistant\DailyReportDepartmentScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AccountStoreTransferReviewController extends Controller
{
    public function __construct(private readonly PortalEntryController $portalEntries,private readonly AssistantPortalAuthorizationService $authorization,private readonly AssistantStoreOwnerService $storeOwners,private readonly DailyReportDepartmentScopeService $departmentScopes){}
    public function index(Request $request):Response|View
    {
        $context=$this->context($request);if($context instanceof Response)return $context;
        $filters=$request->validate(['operation_department_ids'=>['nullable','array'],'operation_department_ids.*'=>['integer','distinct'],'target_type'=>['nullable','in:account,store'],'action_type'=>['nullable','in:discard,transfer'],'status'=>['nullable','in:pending,processing,processed,rejected'],'operator'=>['nullable','string','max:100'],'per_page'=>['nullable','integer','in:20,50,100']]);
        $query=DailyAccountStoreTransfer::query();$all=$this->storeOwners->boundBusinessDepartments();$visible=null;
        $visible=$this->departmentScopes->visibleIdsForReview($context['user']);if($visible!==null){$visible===[]?$query->whereRaw('1=0'):$query->whereIn('operation_department_id',$visible);}
        $departments=$visible===null?$all:$all->whereIn('id',$visible)->values();
        $query->when($filters['operation_department_ids']??[],fn($q,$ids)=>$q->whereIn('operation_department_id',$ids))->when($filters['target_type']??null,fn($q,$v)=>$q->where('target_type',$v))->when($filters['action_type']??null,fn($q,$v)=>$q->where('action_type',$v))->when($filters['status']??null,fn($q,$v)=>$q->where('status',$v))->when($filters['operator']??null,fn($q,$v)=>$q->where('operator_name','like','%'.$v.'%'));
        return view('portals.assistant.daily-submissions.account-store-transfer',['portal'=>$context['portal']->loadMissing('menus'),'user'=>$context['user'],'filters'=>$filters,'departments'=>$departments,'submissions'=>$query->orderByRaw("CASE WHEN status IN ('pending', 'processing') THEN 0 ELSE 1 END")->latest('id')->paginate((int)($filters['per_page']??20))->withQueryString()]);
    }
    public function markProcessing(Request $request,DailyAccountStoreTransfer $submission):RedirectResponse
    {
        $context=$this->context($request);if($context instanceof Response)abort($context->getStatusCode());
        $visible=$this->departmentScopes->visibleIdsForReview($context['user']);if($visible!==null)abort_unless(in_array((int)$submission->operation_department_id,$visible,true),403);
        abort_unless($submission->status === DailyAccountStoreTransfer::STATUS_PENDING, 422, '该弃用/转让需求已开始处理或已完成，请刷新页面查看最新状态。');
        $submission->update(['status'=>DailyAccountStoreTransfer::STATUS_PROCESSING]);
        return back()->with('success','弃用/转让需求已标记为处理中。');
    }
    public function process(Request $request,DailyAccountStoreTransfer $submission):RedirectResponse
    {
        $context=$this->context($request);if($context instanceof Response)abort($context->getStatusCode());
        $visible=$this->departmentScopes->visibleIdsForReview($context['user']);if($visible!==null)abort_unless(in_array((int)$submission->operation_department_id,$visible,true),403);
        abort_unless(in_array($submission->status, [DailyAccountStoreTransfer::STATUS_PENDING, DailyAccountStoreTransfer::STATUS_PROCESSING], true), 422, '该弃用/转让需求已经处理，请刷新页面查看最新状态。');
        $data=$request->validate(['decision'=>['nullable','in:processed,rejected'],'response_text'=>['required','string','max:5000']],['response_text.required'=>'请填写处理结果或驳回原因。']);
        $status=($data['decision']??DailyAccountStoreTransfer::STATUS_PROCESSED)===DailyAccountStoreTransfer::STATUS_REJECTED?DailyAccountStoreTransfer::STATUS_REJECTED:DailyAccountStoreTransfer::STATUS_PROCESSED;
        $submission->update(['status'=>$status,'response_text'=>trim($data['response_text']),'processed_by'=>$context['user']->id,'processed_by_name'=>$context['user']->name?:$context['user']->username,'processed_at'=>now()]);
        return back()->with('success',$status===DailyAccountStoreTransfer::STATUS_REJECTED?'弃用/转让需求已驳回。':'弃用/转让需求已回复并标记为已处理。');
    }
    public function image(Request $request, DailyAccountStoreTransfer $submission, int $image): BinaryFileResponse
    {
        $this->authorizeSubmission($request, $submission);
        $attachment = $this->attachment($submission, $image);

        return response()->file(Storage::disk('local')->path($attachment['path']), [
            'Content-Type' => $attachment['mime'] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function downloadImage(Request $request, DailyAccountStoreTransfer $submission, int $image): BinaryFileResponse
    {
        $this->authorizeSubmission($request, $submission);
        $attachment = $this->attachment($submission, $image);

        return response()->download(
            Storage::disk('local')->path($attachment['path']),
            basename((string) ($attachment['name'] ?? 'reason-image'))
        );
    }

    /** @return array{path:string,name?:string,mime?:string,size?:int} */
    private function attachment(DailyAccountStoreTransfer $submission, int $image): array
    {
        $attachment = ($submission->reason_images ?? [])[$image] ?? null;
        abort_unless(is_array($attachment) && isset($attachment['path']) && Storage::disk('local')->exists($attachment['path']), 404);

        return $attachment;
    }

    private function authorizeSubmission(Request $request, DailyAccountStoreTransfer $submission): void
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $visible = $this->departmentScopes->visibleIdsForReview($context['user']);
        if ($visible !== null) abort_unless(in_array((int) $submission->operation_department_id, $visible, true), 403);
    }
    private function context(Request $request):array|Response{$context=$this->portalEntries->portalContext($request);if($context instanceof Response)return $context;$this->authorization->assertAssistantPortal($context['portal']);abort_unless($this->authorization->isAssistantDepartmentMember($context['user']),403);return $context;}
}
