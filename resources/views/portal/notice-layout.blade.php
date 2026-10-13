<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title ?? $portal->name }}</title>
    @php
        $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    @endphp
    <link rel="stylesheet" href="{{ $portalOrigin }}/vendor/laravel-admin/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/portal-public.css?v=20260929-1">
</head>
<body class="portal-public-body">
    <header class="portal-public-header">
        <a class="portal-public-brand" href="{{ $portalOrigin }}/">
            <i class="fa fa-cube"></i>
            <span>{{ $portal->name }}</span>
        </a>
    </header>
    <main class="portal-public-main">
        @yield('content')
    </main>
</body>
</html>


