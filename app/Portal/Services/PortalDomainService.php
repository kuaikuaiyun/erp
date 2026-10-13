<?php

namespace App\Portal\Services;

use App\Models\Portal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PortalDomainService
{
    public function isConfigured(): bool
    {
        return $this->mainDomain() !== '' && $this->rootDomain() !== '';
    }

    public function isMainRequest(Request $request): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }

        return hash_equals($this->mainDomain(), $this->host($request));
    }

    public function portalForRequest(Request $request): ?Portal
    {
        if (!$this->isConfigured() || $this->isMainRequest($request)) {
            return null;
        }

        $code = $this->portalCodeFromHost($this->host($request));

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
        if (!$this->isConfigured()) {
            return null;
        }

        $code = $portal instanceof Portal ? $portal->code : $portal;

        return strtolower($code).'.'.$this->rootDomain();
    }

    public function portalUrl(Portal|string $portal, string $path = '/'): ?string
    {
        $host = $this->portalDomain($portal);

        return $host === null ? null : $this->urlForHost($host, $this->normalizePath($path));
    }

    public function mainUrl(string $path = '/'): string
    {
        if (!$this->isConfigured()) {
            return url($this->normalizePath($path));
        }

        return $this->urlForHost($this->mainDomain(), $this->normalizePath($path));
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
        return $this->mainAdminUrl('auth/login');
    }

    public function currentPortalPath(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');
        $query = $request->getQueryString();

        return $this->normalizePath($path.($query ? '?'.$query : ''));
    }

    private function mainDomain(): string
    {
        return strtolower(trim((string) config('portals.main_domain', '')));
    }

    private function rootDomain(): string
    {
        return strtolower(trim((string) config('portals.root_domain', '')));
    }

    private function host(Request $request): string
    {
        return strtolower(trim($request->getHost()));
    }

    private function portalCodeFromHost(string $host): ?string
    {
        $suffix = '.'.$this->rootDomain();

        if (!Str::endsWith($host, $suffix)) {
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
        if (!$this->isConfigured()) {
            return null;
        }

        $suffix = '.'.$this->rootDomain();
        $mainDomain = $this->mainDomain();

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


