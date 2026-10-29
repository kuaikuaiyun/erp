<?php

namespace App\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Portal;
use App\Portal\Services\PortalAccessService;
use App\Portal\Services\PortalDomainService;
use App\Portal\Services\PortalReturnUrlService;
use App\Portals\Payment\Http\Controllers\PaymentPortalController;
use App\Portals\Payment\PaymentPortalService;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PortalEntryController extends Controller
{
    public function __construct(
        private readonly PortalDomainService $domains,
        private readonly PortalAccessService $access,
        private readonly PortalReturnUrlService $returnUrls,
        private readonly \App\Portal\Services\PortalMenuAuthorizationService $menuAuthorization,
    ) {
    }

    public function root(Request $request): Response
    {
        if ($this->domains->portalForRequest($request)) {
            return $this->respond($request);
        }

        if ($this->domains->isConfigured() && !$this->domains->isMainRequest($request)) {
            abort(404);
        }

        return response()->view('welcome');
    }

    public function fallback(Request $request): Response
    {
        return $this->respond($request, false);
    }

    /**
     * @return array{portal: Portal, user: \App\Models\AdminUser}|Response
     */
    public function portalContext(Request $request, bool $requiresSystemAdministrator = false): array|Response
    {
        $portal = $this->domains->portalForRequest($request);

        if (!$portal) {
            abort(404);
        }

        if ($portal->isDisabled()) {
            return $this->notice(
                'disabled',
                $portal,
                $this->domains->mainAdminUrl(),
                config('portals.countdown_seconds'),
            );
        }

        $guard = Admin::guard();

        if ($guard->guest()) {
            $this->returnUrls->remember($request, $portal);

            return $this->notice(
                'login',
                $portal,
                $this->domains->mainLoginUrl(),
                config('portals.countdown_seconds'),
            );
        }

        /** @var \App\Models\AdminUser $user */
        $user = $guard->user();

        if (!$this->access->isActiveAccount($user)) {
            $guard->logout();
            $this->returnUrls->remember($request, $portal);

            return $this->notice(
                'login',
                $portal,
                $this->domains->mainLoginUrl(),
                config('portals.countdown_seconds'),
            );
        }

        if ($portal->isMaintenance() && !$user->isAdministrator()) {
            return $this->notice('maintenance', $portal);
        }

        if (!$this->access->canEnter($portal, $user)) {
            return $this->notice(
                'denied',
                $portal,
                $this->domains->mainAdminUrl(),
                config('portals.countdown_seconds'),
            );
        }

        if ($requiresSystemAdministrator && !$user->isAdministrator()) {
            return $this->notice(
                'restricted',
                $portal,
                $this->domains->mainAdminUrl(),
                config('portals.countdown_seconds'),
            );
        }

        $portal->loadMissing('menus');
        if (!$requiresSystemAdministrator) {
            $requestedMenu = $this->menuAuthorization->menuForRequest($portal, $request->path());
            if ($requestedMenu && !$this->menuAuthorization->canAccess($user, $requestedMenu)) {
                abort(403, '您没有访问该页面的权限。');
            }
        }
        $portal->setRelation('menus', $this->menuAuthorization->visibleMenus($portal, $user));

        return [
            'portal' => $portal,
            'user' => $user,
        ];
    }

    private function respond(Request $request, bool $isHome = true): Response
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $portal = $context['portal'];
        $user = $context['user'];

        if ($isHome && app(PaymentPortalService::class)->isPaymentPortal($portal)) {
            return app(PaymentPortalController::class)->workbenchFromContext($context);
        }

        if ($isHome) {
            return response()->view('portal.home', [
                'portal' => $portal,
                'user' => $user,
                'workbenchGreeting' => $this->workbenchGreeting($user),
                'today' => now('Asia/Shanghai')->format('Y-m-d'),
            ]);
        }

        $menu = $this->menuForPath($request, $portal);

        if (!$menu) {
            abort(404);
        }

        return response()->view('portal.menu-page', [
            'portal' => $portal,
            'user' => $user,
            'menu' => $menu,
            'parentMenu' => $portal->menus->firstWhere('id', $menu->parent_id),
        ]);
    }

    private function menuForPath(Request $request, Portal $portal): ?\App\Models\PortalMenu
    {
        $currentPath = '/'.trim($request->path(), '/');

        return $portal->menus
            ->where('is_enabled', true)
            ->first(function (\App\Models\PortalMenu $menu) use ($currentPath): bool {
                $path = trim((string) $menu->path);

                return !Str::startsWith($path, ['http://', 'https://', '#'])
                    && '/'.trim(parse_url($path, PHP_URL_PATH) ?: $path, '/') === $currentPath;
            });
    }

    private function workbenchGreeting(\App\Models\AdminUser $user): string
    {
        $hour = now('Asia/Shanghai')->hour;
        $key = $hour < 12
            ? 'portal.home.greeting.morning'
            : ($hour < 18 ? 'portal.home.greeting.afternoon' : 'portal.home.greeting.evening');

        return trans($key, ['name' => $user->name]);
    }

    private function notice(string $type, Portal $portal, ?string $redirectUrl = null, ?int $countdown = null): Response
    {
        return response()->view('portal.notice', [
            'portal' => $portal,
            'noticeType' => $type,
            'title' => __('portal.public.'.$type.'.title'),
            'message' => __('portal.public.'.$type.'.message'),
            'redirectUrl' => $redirectUrl,
            'countdown' => $countdown,
        ], $type === 'maintenance' ? 503 : 200);
    }
}
