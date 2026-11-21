<?php

namespace Tests\Feature;

require_once __DIR__.'/AssetRemindersTest.php';

use App\Models\DingTalkCompanyGrant;
use App\Portals\AssetManager\ReminderService;
use App\Portals\AssetManager\ReminderDingTalkClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssetReminderDeliveryTest extends TestCase
{
    use AssetRemindersEnvironment;

    public function test_scheduled_runs_merge_departments_and_are_idempotent(): void
    {
        $group = $this->reminderGroup();
        $this->reminderRule($group, ['type' => 'advertising']);
        $this->reminderRule($group, ['type' => 'advertising', 'department_id' => $this->teamB->id]);
        $service = app(ReminderService::class);
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertSame(1, DB::table('asset_reminder_runs')->count());
        $run = DB::table('asset_reminder_runs')->first();
        $this->assertSame('accepted', $run->status);
        $this->assertSame('2026-11-18', $run->data_date);
        $this->assertCount(2, json_decode($run->department_ids, true));
        Http::assertSentCount(1); // Local calendar: only the Webhook message.
        Http::assertSent(fn ($r) => $r->url() === $this->groupValues()['webhook'] && $r['msgtype'] === 'markdown' && str_contains($r['markdown']['text'], '广告费业绩提报提醒'));
        $this->assertNull($run->process_key); // Webhook has no delivery tracking key.
    }

    public function test_advertising_never_queries_people_or_submission_tables(): void
    {
        $this->reminderRule($this->reminderGroup(), ['type' => 'advertising']);
        $this->hrMember($this->teamA); // deliberately not bound to DingTalk
        $this->assertSame(0, app(ReminderService::class)->process(now('Asia/Shanghai')));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/schedule/listbyusers'));
        $run = DB::table('asset_reminder_runs')->first();
        $this->assertStringNotContainsString('未填写人数', $run->message);
        $this->assertSame(0, DB::table('asset_reminder_days')->count());
    }


    public function test_rest_day_uses_rest_time_not_individual_working_state(): void
    {
        $this->reminderRule($this->reminderGroup(), ['type' => 'advertising']);
        $service = app(ReminderService::class);
        $this->travelTo(Carbon::parse('2026-11-21 10:00:00', 'Asia/Shanghai'));
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertSame(0, DB::table('asset_reminder_runs')->count());
        $this->travelTo(Carbon::parse('2026-11-21 11:00:00', 'Asia/Shanghai'));
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertSame(1, DB::table('asset_reminder_runs')->count());
    }

    public function test_revoked_department_or_company_authorization_blocks_scheduled_delivery(): void
    {
        $rule = $this->reminderRule($this->reminderGroup(), ['type' => 'advertising']);
        DingTalkCompanyGrant::where('user_id', $this->hrActor->id)->delete();
        $this->assertSame(1, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $this->assertNotNull(DB::table('asset_reminder_rules')->find($rule->id)->last_error);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/send'));
        DingTalkCompanyGrant::create(['company_id' => $this->company->id, 'user_id' => $this->hrActor->id, 'created_by' => 1]);
        DB::table('asset_computer_department_bindings')->delete();
        $this->assertSame(1, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $this->assertSame(0, DB::table('asset_reminder_runs')->count());
    }

    public function test_send_timeout_is_recorded_as_uncertain_and_never_auto_retried(): void
    {
        $this->reminderRule($this->reminderGroup(), ['type' => 'advertising']);
        $this->sendTimeout = true;
        $service = app(ReminderService::class);
        $this->assertSame(1, $service->process(now('Asia/Shanghai')));
        $run = DB::table('asset_reminder_runs')->first();
        $this->assertSame('uncertain', $run->status);
        $this->assertStringNotContainsString('webhook-test-token', $run->error);
        $this->assertStringNotContainsString('SECtestSigningKey', $run->error);
        $this->sendTimeout = false;
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertSame(1, DB::table('asset_reminder_runs')->count());
        $this->assertSame('uncertain', DB::table('asset_reminder_runs')->first()->status);
    }

    public function test_department_logs_do_not_expose_merged_out_of_scope_names(): void
    {
        $group = $this->reminderGroup();
        $this->reminderRule($group, ['type' => 'advertising']);
        $this->reminderRule($group, ['type' => 'advertising', 'department_id' => $this->teamB->id]);
        app(ReminderService::class)->process(now('Asia/Shanghai'));
        $this->getJson($this->remindersUrl.'/runs?group_id='.$group)->assertOk()->assertJsonPath('total', 1);
        DB::table('asset_computer_department_bindings')->where('target_department_id', $this->teamB->id)->delete();
        $this->getJson($this->remindersUrl.'/runs?group_id='.$group)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_future_effective_date_and_disabled_group_never_send(): void
    {
        $group = $this->reminderGroup();
        $rule = $this->reminderRule($group, ['type' => 'advertising', 'starts_on' => '2026-11-20']);
        $service = app(ReminderService::class);
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        DB::table('asset_reminder_rules')->where('id', $rule->id)->update(['starts_on' => '2026-11-18']);
        DB::table('asset_reminder_groups')->where('id', $group)->update(['enabled' => false]);
        $this->assertSame(1, $service->process(now('Asia/Shanghai')));
        $this->assertSame(0, DB::table('asset_reminder_runs')->count());
    }



    public function test_unsigned_webhook_sends_without_fetching_an_application_token(): void
    {
        $id = $this->reminderGroup();
        $group = DB::table('asset_reminder_groups')->find($id);
        app(ReminderDingTalkClient::class)->send($group, '提醒', '消息内容');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === $this->groupValues()['webhook'] && $r['markdown'] === ['title' => '提醒', 'text' => '消息内容']);
    }

    public function test_signed_webhook_uses_millisecond_timestamp_and_url_encoded_hmac(): void
    {
        $id = app(ReminderService::class)->save($this->hrActor, 'groups', $this->groupValues(['webhook_secret' => 'SECtestSigningKey']), null);
        app(ReminderDingTalkClient::class)->send(DB::table('asset_reminder_groups')->find($id), '提醒', '消息内容');
        Http::assertSentCount(1);
        Http::assertSent(function ($r) {
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);
            return $query === ['access_token' => 'webhook-test-token', 'timestamp' => '1795053600000', 'sign' => 'h1DGODcsFv6JhThhMgpcpqfAuHzgyy2yN52CajGMRH8='];
        });
    }

    public function test_rejected_or_malformed_webhook_results_never_count_as_accepted_or_leak_secrets(): void
    {
        $rule = $this->reminderRule($this->reminderGroup(), ['type' => 'advertising']);
        $this->webhookResponse = ['errcode' => 310000, 'errmsg' => 'webhook-test-token SECtestSigningKey'];
        $service = app(ReminderService::class);
        $this->assertSame(1, $service->process(now('Asia/Shanghai')));
        $run = DB::table('asset_reminder_runs')->first();
        $this->assertSame('uncertain', $run->status);
        $this->assertStringNotContainsString('webhook-test-token', $run->error);
        $this->assertStringNotContainsString('SECtestSigningKey', $run->error);
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertSame(1, DB::table('asset_reminder_runs')->count());
        $this->webhookResponse = ['errmsg' => 'ok'];
        $this->postJson($this->remindersUrl.'/rules/'.$rule->id.'/test', ['confirmed' => true])->assertUnprocessable();
        $this->assertSame(0, DB::table('asset_reminder_runs')->where('status', 'accepted')->count());
    }

    public function test_invalid_encrypted_destination_is_blocked_before_any_webhook_request(): void
    {
        $id = $this->reminderGroup();
        $group = DB::table('asset_reminder_groups')->find($id);
        $group->webhook = 'invalid-ciphertext-webhook-test-token';
        try {
            app(ReminderDingTalkClient::class)->send($group, '提醒', '消息内容');
            $this->fail('Invalid ciphertext must be blocked.');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('asset_reminders.webhook_unavailable'), $e->getMessage());
        }
        Http::assertNothingSent();
    }


}
