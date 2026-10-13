@php
    $administrator = Admin::user();
    $greetingHour = now('Asia/Shanghai')->hour;
    $greetingKey = $greetingHour < 12
        ? 'admin.greeting.morning'
        : ($greetingHour < 18 ? 'admin.greeting.afternoon' : 'admin.greeting.evening');
    $greeting = trans($greetingKey, ['name' => $administrator->name]);
@endphp

<header class="main-header">
    <a href="{{ admin_url('/') }}" class="logo">
        <span class="logo-mini">{!! config('admin.logo-mini', config('admin.name')) !!}</span>
        <span class="logo-lg">{!! config('admin.logo', config('admin.name')) !!}</span>
    </a>

    <nav class="navbar navbar-static-top" role="navigation">
        <a href="#" class="sidebar-toggle" data-toggle="offcanvas" role="button">
            <span class="sr-only">{{ trans('admin.toggle_navigation') }}</span>
        </a>
        <ul class="nav navbar-nav hidden-sm visible-lg-block">
            {!! Admin::getNavbar()->render('left') !!}
        </ul>

        <div class="navbar-custom-menu">
            <ul class="nav navbar-nav">
                {!! Admin::getNavbar()->render() !!}

                <li class="dropdown user user-menu">
                    <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                        <span class="hidden-xs">{{ $greeting }}</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="user-header">
                            <p>
                                {{ $greeting }}
                                <small>{{ trans('admin.member_since', ['date' => optional($administrator->created_at)->format('Y-m-d H:i:s')]) }}</small>
                            </p>
                        </li>
                        <li class="user-footer">
                            <div class="pull-right">
                                <a href="{{ admin_url('auth/logout') }}" class="btn btn-default btn-flat">{{ trans('admin.logout') }}</a>
                            </div>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>
</header>
