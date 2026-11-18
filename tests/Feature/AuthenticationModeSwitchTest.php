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
    public function test_steady_state_checks_do_not_issue_writes_or_locking_reads(): void
    {
        $account = $this->account();
        $request = $this->browser();
        $modes = app(ModeService::class);
        $modes->stamp($request, $account, $modes->current());
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            for ($i = 0; $i < 5; $i++) {
                self::assertSame('dingtalk', $modes->validate($request, $account));
            }
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
        $stateReads = 0;
        foreach ($queries as $query) {
            self::assertDoesNotMatchRegularExpression('/^\\s*(insert|update|delete|replace)\\b|for\\s+update|lock\\s+in\\s+share/i', $query['query']);
            if (str_contains($query['query'], 'erp_authentication_states')) $stateReads++;
        }
        self::assertSame(5, $stateReads);
    }

    public function test_initialization_is_idempotent_and_never_resets_account_versions(): void
    {
        $modes = app(ModeService::class);
        $state = $modes->current();
        self::assertSame(1, (int) $state->version);
        self::assertSame(1, DB::table('erp_authentication_states')->count());
        self::assertSame(1, $modes->accountVersion(999));
        DB::table('erp_authentication_account_versions')->where('account_id', 999)->update(['version' => 7]);
        self::assertSame(7, $modes->accountVersion(999));
        self::assertSame(7, $modes->accountVersion(999));
        self::assertSame(1, (int) $modes->current()->version);
    }

    public function test_repeated_switch_checks_increment_and_audit_only_once(): void
    {
        $modes = app(ModeService::class);
        $state = $modes->current();
        config(['dingtalk.auth_mode' => 'password']);
        $changed = $modes->current();
        self::assertSame((int) $state->version + 1, (int) $changed->version);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame((int) $changed->version, (int) $modes->current()->version);
        }
        self::assertSame(1, DB::table('dingtalk_audits')->where('action', 'authentication.mode_changed')->count());
    }
}
