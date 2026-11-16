<?php
namespace Tests\Feature;
require_once __DIR__.'/DingTalkLoginTest.php';

use App\Services\Authentication\ModeService;
use App\Services\DingTalk\LoginService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthenticationModeSwitchTest extends TestCase
{
    use DingTalkTestEnvironment;

    public function test_round_trip_switch_never_restores_old_sessions(): void
    {
        $account = $this->account();
        $request = $this->browser();
        $modes = app(ModeService::class);
        $modes->stamp($request, $account, $modes->current());
        config(['dingtalk.auth_mode' => 'password']);
        $this->denied(fn () => $modes->validate($request, $account), 401);
        config(['dingtalk.auth_mode' => 'dingtalk']);
        $this->denied(fn () => $modes->validate($request, $account), 401);
    }

    public function test_switch_consumes_pending_dingtalk_state(): void
    {
        $request = $this->browser();
        app(LoginService::class)->start($request);
        config(['dingtalk.auth_mode' => 'password']);
        app(ModeService::class)->current();
        self::assertSame(0, DB::table('dingtalk_challenges')->whereNull('consumed_at')->count());
        $this->denied(fn () => app(LoginService::class)->start($request), 403);
    }

    public function test_disabling_then_enabling_account_invalidates_password_proof(): void
    {
        $account = $this->account();
        config(['dingtalk.auth_mode' => 'password']);
        $modes = app(ModeService::class);
        $request = $this->browser();
        $modes->stamp($request, $account, $modes->current());
        $account->forceFill(['status' => 0])->save();
        $account->forceFill(['status' => 1])->save();
        $this->denied(fn () => $modes->validate($request, $account), 401);
    }

    public function test_invalid_mode_does_not_enable_password_fallback(): void
    {
        config(['dingtalk.auth_mode' => 'invalid']);
        $this->denied(fn () => app(ModeService::class)->current(), 503);
    }
}
