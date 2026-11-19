<?php

namespace Tests\Feature;

require_once __DIR__.'/AssetRemindersTest.php';

use App\Models\DingTalkCompanyGrant;
use App\Portals\AssetManager\ReminderService;
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
        Http::assertSentCount(3); // one token, one group listing, one message
        Http::assertSent(fn ($r) => str_contains($r->url(), '/robot/groupMessages/send') && $r['robotCode'] === 'robot-test' && $r['openConversationId'] === 'cid-test');
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

    public function test_later_round_rechecks_submissions_and_skips_when_nobody_is_missing(): void
    {
        $this->reminderRule($this->reminderGroup());
        $user = $this->boundMember($this->teamA);
        $service = app(ReminderService::class);
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        DB::table('operation_task_recaps')->insert(['user_id' => $user->id, 'data_date' => '2026-11-18', 'submitted_at' => now()]);
        $this->travelTo(Carbon::parse('2026-11-19 14:00:00', 'Asia/Shanghai'));
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertEquals(['accepted', 'skipped'], DB::table('asset_reminder_runs')->orderBy('id')->pluck('status')->all());
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/robot/groupMessages/send')));
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
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/groupMessages/send'));
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
        $this->assertStringNotContainsString('secret-test', $run->error);
        $this->assertStringNotContainsString('reminder-token', $run->error);
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

    public function test_long_multibyte_messages_are_split_without_losing_names_and_not_resent(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $users = [];
        for ($i = 1; $i <= 300; $i++) $users[] = ['id' => 1000 + $i, 'name' => '姓名'.str_repeat('长', 20).$i];
        DB::table('asset_reminder_days')->insert(['rule_id' => $rule->id, 'data_date' => '2026-11-18', 'snapshot' => json_encode($users), 'synced_at' => now()]);
        $service = app(ReminderService::class);
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $messages = Http::recorded(fn ($r) => str_contains($r->url(), '/robot/groupMessages/send'));
        $this->assertGreaterThan(1, $messages->count());
        $text = '';
        foreach ($messages as [$request]) {
            $part = json_decode($request['msgParam'], true)['text'];
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'));
            $this->assertLessThanOrEqual(12000, strlen($part));
            $text .= $part;
        }
        foreach ($users as $user) $this->assertStringContainsString($user['name'], $text);
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertSame($messages->count(), Http::recorded(fn ($r) => str_contains($r->url(), '/robot/groupMessages/send'))->count());
    }

    public function test_attendance_failure_and_missing_schedule_do_not_send_partial_lists(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $this->boundMember($this->teamA, 'missing');
        $this->scheduleOverrides['missing|2026-11-18'] = 'missing';
        $this->assertSame(1, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $this->assertSame(0, DB::table('asset_reminder_runs')->count());
        $this->assertNotNull(DB::table('asset_reminder_rules')->find($rule->id)->last_error);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/groupMessages/send'));
    }

    public function test_roster_is_captured_on_days_without_a_sending_slot(): void
    {
        $rule = $this->reminderRule($this->reminderGroup(), ['work_times' => [], 'rest_times' => []]);
        $user = $this->boundMember($this->teamA);
        $this->assertSame(0, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $snapshot = DB::table('asset_reminder_days')->where('rule_id', $rule->id)->first();
        $this->assertSame($user->id, json_decode($snapshot->snapshot, true)[0]['id']);
        $this->assertSame(0, DB::table('asset_reminder_runs')->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/groupMessages/send'));
    }
}
