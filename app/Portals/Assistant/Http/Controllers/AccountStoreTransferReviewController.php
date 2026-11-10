<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DailyAccountStoreTransfer;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AccountStoreTransferReviewController extends Controller
{
    public function __construct(private readonly PortalEntryController $portalEntries,private readonly AssistantPortalAuthorizationService $authorization,private readonly AssistantStoreOwnerService $storeOwners){}
    public function index(Request $request):Response|View
    {
        $context=$this->context($request);if($context instanceof Response)return $context;
        $filters=$request->validate(['operation_department_id'=>['nullable','integer'],'target_type'=>['nullable','in:account,store'],'action_type'=>['nullable','in:discard,transfer'],'status'=>['nullable','in:pending,processed'],'operator'=>['nullable','string','max:100']]);
        $query=DailyAccountStoreTransfer::query();$all=$this->storeOwners->boundBusinessDepartments();$visible=null;
        if(!$context['user']->isAdministrator()){$visible=$this->storeOwners->visibleOperationDepartmentIds($context['user']);$visible===[]?$query->whereRaw('1=0'):$query->whereIn('operation_department_id',$visible);}
        $departments=$visible===null?$all:$all->whereIn('id',$visible)->values();
        $query->when($filters['operation_department_id']??null,fn($q,$v)=>$q->where('operation_department_id',$v))->when($filters['target_type']??null,fn($q,$v)=>$q->where('target_type',$v))->when($filters['action_type']??null,fn($q,$v)=>$q->where('action_type',$v))->when($filters['status']??null,fn($q,$v)=>$q->where('status',$v))->when($filters['operator']??null,fn($q,$v)=>$q->where('operator_name','like','%'.$v.'%'));
        return view('portals.assistant.daily-submissions.account-store-transfer',['portal'=>$context['portal']->loadMissing('menus'),'user'=>$context['user'],'filters'=>$filters,'departments'=>$departments,'submissions'=>$query->orderByRaw("CASE WHEN status='pending' THEN 0 ELSE 1 END")->latest('id')->paginate(20)->withQueryString()]);
    }
    public function process(Request $request,DailyAccountStoreTransfer $submission):RedirectResponse
    {
        $context=$this->context($request);if($context instanceof Response)abort($context->getStatusCode());
        if(!$context['user']->isAdministrator())abort_unless(in_array((int)$submission->operation_department_id,$this->storeOwners->visibleOperationDepartmentIds($context['user']),true),403);
        $data=$request->validate(['response_text'=>['required','string','max:5000']],['response_text.required'=>'请填写回复内容。']);
        $submission->update(['status'=>DailyAccountStoreTransfer::STATUS_PROCESSED,'response_text'=>trim($data['response_text']),'processed_by'=>$context['user']->id,'processed_by_name'=>$context['user']->name?:$context['user']->username,'processed_at'=>now()]);
        return back()->with('success','弃用/转让需求已回复并标记为已处理。');
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
        if (! $context['user']->isAdministrator()) {
            abort_unless(in_array((int) $submission->operation_department_id, $this->storeOwners->visibleOperationDepartmentIds($context['user']), true), 403);
        }
    }
    private function context(Request $request):array|Response{$context=$this->portalEntries->portalContext($request);if($context instanceof Response)return $context;$this->authorization->assertAssistantPortal($context['portal']);abort_unless($this->authorization->isAssistantDepartmentMember($context['user']),403);return $context;}
}
