<?php
namespace Tests\Feature;
require_once __DIR__.'/DingTalkLoginTest.php';

use App\Services\Authentication\ModeService;
use App\Services\Authentication\PasswordLoginService;
use Encore\Admin\Facades\Admin;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ErpPasswordLoginTest extends TestCase
{
    use DingTalkTestEnvironment;

    public function test_password_login_does_not_call_dingtalk_during_outage(): void
    {
        $account = $this->account();
        config(['dingtalk.auth_mode' => 'password']);
        $this->apiUnavailable = true;
        $request = $this->browser();
        $request->merge(['username' => $account->username, 'password' => 'test-password-123']);
        app(PasswordLoginService::class)->login($request);
        self::assertSame($account->id, (int) Admin::guard()->id());
        self::assertSame('password', app(ModeService::class)->validate($request, $account));
        \Illuminate\Support\Facades\Http::assertNothingSent();
        self::assertNull($request->session()->get('dingtalk.login'));
    }

    public function test_disabled_account_is_rejected(): void
    {
        $account = $this->account();
        $account->forceFill(['status' => 0])->save();
        config(['dingtalk.auth_mode' => 'password']);
        $request = $this->browser();
        $request->merge(['username' => $account->username, 'password' => 'test-password-123']);
        $this->denied(fn () => app(PasswordLoginService::class)->login($request), 403);
        self::assertTrue(Admin::guard()->guest());
    }

    public function test_wrong_password_is_rejected(): void
    {
        $account = $this->account();
        config(['dingtalk.auth_mode' => 'password']);
        $request = $this->browser();
        $request->merge(['username' => $account->username, 'password' => 'incorrect']);
        $this->expectException(ValidationException::class);
        app(PasswordLoginService::class)->login($request);
    }

    public function test_password_login_is_closed_in_dingtalk_mode(): void
    {
        $this->denied(fn () => app(PasswordLoginService::class)->login($this->browser()), 403);
    }

    public function test_expired_password_session_is_rejected(): void
    {
        $account = $this->account();
        config(['dingtalk.auth_mode' => 'password']);
        $request = $this->browser();
        $modes = app(ModeService::class);
        $modes->stamp($request, $account, $modes->current());
        $this->travel(2)->days();
        $this->denied(fn () => $modes->validate($request, $account), 401);
    }

    public function test_captcha_failure_never_creates_session(): void
    {
        $account = $this->account();
        config(['dingtalk.auth_mode' => 'password']);
        $captcha = $this->mock(\App\Services\LoginCaptchaService::class);
        $captcha->shouldReceive('maxAttempts')->andReturn(5);
        $captcha->shouldReceive('decaySeconds')->andReturn(60);
        $captcha->shouldReceive('verify')->once()->andReturn(false);
        $request = $this->browser();
        $request->merge(['username' => $account->username, 'password' => 'test-password-123']);
        try {
            app(PasswordLoginService::class)->login($request);
            $this->fail('Expected captcha rejection');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('captcha', $exception->errors());
        }
        self::assertTrue(Admin::guard()->guest());
    }

    public function test_password_request_reaches_common_portal_gate_and_audit_has_no_dingtalk_actor(): void
    {
        $account = $this->account();
        config(['dingtalk.auth_mode' => 'password']);
        $request = $this->browser();
        $request->merge(['username' => $account->username, 'password' => 'test-password-123']);
        app(PasswordLoginService::class)->login($request);
        $response = app(\App\Http\Middleware\EnforceDingTalkSession::class)->handle($request, fn () => response('portal'));
        self::assertSame(200, $response->getStatusCode());
        $audit = \App\Models\DingTalkAudit::query()->where('action', 'login.succeeded')->latest('id')->firstOrFail();
        self::assertSame('password', $audit->metadata['actor_type']);
        self::assertNull($audit->identity_id);
        self::assertSame((int) $account->id, (int) $audit->account_id);
    }

    public function test_password_throttle_is_enforced(): void
    {
        config(['dingtalk.auth_mode' => 'password']);
        $request = $this->browser();
        $request->merge(['username' => 'account-1', 'password' => 'incorrect']);
        $key = 'admin-login:'.sha1('account-1|'.$request->ip());
        for ($i = 0; $i < 5; $i++) \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        $this->expectException(ValidationException::class);
        app(PasswordLoginService::class)->login($request);
    }
}
