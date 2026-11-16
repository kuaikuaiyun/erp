<?php

namespace Tests\Feature;

require_once __DIR__.'/DingTalkLoginTest.php';

use App\Http\Middleware\EnforceDingTalkSession;
use App\Models\DingTalkIdentity;
use App\Services\DingTalk\BindingService;
use App\Services\DingTalk\CallbackCrypto;
use App\Services\DingTalk\LoginService;
use App\Services\DingTalk\MembershipService;
use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DingTalkRevocationTest extends TestCase
{
    use DingTalkTestEnvironment;

    public function test_departure_revokes_all_accounts_for_one_member_without_disabling_shared_admin(): void
    {
        $shared = $this->account(true);
        $ordinary = $this->account();
        $service = app(BindingService::class);
        $a = $service->bind($shared->id, 'user-a');
        $otherAccount = $service->bind($ordinary->id, 'user-a');
        $b = $service->bind($shared->id, 'user-b');
        $requestA = $this->browser(true);
        $this->proof($requestA, $a);
        $requestB = $this->browser(true);
        $this->proof($requestB, $b);
        app(MembershipService::class)->receiveEvent(['CorpId' => 'dingcorp-test', 'EventType' => 'user_leave_org', 'userId' => ['user-a'], 'eventId' => 'event-leave-a']);
        self::assertFalse($a->fresh()->active);
        self::assertFalse($otherAccount->fresh()->active);
        self::assertTrue($b->fresh()->active);
        self::assertSame(1, (int) $shared->fresh()->status);
        $this->denied(fn () => app(LoginService::class)->validSession($requestA, $shared), 401);
        self::assertSame('user-b', app(LoginService::class)->validSession($requestB, $shared)->user_id);
    }

    public function test_callback_deduplication_does_not_repeat_revocation_and_activation_cannot_restore_it(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $events = app(MembershipService::class);
        $event = ['CorpId' => 'dingcorp-test', 'EventType' => 'user_leave_org', 'UserId' => ['user-a'], 'eventId' => 'event-duplicate'];
        $events->receiveEvent($event);
        $version = $binding->identity()->first()->version;
        $events->receiveEvent($event);
        $events->receiveEvent(['CorpId' => 'dingcorp-test', 'EventType' => 'user_active_org', 'UserId' => ['user-a'], 'eventId' => 'old-active']);
        self::assertSame($version, $binding->identity()->first()->version);
        self::assertFalse($binding->identity()->first()->active);
        self::assertSame(2, DB::table('dingtalk_events')->count());
    }

    public function test_wrong_company_event_is_rejected(): void
    {
        $this->denied(fn () => app(MembershipService::class)->receiveEvent([
            'CorpId' => 'another-company', 'EventType' => 'user_leave_org', 'UserId' => ['user-a'],
        ]), 403);
        self::assertSame(0, DB::table('dingtalk_events')->count());
    }

    public function test_membership_proof_allows_outage_only_until_expiration_and_does_not_record_departure(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $this->proof($request, $binding);
        $this->apiUnavailable = true;
        self::assertSame('user-a', app(LoginService::class)->validSession($request, $account)->user_id);
        $this->travel(6)->minutes();
        $this->denied(fn () => app(LoginService::class)->validSession($request, $account), 503);
        self::assertTrue($binding->identity()->first()->active);
        self::assertTrue($binding->fresh()->active);
        $this->apiUnavailable = false;
        self::assertSame('user-a', app(LoginService::class)->validSession($request, $account)->user_id);
    }

    public function test_missed_departure_notification_is_caught_by_authoritative_revalidation(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $this->proof($request, $binding);
        unset($this->members['user-a']);
        $this->travel(6)->minutes();
        $this->denied(fn () => app(LoginService::class)->validSession($request, $account), 401);
        self::assertFalse($binding->identity()->first()->active);
        self::assertFalse($binding->fresh()->active);
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

    public function test_signed_callback_is_accepted_but_tampering_and_wrong_owner_are_rejected(): void
    {
        $crypto = app(CallbackCrypto::class)->forCompany($this->company);
        $message = json_encode(['CorpId' => 'dingcorp-test', 'EventType' => 'user_leave_org', 'UserId' => ['user-a']]);
        $signed = $crypto->encrypt($message);
        self::assertSame($message, $crypto->decrypt($signed['msg_signature'], $signed['timeStamp'], $signed['nonce'], $signed['encrypt']));
        $this->denied(fn () => $crypto->decrypt(str_repeat('0', 40), $signed['timeStamp'], $signed['nonce'], $signed['encrypt']), 403);
        $wrong=clone $this->company; $wrong->callback_owner_key='wrong-app';
        $crypto=app(CallbackCrypto::class)->forCompany($wrong);
        $this->denied(fn () => $crypto->decrypt($signed['msg_signature'], $signed['timeStamp'], $signed['nonce'], $signed['encrypt']), 403);
    }

    public function test_http_callback_is_verified_and_processed_without_a_browser_csrf_token(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $signed = app(CallbackCrypto::class)->forCompany($this->company)->encrypt(json_encode(['CorpId' => 'dingcorp-test',
            'EventType' => 'user_leave_org', 'UserId' => ['user-a'], 'eventId' => 'http-leave']));
        $query = http_build_query(['signature' => $signed['msg_signature'], 'timestamp' => $signed['timeStamp'], 'nonce' => $signed['nonce']]);
        $response = $this->postJson('/auth/dingtalk/events?company='.$this->company->id.'&'.$query, ['encrypt' => $signed['encrypt']])->assertOk();
        self::assertSame('success', app(CallbackCrypto::class)->forCompany($this->company)->decrypt($response['msg_signature'], $response['timeStamp'], $response['nonce'], $response['encrypt']));
        self::assertFalse($binding->fresh()->active);
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

    public function test_permission_errors_are_not_treated_as_employee_departure(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $this->legacyError = 60011;
        $this->denied(fn () => app(MembershipService::class)->refresh($binding->identity), 503);
        self::assertTrue($binding->identity()->first()->active);
    }

    public function test_late_remote_result_cannot_undo_a_concurrent_leave(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $snapshot = $binding->identity;
        app(MembershipService::class)->receiveEvent(['CorpId' => 'dingcorp-test', 'EventType' => 'user_leave_org', 'UserId' => ['user-a'], 'eventId' => 'concurrent-leave']);
        $this->denied(fn () => app(MembershipService::class)->refresh($snapshot), 401);
        self::assertFalse(DingTalkIdentity::query()->find($snapshot->id)->active);
    }

    public function test_paused_session_can_resume_after_api_recovers(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $this->proof($request, $binding);
        $request->headers->set('Accept', 'application/json');
        Admin::guard()->setUser($account);
        $this->travel(6)->minutes();
        $this->apiUnavailable = true;
        $gate = app(EnforceDingTalkSession::class);
        $response = $gate->handle($request, fn () => response('protected'));
        self::assertSame(503, $response->getStatusCode());
        self::assertTrue(Admin::guard()->check());
        $this->apiUnavailable = false;
        $response = $gate->handle($request, fn () => new \Symfony\Component\HttpFoundation\Response('protected'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_scheduled_reconciliation_catches_a_missed_departure(): void
    {
        $account = $this->account();
        $binding = app(BindingService::class)->bind($account->id, 'user-a');
        unset($this->members['user-a']);
        $this->travel(2)->minutes();
        $this->artisan('dingtalk:reconcile')->assertSuccessful();
        self::assertFalse($binding->fresh()->active);
    }
}
