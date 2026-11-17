@php
    $configurationCodes = ['backend_management', 'menu_configuration', 'review_center_department_bindings'];
    $isConfigurationMenu = in_array($menu->code, $configurationCodes, true);
    $children = $menus->where('parent_id', $menu->getKey())->where('is_enabled', true)
        ->filter(fn ($child) => $canManagePortalMenus || !in_array($child->code, $configurationCodes, true));
    $path = trim((string) $menu->path);
    if (!\Illuminate\Support\Str::startsWith($path, ['http://', 'https://', '#'])) {
        $path = app(\App\Portal\Services\PortalDomainService::class)->portalPath($portal, $path);
    }
    $href = \Illuminate\Support\Str::startsWith($path, ['http://', 'https://'])
        ? $path
        : $portalOrigin.'/'.ltrim($path, '/');
    $icon = $menu->icon ?: 'fa-circle-o';
    $currentPath = '/'.trim(request()->path(), '/');
    $matchesCurrentPath = static function (string $candidatePath) use ($currentPath, $portal): bool {
        if (\Illuminate\Support\Str::startsWith($candidatePath, ['http://', 'https://', '#'])) {
            return false;
        }

        $candidatePath = app(\App\Portal\Services\PortalDomainService::class)->portalPath($portal, $candidatePath);
        $menuPath = '/'.trim(parse_url($candidatePath, PHP_URL_PATH) ?: $candidatePath, '/');

        if ($menuPath === '/' || $menuPath === app(\App\Portal\Services\PortalDomainService::class)->portalPath($portal)) {
            return $currentPath === '/' || $currentPath === $menuPath;
        }

        return $menuPath === $currentPath
            || \Illuminate\Support\Str::startsWith($currentPath, rtrim($menuPath, '/').'/');
    };
    $isCurrent = $matchesCurrentPath($path);
    $hasActiveChild = $children->contains(
        fn ($child): bool => $matchesCurrentPath(trim((string) $child->path))
    );
    $isExpanded = $hasActiveChild;
    $label = $menu->title ?: __($menu->title_key);
@endphp

@if(!$isConfigurationMenu || $canManagePortalMenus)
    @if($children->isEmpty())
    <li class="{{ $isCurrent ? 'active' : '' }}">
        <a href="{{ $href }}">
            <i class="fa {{ $icon }}"></i><span>{{ $label }}</span>
            @if($menu->code === 'review_center_operation_tasks' && (($reviewCenterPendingCounts['recaps'] ?? 0) > 0 || ($reviewCenterPendingCounts['tests'] ?? 0) > 0))
            <span class="operation-review-counts" title="{{ __('review_center.pending.title') }}">
                @if(($reviewCenterPendingCounts['recaps'] ?? 0) > 0)
                <span class="operation-review-count operation-review-count-recap">{{ __('review_center.pending.recaps', ['count' => $reviewCenterPendingCounts['recaps']]) }}</span>
                @endif
                @if(($reviewCenterPendingCounts['tests'] ?? 0) > 0)
                <span class="operation-review-count operation-review-count-test">{{ __('review_center.pending.tests', ['count' => $reviewCenterPendingCounts['tests']]) }}</span>
                @endif
            </span>
            @endif
        </a>
    </li>
    @else
    <li class="treeview {{ $hasActiveChild ? 'active' : '' }} {{ $isExpanded ? 'menu-open' : '' }}">
        <a href="#">
            <i class="fa {{ $icon }}"></i><span>{{ $label }}</span>
            @if($menu->code === 'review_center_operation_tasks' && (($reviewCenterPendingCounts['recaps'] ?? 0) > 0 || ($reviewCenterPendingCounts['tests'] ?? 0) > 0))
            <span class="operation-review-counts" title="{{ __('review_center.pending.title') }}">
                @if(($reviewCenterPendingCounts['recaps'] ?? 0) > 0)
                <span class="operation-review-count operation-review-count-recap">{{ __('review_center.pending.recaps', ['count' => $reviewCenterPendingCounts['recaps']]) }}</span>
                @endif
                @if(($reviewCenterPendingCounts['tests'] ?? 0) > 0)
                <span class="operation-review-count operation-review-count-test">{{ __('review_center.pending.tests', ['count' => $reviewCenterPendingCounts['tests']]) }}</span>
                @endif
            </span>
            @endif
            <i class="fa fa-angle-left pull-right"></i>
        </a>
        <ul class="treeview-menu" @if($isExpanded) style="display:block" @endif>
            @foreach($children as $child)
                @include('portals.review-center.partials.menu', ['menu' => $child])
            @endforeach
        </ul>
    </li>
    @endif
@endif
