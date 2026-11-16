<?php

namespace Tests\Feature;

require_once __DIR__.'/DingTalkLoginTest.php';

use App\Models\DingTalkAudit;
use App\Models\DingTalkIdentity;
use App\Services\DingTalk\BindingService;
use App\Services\DingTalk\LoginService;
use App\Services\DingTalk\MembershipService;
use Tests\TestCase;

class DingTalkBindingTest extends TestCase
{
    use DingTalkTestEnvironment;

    public function test_ordinary_account_cannot_bind_two_members(): void
    {
        $account = $this->account();
        app(BindingService::class)->bind($account->id, 'user-a');
        $this->denied(fn () => app(BindingService::class)->bind($account->id, 'user-b'), 422);
        self::assertSame(1, DingTalkIdentity::query()->count());
        self::assertSame(1, \App\Models\DingTalkBinding::query()->where('account_id', $account->id)->where('active', true)->count());
    }

    public function test_retrying_a_successful_binding_is_idempotent_and_has_no_duplicate_audit(): void
    {
        $account=$this->account(); $service=app(BindingService::class);
        $first=$service->bind($account->id,'user-a');
        $again=$service->bind($account->id,'user-a');
        self::assertSame($first->id,$again->id);
        self::assertSame(1,DingTalkAudit::query()->where('action','binding.created')->count());
    }

    public function test_superadmin_can_bind_multiple_members_and_member_can_bind_multiple_accounts(): void
    {
        $admin = $this->account(true);
        $ordinary = $this->account();
        $service = app(BindingService::class);
        $service->bind($admin->id, 'user-a');
        $service->bind($admin->id, 'user-b');
        $service->bind($ordinary->id, 'user-a');
        self::assertSame(2, \App\Models\DingTalkBinding::query()->where('account_id', $admin->id)->where('active', true)->count());
        $identity = DingTalkIdentity::query()->where('user_id', 'user-a')->firstOrFail();
        self::assertCount(2, app(LoginService::class)->choices($identity));
    }

    public function test_unbinding_and_rebinding_does_not_restore_a_pending_login(): void
    {
        $account = $this->account();
        $service = app(BindingService::class);
        $binding = $service->bind($account->id, 'user-a');
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $login->acceptCallback($request, $flow['state'], 'code-test');
        $service->unbind($binding->id);
        $service->bind($account->id, 'user-a');
        $this->denied(fn () => $login->complete($request, $account->id), 403);
    }

    public function test_rejoining_does_not_automatically_restore_any_old_binding(): void
    {
        $first = $this->account();
        $second = $this->account();
        $service = app(BindingService::class);
        $one = $service->bind($first->id, 'user-a');
        $two = $service->bind($second->id, 'user-a');
        $events = app(MembershipService::class);
        $events->receiveEvent(['CorpId' => 'dingcorp-test', 'EventType' => 'user_leave_org', 'UserId' => ['user-a'], 'eventId' => 'leave-a']);
        $events->receiveEvent(['CorpId' => 'dingcorp-test', 'EventType' => 'user_add_org', 'UserId' => ['user-a'], 'eventId' => 'rejoin-a']);
        self::assertFalse($one->fresh()->active);
        self::assertFalse($one->identity()->first()->active);
        $service->bind($first->id, 'user-a');
        self::assertTrue($one->fresh()->active);
        self::assertFalse($two->fresh()->active);
    }

    public function test_binding_audit_preserves_actual_operator_separately_from_target_account(): void
    {
        $admin = $this->account(true);
        $target = $this->account();
        $actorBinding = app(BindingService::class)->bind($admin->id, 'user-b');
        $request = $this->browser();
        \Encore\Admin\Facades\Admin::guard()->setUser($admin);
        $this->proof($request, $actorBinding);
        $request->attributes->set('dingtalk.identity', $actorBinding->identity);
        $request->attributes->set('dingtalk.account_id', $admin->id);
        app(BindingService::class)->bind($target->id, 'user-a');
        $audit = DingTalkAudit::query()->where('action', 'binding.created')->latest('id')->firstOrFail();
        self::assertSame('user-b', $audit->user_id);
        self::assertSame($admin->id, (int) $audit->account_id);
        self::assertSame($target->id, $audit->metadata['target_account_id']);
        self::assertSame('dingtalk', $audit->metadata['actor_type']);
        self::assertSame('dingcorp-test', $audit->metadata['target_corp_id']);
    }
}
