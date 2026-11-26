<?php

namespace App\Services\Authentication;

use App\Models\DingTalkCompany;
use App\Models\ErpLoginSite;
use App\Models\Portal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class LoginSiteSettings
{
    public function __construct(private LoginSiteService $domains, private LoginSiteDomainRoot $roots)
    {
    }

    public function validate(Request $request, ErpLoginSite $site, Collection $sites, array $extraRules = []): array
    {
        $domain = $request->input('domain');
        if (is_string($domain)) {
            $request->merge(['domain' => strtolower(trim($domain))]);
        }

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])$/D',
                Rule::unique('erp_login_sites')->ignore($site->id)],
            'display_name' => ['required', 'string', 'max:190'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'remove_logo' => ['nullable', 'boolean'],
            'password_enabled' => ['nullable', 'boolean'],
            'dingtalk_enabled' => ['nullable', 'boolean'],
        ] + $extraRules, [
            'domain.regex' => __('enterprise_login.invalid_domain'),
            'domain.unique' => __('enterprise_login.domain_taken'),
        ], __('enterprise_login.fields') + __('dingtalk.company.fields'));

        $data['password_enabled'] = $request->boolean('password_enabled');
        $data['dingtalk_enabled'] = $request->boolean('dingtalk_enabled');
        if (!$data['password_enabled'] && !$data['dingtalk_enabled']) {
            throw ValidationException::withMessages(['password_enabled' => __('enterprise_login.method_required')]);
        }

        $data['root_domain'] = $site->exists && $site->domain === $data['domain'] && $site->root_domain !== null
            ? $site->rootDomain() : $this->roots->infer($data['domain']);
        if ($data['root_domain'] === null) {
            throw ValidationException::withMessages(['domain' => __('enterprise_login.public_suffix')]);
        }
        $rootDomain = $data['root_domain'];
        $roots = $sites->reject(fn (ErpLoginSite $other): bool => $other->is($site))
            ->map(fn (ErpLoginSite $other): string => $other->rootDomain());
        $legacy = strtolower(trim((string) config('portals.root_domain', '')));
        if ($legacy !== $rootDomain) {
            $roots->push($legacy);
        }
        foreach ($roots as $root) {
            if ($this->domains->contains($root, $rootDomain) || $this->domains->contains($rootDomain, $root)) {
                throw ValidationException::withMessages(['domain' => __('enterprise_login.domain_overlap')]);
            }
        }

        $prefix = $this->domains->subdomainLabel($rootDomain, $data['domain']);
        if ($prefix !== null && Portal::query()->where('code', $prefix)->exists()) {
            throw ValidationException::withMessages(['domain' => __('enterprise_login.portal_code_conflict', ['code' => $prefix])]);
        }

        if ($request->hasFile('logo') && $request->boolean('remove_logo')) {
            throw ValidationException::withMessages(['logo' => __('dingtalk.entry.logo_conflict')]);
        }

        return $data;
    }

    public function validateDingTalk(array $data, ?DingTalkCompany $company): void
    {
        if (!$data['dingtalk_enabled']) {
            return;
        }

        $callback = $this->domains->callback($data['domain']);
        if (!$company?->enabled || $company->trashed() || !hash_equals($callback, (string) $company->redirect_uri)
            || (app()->isProduction() && !str_starts_with($callback, 'https://'))) {
            throw ValidationException::withMessages(['dingtalk_enabled' => __('enterprise_login.dingtalk_unavailable')]);
        }
    }

    public function uploadLogo(Request $request): ?string
    {
        if (!$request->hasFile('logo')) {
            return null;
        }

        $path = $request->file('logo')->store('erp/login-site-logos', 'public');
        if (!$path) {
            throw ValidationException::withMessages(['logo' => __('dingtalk.entry.logo_failed')]);
        }

        return $path;
    }

    public function deleteLogo(?string $path): void
    {
        if (!$path || !str_starts_with($path, 'erp/login-site-logos/')) {
            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function persist(ErpLoginSite $site, array $data, ?string $logo, bool $removeLogo): ?string
    {
        unset($data['logo'], $data['remove_logo'], $data['name']);
        $previousRoot = $site->exists ? $site->rootDomain() : null;
        $site->fill($data);
        $rootChanged = $previousRoot !== $site->rootDomain();
        if ($site->exists && ($rootChanged || $site->isDirty(['domain', 'password_enabled']))) {
            $site->password_version++;
        }
        if ($site->exists && ($rootChanged || $site->isDirty(['domain', 'dingtalk_enabled', 'dingtalk_company_id']))) {
            $site->dingtalk_version++;
        }

        $oldLogo = null;
        if ($logo || $removeLogo) {
            $oldLogo = $site->logo_path;
            $site->logo_path = $logo;
        }
        $site->save();

        return $oldLogo;
    }
}
