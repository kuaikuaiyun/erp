<?php

namespace App\Services\DingTalk;

use App\Models\DingTalkCompany;
use App\Portal\Services\PortalDomainService;
use App\Services\Authentication\LoginSiteLogo;
use App\Services\Authentication\LoginSiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LoginEntryService
{
    public function url(Request $request): string
    {
        return app(PortalDomainService::class)->mainAdminUrl('auth/login');
    }

    public function logoUrl(DingTalkCompany $company): ?string
    {
        if (!$company->login_logo_path || !str_starts_with($company->login_logo_path, 'dingtalk/company-logos/')) {
            return null;
        }

        return Storage::disk($company->login_logo_disk ?: config('admin.upload.disk', 'admin'))->url($company->login_logo_path);
    }

    public function viewData(Request $request): array
    {
        $site = app(LoginSiteService::class)->forRequest($request);
        $name = $site?->display_name ?? config('admin.name');

        return [
            'brandName' => $name,
            'brandLogo' => app(LoginSiteLogo::class)->url($site?->logo_path),
            'pageTitle' => __('enterprise_login.heading').' · '.$name,
        ];
    }
}
