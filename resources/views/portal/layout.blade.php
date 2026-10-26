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
<script>$(function(){ $('[data-toggle="tooltip"]').tooltip({container:'body',placement:'top'}); });</script>
@if(in_array($portal->code, ['yunying', 'caigou', 'cangku'], true))
<script src="{{ $portalOrigin }}/assets/erp/procurement-pending-watcher.js?v=20261026-1"></script>
@endif
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

{{-- 全局图片放大 overlay：所有 portal 页面的 .erp-zoomable 图片点击放大都用这一个。零依赖、零影响（默认 display:none）。 --}}
<div id="erp-image-zoom" role="dialog" aria-modal="true" aria-label="图片放大预览"
     style="position:fixed;inset:0;z-index:9999;display:none;background:rgba(0,0,0,0.88);cursor:zoom-out;"
     tabindex="-1">
    <button type="button" id="erp-image-zoom-close" aria-label="关闭"
            style="position:absolute;top:16px;right:20px;background:none;border:none;color:#fff;font-size:28px;line-height:1;cursor:pointer;padding:4px 10px;opacity:0.7;">×</button>
    <img id="erp-image-zoom-img" alt=""
         style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);max-width:92vw;max-height:90vh;object-fit:contain;border-radius:4px;box-shadow:0 8px 40px rgba(0,0,0,0.6);pointer-events:none;">
</div>
<script>
(function(){
    var overlay = document.getElementById('erp-image-zoom');
    if (!overlay) return;
    var img = document.getElementById('erp-image-zoom-img');
    var closeBtn = document.getElementById('erp-image-zoom-close');

    function open(src) {
        if (!src) return;
        img.src = src;
        overlay.style.display = 'block';
        document.body.style.overflow = 'hidden';
        overlay.focus();
    }
    function close() {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
        img.src = '';
    }

    // 暴露到 window，供内联 onclick="window.erpZoomOpen(this.src)" 直接调用
    window.erpZoomOpen = open;
    window.erpZoomClose = close;

    // 事件委托兜底：点击任意 .erp-zoomable 图片也能放大
    document.addEventListener('click', function(ev){
        var target = ev.target;
        if (!target || !target.tagName) return;
        // 放大后点 overlay 本体或 × 关闭
        if (overlay.style.display === 'block') {
            if (target === overlay || target === closeBtn) { close(); ev.stopPropagation(); return; }
        }
        // 拦截 .erp-zoomable 图片点击
        if (target.tagName === 'IMG' && target.classList.contains('erp-zoomable')) {
            var src = target.getAttribute('data-full-src') || target.src;
            if (src) { open(src); ev.preventDefault(); ev.stopPropagation(); }
        }
    }, false); // false = 冒泡阶段

    // ESC 关闭
    document.addEventListener('keydown', function(ev){
        if (ev.key === 'Escape' && overlay.style.display === 'block') close();
    });
})();
</script>
</body>
</html>
