<?php
namespace App\Admin\Controllers\ConfigurationCenter;

use App\Http\Controllers\Controller;
use App\Models\DingTalkCompany;
use App\Models\DingTalkIdentity;
use App\Services\DingTalk\AuditService;
use App\Services\DingTalk\DirectoryService;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DingTalkCompanyController extends Controller
{
    private function cards(): array
    {
        return DingTalkCompany::withTrashed()->orderBy('id')->get()->map(function ($company): array {
            $identities=DingTalkIdentity::query()->where('corp_id',$company->corp_id)->where('active',true)
                ->whereHas('bindings',fn ($q)=>$q->where('active',true)->whereHas('account',fn ($a)=>$a->where('status',1)));
            return ['id'=>$company->id,'name'=>$company->name,'corp_id'=>$company->corp_id,'app_key'=>$company->app_key,
                'login_slug'=>$company->login_slug,'login_url'=>app(\App\Services\DingTalk\LoginEntryService::class)->companyUrl($company),
                'login_display_name'=>$company->login_display_name,'login_page_title'=>$company->login_page_title,'login_welcome'=>$company->login_welcome,
                'logo_url'=>app(\App\Services\DingTalk\LoginEntryService::class)->logoUrl($company),
                'archived'=>$company->trashed(),'redirect_uri'=>$company->redirect_uri,'callback_owner_key'=>$company->callback_owner_key,'enabled'=>$company->enabled,
                'member_count'=>$company->member_count,'paired_members'=>(clone $identities)->count(),
                'paired_accounts'=>\App\Models\DingTalkBinding::query()->where('active',true)->whereIn('identity_id',(clone $identities)->select('id'))->distinct()->count('account_id'),
                'synced_at'=>$company->directory_synced_at?->format('Y-m-d H:i:s'), 'sync_pending'=>(bool)$company->directory_state,
                'events_url'=>app(\App\Portal\Services\PortalDomainService::class)->mainUrl('/auth/dingtalk/events').'?company='.$company->id];
        })->all();
    }
    public function index(Content $content)
    {
        $cards=$this->cards();
        if (request()->expectsJson()) return response()->json(['companies'=>$cards])->header('Cache-Control','no-store');
        $options=['companies'=>$cards,'save_url'=>admin_url('configuration/dingtalk/companies'),'base_url'=>admin_url('configuration/dingtalk'),'csrf'=>csrf_token(),'callback_url'=>app(\App\Portal\Services\PortalDomainService::class)->mainUrl('/auth/dingtalk/callback')];
        $messages=array_merge(__('dingtalk.company'),__('dingtalk.manage'),__('dingtalk.workspace'),__('dingtalk.entry'));
        return $content->title(__('dingtalk.company.title'))->body(view('admin.dingtalk.companies',compact('options','messages'))->render());
    }
    public function save(Request $request)
    {
        $id=$request->integer('id');
        $company=$id ? DingTalkCompany::withTrashed()->findOrFail($id) : new DingTalkCompany();
        $data=$request->validate([
            'name'=>['required','string','max:190'], 'corp_id'=>['required','string','max:128',Rule::unique('dingtalk_companies','corp_id')->ignore($id ?: null)],
            'app_key'=>['required','string','max:190'],'redirect_uri'=>['required','url','max:2048'], 'enabled'=>['required','boolean'],
            'callback_owner_key'=>['required','string','max:190'],
            'login_slug'=>['required','string','max:64','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',Rule::notIn(['login','logout','callback','password','admin']),Rule::unique('dingtalk_companies','login_slug')->ignore($id ?: null)],
            'login_display_name'=>['nullable','string','max:190'], 'login_page_title'=>['nullable','string','max:190'],
            'login_welcome'=>['nullable','string','max:500'], 'logo_remove'=>['nullable','boolean'],
            'logo'=>['nullable','image','mimes:png,jpg,jpeg,webp','max:2048','dimensions:max_width=4096,max_height=4096'],
            'app_secret'=>[$id ? 'nullable' : 'required','string','max:2048'], 'callback_token'=>[$id ? 'nullable' : 'required','string','max:128'],
            'callback_aes_key'=>[$id ? 'nullable' : 'required','string','size:43'],
        ],['corp_id.unique'=>__('dingtalk.company.corp_exists'),'login_slug.unique'=>__('dingtalk.entry.slug_exists'),'login_slug.regex'=>__('dingtalk.entry.slug_format')],__('dingtalk.company.fields'));
        if ($id && !hash_equals($company->corp_id,$data['corp_id'])) throw ValidationException::withMessages(['corp_id'=>__('dingtalk.company.corp_immutable')]);
        $expected=app(\App\Portal\Services\PortalDomainService::class)->mainUrl('/auth/dingtalk/callback');
        if (!hash_equals(rtrim($expected,'/'),rtrim($data['redirect_uri'],'/')) || (app()->environment('production') && !str_starts_with($data['redirect_uri'],'https://'))) {
            throw ValidationException::withMessages(['redirect_uri'=>__('dingtalk.company.callback_mismatch',['url'=>$expected])]);
        }
        if (!empty($data['callback_aes_key']) && strlen(base64_decode($data['callback_aes_key'].'=',true) ?: '')!==32) {
            throw ValidationException::withMessages(['callback_aes_key'=>__('dingtalk.company.invalid_aes')]);
        }
        if ($request->boolean('logo_remove') && $request->hasFile('logo')) {
            throw ValidationException::withMessages(['logo'=>__('dingtalk.entry.logo_conflict')]);
        }
        $newLogo = null;
        $disk = config('admin.upload.disk', 'admin');
        if ($request->hasFile('logo')) {
            $newLogo = $request->file('logo')->store('dingtalk/company-logos', $disk);
            if (!$newLogo) throw ValidationException::withMessages(['logo'=>__('dingtalk.entry.logo_failed')]);
        }
        $removeLogo = $request->boolean('logo_remove');
        unset($data['logo'], $data['logo_remove']);
        $oldLogo = null;
        try {
            DB::transaction(function () use ($id,$company,$data,$newLogo,$disk,$removeLogo,&$oldLogo): void {
                $current=$id ? DingTalkCompany::withTrashed()->lockForUpdate()->findOrFail($id) : $company;
                $authChanged=$current->trashed();
                if ($current->trashed()) $current->restore();
                if ($newLogo || $removeLogo) {
                    $oldLogo = [$current->login_logo_disk ?: $disk, $current->login_logo_path];
                    $current->login_logo_path = $newLogo;
                    $current->login_logo_disk = $newLogo ? $disk : null;
                }
                foreach ($data as $key=>$value) {
                    if (in_array($key,['app_secret','callback_token','callback_aes_key'],true) && ($value===null || $value==='')) continue;
                    if (!in_array($key,['name','login_slug','login_display_name','login_page_title','login_welcome'],true) && (string)$current->getAttribute($key)!==(string)$value) $authChanged=true;
                    $current->setAttribute($key,$value);
                }
                if ($id && $authChanged) $current->forceFill(['version'=>$current->version+1,'directory_state'=>null,'directory_generation'=>null,'directory_synced_at'=>null,'member_count'=>null]);
                $current->save();
                if ($id && $authChanged) DB::table('dingtalk_directory_members')->where('company_id',$current->id)->delete();
                app(AuditService::class)->record($id ? 'company.updated' : 'company.created',null,null,['target_corp_id'=>$current->corp_id,'company_id'=>$current->id]);
            },3);
        } catch (\Throwable $exception) {
            if ($newLogo) $this->deleteLogo($disk, $newLogo);
            throw $exception;
        }
        if ($oldLogo && $oldLogo[1]) $this->deleteLogo($oldLogo[0], $oldLogo[1]);
        return response()->json(['message'=>__('dingtalk.company.saved'),'id'=>$id ?: $company->id]);
    }
    private function deleteLogo(string $disk, string $path): void
    {
        if (!str_starts_with($path, 'dingtalk/company-logos/')) return;
        try { Storage::disk($disk)->delete($path); } catch (\Throwable $exception) { report($exception); }
    }

    public function destroy(int $company)
    {
        DB::transaction(function () use ($company): void {
            $record=DingTalkCompany::query()->lockForUpdate()->findOrFail($company);
            $record->forceFill(['enabled'=>false,'version'=>$record->version+1,'directory_state'=>null])->save(); $record->delete();
            app(AuditService::class)->record('company.archived',null,null,['target_corp_id'=>$record->corp_id,'company_id'=>$record->id]);
        },3);
        return response()->json(['message'=>__('dingtalk.company.archived')]);
    }
    public function sync(int $company,Request $request,DirectoryService $directory)
    {
        $record=app(\App\Services\DingTalk\CompanyService::class)->enabled($company);
        return response()->json($directory->syncStep($record,$request->boolean('restart')))->header('Cache-Control','no-store');
    }
    public function directory(int $company,Request $request,DirectoryService $directory)
    {
        $record=app(\App\Services\DingTalk\CompanyService::class)->enabled($company);
        $input=$request->validate(['department'=>['required','integer','min:1'],'cursor'=>['required','integer','min:0'],'refresh'=>['nullable','boolean']]);
        $page=$directory->companyMembers($record,(int)$input['department'],(int)$input['cursor'],$request->boolean('refresh'));
        $ids=array_column($page['members'],'user_id');
        $identities=DingTalkIdentity::query()->with(['bindings.account'])->where('corp_id',$record->corp_id)->whereIn('user_id',$ids)->get()->keyBy('user_id');
        foreach ($page['members'] as &$member) {
            $member['accounts']=($identities->get($member['user_id'])?->bindings ?? collect())->where('active',true)
                ->filter(fn ($b)=>$b->account && (int)$b->account->status===1 && $identities->get($member['user_id'])->active)
                ->map(fn ($b)=>['id'=>$b->account_id,'name'=>$b->account->name,'username'=>$b->account->username])->values()->all();
        }
        unset($member);
        $page['departments']=$directory->companyDepartments($record,(int)$input['department'],$request->boolean('refresh'));
        return response()->json($page)->header('Cache-Control','no-store');
    }
}
