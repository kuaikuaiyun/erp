<?php

namespace App\Services\DingTalk;

use App\Models\DingTalkCompany;
use App\Portal\Services\PortalDomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LoginEntryService
{
    public const KEY = 'dingtalk.entry_company';

    public function company(Request $request): ?DingTalkCompany
    {
        if (!$request->hasSession()) return null;
        $id = $request->session()->get(self::KEY)
            ?? $request->session()->get(LoginService::PROOF_KEY.'.company_id');
        if (!$id) return null;
        return DingTalkCompany::query()->where('enabled', true)->whereNotNull('login_slug')->find($id);
    }

    public function remember(Request $request, DingTalkCompany $company): void
    {
        // Routing hint only. Membership and login proofs are verified independently.
        $request->session()->put(self::KEY, (int) $company->id);
    }

    public function companyUrl(DingTalkCompany $company): ?string
    {
        return $company->login_slug
            ? app(PortalDomainService::class)->mainAdminUrl('auth/login/'.rawurlencode($company->login_slug)) : null;
    }

    public function url(Request $request): string
    {
        $base = app(PortalDomainService::class)->mainAdminUrl('auth/login');
        if (config('dingtalk.auth_mode') === 'password') return $base;
        $company = $this->company($request);
        return $company ? ($this->companyUrl($company) ?? $base) : $base;
    }

    public function logoUrl(DingTalkCompany $company): ?string
    {
        if (!$company->login_logo_path || !str_starts_with($company->login_logo_path, 'dingtalk/company-logos/')) return null;
        return Storage::disk($company->login_logo_disk ?: config('admin.upload.disk', 'admin'))->url($company->login_logo_path);
    }

    public function viewData(Request $request, ?DingTalkCompany $company = null, bool $useContext = true): array
    {
        if ($useContext) $company ??= $this->company($request);
        $name = $company ? ($company->login_display_name ?: $company->name) : config('admin.name');
        return [
            'brandName' => $name,
            'brandLogo' => $company ? $this->logoUrl($company) : null,
            'pageTitle' => $company?->login_page_title ?: __('dingtalk.login.title').' · '.$name,
            'welcome' => $company?->login_welcome ?: __('dingtalk.login.description'),
            'entryUrl' => $company ? $this->companyUrl($company) : null,
        ];
    }
}
