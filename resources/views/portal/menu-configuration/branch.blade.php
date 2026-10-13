@php
    $children = $menus->where('parent_id', $menu->getKey());
    $menuLabel = $menu->title ?: __($menu->title_key);
    $path = trim((string) $menu->path);
    $href = \Illuminate\Support\Str::startsWith($path, ['http://', 'https://'])
        ? $path
        : $portalOrigin.'/'.ltrim($path, '/');
@endphp

<li class="dd-item" data-id="{{ $menu->getKey() }}">
    <div class="dd-handle">
        <i class="fa {{ $menu->icon ?: 'fa-bars' }}"></i>&nbsp;<strong>{{ $menuLabel }}</strong>
        @if (!in_array($path, ['#', ''], true))
            <a href="{{ $href }}" class="dd-nodrag portal-menu-tree-link">{{ $href }}</a>
        @endif
    </div>
    @if ($children->isNotEmpty())
        <ol class="dd-list">
            @foreach ($children as $child)
                @include('portal.menu-configuration.branch', [
                    'menu' => $child,
                    'menus' => $menus,
                    'portalOrigin' => $portalOrigin,
                ])
            @endforeach
        </ol>
    @endif
</li>


