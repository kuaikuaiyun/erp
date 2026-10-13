<?php

namespace App\Http\Middleware;

use App\Services\SystemSettingService;
use Closure;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAdminLoginExpiration
{
    public const LOGIN_ISSUED_AT_KEY = 'admin.login_issued_at';

    public function __construct(private readonly SystemSettingService $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Admin::guard();

        if ($guard->guest()) {
            return $next($request);
        }

        $issuedAt = (int) $request->session()->get(self::LOGIN_ISSUED_AT_KEY, 0);

        if ($issuedAt <= 0) {
            $request->session()->put(self::LOGIN_ISSUED_AT_KEY, now()->timestamp);

            return $next($request);
        }

        if (now()->timestamp < $issuedAt + $this->settings->loginExpirationSeconds()) {
            return $next($request);
        }

        if (method_exists($guard, 'logoutCurrentDevice')) {
            $guard->logoutCurrentDevice();
        } else {
            $guard->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(admin_url('auth/login'))->withErrors([
            'login_expired' => __('configuration.basic.login.expired'),
        ]);
    }
}


