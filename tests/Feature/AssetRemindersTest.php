<?php

namespace Tests\Feature;

require_once __DIR__.'/AssetAccountsTest.php';

use App\Models\AdminUser;
use App\Models\DingTalkBinding;
use App\Models\DingTalkCompanyGrant;
use App\Models\DingTalkIdentity;
use App\Portals\AssetManager\ReminderAttendanceService;
use App\Portals\AssetManager\ReminderRecapService;
use App\Portals\AssetManager\ReminderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

trait AssetRemindersEnvironment
{
    use AssetAccountsEnvironment { setUp as private accountsSetUp; }

    protected string $remindersUrl = '/asset-manager/reminders';
    protected array $scheduleOverrides = [];
    protected bool $sendTimeout = false;
    protected bool $attendanceFailure = false;

    protected function setUp(): void
    {
        $this->accountsSetUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00', 'Asia/Shanghai'));
        (require database_path('migrations/2026_11_19_000100_install_asset_reminders.php'))->up();
        Schema::create('operation_task_recaps', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->date('data_date');
            $t->string('workflow_status')->default('pending_leader'); $t->timestamp('submitted_at')->nullable();
            $t->softDeletes(); $t->timestamps();
        });
        DingTalkCompanyGrant::create(['company_id' => $this->company->id, 'user_id' => $this->hrActor->id, 'created_by' => 1]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1.0/oauth2/accessToken') return Http::response(['accessToken' => 'reminder-token', 'expireIn' => 7200]);
            if ($path === '/topapi/attendance/getsimplegroups') return Http::response($this->attendanceFailure ? ['errcode' => 88] : ['errcode' => 0, 'result' => ['groups' => [['group_id' => 12, 'group_name' => 'Weekdays', 'type' => 'FIXED', 'work_day_list' => [0, 10, 10, 10, 10, 10, 0]]], 'has_more' => false]]);
            if ($path === '/topapi/attendance/schedule/listbyusers') {
                $date = Carbon::createFromTimestampMs($request['from_date_time'], 'Asia/Shanghai')->toDateString();
                $rows = [];
                foreach (explode(',', $request['userids']) as $userid) {
                    $state = $this->scheduleOverrides[$userid.'|'.$date] ?? 'N';
                    if ($state !== 'missing') $rows[] = ['userid' => $userid, 'is_rest' => $state, 'work_date' => $date.' 00:00:00'];
                }
                return Http::response(['errcode' => 0, 'result' => $rows]);
            }
            if ($path === '/v1.0/robot/groupMessages/send') {
                if ($this->sendTimeout) throw new ConnectionException('secret-test reminder-token must not leak');
                return Http::response(['processQueryKey' => 'process-1']);
            }
            throw new \LogicException('Unexpected reminder HTTP request');
        });
    }

    protected function groupValues(array $overrides = []): array
    {
        return array_replace(['department_id' => $this->teamA->id, 'name' => 'Daily group', 'company_id' => $this->company->id,
            'robot_code' => 'robot-test', 'conversation_id' => 'cid-test', 'enabled' => true, 'remark' => 'Reminder test'], $overrides);
    }

    protected function reminderGroup(): int
    {
        return app(ReminderService::class)->save($this->hrActor, 'groups', $this->groupValues(), null);
    }

    protected function ruleValues(int $group, array $overrides = []): array
    {
        return array_replace(['department_id' => $this->teamA->id, 'group_id' => $group, 'type' => 'recap', 'enabled' => true,
            'attendance_group_id' => 12, 'operator_userid' => 'attendance-admin', 'work_times' => ['10:00', '14:00'],
            'rest_times' => ['11:00'], 'starts_on' => '2026-11-18', 'entry_url' => 'https://tasks.example.com/recaps'], $overrides);
    }

    protected function reminderRule(int $group, array $overrides = []): object
    {
        $id = app(ReminderService::class)->save($this->hrActor, 'rules', $this->ruleValues($group, $overrides), null);
        return DB::table('asset_reminder_rules')->find($id);
    }

    protected function boundMember($department, string $userid = 'test-member'): AdminUser
    {
        $user = $this->hrMember($department);
        $identity = DingTalkIdentity::create(['corp_id' => $this->company->corp_id, 'user_id' => $userid, 'union_id' => 'union-'.$userid, 'name' => $user->name, 'active' => true]);
        DingTalkBinding::create(['identity_id' => $identity->id, 'account_id' => $user->id, 'active' => true]);
        return $user;
    }
}

class AssetRemindersTest extends TestCase
{
    use AssetRemindersEnvironment;

    public function test_page_configuration_and_menu_installation_are_repeatable(): void
    {
        (require database_path('migrations/2026_11_19_000100_install_asset_reminders.php'))->up();
        $this->assertSame(1, DB::table('portal_menus')->where('code', 'asset_manager_reminders')->count());
        $this->get($this->remindersUrl)->assertOk()->assertSee('asset-reminders.js')->assertDontSee('secret-test');
        $this->getJson($this->remindersUrl.'/options')->assertOk()->assertJsonCount(2, 'departments')->assertJsonCount(1, 'companies')->assertDontSee('app_secret');
        $this->postJson($this->remindersUrl.'/groups', $this->groupValues())->assertOk();
        $group = DB::table('asset_reminder_groups')->first();
        $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group->id))->assertOk();
        $this->get($this->remindersUrl.'?type=recap&per_page=20')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    }

    public function test_department_and_company_scope_cannot_be_bypassed(): void
    {
        $this->postJson($this->remindersUrl.'/groups', $this->groupValues(['department_id' => $this->outside->id]))->assertUnprocessable();
        DingTalkCompanyGrant::where('user_id', $this->hrActor->id)->delete();
        $this->postJson($this->remindersUrl.'/groups', $this->groupValues())->assertForbidden();
        $this->getJson($this->remindersUrl.'/attendance?company_id='.$this->company->id)->assertForbidden();
    }

    public function test_members_are_readonly_and_disabled_menu_blocks_the_page(): void
    {
        $group = $this->reminderGroup();
        $rule = $this->reminderRule($group);
        DB::table('admin_role_users')->where('user_id', $this->hrActor->id)->update(['position' => 'member']);
        $this->hrActor->unsetRelation('roles')->unsetRelation('departments');
        $this->get($this->remindersUrl)->assertOk()->assertViewHas('canManage', false);
        $this->getJson($this->remindersUrl.'/rules/'.$rule->id)->assertOk();
        $this->putJson($this->remindersUrl.'/rules/'.$rule->id, $this->ruleValues($group, ['version' => 1]))->assertForbidden();
        $this->postJson($this->remindersUrl.'/rules/'.$rule->id.'/test', ['confirmed' => true])->assertForbidden();
        DB::table('portal_menus')->where('code', 'asset_manager_reminders')->update(['is_enabled' => false]);
        $this->get($this->remindersUrl)->assertForbidden();
    }

    public function test_validation_versions_and_in_use_group_transport_are_enforced(): void
    {
        $group = $this->reminderGroup();
        $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group, ['work_times' => ['10:00', '10:00']]))->assertUnprocessable();
        $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group, ['entry_url' => 'javascript:alert(1)']))->assertUnprocessable();
        $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group, ['starts_on' => '2026-11-01']))->assertUnprocessable();
        $rule = $this->reminderRule($group);
        $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group))->assertUnprocessable();
        $this->putJson($this->remindersUrl.'/rules/'.$rule->id, $this->ruleValues($group, ['version' => 2]))->assertConflict();
        $this->putJson($this->remindersUrl.'/rules/'.$rule->id, $this->ruleValues($group, ['version' => 1, 'work_times' => ['09:00']]))->assertOk();
        $this->putJson($this->remindersUrl.'/groups/'.$group, $this->groupValues(['version' => 1, 'conversation_id' => 'another-cid']))->assertOk();
        $this->deleteJson($this->remindersUrl.'/groups/'.$group)->assertUnprocessable();
        $this->deleteJson($this->remindersUrl.'/rules/'.$rule->id)->assertOk();
        $this->assertFalse((bool) DB::table('asset_reminder_rules')->find($rule->id)->enabled);
    }

    public function test_cross_department_people_and_monthly_person_dates_are_deduplicated(): void
    {
        $group = $this->reminderGroup();
        $a = $this->reminderRule($group);
        $b = $this->reminderRule($group, ['department_id' => $this->teamB->id]);
        $user = $this->boundMember($this->teamA);
        $user->roles()->attach($this->teamB->id, ['position' => 'member']);
        $summary = app(ReminderRecapService::class)->summary([$a, $b], $this->company, Carbon::parse('2026-11-18', 'Asia/Shanghai'));
        $this->assertSame(1, $summary['count']);
        $this->assertSame(1, $summary['monthly_count']);
        $this->assertSame(1, $summary['monthly_people']);
        $this->assertCount(1, $summary['departments']);
        $this->assertSame(2, DB::table('asset_reminder_days')->count());
        $payload = app(ReminderService::class)->build([$a, $b], Carbon::parse('2026-11-18', 'Asia/Shanghai'));
        $this->assertSame(1, substr_count($payload['text'], $user->name));
    }

    public function test_submitted_rejected_and_soft_deleted_submissions_still_count_as_written(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $user = $this->boundMember($this->teamA);
        DB::table('operation_task_recaps')->insert(['user_id' => $user->id, 'data_date' => '2026-11-18', 'submitted_at' => now(), 'workflow_status' => 'rejected', 'deleted_at' => now()]);
        $summary = app(ReminderRecapService::class)->summary([$rule], $this->company, Carbon::parse('2026-11-18', 'Asia/Shanghai'));
        $this->assertSame(0, $summary['count']);
        $this->assertSame(0, $summary['monthly_count']);
    }

    public function test_current_missing_total_decreases_after_submission_and_keeps_historical_roster(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $user = $this->boundMember($this->teamA);
        $date = Carbon::parse('2026-11-18', 'Asia/Shanghai');
        $service = app(ReminderRecapService::class);
        $this->assertSame(1, $service->summary([$rule], $this->company, $date)['monthly_count']);
        DB::table('admin_role_users')->where('user_id', $user->id)->delete();
        $this->assertSame(1, $service->summary([$rule], $this->company, $date)['monthly_count']);
        DB::table('operation_task_recaps')->insert(['user_id' => $user->id, 'data_date' => '2026-11-18', 'submitted_at' => now(), 'workflow_status' => 'pending_leader']);
        $this->assertSame(0, $service->summary([$rule], $this->company, $date)['monthly_count']);
    }

    public function test_resting_and_not_yet_hired_people_are_excluded(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $this->boundMember($this->teamA, 'rest-person');
        $future = $this->hrMember($this->teamA); $future->hired_at = '2026-11-19'; $future->save();
        $this->scheduleOverrides['rest-person|2026-11-18'] = 'Y';
        $summary = app(ReminderRecapService::class)->summary([$rule], $this->company, Carbon::parse('2026-11-18', 'Asia/Shanghai'));
        $this->assertSame(0, $summary['count']);
    }

    public function test_unknown_schedule_or_missing_binding_cannot_produce_a_partial_missing_list(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $this->boundMember($this->teamA, 'unknown');
        $this->scheduleOverrides['unknown|2026-11-18'] = 'missing';
        $this->getJson($this->remindersUrl.'/rules/'.$rule->id.'/preview')->assertUnprocessable();
        $this->assertSame(0, DB::table('asset_reminder_days')->count());
        $this->hrMember($this->teamA);
        $this->getJson($this->remindersUrl.'/rules/'.$rule->id.'/preview')->assertUnprocessable();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/groupMessages/send'));
    }

    public function test_group_week_rules_use_sunday_index_and_reject_ambiguous_rules(): void
    {
        $service = app(ReminderAttendanceService::class);
        $group = ['type' => 'FIXED', 'work_day_list' => [0, 11, 11, 11, 11, 11, 0]];
        $this->assertSame('work', $service->dayType($group, Carbon::parse('2026-11-16')));
        $this->assertSame('rest', $service->dayType($group, Carbon::parse('2026-11-21')));
        $this->assertSame('rest', $service->dayType($group, Carbon::parse('2026-11-22')));
        $this->expectException(\RuntimeException::class);
        $service->dayType(['type' => 'TURN'], Carbon::parse('2026-11-19'));
    }

    public function test_preview_is_readonly_and_test_send_needs_explicit_confirmation(): void
    {
        $rule = $this->reminderRule($this->reminderGroup(), ['type' => 'advertising']);
        $this->getJson($this->remindersUrl.'/rules/'.$rule->id.'/preview')->assertOk()->assertDontSee('monthly_count');
        $this->assertSame(0, DB::table('asset_reminder_days')->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/groupMessages/send'));
        $this->postJson($this->remindersUrl.'/rules/'.$rule->id.'/test')->assertUnprocessable();
        $this->postJson($this->remindersUrl.'/rules/'.$rule->id.'/test', ['confirmed' => true])->assertOk();
        $this->assertTrue((bool) DB::table('asset_reminder_runs')->first()->is_test);
        $this->assertStringContainsString('测试消息', DB::table('asset_reminder_runs')->first()->message);
    }

    public function test_edit_and_stop_are_blocked_while_group_delivery_is_in_progress(): void
    {
        $group = $this->reminderGroup();
        $rule = $this->reminderRule($group);
        $lock = \Illuminate\Support\Facades\Cache::lock('asset-reminder:delivery:'.$group, 300);
        $this->assertTrue($lock->get());
        try {
            $this->putJson($this->remindersUrl.'/rules/'.$rule->id, $this->ruleValues($group, ['version' => 1]))->assertUnprocessable();
            $this->deleteJson($this->remindersUrl.'/rules/'.$rule->id)->assertUnprocessable();
            $this->assertTrue((bool) DB::table('asset_reminder_rules')->find($rule->id)->enabled);
        } finally { $lock->release(); }
    }

    public function test_shared_group_edit_requires_every_referenced_department_scope(): void
    {
        $group = $this->reminderGroup();
        $this->reminderRule($group, ['department_id' => $this->teamB->id]);
        DB::table('asset_computer_department_bindings')->where('target_department_id', $this->teamB->id)->delete();
        $this->putJson($this->remindersUrl.'/groups/'.$group, $this->groupValues(['version' => 1, 'name' => 'Redirected']))->assertForbidden();
        $this->assertSame('Daily group', DB::table('asset_reminder_groups')->find($group)->name);
    }

    public function test_snapshot_month_count_does_not_repeat_same_person_on_multiple_reminder_rounds(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $this->boundMember($this->teamA);
        $service = app(ReminderRecapService::class);
        $date = Carbon::parse('2026-11-19', 'Asia/Shanghai');
        $first = $service->summary([$rule], $this->company, $date);
        $second = $service->summary([$rule], $this->company, $date);
        $this->assertSame(1, $first['count']);
        $this->assertSame(2, $first['monthly_count']);
        $this->assertSame(1, $first['monthly_people']);
        $this->assertSame($first, $second);
        $this->assertSame('2026-11-18', $first['from']);
    }

    public function test_disabled_configuration_can_be_previewed_and_explicitly_tested_without_enabling_schedules(): void
    {
        $group = $this->reminderGroup();
        $rule = $this->reminderRule($group, ['type' => 'advertising', 'enabled' => false]);
        DB::table('asset_reminder_groups')->where('id', $group)->update(['enabled' => false]);
        $this->getJson($this->remindersUrl.'/rules/'.$rule->id.'/preview')->assertOk();
        $this->postJson($this->remindersUrl.'/rules/'.$rule->id.'/test', ['confirmed' => true])->assertOk();
        $this->assertSame(0, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $this->assertSame(1, DB::table('asset_reminder_runs')->count());
        $this->assertFalse((bool) DB::table('asset_reminder_rules')->find($rule->id)->enabled);
    }
}
