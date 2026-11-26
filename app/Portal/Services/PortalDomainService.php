<?php

namespace App\Portal\Services;

use App\Models\Portal;
use App\Services\Authentication\LoginSiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PortalDomainService
{
    public function isConfigured(): bool
    {
        return ($this->mainDomain() !== '' && $this->rootDomain() !== '') || app(LoginSiteService::class)->hasSites(request());
    }

    public function isMainRequest(Request $request): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }

        return hash_equals($this->mainDomain($request), $this->host($request));
    }

    public function portalForRequest(Request $request): ?Portal
    {
        if (!$this->isConfigured()) {
            return null;
        }

        if ($this->isMainRequest($request)) {
            foreach (config('portals.main_domain_paths', []) as $code => $prefix) {
                $path = $this->normalizePath($prefix);
                if ($request->getPathInfo() === $path || str_starts_with($request->getPathInfo(), $path.'/')) {
                    return Portal::query()->where('code', $code)->first();
                }
            }

            return null;
        }

        $code = $this->portalCodeFromHost($this->host($request), $this->rootDomain($request));

        if ($code === null) {
            return null;
        }

        return Portal::query()->where('code', $code)->first();
    }

    public function isReservedCode(string $code): bool
    {
        $code = strtolower(trim($code));
        $reserved = collect(config('portals.reserved_codes', []))
            ->filter()
            ->map(fn (string $value): string => strtolower($value));
        $mainLabel = $this->mainDomainLabel();

        if ($mainLabel !== null) {
            $reserved->push($mainLabel);
        }

        return $reserved->unique()->contains($code);
    }

    public function portalDomain(Portal|string $portal): ?string
    {
        if ($this->rootDomain() === '') {
            return null;
        }

        $code = $portal instanceof Portal ? $portal->code : $portal;

        return strtolower($code).'.'.$this->rootDomain();
    }

    public function portalUrl(Portal|string $portal, string $path = '/'): ?string
    {
        $host = $this->portalDomain($portal);

        $path = $this->normalizePath($path);

        $portalPath = $path === '/' || str_starts_with($path, '/?') ? $path : $this->portalPath($portal, $path);

        return $host === null ? null : $this->urlForHost($host, $portalPath);
    }

    public function portalPath(Portal|string $portal, string $path = '/'): string
    {
        $code = $portal instanceof Portal ? $portal->code : $portal;
        $prefix = config('portals.main_domain_paths', [])[$code] ?? null;
        $path = $this->normalizePath($path);
        if ($prefix === null || $path === $prefix || str_starts_with($path, $prefix.'/') || str_starts_with($path, $prefix.'?')) {
            return $path;
        }

        return $prefix.($path === '/' ? '' : $path);
    }

    public function mainUrl(string $path = '/'): string
    {
        $main = $this->mainDomain();

        return $main !== '' ? $this->urlForHost($main, $this->normalizePath($path)) : url($this->normalizePath($path));
    }

    public function mainAdminUrl(string $path = ''): string
    {
        $prefix = trim((string) config('admin.route.prefix'), '/');
        $path = trim($path, '/');
        $relative = '/'.trim($prefix.($path !== '' ? '/'.$path : ''), '/');

        return $this->mainUrl($relative);
    }

    public function mainLoginUrl(): string
    {
        return app(\App\Services\DingTalk\LoginEntryService::class)->url(request());
    }

    public function currentPortalPath(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');
        $query = $request->getQueryString();

        return $this->normalizePath($path.($query ? '?'.$query : ''));
    }

    private function mainDomain(?Request $request = null): string
    {
        return app(LoginSiteService::class)->forRequest($request ?? request())?->domain
            ?? strtolower(trim((string) config('portals.main_domain', '')));
    }

    public function rootDomain(?Request $request = null): string
    {
        return app(LoginSiteService::class)->forRequest($request ?? request())?->domain
            ?? strtolower(trim((string) config('portals.root_domain', '')));
    }

    private function host(Request $request): string
    {
        return strtolower(trim($request->getHost()));
    }

    private function portalCodeFromHost(string $host, string $root): ?string
    {
        $suffix = '.'.$root;

        if ($root === '' || !Str::endsWith($host, $suffix)) {
            return null;
        }

        $code = substr($host, 0, -strlen($suffix));

        if ($code === '' || str_contains($code, '.')) {
            return null;
        }

        return $code;
    }

    private function mainDomainLabel(): ?string
    {
        $root = strtolower(trim((string) config('portals.root_domain', '')));
        $suffix = '.'.$root;
        $mainDomain = strtolower(trim((string) config('portals.main_domain', '')));

        if (!Str::endsWith($mainDomain, $suffix)) {
            return null;
        }

        $label = substr($mainDomain, 0, -strlen($suffix));

        return $label !== '' && !str_contains($label, '.') ? $label : null;
    }

    private function urlForHost(string $host, string $path): string
    {
        return rtrim((string) config('portals.scheme', 'https'), ':/').'://'.$host.$path;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/'.ltrim($path, '/');
    }
}


