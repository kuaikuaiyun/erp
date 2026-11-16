<?php
namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\DingTalkCompany;
use App\Models\DingTalkCompanyGrant;
use App\Services\DingTalk\AuditService;
use App\Services\DingTalk\BindingAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DingTalkCompanyGrantController extends Controller
{
    private function owner(): void { abort_unless(app(BindingAccessService::class)->isOwner(),403,__('dingtalk.manage.owner_only')); }
    public function index()
    {
        $this->owner();
        return response()->json(['companies'=>DingTalkCompany::query()->orderBy('name')->get(['id','name','enabled']),
            'managers'=>app(BindingAccessService::class)->managers()->map(fn ($u)=>['id'=>$u->id,'name'=>$u->name,'username'=>$u->username]),
            'grants'=>DingTalkCompanyGrant::query()->get(['company_id','user_id'])])->header('Cache-Control','no-store');
    }
    public function store(Request $request)
    {
        $this->owner();
        $data=$request->validate(['company_id'=>['required','integer','min:1'],'user_ids'=>['present','array'],'user_ids.*'=>['integer','distinct','min:2']]);
        DB::transaction(function () use ($data): void {
            $this->owner();
            $company=DingTalkCompany::query()->lockForUpdate()->findOrFail($data['company_id']);
            $ids=array_map('intval',$data['user_ids']); sort($ids);
            $targets=AdminUser::query()->whereIn('id',$ids)->lockForUpdate()->get();
            $eligible=app(BindingAccessService::class)->managers()->pluck('id')->map(fn ($id)=>(int)$id)->all();
            if ($targets->count()!==count($ids) || array_diff($ids,$eligible)) throw ValidationException::withMessages(['user_ids'=>__('dingtalk.company.managers_only')]);
            DingTalkCompanyGrant::query()->where('company_id',$company->id)->whereNotIn('user_id',$ids)->delete();
            foreach ($ids as $user) DingTalkCompanyGrant::query()->firstOrCreate(['company_id'=>$company->id,'user_id'=>$user],['created_by'=>1]);
            app(AuditService::class)->record('company.grants_changed',null,1,['company_id'=>$company->id,'target_corp_id'=>$company->corp_id,'accounts'=>$ids]);
        },3);
        return response()->json(['message'=>__('dingtalk.company.grants_saved')]);
    }
}
