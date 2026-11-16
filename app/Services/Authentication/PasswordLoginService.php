<?php
namespace App\Services\Authentication;

use App\Models\AdminUser;
use App\Services\LoginCaptchaService;
use App\Services\DingTalk\AuditService;
use App\Portal\Services\PortalReturnUrlService;
use App\Portal\Services\PortalDomainService;
use App\Http\Middleware\EnforceAdminLoginExpiration;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PasswordLoginService
{
    public function login(Request $request): string
    {
        $modes = app(ModeService::class);
        $state = $modes->current();
        if ($state->mode !== 'password') throw new HttpException(403, __('dingtalk.errors.password_disabled'));
        $request->attributes->set('erp.auth_mode', 'password');
        $request->attributes->remove('dingtalk.identity');
        $captcha = app(LoginCaptchaService::class);
        $key = 'admin-login:'.sha1(Str::lower((string) $request->input('username')).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, $captcha->maxAttempts())) {
            throw ValidationException::withMessages(['username' => __('configuration.login.rate_limited', ['seconds' => RateLimiter::availableIn($key)])]);
        }
        $data = $request->validate(['username' => ['required', 'string', 'max:190'], 'password' => ['required', 'string', 'max:255']],
            ['username.required' => __('configuration.login.username_required'), 'password.required' => __('configuration.login.password_required')]);
        if (!$captcha->verify($request)) {
            RateLimiter::hit($key, $captcha->decaySeconds());
            throw ValidationException::withMessages(['captcha' => __('configuration.login.captcha_failed')]);
        }
        $guard = Admin::guard();
        $candidate = AdminUser::query()->where('username', $data['username'])->first();
        $version = $candidate ? $modes->accountVersion((int) $candidate->id) : 0;
        if (!$guard->attempt($data, false)) {
            RateLimiter::hit($key, $captcha->decaySeconds());
            app(AuditService::class)->record('login.denied', null, null, ['reason' => 'password_failed']);
            throw ValidationException::withMessages(['username' => trans('auth.failed')]);
        }
        try {
            $account = $guard->user()->fresh();
            if (!$account || (isset($account->status) && (int) $account->status !== AdminUser::STATUS_ACTIVE)
                || $version !== $modes->accountVersion((int) $account->id)) {
                throw new HttpException(403, trans('admin.login_banned'));
            }
            $request->session()->regenerate(true);
            $request->session()->forget(['dingtalk.login', 'dingtalk.pending', 'url.intended']);
            $modes->stamp($request, $account, $state);
            $modes->validate($request, $account);
            $request->session()->put(EnforceAdminLoginExpiration::LOGIN_ISSUED_AT_KEY, now()->timestamp);
            app(AuditService::class)->record('login.succeeded', null, (int) $account->id);
            RateLimiter::clear($key);
            return app(PortalReturnUrlService::class)->pull($request) ?? app(PortalDomainService::class)->mainAdminUrl();
        } catch (\Throwable $exception) {
            $guard->logoutCurrentDevice();
            $request->session()->forget(ModeService::KEY);
            throw $exception;
        }
    }
}
