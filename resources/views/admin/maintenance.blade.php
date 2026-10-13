<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('admin.name') }} | {{ __('configuration.maintenance.page_title') }}</title>
    <link rel="stylesheet" href="{{ admin_asset('vendor/laravel-admin/font-awesome/css/font-awesome.min.css') }}">
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { align-items: center; background: #050b18; color: #e8f4ff; display: flex; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; justify-content: center; margin: 0; min-height: 100vh; overflow: hidden; padding: 24px; position: relative; }
        body::before { background-image: linear-gradient(rgba(74, 222, 255, .09) 1px, transparent 1px), linear-gradient(90deg, rgba(74, 222, 255, .09) 1px, transparent 1px); background-size: 32px 32px; content: ""; inset: 0; mask-image: radial-gradient(circle at center, #000 0, rgba(0, 0, 0, .55) 42%, transparent 82%); opacity: .85; position: absolute; }
        body::after { background: radial-gradient(circle at 50% 44%, rgba(21, 169, 255, .24), transparent 33%), radial-gradient(circle at 12% 90%, rgba(97, 82, 255, .18), transparent 28%), radial-gradient(circle at 88% 8%, rgba(0, 255, 196, .13), transparent 25%); content: ""; inset: 0; pointer-events: none; position: absolute; }
        .maintenance-card { background: linear-gradient(145deg, rgba(18, 36, 65, .9), rgba(8, 18, 38, .9)); border: 1px solid rgba(125, 211, 252, .3); border-radius: 18px; box-shadow: 0 0 0 1px rgba(56, 189, 248, .06) inset, 0 24px 70px rgba(0, 0, 0, .48), 0 0 48px rgba(14, 165, 233, .15); max-width: 590px; overflow: hidden; padding: 44px 38px 42px; position: relative; text-align: center; width: 100%; z-index: 1; }
        .maintenance-card::before { background: linear-gradient(90deg, transparent, rgba(125, 211, 252, .7), transparent); content: ""; height: 1px; left: 15%; opacity: .8; position: absolute; right: 15%; top: 0; }
        .gear-stage { height: 132px; margin: 0 auto 18px; position: relative; width: 172px; }
        .gear { filter: drop-shadow(0 0 13px rgba(56, 189, 248, .55)); position: absolute; }
        .gear-large { animation: gear-spin 12s linear infinite; color: #52d6ff; font-size: 96px; left: 8px; top: 20px; }
        .gear-small { animation: gear-spin-reverse 8s linear infinite; color: #7c9cff; font-size: 63px; right: 8px; top: 3px; }
        .gear-core { background: #071223; border: 1px solid rgba(125, 211, 252, .5); border-radius: 50%; box-shadow: 0 0 0 7px rgba(56, 189, 248, .08), 0 0 25px rgba(56, 189, 248, .5); height: 19px; left: 61px; position: absolute; top: 58px; width: 19px; }
        .pulse-ring { animation: pulse-ring 2.4s ease-out infinite; border: 1px solid rgba(82, 214, 255, .55); border-radius: 50%; height: 94px; left: 25px; position: absolute; top: 21px; width: 94px; }
        h1 { font-size: 25px; font-weight: 600; letter-spacing: .04em; margin: 0 0 17px; text-shadow: 0 0 18px rgba(125, 211, 252, .4); }
        .maintenance-message { color: #b8cbe0; font-size: 15px; line-height: 1.9; margin: 0; white-space: pre-wrap; word-break: break-word; }
        .status-light { animation: status-pulse 1.8s ease-in-out infinite; background: #4ade80; border-radius: 50%; box-shadow: 0 0 0 4px rgba(74, 222, 128, .12), 0 0 15px rgba(74, 222, 128, .85); height: 7px; margin: 28px auto 0; width: 7px; }
        @keyframes gear-spin { to { transform: rotate(360deg); } }
        @keyframes gear-spin-reverse { to { transform: rotate(-360deg); } }
        @keyframes pulse-ring { 0% { opacity: .85; transform: scale(.78); } 75%, 100% { opacity: 0; transform: scale(1.32); } }
        @keyframes status-pulse { 50% { box-shadow: 0 0 0 8px rgba(74, 222, 128, 0), 0 0 19px rgba(74, 222, 128, .9); transform: scale(1.12); } }
        @media (max-width: 540px) { .maintenance-card { padding: 38px 24px 34px; } .gear-stage { transform: scale(.9); margin-bottom: 10px; } h1 { font-size: 22px; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; } }
    </style>
</head>
<body>
    <main class="maintenance-card">
        <div class="gear-stage" aria-hidden="true">
            <span class="pulse-ring"></span>
            <i class="fa fa-cog gear gear-large"></i>
            <i class="fa fa-cog gear gear-small"></i>
            <span class="gear-core"></span>
        </div>
        <h1>{{ __('configuration.maintenance.title') }}</h1>
        <p class="maintenance-message">{{ $message }}</p>
        <span class="status-light" aria-hidden="true"></span>
    </main>
</body>
</html>


