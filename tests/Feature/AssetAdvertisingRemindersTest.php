<?php

namespace Tests\Feature;

require_once __DIR__.'/AssetRemindersTest.php';

use App\Models\AdminUser;
use App\Models\Department;
use App\Portals\AssetManager\ReminderAdvertisingService;
use App\Portals\AssetManager\ReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssetAdvertisingRemindersTest extends TestCase
{
    use AssetRemindersEnvironment;

    private function report(AdminUser $owner, string $source = 'operation', string $date = '2026-11-19', array $extra = []): int
    {
        $table = $source === 'operation' ? 'operation_reports' : 'fashion_advertising_reports';
        $key = $source === 'operation' ? 'operator_user_id' : 'submitter_id';
        $values = [$key => $owner->id, 'report_date' => $date, 'status' => 'pending', 'created_at' => now('UTC'), 'updated_at' => now('UTC')];
        if ($source !== 'operation') $values['project_code'] = $source;
        return DB::table($table)->insertGetId(array_replace($values, $extra));
    }

    private function summary(object $rule, bool $persist = false): array
    {
        return app(ReminderAdvertisingService::class)->summary([$rule], now('Asia/Shanghai'), $persist);
    }

    public function test_any_of_five_systems_completes_member_and_all_statuses_count(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        foreach (['operation', 'fuzhuang', 'baokuan-fashion', 'baokuan-general', 'danbao-general'] as $i => $source) {
            $person = $this->hrMember($this->teamA);
            $this->report($person, $source, extra: ['status' => ['pending', 'approved', 'rejected'][$i % 3]]);
        }
        $missing = $this->hrMember($this->teamA);
        $summary = $this->summary($rule);
        $this->assertSame(1, $summary['count']);
        $this->assertSame([$missing->id], array_column($summary['departments'][$this->teamA->id], 'id'));
        $this->assertSame(1, $summary['monthly_count']);
        Http::assertNothingSent();
    }

    public function test_cross_department_and_cross_source_members_are_deduplicated(): void
    {
        $group = $this->reminderGroup();
        $a = $this->reminderRule($group); $b = $this->reminderRule($group, ['department_id' => $this->teamB->id]);
        $member = $this->hrMember($this->teamA); $member->roles()->attach($this->teamB->id, ['position' => 'member']);
        $service = app(ReminderAdvertisingService::class);
        $summary = $service->summary([$b, $a], now('Asia/Shanghai'), true);
        $this->assertSame(1, $summary['count']); $this->assertSame(1, $summary['monthly_count']); $this->assertSame(1, $summary['monthly_people']);
        $this->assertSame([$this->teamA->id, $this->teamB->id], array_keys($summary['departments']));
        foreach ($summary['departments'] as $members) $this->assertSame([$member->id], array_column($members, 'id'));
        $this->report($member); $this->report($member, 'fuzhuang');
        $completed = $service->summary([$a, $b], now('Asia/Shanghai'), false);
        $this->assertSame(0, $completed['count']); $this->assertSame([], $completed['departments']);
    }

    public function test_one_rule_lists_member_in_each_selected_department_but_not_unselected_memberships(): void
    {
        $rule = $this->reminderRule($this->reminderGroup(), ['department_ids' => [$this->teamA->id, $this->teamB->id]]);
        $shared = $this->hrMember($this->teamA);
        $shared->roles()->attach($this->teamB->id, ['position' => 'member']);
        $shared->roles()->attach($this->outside->id, ['position' => 'member']);
        $single = $this->hrMember($this->teamA);
        $summary = $this->summary($rule, true);
        $this->assertSame(2, $summary['count']); $this->assertSame(2, $summary['monthly_count']); $this->assertSame(2, $summary['monthly_people']);
        $this->assertSame([$shared->id, $single->id], array_column($summary['departments'][$this->teamA->id], 'id'));
        $this->assertSame([$shared->id], array_column($summary['departments'][$this->teamB->id], 'id'));
        $this->assertArrayNotHasKey($this->outside->id, $summary['departments']);
        $message = app(ReminderService::class)->build([$rule], now('Asia/Shanghai'), 1, false, true)['text'];
        $this->assertStringContainsString('本次未提交人数：2人（按账号去重）', $message);
        $this->assertStringContainsString('Team A（2人）', $message);
        $this->assertStringContainsString('Team B（1人）', $message);
        $this->report($shared, 'fuzhuang');
        $completed = $this->summary($rule);
        $this->assertSame(1, $completed['count']); $this->assertSame(1, $completed['monthly_count']);
        $this->assertArrayNotHasKey($this->teamB->id, $completed['departments']);
        $this->assertSame([$single->id], array_column($completed['departments'][$this->teamA->id], 'id'));
    }

    public function test_department_display_change_preserves_legacy_snapshots_and_unique_monthly_person_dates(): void
    {
        $rule = $this->reminderRule($this->reminderGroup(), ['department_ids' => [$this->teamA->id, $this->teamB->id]]);
        $shared = $this->hrMember($this->teamA); $shared->roles()->attach($this->teamB->id, ['position' => 'member']);
        $this->summary($rule, true);
        $before = DB::table('asset_advertising_reminder_days')->where('data_date', '2026-11-19')->value('snapshot');
        $this->travelTo(Carbon::parse('2026-11-20 10:00', 'Asia/Shanghai'));
        $summary = $this->summary($rule, true);
        $this->assertSame(1, $summary['count']); $this->assertSame(2, $summary['monthly_count']); $this->assertSame(1, $summary['monthly_people']);
        $this->assertCount(2, $summary['departments']);
        $this->assertSame($before, DB::table('asset_advertising_reminder_days')->where('data_date', '2026-11-19')->value('snapshot'));
    }

    public function test_only_active_employed_selected_members_are_expected_without_order_requirements(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $eligible = $this->hrMember($this->teamA);
        $disabled = $this->hrMember($this->teamA); $disabled->forceFill(['status' => 0])->save();
        $resigned = $this->hrMember($this->teamA); $resigned->forceFill(['employment_status' => 'resigned'])->save();
        $future = $this->hrMember($this->teamA); $future->forceFill(['hired_at' => '2026-11-20'])->save();
        $departed = $this->hrMember($this->teamA); $departed->forceFill(['resigned_at' => '2026-11-19'])->save();
        $this->hrMember($this->outside);
        $queries = []; DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });
        $summary = $this->summary($rule);
        $this->assertSame(1, $summary['count']);
        $this->assertSame($eligible->id, $summary['departments'][$this->teamA->id][0]['id']);
        $this->assertStringNotContainsString('logistics', implode(' ', $queries));
    }

    public function test_all_positions_are_checked_when_the_account_belongs_to_selected_department(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $this->hrMember($this->teamA, 'manager');
        $this->hrMember($this->teamA, 'deputy');
        $this->hrMember($this->teamA, 'member');
        $this->hrOwner->roles()->attach($this->teamA->id, ['position' => 'member']);
        $this->assertSame(4, $this->summary($rule)['count']);
    }

    public function test_report_business_date_owner_and_soft_deletion_determine_completion(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $a = $this->hrMember($this->teamA); $b = $this->hrMember($this->teamA);
        // A clicked submit on B's behalf; only B has completed.
        $this->report($b, extra: ['submitted_by' => $a->id]);
        $this->report($a, date: '2026-11-18');
        $deleted = $this->report($a, 'fuzhuang', extra: ['deleted_at' => now('UTC')]);
        $this->report($a, 'not-a-supported-system');
        $this->assertSame([$a->id], array_column($this->summary($rule)['departments'][$this->teamA->id], 'id'));
        DB::table('fashion_advertising_reports')->where('id', $deleted)->update(['deleted_at' => null]);
        $this->assertSame(0, $this->summary($rule)['count']);
    }

    public function test_monthly_counts_only_observed_days_and_late_submission_removes_missing_person_date(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        // Simulate an existing rule created before this feature was deployed.
        DB::table('asset_reminder_rules')->where('id', $rule->id)->update(['starts_on' => '2026-11-01']);
        $rule->starts_on = '2026-11-01';
        $person = $this->hrMember($this->teamA);
        $first = $this->summary($rule, true);
        $this->assertSame('2026-11-19', $first['from']); $this->assertSame(1, $first['monthly_count']);
        $this->travelTo(Carbon::parse('2026-11-20 10:00', 'Asia/Shanghai'));
        $this->assertSame(2, $this->summary($rule, true)['monthly_count']);
        $this->report($person, 'danbao-general', '2026-11-19');
        $summary = $this->summary($rule);
        $this->assertSame(1, $summary['monthly_count']); $this->assertSame(1, $summary['count']);
        $this->report($person, 'operation', '2026-11-20');
        $this->assertSame(0, $this->summary($rule)['monthly_count']);
    }

    public function test_today_roster_refreshes_but_new_members_do_not_rewrite_past_and_scope_removal_is_respected(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $first = $this->hrMember($this->teamA); $this->summary($rule, true);
        $this->travelTo(Carbon::parse('2026-11-20 10:00', 'Asia/Shanghai'));
        $new = $this->hrMember($this->teamA); $summary = $this->summary($rule, true);
        $this->assertSame(2, $summary['count']); $this->assertSame(3, $summary['monthly_count']);
        $old = DB::table('asset_advertising_reminder_days')->where('data_date', '2026-11-19')->value('snapshot');
        $this->assertSame([$first->id], array_column(json_decode($old, true), 'id'));
        $new->forceFill(['status' => 0])->save();
        $this->assertSame(1, $this->summary($rule, true)['count']);
        $rule->department_id = $this->teamB->id; $rule->department_ids = json_encode([$this->teamB->id]);
        $this->assertSame(0, $this->summary($rule)['monthly_count']);
    }

    public function test_preview_uses_today_in_china_and_does_not_write_rosters_or_send_messages(): void
    {
        $rule = $this->reminderRule($this->reminderGroup()); $person = $this->hrMember($this->teamA);
        $this->travelTo(Carbon::parse('2026-11-19 16:05', 'UTC')); // November 20, 00:05 in China.
        $this->report($person, date: '2026-11-19');
        $response = $this->getJson($this->remindersUrl.'/rules/'.$rule->id.'/preview')->assertOk();
        $this->assertStringContainsString('2026年11月20日', $response->json('message'));
        $this->assertStringContainsString('本次未提交人数：1人', $response->json('message'));
        $this->assertSame(0, DB::table('asset_advertising_reminder_days')->count());
        $this->assertSame(0, DB::table('asset_reminder_runs')->count()); Http::assertNothingSent();
    }

    public function test_every_round_rechecks_and_zero_missing_skips_real_send_but_explicit_test_is_allowed(): void
    {
        $rule = $this->reminderRule($this->reminderGroup()); $person = $this->hrMember($this->teamA);
        $service = app(ReminderService::class);
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertSame('accepted', DB::table('asset_reminder_runs')->first()->status);
        $this->report($person);
        $this->travelTo(Carbon::parse('2026-11-19 14:00', 'Asia/Shanghai'));
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertSame('skipped', DB::table('asset_reminder_runs')->orderByDesc('id')->first()->status);
        $this->assertSame(0, $service->process(now('Asia/Shanghai'))); Http::assertSentCount(1);
        $this->postJson($this->remindersUrl.'/rules/'.$rule->id.'/test', ['confirmed' => true])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_same_day_effective_rule_runs_and_missing_report_source_fails_closed(): void
    {
        $rule = $this->reminderRule($this->reminderGroup(), ['starts_on' => '2026-11-19']); $this->hrMember($this->teamA);
        Schema::drop('fashion_advertising_reports');
        $this->assertSame(1, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $run = DB::table('asset_reminder_runs')->first();
        $this->assertSame('failed', $run->status); $this->assertNull($run->message);
        $this->assertStringNotContainsString('SELECT', $run->error); Http::assertNothingSent();
        $this->assertNotNull(DB::table('asset_reminder_rules')->find($rule->id)->last_error);
    }

    public function test_rollout_does_not_resend_a_round_already_sent_by_old_pure_reminder(): void
    {
        $group = $this->reminderGroup(); $rule = $this->reminderRule($group); $this->hrMember($this->teamA);
        DB::table('asset_reminder_runs')->insert(['delivery_key' => str_repeat('a', 64), 'group_id' => $group, 'type' => 'advertising',
            'data_date' => '2026-11-18', 'slot' => '10:00', 'round' => 1, 'rule_ids' => json_encode([$rule->id]),
            'department_ids' => json_encode([$this->teamA->id]), 'status' => 'accepted', 'is_test' => false,
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $this->assertSame(0, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $this->assertSame(1, DB::table('asset_reminder_runs')->count()); Http::assertNothingSent();
    }

    public function test_migration_is_repeatable_and_existing_pure_reminder_history_is_not_modified(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $before = (array) DB::table('asset_reminder_rules')->find($rule->id);
        $migration = require database_path('migrations/2026_11_21_000100_add_asset_advertising_reminder_days.php');
        $migration->up(); $migration->up(); $migration->down();
        $this->assertSame($before, (array) DB::table('asset_reminder_rules')->find($rule->id));
        $this->assertSame(0, DB::table('asset_advertising_reminder_days')->count());
    }

    public function test_unchanged_mysql_formatted_roster_does_not_cause_minutely_writes(): void
    {
        $rule = $this->reminderRule($this->reminderGroup()); $this->hrMember($this->teamA);
        $service = app(ReminderAdvertisingService::class); $roster = $service->capture($rule, now('Asia/Shanghai'));
        DB::table('asset_advertising_reminder_days')->update(['snapshot' => json_encode($roster, JSON_PRETTY_PRINT), 'synced_at' => '2026-11-19 01:00:00']);
        $service->capture($rule, now('Asia/Shanghai'));
        $this->assertSame('2026-11-19 01:00:00', DB::table('asset_advertising_reminder_days')->value('synced_at'));
    }
}
