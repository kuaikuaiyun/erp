<?php

namespace App\Http\Middleware;

use App\Portal\Services\PortalDomainService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectFinanceDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $domains = app(PortalDomainService::class);
        if ($domains->isConfigured() && $domains->isMainRequest($request) && in_array($request->method(), ['GET', 'HEAD'], true)) {
            $path = $request->getPathInfo();
            if (rtrim($path, '/') === '/finance') {
                $path = '/';
            }
            $query = $request->getQueryString();

            return redirect()->away($domains->portalUrl('caiwu', $path.($query ? '?'.$query : '')), 302);
        }

        return $next($request);
    }
}
