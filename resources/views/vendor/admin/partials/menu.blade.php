@php
    $administrator = Admin::user();
    $canRenderMenuItem = function (array $menuItem) use (&$canRenderMenuItem, $administrator): bool {
        $roles = \Illuminate\Support\Arr::get($menuItem, 'roles', []);
        $permission = \Illuminate\Support\Arr::get($menuItem, 'permission');

        if (!$administrator->visible($roles) || !$administrator->can($permission)) {
            return false;
        }

        $children = \Illuminate\Support\Arr::get($menuItem, 'children', []);

        if ($children === []) {
            return true;
        }

        foreach ($children as $child) {
            if ($canRenderMenuItem($child)) {
                return true;
            }
        }

        return false;
    };
@endphp

@if ($canRenderMenuItem($item))
    @if (empty($item['children']))
        <li>
            @if (url()->isValidUrl($item['uri']))
                <a href="{{ $item['uri'] }}" target="_blank">
            @else
                <a href="{{ admin_url($item['uri']) }}">
            @endif
                <i class="fa {{ $item['icon'] }}"></i>
                @if (Lang::has($titleTranslation = 'admin.menu_titles.'.trim(str_replace(' ', '_', strtolower($item['title'])))))
                    <span>{{ __($titleTranslation) }}</span>
                @else
                    <span>{{ admin_trans($item['title']) }}</span>
                @endif
            </a>
        </li>
    @else
        <li class="treeview">
            <a href="#">
                <i class="fa {{ $item['icon'] }}"></i>
                @if (Lang::has($titleTranslation = 'admin.menu_titles.'.trim(str_replace(' ', '_', strtolower($item['title'])))))
                    <span>{{ __($titleTranslation) }}</span>
                @else
                    <span>{{ admin_trans($item['title']) }}</span>
                @endif
                <i class="fa fa-angle-left pull-right"></i>
            </a>
            <ul class="treeview-menu">
                @foreach ($item['children'] as $item)
                    @include('admin::partials.menu', $item)
                @endforeach
            </ul>
        </li>
    @endif
@endif
