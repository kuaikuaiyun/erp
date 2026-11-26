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
        config(['session.domain' => $site->rootDomain()]);

        try {
            $response = $next($request);

            if ($site->domain !== $site->rootDomain() && $request->routeIs(config('admin.route.prefix').'.login')) {
                // The retired host-scoped cookie can otherwise mask the new cookie sent to sibling domains.
                foreach ([$site->domain, null] as $oldDomain) {
                    $response->headers->clearCookie(
                        config('session.cookie'), config('session.path', '/'), $oldDomain,
                        $request->isSecure() || (bool) config('session.secure'), true, config('session.same_site'),
                    );
                }
            }

            return $response;
        } finally {
            config(['session.domain' => $previous]);
        }
    }
}
