<?php
namespace App\Admin\Controllers;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\DingTalkCompany;
use App\Services\DingTalk\BindingAccessService;
use App\Services\DingTalk\BindingService;
use App\Services\DingTalk\DirectoryService;
use Illuminate\Http\Request;

class DingTalkBindingController extends Controller
{
    public function options(int $account,BindingAccessService $access)
    {
        $target=AdminUser::query()->findOrFail($account); $access->assertAccount($target);
        $companies=DingTalkCompany::withTrashed()->get(['id','name','corp_id'])->keyBy('corp_id');
        $removableCompanies=$access->isOwner() ? null : \App\Models\DingTalkCompanyGrant::query()->where('user_id',\Encore\Admin\Facades\Admin::user()->id)->pluck('company_id')->map(fn ($id)=>(int)$id)->all();
        $bindings=$access->bindings()->where('account_id',$account)->with('identity')->orderByDesc('id')->get()->map(fn ($b)=>[
            'id'=>$b->id,'name'=>$b->identity?->name,'user_id'=>$b->identity?->user_id,
            'company'=>$companies->get($b->identity?->corp_id)?->name ?? $b->identity?->corp_id,
            'active'=>(bool)($b->active && $b->identity?->active),
            'removable'=>(bool)$b->active && ($removableCompanies===null || in_array((int)$companies->get($b->identity?->corp_id)?->id,$removableCompanies,true))]);
        return response()->json(['account'=>['id'=>$target->id,'name'=>$target->name,'username'=>$target->username],
            'companies'=>$access->companies(),'bindings'=>$bindings])->header('Cache-Control','no-store');
    }
    public function store(Request $request,BindingAccessService $access,BindingService $service)
    {
        $input=$request->validate(['account_id'=>['required','integer','min:1'],'company_id'=>['required','integer','min:1'],'user_id'=>['required','string','max:128']]);
        $access->assertAccount(AdminUser::query()->findOrFail($input['account_id']));
        $company=app(\App\Services\DingTalk\CompanyService::class)->enabled((int)$input['company_id']); $access->assertCorp($company->corp_id);
        $service->bind((int)$input['account_id'],$input['user_id'],false,(int)$company->id);
        return response()->json(['message'=>__('dingtalk.bindings.saved')]);
    }
    public function destroy(int $binding,BindingAccessService $access,BindingService $service)
    {
        $record=$access->bindings()->findOrFail($binding); $access->assertAccount($record->account()->firstOrFail()); $service->unbind($record->id);
        return response()->json(['message'=>__('dingtalk.bindings.removed')]);
    }
    public function directory(Request $request,BindingAccessService $access,DirectoryService $directory)
    {
        $input=$request->validate(['company_id'=>['required','integer','min:1'],'department'=>['required','integer','min:1'],'cursor'=>['required','integer','min:0'],'refresh'=>['nullable','boolean']]);
        $company=app(\App\Services\DingTalk\CompanyService::class)->enabled((int)$input['company_id']); $access->assertCorp($company->corp_id);
        $result=$directory->members($company->corp_id,(int)$input['department'],(int)$input['cursor'],$request->boolean('refresh'));
        $result['departments']=$directory->departments($company->corp_id,(int)$input['department'],$request->boolean('refresh'));
        $access->assertCorp($company->corp_id);
        return response()->json($result)->header('Cache-Control','no-store');
    }
    public function audits(Request $request,BindingAccessService $access)
    {
        $input=$request->validate(['account_id'=>['required','integer','min:1'],'kind'=>['required','in:login,operation']]);
        $query=$access->accountAudits(AdminUser::query()->findOrFail($input['account_id']),$input['kind']);
        $audits=$query->latest('id')->paginate(20);
        return response()->json(['rows'=>$audits->getCollection()->map(fn ($a)=>[
            'id'=>$a->id,'time'=>$a->created_at?->format('Y-m-d H:i:s'), 'action'=>__('dingtalk.audits.actions')[$a->action] ?? $a->action,
            'actor'=>$a->actor_name,'ip'=>$a->ip,'source'=>__('dingtalk.audits.values')[$a->metadata['actor_type'] ?? 'system'] ?? '',
            'details'=>implode(' / ',array_filter([$a->metadata['target_company_name'] ?? $a->corp_id,$a->metadata['target_name'] ?? null,
                $a->metadata['target_user_id'] ?? $a->user_id],fn ($value)=>$value!==null && $value!=='')),
        ]),'page'=>$audits->currentPage(),'last_page'=>$audits->lastPage()])->header('Cache-Control','no-store');
    }
}
