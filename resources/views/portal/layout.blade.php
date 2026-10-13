<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $portal->name }} | @yield('page_title', __('portal.menu.home'))</title>
    @php
        $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
        $watermarkScript = app(\App\Services\AdminWatermarkService::class)->scriptForAdministrator($user);
    @endphp
    <link rel="stylesheet" href="{{ $portalOrigin }}/vendor/laravel-admin/AdminLTE/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/vendor/laravel-admin/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/vendor/laravel-admin/laravel-admin/laravel-admin.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/vendor/laravel-admin/AdminLTE/dist/css/AdminLTE.min.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/vendor/laravel-admin/AdminLTE/dist/css/skins/{{ config('admin.skin', 'skin-blue-light') }}.min.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/system-font.css?v=20260928-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/sidebar-layout.css?v=20261006-2">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/admin-notifications.css?v=20260929-2">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/portal-shell.css?v=20261006-2">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/interaction-loading-20261011-1.css?v=20261011-3">
    <script>window.ErpInteractionLoadingConfig = @json(__('loading'));</script>
    @stack('styles')
    <script src="{{ $portalOrigin }}/vendor/laravel-admin/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js"></script>
</head>
<body class="hold-transition {{ config('admin.skin', 'skin-blue-light') }} {{ implode(' ', config('admin.layout', [])) }} portal-admin-shell">
@php
    $greetingHour = now('Asia/Shanghai')->hour;
    $greetingKey = $greetingHour < 12
        ? 'admin.greeting.morning'
        : ($greetingHour < 18 ? 'admin.greeting.afternoon' : 'admin.greeting.evening');
    $greeting = trans($greetingKey, ['name' => $user->name]);
    $footer = app(\App\Services\SystemSettingService::class)->footer();
@endphp
<div class="wrapper">
    <header class="main-header">
        <a href="{{ $portalOrigin }}/" class="logo">
            <span class="logo-mini"><i class="fa fa-cube"></i></span>
            <span class="logo-lg"><i class="fa fa-cube"></i> {{ $portal->name }}</span>
        </a>

        <nav class="navbar navbar-static-top" role="navigation">
            <a href="#" class="sidebar-toggle" data-toggle="offcanvas" role="button">
                <span class="sr-only">{{ __('admin.toggle_navigation') }}</span>
            </a>
            <div class="navbar-custom-menu">
                <ul class="nav navbar-nav">
                    <li>
                        <a href="{{ request()->fullUrl() }}" title="{{ __('admin.refresh') }}">
                            <i class="fa fa-refresh" aria-hidden="true"></i>
                            <span class="sr-only">{{ __('admin.refresh') }}</span>
                        </a>
                    </li>
                    <li class="portal-header-user" aria-label="{{ $greeting }}">
                        <span>
                            <span class="hidden-xs">{{ $greeting }}</span>
                        </span>
                    </li>
                </ul>
            </div>
        </nav>
    </header>

    <aside class="main-sidebar">
        <section class="sidebar">
            <ul class="sidebar-menu">
                @foreach ($portal->menus->where('parent_id', 0)->where('is_enabled', true) as $menu)
                    @include('portal.partials.menu', [
                        'menu' => $menu,
                        'menus' => $portal->menus,
                        'portalOrigin' => $portalOrigin,
                        'canManagePortalMenus' => $user->isAdministrator(),
                    ])
                @endforeach
            </ul>
        </section>
    </aside>

    <div class="content-wrapper" id="pjax-container">
        @yield('content')
    </div>

    <footer class="main-footer admin-custom-footer">
        <strong>{{ $footer['copyright_text'] !== '' ? $footer['copyright_text'] : __('configuration.footer.default_copyright', ['name' => config('admin.name')]) }}</strong>
        @if($footer['contact_information'] !== '')
            <button type="button" class="admin-footer-contact" id="portal-footer-contact">{{ __('configuration.footer.contact_link') }}</button>
        @endif
    </footer>
</div>

<button id="totop" title="{{ __('admin.back') }}" style="display: none;"><i class="fa fa-chevron-up"></i></button>

<script src="{{ $portalOrigin }}/vendor/laravel-admin/AdminLTE/bootstrap/js/bootstrap.min.js"></script>
<script src="{{ $portalOrigin }}/vendor/laravel-admin/AdminLTE/plugins/slimScroll/jquery.slimscroll.min.js"></script>
<script src="{{ $portalOrigin }}/vendor/laravel-admin/AdminLTE/dist/js/app.min.js"></script>
<script src="{{ $portalOrigin }}/assets/erp/admin-notifications.js?v=20260929-2"></script>
<script src="{{ $portalOrigin }}/assets/erp/admin-navigation.js?v=20260929-1"></script>
<script src="{{ $portalOrigin }}/assets/erp/interaction-loading-20261011-1.js?v=20261012-1"></script>
@stack('scripts')
@if ($watermarkScript !== null)
    <script>{!! $watermarkScript !!}</script>
@endif
@if($footer['contact_information'] !== '')
    <script>
        (function () {
            var contactButton = document.getElementById('portal-footer-contact');

            if (!contactButton) { return; }

            contactButton.addEventListener('click', function () {
                if (!window.ErpAdminNotifier) { return; }

                window.ErpAdminNotifier.notify({
                    type: 'info',
                    title: @json(__('configuration.footer.contact_toast_title')),
                    message: @json($footer['contact_information'])
                });
            });
        })();
    </script>
@endif
</body>
</html>
