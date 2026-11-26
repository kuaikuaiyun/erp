<?php

namespace App\Admin\Controllers\ConfigurationCenter;

use App\Http\Controllers\Controller;
use App\Models\DingTalkCompany;
use App\Models\ErpLoginSite;
use App\Services\Authentication\LoginSiteService;
use App\Services\Authentication\LoginSiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ErpLoginSiteController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $site = $request->integer('site');

        return redirect(admin_base_path('configuration/dingtalk').($site ? '?site='.$site : ''));
    }

    public function save(Request $request, LoginSiteService $domains): RedirectResponse
    {
        $settings = app(LoginSiteSettings::class);
        $logo = null;
        try {
            [$site, $previousDomain, $oldLogo] = DB::transaction(function () use ($request, $settings, &$logo): array {
                $sites = ErpLoginSite::query()->orderBy('id')->lockForUpdate()->get();
                $site = $request->integer('id') ? $sites->find($request->integer('id')) : new ErpLoginSite();
                abort_unless($site, 404);
                $previousDomain = $site->domain;
                $data = $settings->validate($request, $site, $sites, [
                    'dingtalk_company_id' => ['nullable', 'integer', 'exists:dingtalk_companies,id', Rule::unique('erp_login_sites')->ignore($site->id)],
                ]);
                $data['dingtalk_company_id'] ??= $site->dingtalk_company_id;
                if ($site->dingtalk_company_id && (int) $data['dingtalk_company_id'] !== (int) $site->dingtalk_company_id) {
                    throw ValidationException::withMessages(['dingtalk_company_id' => __('enterprise_login.association_mismatch')]);
                }
                $company = DingTalkCompany::query()->lockForUpdate()->find($data['dingtalk_company_id']);
                $settings->validateDingTalk($data, $company);
                $logo ??= $settings->uploadLogo($request);
                $oldLogo = $settings->persist($site, $data, $logo, $request->boolean('remove_logo'));

                return [$site, $previousDomain, $oldLogo];
            }, 3);
        } catch (Throwable $exception) {
            $settings->deleteLogo($logo);
            throw $exception;
        }

        $settings->deleteLogo($oldLogo);
        admin_toastr(__('enterprise_login.saved'));

        $path = admin_base_path('configuration/dingtalk').'?site='.$site->id;
        $host = strtolower($request->getHost());
        if ($host === $previousDomain || $host === $site->rootDomain() || $domains->subdomainLabel($site->rootDomain(), $host) !== null) {
            return redirect()->to(config('portals.scheme').'://'.$site->domain.$path);
        }

        return redirect($path);
    }
}
