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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

trait AssetRemindersEnvironment
{
    use AssetAccountsEnvironment { setUp as private accountsSetUp; }

    protected string $remindersUrl = '/asset-manager/reminders';
    protected array $scheduleOverrides = [];
    protected bool $sendTimeout = false;
    protected bool $attendanceFailure = false;
    protected array $webhookResponse = ['errcode' => 0, 'errmsg' => 'ok'];
    protected array $apiFailures = [];

    protected function setUp(): void
    {
        $this->accountsSetUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00', 'Asia/Shanghai'));
        (require database_path('migrations/2026_11_19_000100_install_asset_reminders.php'))->up();
        (require database_path('migrations/2026_11_19_000200_add_webhook_to_asset_reminder_groups.php'))->up();
        (require database_path('migrations/2026_11_19_000300_add_departments_to_asset_reminder_rules.php'))->up();
        (require database_path('migrations/2026_11_20_000100_extend_asset_reminder_notifications.php'))->up();
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
            if (isset($this->apiFailures[$path])) {
                $failure = $this->apiFailures[$path];
                if ($failure === 'timeout') throw new ConnectionException('secret-test reminder-token webhook-test-token SECtestSigningKey');
                return Http::response($failure['body'], $failure['status'] ?? 200);
            }
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
            if ($path === '/robot/send') {
                if ($this->sendTimeout) throw new ConnectionException('webhook-test-token SECtestSigningKey must not leak');
                return Http::response($this->webhookResponse);
            }
            throw new \LogicException('Unexpected reminder HTTP request');
        });
    }

    protected function groupValues(array $overrides = []): array
    {
        return array_replace(['department_id' => $this->teamA->id, 'name' => 'Daily group', 'company_id' => $this->company->id,
            'webhook' => 'https://oapi.dingtalk.com/robot/send?access_token=webhook-test-token', 'webhook_secret' => '',
            'enabled' => true, 'remark' => 'Reminder test'], $overrides);
    }

    protected function reminderGroup(): int
    {
        return app(ReminderService::class)->save($this->hrActor, 'groups', $this->groupValues(), null);
    }

    protected function ruleValues(int $group, array $overrides = []): array
    {
        return array_replace(['department_id' => $this->teamA->id, 'group_id' => $group, 'type' => 'advertising', 'enabled' => true,
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

    public function test_department_options_include_hierarchy_without_exposing_unauthorized_children(): void
    {
        $parent = \App\Models\Department::create(['name' => '一级测试部门', 'slug' => 'reminder-parent']);
        $this->teamA->update(['parent_id' => $parent->id]);
        $this->outside->update(['parent_id' => $parent->id]);
        $response = $this->getJson($this->remindersUrl.'/options')->assertOk();
        $rows = collect($response->json('departments'));
        $this->assertSame($parent->id, $rows->firstWhere('id', $this->teamA->id)['parent_id']);
        $this->assertSame($parent->name, $rows->firstWhere('id', $this->teamA->id)['parent_name']);
        $this->assertFalse($rows->contains('id', $this->outside->id));
    }

    public function test_three_round_limit_and_default_link_preserve_existing_configuration(): void
    {
        $group = $this->reminderGroup();
        $values = $this->ruleValues($group);
        unset($values['entry_url']);
        $values['work_times'] = ['16:00', '10:00', '13:00'];
        $id = $this->postJson($this->remindersUrl.'/rules', $values)->assertOk()->json('id');
        $stored = DB::table('asset_reminder_rules')->find($id);
        $this->assertSame('https://www.kuaiyun8888.com/kky/', $stored->entry_url);
        $this->assertSame('["10:00","13:00","16:00"]', $stored->work_times);
        $values['version'] = 1; $values['entry_url'] = 'https://example.com/custom';
        foreach (['work_times', 'rest_times'] as $key) {
            $invalid = $values; $invalid[$key] = ['10:00', '11:00', '12:00', '13:00'];
            $this->putJson($this->remindersUrl.'/rules/'.$id, $invalid)->assertUnprocessable();
        }
        $this->putJson($this->remindersUrl.'/rules/'.$id, $values)->assertOk();
        $this->assertSame('https://example.com/custom', DB::table('asset_reminder_rules')->find($id)->entry_url);
        DB::table('asset_reminder_rules')->where('id', $id)->update(['work_times' => '["10:00","11:00","12:00","13:00"]']);
        $this->getJson($this->remindersUrl.'/rules/'.$id)->assertOk()->assertJsonPath('record.work_times', '["10:00","11:00","12:00","13:00"]');
    }

    public function test_multiple_departments_share_one_rule_and_deduplicate_members(): void
    {
        $group = $this->reminderGroup();
        $values = $this->ruleValues($group);
        unset($values['department_id']);
        $values['department_ids'] = [$this->teamA->id, $this->teamB->id];
        $response = $this->postJson($this->remindersUrl.'/rules', $values)->assertOk();
        $id = $response->json('id');
        $this->assertSame(1, DB::table('asset_reminder_rules')->count());
        $member = $this->hrMember($this->teamA);
        $member->roles()->attach($this->teamB->id, ['position' => 'member']);
        $other = $this->hrMember($this->teamB);
        $rule = DB::table('asset_reminder_rules')->find($id);
        $summary = app(ReminderRecapService::class)->summary([$rule], $this->company, Carbon::parse('2026-11-18'), false);
        $this->assertSame(2, $summary['count']);
        $this->get($this->remindersUrl.'?department_id='.$this->teamB->id)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $preview = $this->getJson($this->remindersUrl.'/rules/'.$id.'/preview')->assertOk();
        $this->assertStringContainsString('广告费业绩提报提醒', $preview->json('message'));
        $this->postJson($this->remindersUrl.'/rules/'.$id.'/test', ['confirmed' => true])->assertOk();
        $this->assertCount(2, json_decode(DB::table('asset_reminder_runs')->first()->department_ids, true));
        $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group, ['department_id' => $this->teamB->id]))->assertUnprocessable();
        $values['version'] = 1; $values['work_times'] = ['15:00'];
        $this->putJson($this->remindersUrl.'/rules/'.$id, $values)->assertOk();
        $this->assertSame('["15:00"]', DB::table('asset_reminder_rules')->find($id)->work_times);
        DB::table('asset_computer_department_bindings')->where('target_department_id', $this->teamB->id)->delete();
        $this->getJson($this->remindersUrl.'/rules/'.$id)->assertNotFound();
        $this->getJson($this->remindersUrl.'/runs?group_id='.$group)->assertOk()->assertJsonPath('total', 0);
        $this->putJson($this->remindersUrl.'/groups/'.$group, $this->groupValues(['version' => 1]))->assertForbidden();
    }

    public function test_multi_department_validation_and_stop_are_atomic(): void
    {
        $group = $this->reminderGroup();
        foreach ([[], [$this->teamA->id, $this->teamA->id], [$this->teamA->id, $this->outside->id]] as $ids) {
            $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group, ['department_ids' => $ids]))->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('asset_reminder_rules')->count());
        $values = $this->ruleValues($group, ['department_ids' => [$this->teamA->id, $this->teamB->id]]);
        $id = $this->postJson($this->remindersUrl.'/rules', $values)->assertOk()->json('id');
        $this->deleteJson($this->remindersUrl.'/rules/'.$id)->assertOk();
        $this->assertFalse((bool) DB::table('asset_reminder_rules')->find($id)->enabled);
        $this->assertSame(0, app(ReminderService::class)->process(now('Asia/Shanghai')));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/send'));
    }

    public function test_page_configuration_and_menu_installation_are_repeatable(): void
    {
        (require database_path('migrations/2026_11_19_000100_install_asset_reminders.php'))->up();
        $this->assertSame(1, DB::table('portal_menus')->where('code', 'asset_manager_reminders')->count());
        $this->get($this->remindersUrl)->assertOk()->assertSee('asset-reminders.js')->assertDontSee('secret-test');
        $this->getJson($this->remindersUrl.'/options')->assertOk()->assertJsonCount(2, 'departments')->assertJsonCount(1, 'companies')->assertDontSee('app_secret');
        $this->postJson($this->remindersUrl.'/groups', $this->groupValues())->assertOk();
        $group = DB::table('asset_reminder_groups')->first();
        $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group->id))->assertOk();
        $this->get($this->remindersUrl.'?type=advertising&per_page=20')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
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
        $this->putJson($this->remindersUrl.'/groups/'.$group, $this->groupValues(['version' => 1, 'webhook' => 'https://oapi.dingtalk.com/robot/send?access_token=another-token']))->assertOk();
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
        $this->assertSame(1, $summary['count']);
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

    public function test_department_members_need_no_binding_and_future_hires_remain_excluded(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $this->hrMember($this->teamA);
        $future = $this->hrMember($this->teamA); $future->hired_at = '2026-11-19'; $future->save();
        $this->scheduleOverrides['rest-person|2026-11-18'] = 'Y';
        $summary = app(ReminderRecapService::class)->summary([$rule], $this->company, Carbon::parse('2026-11-18', 'Asia/Shanghai'));
        $this->assertSame(1, $summary['count']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/schedule/listbyusers'));
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
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/send'));
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

    public function test_webhook_credentials_are_encrypted_and_never_returned_by_page_options_or_details(): void
    {
        $values = $this->groupValues(['webhook_secret' => 'SECtestSigningKey']);
        $id = app(ReminderService::class)->save($this->hrActor, 'groups', $values, null);
        $stored = DB::table('asset_reminder_groups')->find($id);
        $this->assertNotSame($values['webhook'], $stored->webhook);
        $this->assertNotSame($values['webhook_secret'], $stored->webhook_secret);
        $this->assertSame($values['webhook'], Crypt::decryptString($stored->webhook));
        $this->assertSame($values['webhook_secret'], Crypt::decryptString($stored->webhook_secret));
        $this->assertNull($stored->robot_code);
        $this->assertNull($stored->conversation_id);
        foreach ([$this->remindersUrl, $this->remindersUrl.'/options', $this->remindersUrl.'/groups/'.$id] as $url) {
            $response = $this->get($url, ['Accept' => 'application/json'])->assertOk();
            foreach ([$values['webhook'], 'webhook-test-token', $values['webhook_secret'], $stored->webhook, $stored->webhook_secret, $stored->webhook_hash] as $private) {
                $response->assertDontSee($private, false);
            }
            $response->assertDontSee('robot_code')->assertDontSee('conversation_id');
        }
        $this->getJson($this->remindersUrl.'/groups/'.$id)->assertJsonPath('record.webhook_configured', true)->assertJsonPath('record.webhook_secret_configured', true);
        DB::table('admin_role_users')->where('user_id', $this->hrActor->id)->update(['position' => 'member']);
        $this->hrActor->unsetRelation('roles')->unsetRelation('departments');
        $this->getJson($this->remindersUrl.'/groups/'.$id)->assertOk()->assertDontSee('webhook-test-token')->assertDontSee('SECtestSigningKey');
        $this->putJson($this->remindersUrl.'/groups/'.$id, $values + ['version' => 1])->assertForbidden();
    }

    public function test_blank_fields_preserve_credentials_and_explicit_clear_removes_only_the_signing_secret(): void
    {
        $id = app(ReminderService::class)->save($this->hrActor, 'groups', $this->groupValues(['webhook_secret' => 'SECtestSigningKey']), null);
        $stored = DB::table('asset_reminder_groups')->find($id);
        $this->putJson($this->remindersUrl.'/groups/'.$id, $this->groupValues(['version' => 1, 'webhook' => '', 'webhook_secret' => '']))->assertOk();
        $updated = DB::table('asset_reminder_groups')->find($id);
        $this->assertSame($stored->webhook, $updated->webhook);
        $this->assertSame($stored->webhook_secret, $updated->webhook_secret);
        $this->putJson($this->remindersUrl.'/groups/'.$id, $this->groupValues(['version' => 2, 'webhook' => '', 'webhook_secret' => 'SECnewKey', 'clear_webhook_secret' => true]))->assertUnprocessable();
        $this->putJson($this->remindersUrl.'/groups/'.$id, $this->groupValues(['version' => 2, 'webhook' => '', 'clear_webhook_secret' => true]))->assertOk();
        $updated = DB::table('asset_reminder_groups')->find($id);
        $this->assertSame($stored->webhook, $updated->webhook);
        $this->assertNull($updated->webhook_secret);
        $this->putJson($this->remindersUrl.'/groups/'.$id, $this->groupValues(['version' => 3, 'webhook' => 'https://oapi.dingtalk.com/robot/send?access_token=replacement-token', 'webhook_secret' => 'SECnewKey']))->assertOk();
        $updated = DB::table('asset_reminder_groups')->find($id);
        $this->assertSame('SECnewKey', Crypt::decryptString($updated->webhook_secret));
        $this->assertStringContainsString('replacement-token', Crypt::decryptString($updated->webhook));
    }

    public function test_only_original_official_webhook_urls_are_accepted_and_duplicates_are_blocked(): void
    {
        foreach (['', 'http://oapi.dingtalk.com/robot/send?access_token=token', 'https://example.com/robot/send?access_token=token',
            'https://oapi.dingtalk.com@127.0.0.1/robot/send?access_token=token', 'https://oapi.dingtalk.com.evil.example/robot/send?access_token=token',
            'https://oapi.dingtalk.com:8443/robot/send?access_token=token', 'https://oapi.dingtalk.com/robot/send?access_token=token&timestamp=1&sign=temporary',
            'https://oapi.dingtalk.com/robot/send?access_token=token#fragment'] as $url) {
            $this->postJson($this->remindersUrl.'/groups', $this->groupValues(['webhook' => $url]))->assertUnprocessable();
        }
        $this->postJson($this->remindersUrl.'/groups', $this->groupValues(['webhook_secret' => 'not-a-signing-key']))->assertUnprocessable();
        $this->reminderGroup();
        $this->postJson($this->remindersUrl.'/groups', $this->groupValues(['department_id' => $this->teamB->id]))->assertUnprocessable();
        $this->assertSame(1, DB::table('asset_reminder_groups')->count());
        Http::assertNothingSent();
    }

    public function test_incremental_migration_stops_legacy_destinations_and_preserves_rules_and_history(): void
    {
        $id = $this->reminderGroup();
        $rule = $this->reminderRule($id, ['type' => 'advertising']);
        $this->assertSame(0, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $history = (array) DB::table('asset_reminder_runs')->first();
        $legacy = (array) DB::table('asset_reminder_groups')->find($id);
        unset($legacy['webhook'], $legacy['webhook_secret'], $legacy['webhook_hash']);
        $legacy['robot_code'] = 'old-robot'; $legacy['conversation_id'] = 'old-conversation';
        // Recreate the original schema inside this in-memory fixture to exercise a populated upgrade.
        Schema::drop('asset_reminder_groups');
        (require database_path('migrations/2026_11_19_000100_install_asset_reminders.php'))->up();
        DB::table('asset_reminder_groups')->insert($legacy);
        $migration = require database_path('migrations/2026_11_19_000200_add_webhook_to_asset_reminder_groups.php');
        $migration->up();
        $migration->up();
        $stored = DB::table('asset_reminder_groups')->find($id);
        $this->assertFalse((bool) $stored->enabled);
        $this->assertSame(2, (int) $stored->version);
        $this->assertSame('old-conversation', $stored->conversation_id);
        $this->assertSame($history, (array) DB::table('asset_reminder_runs')->first());
        $this->assertTrue((bool) DB::table('asset_reminder_rules')->find($rule->id)->enabled);
        $this->getJson($this->remindersUrl.'/groups/'.$id)->assertOk()->assertJsonPath('record.webhook_configured', false)->assertDontSee('old-robot')->assertDontSee('old-conversation');
        $this->putJson($this->remindersUrl.'/groups/'.$id, $this->groupValues(['version' => 2, 'webhook' => '']))->assertUnprocessable();
        $this->postJson($this->remindersUrl.'/rules/'.$rule->id.'/test', ['confirmed' => true])->assertUnprocessable();
        $this->assertSame(1, Http::recorded(fn ($r) => str_contains($r->url(), '/robot/send'))->count());
        $this->putJson($this->remindersUrl.'/groups/'.$id, $this->groupValues(['version' => 2]))->assertOk();
        $migration->up();
        $this->assertTrue((bool) DB::table('asset_reminder_groups')->find($id)->enabled);
        $this->assertSame(3, (int) DB::table('asset_reminder_groups')->find($id)->version);
    }






    public function test_runtime_scope_failure_is_shown_without_exposing_internal_details(): void
    {
        $group = $this->reminderGroup();
        $rule = $this->reminderRule($group);
        DB::table('asset_reminder_groups')->where('id', $group)->update(['updated_by' => 999999]);
        $this->getJson($this->remindersUrl.'/rules/'.$rule->id.'/preview')->assertUnprocessable()
            ->assertJsonPath('message', __('asset_reminders.check_group_authorization'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/send'));
    }


}
