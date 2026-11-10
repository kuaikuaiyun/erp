<?php

namespace App\Portals\OperationTasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\OperationAssetDailyRecord;
use App\Models\OperationTaskAsset;
use App\Models\OperationTaskRecap;
use App\Models\OperationTaskReview;
use App\Models\OperationTestTask;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Services\DepartmentAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class OperationTaskController extends Controller
{
    private const PORTAL_CODE = 'operation-tasks';
    private const MEDIA = ['Mintegral','AppLovin','Google','Snapchat','MediaGo','SmartNews','Pinterest','BrainX','Bigo','NewsBreak','Facebook','TikTok','其他','Google老户','TikTok加白户'];

    public function __construct(private readonly PortalEntryController $portalEntries, private readonly DepartmentAuthorizationService $departments) {}

    public function recaps(Request $request): Response|View
    {
        $context = $this->context($request); if ($context instanceof Response) return $context;
        $yesterday = now('Asia/Shanghai')->subDay()->format('Y-m-d');
        $yesterdayRecap = OperationTaskRecap::query()
            ->where('user_id', $context['user']->id)
            ->whereDate('data_date', $yesterday)
            ->first();
        $query = OperationTaskRecap::query()->orderByDesc('data_date')->orderByDesc('id');
        if (! $this->canManage($context['user'])) $query->where('user_id', $context['user']->id);
        elseif (! $context['user']->isAdministrator()) $query->whereIn('department_id', $this->visibleDepartmentIds($context['user']));
        return view('portals.operation-tasks.recaps', [...$context,
            'recaps' => $query->paginate(20)->withQueryString(),
            'mediaOptions' => self::MEDIA,
            'yesterday' => $yesterday,
            'yesterdayRecap' => $yesterdayRecap,
        ]);
    }

    public function storeRecap(Request $request): RedirectResponse
    {
        $context = $this->authorized($request); $user = $context['user'];
        $data = $request->validate([
            'data_date' => ['required','date','before_or_equal:today'], 'media' => ['required','array','min:1'],
            'media.*.name' => ['required','string','max:100'], 'media.*.custom_name' => ['nullable','string','max:100'],
            'media.*.spend' => ['required','numeric','min:0'], 'media.*.orders' => ['required','integer','min:0'], 'media.*.revenue' => ['required','numeric','min:0'],
            'finding_answer' => ['required','string','max:10000'], 'action_answer' => ['required','string','max:10000'],
            'advertising_plan' => ['required','string','max:10000'], 'support_needed' => ['nullable','string','max:10000'],
        ]);
        $items = collect($data['media'])->map(function(array $row): array {
            $name = $row['name'] === '其他' ? trim((string)($row['custom_name'] ?? '')) : $row['name'];
            abort_if($name === '', 422, '选择“其他”时请填写媒体名称。');
            return ['name'=>$name,'spend'=>(float)$row['spend'],'orders'=>(int)$row['orders'],'revenue'=>(float)$row['revenue'],'roi'=>(float)$row['spend']>0?(float)$row['revenue']/(float)$row['spend']:null];
        });
        abort_if($items->pluck('name')->map(fn($v)=>mb_strtolower(trim($v)))->duplicates()->isNotEmpty(), 422, '同一份复盘中不能重复添加相同媒体。');
        $department = $this->department($user); $existing = OperationTaskRecap::query()->where('user_id',$user->id)->whereDate('data_date',$data['data_date'])->first();
        $payload = ['data_date'=>$data['data_date'],'user_id'=>$user->id,'user_name'=>$user->name?:$user->username,'department_id'=>$department?->id,'department_name'=>$department?->name,'media_items'=>$items->values()->all(),'total_revenue_usd'=>$items->sum('revenue'),'total_orders'=>$items->sum('orders'),'total_spend_usd'=>$items->sum('spend'),'finding_answer'=>trim($data['finding_answer']),'action_answer'=>trim($data['action_answer']),'advertising_plan'=>trim($data['advertising_plan']),'support_needed'=>trim((string)($data['support_needed']??''))?:null];
        if ($existing) { $history=$existing->version_history??[];$history[]=['version'=>$existing->version,'snapshot'=>$existing->only(array_keys($payload)),'changed_by'=>$user->name?:$user->username,'changed_at'=>now()->toIso8601String()];$existing->update([...$payload,'version'=>$existing->version+1,'version_history'=>$history]); }
        else OperationTaskRecap::query()->create($payload);
        return back()->with('success',$existing?'复盘已更新，旧版本已保留。':'昨日复盘已保存。');
    }

    public function tests(Request $request): Response|View
    {
        $context=$this->context($request);if($context instanceof Response)return $context;
        $query=OperationTestTask::query()->withCount('assets')->with(['reviews','assets.reviews'])->latest('submitted_at');
        if(!$this->canManage($context['user']))$query->where('owner_user_id',$context['user']->id);
        elseif(!$context['user']->isAdministrator())$query->whereIn('department_id',$this->visibleDepartmentIds($context['user']));
        return view('portals.operation-tasks.tests',[...$context,'tasks'=>$query->paginate(18)->withQueryString()]);
    }

    public function storeTest(Request $request): RedirectResponse
    {
        $context=$this->authorized($request);$user=$context['user'];$pageType=(string)$request->input('page_type');$minimum=$pageType==='category'?3:2;
        $data=$request->validate(['page_type'=>['required',Rule::in(['single','category'])],'task_type'=>['required',Rule::in(['new','iteration'])],'landing_url'=>['required','url','max:2048'],'selection_objective'=>['required','string','max:10000'],'assets'=>['required','array','min:'.$minimum],'assets.*.selling_point'=>['required','string','max:1000'],'assets.*.file'=>['nullable','file','mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm','max:51200'],'assets.*.url'=>['nullable','url','max:2048'],'ai_confirmed'=>['accepted']]);
        foreach($data['assets'] as $i=>$asset)abort_if(!$request->hasFile("assets.$i.file")&&empty($asset['url']),422,'每条素材必须上传文件或填写素材链接。');
        $existing=OperationTestTask::query()->where('owner_user_id',$user->id)->where('landing_url',$data['landing_url'])->first();
        if($existing)return back()->withInput()->withErrors(['landing_url'=>'该落地页已有任务，请进入原任务追加素材或迭代。']);
        $department=$this->department($user);$connection=config('admin.database.connection')?:config('database.default');
        DB::connection($connection)->transaction(function()use($data,$request,$user,$department):void{$task=OperationTestTask::query()->create(['task_no'=>'OT'.now()->format('YmdHis').str_pad((string)random_int(1,999),3,'0',STR_PAD_LEFT),'owner_user_id'=>$user->id,'owner_name'=>$user->name?:$user->username,'department_id'=>$department?->id,'department_name'=>$department?->name,'page_type'=>$data['page_type'],'task_type'=>$data['task_type'],'landing_url'=>$data['landing_url'],'selection_objective'=>trim($data['selection_objective']),'submitted_at'=>now()]);foreach($data['assets'] as $i=>$asset){$file=$request->file("assets.$i.file");OperationTaskAsset::query()->create(['task_id'=>$task->id,'uploader_user_id'=>$user->id,'uploader_name'=>$user->name?:$user->username,'selling_point'=>trim($asset['selling_point']),'file_path'=>$file?->store('operation-tasks/assets/'.now()->format('Y/m'),'local'),'original_name'=>$file?->getClientOriginalName(),'external_url'=>trim((string)($asset['url']??''))?:null,'mime_type'=>$file?->getMimeType(),'ai_confirmed'=>true]);}});
        return redirect()->route('operation-tasks.tests.index')->with('success','测品任务已提交；待审核期间可先登记投放。');
    }

    public function showTest(Request $request,OperationTestTask $task):Response|View
    { $context=$this->context($request);if($context instanceof Response)return $context;$this->authorizeTask($context['user'],$task);$task->load(['assets.dailyRecords','assets.reviews','reviews']);return view('portals.operation-tasks.test-detail',[...$context,'task'=>$task,'mediaOptions'=>self::MEDIA]); }

    public function assetFile(Request $request,OperationTaskAsset $asset):BinaryFileResponse
    { $context=$this->authorized($request);$asset->loadMissing('task');$this->authorizeTask($context['user'],$asset->task);abort_unless($asset->file_path&&Storage::disk('local')->exists($asset->file_path),404);return response()->file(Storage::disk('local')->path($asset->file_path)); }

    public function storeAssetDaily(Request $request,OperationTaskAsset $asset):RedirectResponse
    {
        $context=$this->authorized($request);$asset->loadMissing('task');$this->authorizeTask($context['user'],$asset->task);abort_if($asset->retired_at,422,'已淘汰素材不能继续填写数据。');
        $data=$request->validate(['media'=>['required','string','max:100'],'custom_media'=>['nullable','string','max:100'],'data_date'=>['required','date','before_or_equal:today'],'spend_usd'=>['required','numeric','min:0'],'orders'=>['required','integer','min:0'],'roi'=>['nullable','numeric','min:0'],'analysis'=>['required','string','max:5000'],'next_action'=>['required','string','max:5000']]);$media=$data['media']==='其他'?trim((string)($data['custom_media']??'')):$data['media'];abort_if($media==='',422,'请填写其他媒体名称。');
        $record=OperationAssetDailyRecord::query()->where(['asset_id'=>$asset->id,'media'=>$media])->whereDate('data_date',$data['data_date'])->first();$payload=['asset_id'=>$asset->id,'media'=>$media,'data_date'=>$data['data_date'],'spend_usd'=>$data['spend_usd'],'orders'=>$data['orders'],'roi'=>$data['roi']??null,'analysis'=>trim($data['analysis']),'next_action'=>trim($data['next_action']),'created_by'=>$context['user']->id];if($record){$history=$record->version_history??[];$history[]=['version'=>$record->version,'snapshot'=>$record->only(['spend_usd','orders','roi','analysis','next_action']),'changed_at'=>now()->toIso8601String()];$record->update([...$payload,'version'=>$record->version+1,'version_history'=>$history]);}else OperationAssetDailyRecord::query()->create($payload);return back()->with('success','素材每日数据已保存。');
    }

    public function reviews(Request $request):Response|View
    { $context=$this->context($request);if($context instanceof Response)return $context;abort_unless($this->canReview($context['user']),403);$query=OperationTestTask::query()->with(['assets.reviews','reviews'])->latest('submitted_at');if(!$context['user']->isAdministrator())$query->whereIn('department_id',$this->visibleDepartmentIds($context['user']));return view('portals.operation-tasks.reviews',[...$context,'tasks'=>$query->paginate(20)->withQueryString(),'canLeaderReview'=>$this->canManage($context['user']),'canDepartmentReview'=>$context['user']->isAdministrator()||$context['user']->can('portal.operation-tasks.review.department')]); }

    public function review(Request $request):RedirectResponse
    {
        $context=$this->authorized($request);$data=$request->validate(['target_type'=>['required',Rule::in(['task','asset'])],'target_id'=>['required','integer'],'channel'=>['required',Rule::in(['leader','department'])],'result'=>['required',Rule::in(['approved','rejected'])],'opinion'=>['nullable','string','max:5000'],'rejection_scope'=>['nullable',Rule::in(['task','asset'])]]);abort_if($data['result']==='rejected'&&trim((string)($data['opinion']??''))==='',422,'驳回必须填写具体整改意见。');abort_unless($data['channel']==='leader'?$this->canManage($context['user']):($context['user']->isAdministrator()||$context['user']->can('portal.operation-tasks.review.department')),403);$target=$data['target_type']==='task'?OperationTestTask::query()->findOrFail($data['target_id']):OperationTaskAsset::query()->with('task')->findOrFail($data['target_id']);$task=$data['target_type']==='task'?$target:$target->task;abort_unless($context['user']->isAdministrator()||in_array((int)$task->department_id,$this->visibleDepartmentIds($context['user']),true),403);$version=(int)$target->version;OperationTaskReview::query()->updateOrCreate(['target_type'=>$data['target_type'],'target_id'=>$target->id,'target_version'=>$version,'channel'=>$data['channel']],['result'=>$data['result'],'opinion'=>trim((string)($data['opinion']??''))?:null,'rejection_scope'=>$data['result']==='rejected'?($data['rejection_scope']??$data['target_type']):null,'reviewer_id'=>$context['user']->id,'reviewer_name'=>$context['user']->name?:$context['user']->username,'reviewed_at'=>now()]);if($data['result']==='rejected'){if($data['target_type']==='task')$target->update(['rectification_status'=>'required']);else $target->task()->update(['rectification_status'=>'required']);}return back()->with('success','审核结果已保存并绑定当前版本。');
    }

    public function dashboard(Request $request):Response|View
    { $context=$this->context($request);if($context instanceof Response)return $context;abort_unless($this->canManage($context['user']),403);$from=$request->date('from')?->format('Y-m-d')??now('Asia/Shanghai')->subDays(6)->format('Y-m-d');$to=$request->date('to')?->format('Y-m-d')??now('Asia/Shanghai')->format('Y-m-d');$recapQuery=OperationTaskRecap::query()->whereBetween('data_date',[$from,$to]);$taskQuery=OperationTestTask::query()->withCount(['assets'=>fn($q)=>$q->whereBetween('created_at',[$from.' 00:00:00',$to.' 23:59:59'])])->whereBetween('submitted_at',[$from.' 00:00:00',$to.' 23:59:59']);if(!$context['user']->isAdministrator()){$ids=$this->visibleDepartmentIds($context['user']);$recapQuery->whereIn('department_id',$ids);$taskQuery->whereIn('department_id',$ids);}return view('portals.operation-tasks.dashboard',[...$context,'from'=>$from,'to'=>$to,'recaps'=>$recapQuery->get(),'tasks'=>$taskQuery->get()]); }

    private function context(Request $request):array|Response{$context=$this->portalEntries->portalContext($request);if($context instanceof Response)return $context;abort_unless($context['portal']->code===self::PORTAL_CODE,404);return $context;}
    private function authorized(Request $request):array{$context=$this->context($request);if($context instanceof Response)abort($context->getStatusCode());return $context;}
    private function department(AdminUser $user){return $user->departments()->wherePivot('is_primary',true)->first()?:$user->departments()->first();}
    private function canManage(AdminUser $user):bool{return $user->isAdministrator()||$user->departments()->wherePivotIn('position',['manager','supervisor'])->exists();}
    private function canReview(AdminUser $user):bool{return $this->canManage($user)||$user->can('portal.operation-tasks.review.department');}
    private function visibleDepartmentIds(AdminUser $user):array{return $user->isAdministrator()?[]:$this->departments->managedDepartmentIds($user)->merge($user->departments()->pluck('id'))->map(fn($id)=>(int)$id)->unique()->values()->all();}
    private function authorizeTask(AdminUser $user,OperationTestTask $task):void{abort_unless($this->canManage($user)||(int)$task->owner_user_id===(int)$user->id,403);}
}
