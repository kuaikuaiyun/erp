@php
    $configurationCodes = ['backend_management', 'menu_configuration'];
    $isConfigurationMenu = in_array($menu->code, $configurationCodes, true);
    $children = $menus
        ->where('parent_id', $menu->getKey())
        ->where('is_enabled', true)
        ->filter(fn ($child) => $canManagePortalMenus || !in_array($child->code, $configurationCodes, true));
    $path = trim((string) $menu->path);
    $href = \Illuminate\Support\Str::startsWith($path, ['http://', 'https://'])
        ? $path
        : $portalOrigin.'/'.ltrim($path, '/');
    $icon = $menu->icon ?: 'fa-circle-o';
    $currentPath = '/'.trim(request()->path(), '/');
    $matchesCurrentPath = static function (string $candidatePath) use ($currentPath): bool {
        if (\Illuminate\Support\Str::startsWith($candidatePath, ['http://', 'https://'])) {
            return false;
        }

        $menuPath = '/'.trim(parse_url($candidatePath, PHP_URL_PATH) ?: $candidatePath, '/');

        if ($menuPath === '/') {
            return $currentPath === '/';
        }

        return $menuPath === $currentPath
            || \Illuminate\Support\Str::startsWith($currentPath, rtrim($menuPath, '/').'/');
    };
    $isCurrent = $matchesCurrentPath($path);
    $hasActiveChild = $children->contains(
        fn ($child): bool => $matchesCurrentPath(trim((string) $child->path))
    );
    $label = $menu->title ?: __($menu->title_key);
    $canAccessProductRequestMenu = match ($menu->code) {
        'procurement_inspections' => app(\App\Portals\ProductRequests\ProductRequestAccessService::class)->canInspectProcurement($user),
        'placement_reviews' => app(\App\Portals\ProductRequests\ProductRequestAccessService::class)->canReviewPlacement($user),
        default => true,
    };
    $canAccessAssistantAnalyticsMenu = $menu->code !== 'assistant_shopify_analytics'
        || app(\App\Portals\Assistant\AssistantPortalAuthorizationService::class)->canViewShopifyAnalytics($user);
@endphp

@if ((!$isConfigurationMenu || $canManagePortalMenus) && $canAccessProductRequestMenu && $canAccessAssistantAnalyticsMenu)
    @if ($children->isEmpty())
    <li class="{{ $isCurrent ? 'active' : '' }}">
        <a href="{{ $href }}">
            <i class="fa {{ $icon }}"></i>
            <span>{{ $label }}</span>
        </a>
    </li>
    @else
    <li class="treeview {{ $hasActiveChild ? 'active menu-open' : '' }}">
        <a href="#">
            <i class="fa {{ $icon }}"></i>
            <span>{{ $label }}</span>
            <i class="fa fa-angle-left pull-right"></i>
        </a>
        <ul class="treeview-menu">
            @foreach ($children as $child)
                @include('portal.partials.menu', [
                    'menu' => $child,
                    'menus' => $menus,
                    'portalOrigin' => $portalOrigin,
                    'canManagePortalMenus' => $canManagePortalMenus,
                ])
            @endforeach
        </ul>
    </li>
    @endif
@endif
