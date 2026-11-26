<?php

namespace App\Http\Middleware;

use App\Models\ErpLoginSite;
use App\Services\Authentication\LoginSiteService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseEnterpriseSessionDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $domains = app(LoginSiteService::class);
        $sites = $domains->sites();
        $site = $domains->match($request, $sites);
        $request->attributes->set(ErpLoginSite::class, $site);
        $request->attributes->set('erp.has_login_sites', $sites->isNotEmpty());
        if (!$site) {
            return $next($request);
        }

        $previous = config('session.domain');
        config(['session.domain' => $site->domain]);

        try {
            return $next($request);
        } finally {
            config(['session.domain' => $previous]);
        }
    }
}
