<?php

namespace App\Portal\Http\Middleware;

use App\Portal\Services\PortalDomainService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMainPortalDomain
{
    public function __construct(private readonly PortalDomainService $domains)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->domains->isConfigured() || $this->domains->isMainRequest($request)) {
            return $next($request);
        }

        abort(404);
    }
}


