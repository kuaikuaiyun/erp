<?php

namespace App\Services\Authentication;

use App\Models\DingTalkCompany;
use App\Models\ErpLoginSite;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class LoginSiteService
{
    public function sites(): Collection
    {
        return Schema::hasTable('erp_login_sites') ? ErpLoginSite::query()->get() : new Collection();
    }

    public function forRequest(Request $request, bool $fresh = false): ?ErpLoginSite
    {
        if (!$fresh && $request->attributes->has(ErpLoginSite::class)) {
            return $request->attributes->get(ErpLoginSite::class);
        }

        return $this->match($request, $this->sites());
    }

    public function match(Request $request, Collection $sites): ?ErpLoginSite
    {
        $host = strtolower($request->getHost());

        return $sites->sortByDesc(fn (ErpLoginSite $site): int => strlen($site->domain))
            ->first(fn (ErpLoginSite $site): bool => $site->domain === $host || ($this->contains($site->domain, $host)
                && !str_contains(substr($host, 0, -strlen($site->domain) - 1), '.')));
    }

    public function hasSites(Request $request): bool
    {
        return $request->attributes->get('erp.has_login_sites') ?? $this->sites()->isNotEmpty();
    }

    public function contains(string $root, string $host): bool
    {
        return $root !== '' && ($root === $host || str_ends_with($host, '.'.$root));
    }

    public function callback(string $domain): string
    {
        return config('portals.scheme').'://'.$domain.'/auth/dingtalk/callback';
    }

    public function acceptsCallback(DingTalkCompany $company, string $url, string $currentCallback): bool
    {
        $sites = $this->sites();
        $assigned = $company->exists ? $sites->firstWhere('dingtalk_company_id', $company->getKey()) : null;

        return $assigned
            ? hash_equals($this->callback($assigned->domain), $url)
            : hash_equals($currentCallback, $url) || $sites->contains(fn (ErpLoginSite $site): bool => $this->callback($site->domain) === $url);
    }
}
