<?php

namespace App\Portal\Services;

use App\Models\Portal;
use Illuminate\Http\Request;

class PortalReturnUrlService
{
    private const SESSION_KEY = 'portal.return_after_login';

    public function __construct(private readonly PortalDomainService $domains)
    {
    }

    public function remember(Request $request, Portal $portal): void
    {
        if (!$this->isNavigablePageRequest($request)) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, [
            'portal_id' => (int) $portal->getKey(),
            'path' => $this->domains->currentPortalPath($request),
        ]);
    }

    public function pull(Request $request): ?string
    {
        $stored = $request->session()->pull(self::SESSION_KEY);

        if (!is_array($stored)
            || !isset($stored['portal_id'])
            || !is_numeric($stored['portal_id'])
            || !is_string($stored['path'] ?? null)
            || !str_starts_with($stored['path'], '/')
            || str_starts_with($stored['path'], '//')) {
            return null;
        }

        $portal = Portal::query()->find((int) $stored['portal_id']);

        if (!$portal) {
            return null;
        }

        if ($this->isBackgroundEndpoint($stored['path'])) {
            return $this->domains->portalUrl($portal);
        }

        return $this->domains->portalUrl($portal, $stored['path']);
    }

    private function isNavigablePageRequest(Request $request): bool
    {
        return $request->isMethod('get')
            && !$request->ajax()
            && !$request->expectsJson()
            && !$this->isBackgroundEndpoint($this->domains->currentPortalPath($request));
    }

    private function isBackgroundEndpoint(string $path): bool
    {
        $path = (string) (parse_url($path, PHP_URL_PATH) ?: $path);

        return (bool) preg_match('#^/orders/tongtu-match-batches/[^/]+/progress$#', $path);
    }
}

