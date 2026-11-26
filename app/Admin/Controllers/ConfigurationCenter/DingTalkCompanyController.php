<?php
namespace App\Admin\Controllers\ConfigurationCenter;

use App\Http\Controllers\Controller;
use App\Models\DingTalkBinding;
use App\Models\DingTalkCompany;
use App\Models\DingTalkIdentity;
use App\Models\ErpLoginSite;
use App\Portal\Services\PortalDomainService;
use App\Services\Authentication\LoginSiteLogo;
use App\Services\Authentication\LoginSiteService;
use App\Services\Authentication\LoginSiteSettings;
use App\Services\DingTalk\AuditService;
use App\Services\DingTalk\DirectoryService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class DingTalkCompanyController extends Controller
{
    private function cards(bool $canConfigureLogin): array
    {
        $sites = $canConfigureLogin ? app(LoginSiteService::class)->sites() : collect();
        $linked = $sites->whereNotNull('dingtalk_company_id')->keyBy('dingtalk_company_id');
        $cards = DingTalkCompany::withTrashed()->orderBy('id')->get()->map(function (DingTalkCompany $company) use ($linked): array {
            $identities = DingTalkIdentity::query()->where('corp_id', $company->corp_id)->where('active', true)
                ->whereHas('bindings', fn ($q) => $q->where('active', true)->whereHas('account', fn ($a) => $a->where('status', 1)));
            return [
                'id' => $company->id,
                'name' => $company->name,
                'corp_id' => $company->corp_id,
                'app_key' => $company->app_key,
                'archived' => $company->trashed(),
                'redirect_uri' => $company->redirect_uri,
                'enabled' => $company->enabled,
                'login_site' => $this->siteCard($linked->get($company->id)),
                'member_count' => $company->member_count,
                'paired_members' => (clone $identities)->count(),
                'paired_accounts' => DingTalkBinding::query()->where('active', true)
                    ->whereIn('identity_id', (clone $identities)->select('id'))->distinct()->count('account_id'),
                'synced_at' => $company->directory_synced_at?->format('Y-m-d H:i:s'),
                'sync_pending' => (bool) $company->directory_state,
            ];
        })->all();

        foreach ($sites->whereNull('dingtalk_company_id') as $site) {
            $cards[] = [
                'id' => null,
                'name' => $site->company_name ?: $site->display_name,
                'archived' => false,
                'login_site' => $this->siteCard($site),
            ];
        }

        return $cards;
    }

    private function siteCard(?ErpLoginSite $site): ?array
    {
        if (!$site) {
            return null;
        }

        return [
            'id' => $site->id,
            'domain' => $site->domain,
            'root_domain' => $site->rootDomain(),
            'display_name' => $site->display_name,
            'logo_url' => app(LoginSiteLogo::class)->url($site->logo_path),
            'password_enabled' => $site->password_enabled,
            'dingtalk_enabled' => $site->dingtalk_enabled,
        ];
    }

    public function index(Content $content)
    {
        $canConfigureLogin = (bool) Admin::user()?->isAdministrator();
        $cards = $this->cards($canConfigureLogin);
        if (request()->expectsJson()) {
            return response()->json(['companies' => $cards])->header('Cache-Control', 'no-store');
        }

        $options = [
            'companies' => $cards,
            'save_url' => admin_url('configuration/dingtalk/companies'),
            'base_url' => admin_url('configuration/dingtalk'),
            'csrf' => csrf_token(),
            'callback_url' => app(PortalDomainService::class)->mainUrl('/auth/dingtalk/callback'),
            'callback_template' => app(LoginSiteService::class)->callback(':domain'),
            'open_site' => $canConfigureLogin ? request()->integer('site') : 0,
            'can_configure_login' => $canConfigureLogin,
        ];
        $messages = array_merge(__('dingtalk.company'), __('dingtalk.manage'), __('dingtalk.workspace'), __('dingtalk.entry'), __('enterprise_login.editor'));

        return $content->title(__('dingtalk.company.title'))
            ->body(view('admin.dingtalk.companies', compact('options', 'messages', 'canConfigureLogin'))->render());
    }

    public function save(Request $request)
    {
        if ($request->boolean('login_configuration')) {
            abort_unless(Admin::user()?->isAdministrator(), 403, __('organization.authorization.super_admin_only'));

            return $this->saveEnterprise($request, app(LoginSiteSettings::class), app(LoginSiteService::class));
        }

        return $this->saveDingTalkCompany($request);
    }

    private function saveEnterprise(Request $request, LoginSiteSettings $settings, LoginSiteService $domains)
    {
        $ids = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'site_id' => ['nullable', 'integer', 'min:1'],
            'existing_company_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $logo = null;
        try {
            [$site, $company, $oldLogo, $previousDomain, $previousRoot] = DB::transaction(function () use ($request, $settings, $domains, $ids, &$logo): array {
                $sites = ErpLoginSite::query()->orderBy('id')->lockForUpdate()->get();
                $companyId = $ids['id'] ?? null;
                $site = isset($ids['site_id']) ? $sites->find($ids['site_id']) : null;
                abort_if(isset($ids['site_id']) && !$site, 404);
                if ($site && $companyId && (int) $site->dingtalk_company_id !== (int) $companyId) {
                    throw ValidationException::withMessages(['site_id' => __('enterprise_login.association_mismatch')]);
                }
                $existingId = $ids['existing_company_id'] ?? null;
                if ($existingId && (!$site || $companyId || $site->dingtalk_company_id || !$request->boolean('dingtalk_enabled')
                    || $sites->firstWhere('dingtalk_company_id', $existingId))) {
                    throw ValidationException::withMessages(['existing_company_id' => __('enterprise_login.association_mismatch')]);
                }
                $companyId ??= $existingId ?? $site?->dingtalk_company_id;
                $site ??= $companyId ? $sites->firstWhere('dingtalk_company_id', $companyId) : null;
                $site ??= new ErpLoginSite();
                $previousDomain = $site->domain;
                $previousRoot = $site->exists ? $site->rootDomain() : null;

                $company = $companyId ? DingTalkCompany::withTrashed()->lockForUpdate()->findOrFail($companyId) : null;
                if ($existingId && $company->trashed()) {
                    throw ValidationException::withMessages(['existing_company_id' => __('enterprise_login.association_mismatch')]);
                }
                $data = $settings->validate($request, $site, $sites, ['name' => ['required', 'string', 'max:190']]);
                $isEdit = $site->exists || $company !== null;
                if ($company || $data['dingtalk_enabled']) {
                    $company ??= new DingTalkCompany();
                    $credentials = $request->validate($this->credentialRules($company), [
                        'corp_id.unique' => __('dingtalk.company.corp_exists'),
                    ], __('dingtalk.company.fields'));
                    $this->validateCorpId($company, $credentials['corp_id']);
                    $credentials['name'] = $data['name'];
                    $credentials['redirect_uri'] = $domains->callback($data['domain']);
                    $this->persistCompany($company, $credentials);
                }

                $settings->validateDingTalk($data, $company);
                $data['company_name'] = $data['name'];
                $data['dingtalk_company_id'] = $company?->id;
                $logo ??= $settings->uploadLogo($request);
                $oldLogo = $settings->persist($site, $data, $logo, $request->boolean('remove_logo'));

                app(AuditService::class)->record($isEdit ? 'company.updated' : 'company.created', null, Admin::user()->getKey(), [
                    'target_corp_id' => $company?->corp_id,
                    'company_id' => $company?->id,
                    'login_site_id' => $site->id,
                    'domain' => $site->domain,
                    'password_enabled' => $site->password_enabled,
                    'dingtalk_enabled' => $site->dingtalk_enabled,
                ]);

                return [$site, $company, $oldLogo, $previousDomain, $previousRoot];
            }, 3);
        } catch (Throwable $exception) {
            $settings->deleteLogo($logo);
            throw $exception;
        }

        $settings->deleteLogo($oldLogo);

        $host = strtolower($request->getHost());
        $domainChanged = $previousDomain !== $site->domain || $previousRoot !== $site->rootDomain();
        $sessionScopeChanged = $domainChanged && ($previousDomain === $host || $site->domain === $host
            || ($previousDomain === null && ($host === $site->rootDomain() || $domains->subdomainLabel($site->rootDomain(), $host) !== null)));
        $redirect = $sessionScopeChanged
            ? config('portals.scheme').'://'.$site->domain.'/'.ltrim(admin_base_path('configuration/dingtalk'), '/') : null;

        return response()->json([
            'message' => __('enterprise_login.saved'),
            'id' => $company?->id,
            'login_site' => $this->siteCard($site),
            'redirect_url' => $redirect,
        ]);
    }

    private function credentialRules(DingTalkCompany $company): array
    {
        return [
            'corp_id' => ['required', 'string', 'max:128', Rule::unique('dingtalk_companies', 'corp_id')->ignore($company->id)],
            'app_key' => ['required', 'string', 'max:190'],
            'app_secret' => [$company->exists ? 'nullable' : 'required', 'string', 'max:2048'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    private function validateCorpId(DingTalkCompany $company, string $corpId): void
    {
        if ($company->exists && !hash_equals($company->corp_id, $corpId)) {
            throw ValidationException::withMessages(['corp_id' => __('dingtalk.company.corp_immutable')]);
        }
    }

    private function persistCompany(DingTalkCompany $company, array $data): void
    {
        $isUpdate = $company->exists;
        $authChanged = $company->trashed();
        if ($company->trashed()) {
            $company->restore();
        }

        foreach ($data as $key => $value) {
            if ($key === 'app_secret' && ($value === null || $value === '')) {
                continue;
            }
            if (!in_array($key, ['name', 'login_display_name', 'login_page_title', 'login_welcome'], true)
                && (string) $company->getAttribute($key) !== (string) $value) {
                $authChanged = true;
            }
            $company->setAttribute($key, $value);
        }
        if ($isUpdate && $authChanged) {
            $company->forceFill(['version' => $company->version + 1, 'directory_state' => null,
                'directory_generation' => null, 'directory_synced_at' => null, 'member_count' => null]);
        }
        $company->save();
        if ($isUpdate && $authChanged) {
            DB::table('dingtalk_directory_members')->where('company_id', $company->id)->delete();
        }
    }

    private function linkedSiteForUpdate(int $company): ?ErpLoginSite
    {
        return Schema::hasTable('erp_login_sites')
            ? ErpLoginSite::query()->where('dingtalk_company_id', $company)->lockForUpdate()->first() : null;
    }

    private function disableSiteScan(?ErpLoginSite $site): void
    {
        if (!$site?->dingtalk_enabled) {
            return;
        }
        if (!$site->password_enabled) {
            throw ValidationException::withMessages(['enabled' => __('enterprise_login.password_before_disabling')]);
        }

        $site->dingtalk_enabled = false;
        $site->dingtalk_version++;
        $site->save();
    }

    private function saveDingTalkCompany(Request $request)
    {
        $id=$request->integer('id');
        $company=$id ? DingTalkCompany::withTrashed()->findOrFail($id) : new DingTalkCompany();
        $data=$request->validate($this->credentialRules($company) + [
            'name'=>['required','string','max:190'], 'redirect_uri'=>['required','url','max:2048'],
            'login_display_name'=>['nullable','string','max:190'], 'login_page_title'=>['nullable','string','max:190'],
            'login_welcome'=>['nullable','string','max:500'], 'logo_remove'=>['nullable','boolean'],
            'logo'=>['nullable','image','mimes:png,jpg,jpeg,webp','max:2048','dimensions:max_width=4096,max_height=4096'],
        ],['corp_id.unique'=>__('dingtalk.company.corp_exists')],__('dingtalk.company.fields'));
        $this->validateCorpId($company, $data['corp_id']);
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
            $savedId = DB::transaction(function () use ($id,$data,$newLogo,$disk,$removeLogo,&$oldLogo): int {
                $site = $id && !$data['enabled'] ? $this->linkedSiteForUpdate($id) : null;
                $current = $id ? DingTalkCompany::withTrashed()->lockForUpdate()->findOrFail($id) : new DingTalkCompany();
                $expected = app(PortalDomainService::class)->mainUrl('/auth/dingtalk/callback');
                if (!app(LoginSiteService::class)->acceptsCallback($current, $data['redirect_uri'], $expected)
                    || (app()->isProduction() && !str_starts_with($data['redirect_uri'], 'https://'))) {
                    throw ValidationException::withMessages(['redirect_uri' => __('dingtalk.company.callback_mismatch', ['url' => $expected])]);
                }
                $this->disableSiteScan($site);
                if ($newLogo || $removeLogo) {
                    $oldLogo = [$current->login_logo_disk ?: $disk, $current->login_logo_path];
                    $current->login_logo_path = $newLogo;
                    $current->login_logo_disk = $newLogo ? $disk : null;
                }
                $this->persistCompany($current, $data);
                app(AuditService::class)->record($id ? 'company.updated' : 'company.created',null,null,['target_corp_id'=>$current->corp_id,'company_id'=>$current->id]);

                return $current->id;
            },3);
        } catch (\Throwable $exception) {
            if ($newLogo) $this->deleteLogo($disk, $newLogo);
            throw $exception;
        }
        if ($oldLogo && $oldLogo[1]) $this->deleteLogo($oldLogo[0], $oldLogo[1]);
        return response()->json(['message'=>__('dingtalk.company.saved'),'id'=>$savedId]);
    }
    private function deleteLogo(string $disk, string $path): void
    {
        if (!str_starts_with($path, 'dingtalk/company-logos/')) return;
        try { Storage::disk($disk)->delete($path); } catch (\Throwable $exception) { report($exception); }
    }

    public function destroy(int $company)
    {
        DB::transaction(function () use ($company): void {
            $site = $this->linkedSiteForUpdate($company);
            $record=DingTalkCompany::query()->lockForUpdate()->findOrFail($company);
            $this->disableSiteScan($site);
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
