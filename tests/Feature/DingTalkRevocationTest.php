<?php

namespace Tests\Feature;

require_once __DIR__.'/DingTalkLoginTest.php';

use App\Http\Middleware\EnforceDingTalkSession;
use App\Services\DingTalk\BindingService;
use App\Services\DingTalk\LoginService;
use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DingTalkRevocationTest extends TestCase
{
    use DingTalkTestEnvironment;

    public function test_new_scan_rejects_departed_member_without_unbinding(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        unset($this->members['user-a']);

        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code'), 403);
        self::assertTrue($binding->fresh()->active);
        self::assertTrue($binding->identity()->first()->active);
        self::assertTrue(Admin::guard()->guest());
    }

    public function test_departure_between_scan_and_account_selection_is_checked_again(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $login->acceptCallback($request, $flow['state'], 'code');
        unset($this->members['user-a']);

        $this->denied(fn () => $login->complete($request, $account->id), 403);
        self::assertTrue($binding->fresh()->active);
        self::assertTrue(Admin::guard()->guest());
    }

    public function test_established_session_uses_local_binding_without_asking_dingtalk(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $this->proof($request, $binding);
        $request->headers->set('Accept', 'application/json');
        Admin::guard()->setUser($account);
        $this->travel(6)->minutes();
        $this->apiUnavailable = true;
        $before = Http::recorded()->count();

        $gate = app(EnforceDingTalkSession::class);
        self::assertSame(200, $gate->handle($request, fn () => response('protected'))->getStatusCode());
        self::assertSame($before, Http::recorded()->count());
        self::assertTrue($binding->fresh()->active);

        $account->forceFill(['status' => 0])->save();
        self::assertSame(401, $gate->handle($request, fn () => response('protected'))->getStatusCode());
    }

    public function test_departure_callback_and_command_are_absent(): void
    {
        self::assertFalse(Route::has('dingtalk.events'));
        self::assertArrayNotHasKey('dingtalk:reconcile', \Illuminate\Support\Facades\Artisan::all());
    }

    public function test_account_disable_then_enable_never_restores_old_sessions(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $this->proof($request, $binding);
        $account->forceFill(['status' => 0])->save();
        $account->forceFill(['status' => 1])->save();
        $this->denied(fn () => app(LoginService::class)->validSession($request, $account), 401);
    }

    public function test_main_backend_and_portal_requests_cannot_use_a_legacy_session_without_proof(): void
    {
        $account = $this->account();
        Admin::guard()->setUser($account);
        $request = $this->browser();
        $request->headers->set('Accept', 'application/json');
        $reached = false;
        $response = app(EnforceDingTalkSession::class)->handle($request, function () use (&$reached) {
            $reached = true;
            return response('protected');
        });
        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($reached);
    }

}
