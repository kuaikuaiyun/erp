<?php

namespace Tests\Feature;

require_once __DIR__.'/AssetRemindersTest.php';

use App\Models\DingTalkCompany;
use App\Models\DingTalkCompanyGrant;
use App\Portals\AssetManager\ReminderCalendarService;
use App\Portals\AssetManager\ReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssetReminderCalendarsTest extends TestCase
{
    use AssetRemindersEnvironment;

    private function values(string $month = '2026-11', string $type = 'work', int $version = 1): array
    {
        $date = Carbon::createFromFormat('!Y-m', $month); $days = [];
        for ($i = 1; $i <= $date->daysInMonth; $i++) $days[$date->copy()->day($i)->toDateString()] = $type;
        return ['company_id' => $this->company->id, 'month' => $month, 'version' => $version, 'days' => $days];
    }

    public function test_month_save_edit_audit_and_stale_conflict_are_atomic(): void
    {
        $data = $this->values();
        $this->putJson($this->remindersUrl.'/calendar', $data)->assertOk();
        $data['days']['2026-11-19'] = 'rest'; $data['version'] = 2;
        $this->putJson($this->remindersUrl.'/calendar', $data)->assertOk();
        $this->getJson($this->remindersUrl.'/calendar?company_id='.$this->company->id.'&month=2026-11')->assertOk()->assertJsonPath('days.2026-11-19', 'rest')->assertJsonPath('version', 3);
        $this->putJson($this->remindersUrl.'/calendar', $data)->assertConflict();
        $this->assertSame(3, DB::table('asset_reminder_calendar_logs')->count());
        $log = DB::table('asset_reminder_calendar_logs')->orderByDesc('id')->first();
        $this->assertSame('work', json_decode($log->before_days, true)['2026-11-19']);
        $this->assertSame('rest', json_decode($log->after_days, true)['2026-11-19']);
        $this->assertSame($this->hrActor->id, $log->actor_id);
    }

    public function test_complete_real_month_is_required_including_leap_day(): void
    {
        $data = $this->values('2028-02', 'rest', 0);
        $this->assertCount(29, $data['days']);
        unset($data['days']['2028-02-29']);
        $this->putJson($this->remindersUrl.'/calendar', $data)->assertUnprocessable();
        $data['days']['2028-03-01'] = 'work';
        $this->putJson($this->remindersUrl.'/calendar', $data)->assertUnprocessable();
        $this->putJson($this->remindersUrl.'/calendar', $this->values('2028-02', 'rest', 0))->assertOk();
        $this->getJson($this->remindersUrl.'/calendar?month=not-month')->assertUnprocessable();
    }

    public function test_member_can_read_own_group_company_but_cannot_write_or_read_other_companies(): void
    {
        $this->reminderGroup();
        $member = $this->hrMember($this->hrSource); $this->hrActor = $member;
        $this->getJson($this->remindersUrl.'/calendar?company_id='.$this->company->id.'&month=2026-11')->assertOk()->assertJsonPath('can_write', false);
        $this->putJson($this->remindersUrl.'/calendar', $this->values())->assertForbidden();
        $this->getJson($this->remindersUrl.'/calendar?company_id=99999&month=2026-11')->assertForbidden();
    }

    public function test_company_grant_revocation_blocks_calendar_edit(): void
    {
        DingTalkCompanyGrant::where('user_id', $this->hrActor->id)->delete();
        $this->putJson($this->remindersUrl.'/calendar', $this->values())->assertForbidden();
    }

    public function test_unconfigured_month_or_legacy_unlinked_rule_blocks_scheduled_send(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        DB::table('asset_reminder_rules')->where('id', $rule->id)->update(['calendar_company_id' => null]);
        $this->assertSame(1, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $this->assertSame(__('asset_reminders.calendar.unlinked'), DB::table('asset_reminder_rules')->find($rule->id)->last_error);
        DB::table('asset_reminder_rules')->where('id', $rule->id)->update(['calendar_company_id' => $this->company->id]);
        $this->travelTo(Carbon::parse('2026-12-01 10:00', 'Asia/Shanghai'));
        $this->assertSame(1, app(ReminderService::class)->process(now('Asia/Shanghai')));
        Http::assertNothingSent();
    }

    public function test_temporary_change_is_immediate_without_repeating_processed_round_or_catching_up(): void
    {
        $this->hrMember($this->teamA);
        $this->reminderRule($this->reminderGroup()); $service = app(ReminderService::class);
        $this->assertSame(0, $service->process(now('Asia/Shanghai'))); // Work round one at 10:00.
        app(ReminderCalendarService::class)->save($this->hrActor, $this->values('2026-11', 'rest'));
        $this->travelTo(Carbon::parse('2026-11-19 11:00', 'Asia/Shanghai'));
        $this->assertSame(0, $service->process(now('Asia/Shanghai'))); // Rest round one must not repeat.
        $this->travelTo(Carbon::parse('2026-11-19 13:59', 'Asia/Shanghai'));
        app(ReminderCalendarService::class)->save($this->hrActor, $this->values('2026-11', 'work', 2));
        $this->assertSame(0, $service->process(now('Asia/Shanghai'))); // No catchup.
        $this->travelTo(Carbon::parse('2026-11-19 14:00', 'Asia/Shanghai'));
        $this->assertSame(0, $service->process(now('Asia/Shanghai')));
        $this->assertSame(2, DB::table('asset_reminder_runs')->count());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => !str_contains($r->url(), '/robot/send'));
    }

    public function test_rest_to_work_change_enables_future_work_slot(): void
    {
        $this->reminderRule($this->reminderGroup());
        $this->travelTo(Carbon::parse('2026-11-21 09:59', 'Asia/Shanghai'));
        app(ReminderCalendarService::class)->save($this->hrActor, $this->values());
        $this->travelTo(Carbon::parse('2026-11-21 10:00', 'Asia/Shanghai'));
        $this->assertSame(0, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $this->assertSame(1, DB::table('asset_reminder_runs')->count());
    }

    public function test_company_mismatch_and_calendar_edit_during_delivery_are_blocked(): void
    {
        $group = $this->reminderGroup();
        $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group, ['calendar_company_id' => 99999]))->assertUnprocessable();
        $lock = Cache::lock('asset-reminder:delivery:'.$group, 300); $lock->get();
        try { $this->putJson($this->remindersUrl.'/calendar', $this->values())->assertUnprocessable(); }
        finally { $lock->release(); }
        $this->assertSame(1, DB::table('asset_reminder_calendar_logs')->count());
        $this->putJson($this->remindersUrl.'/calendar', $this->values())->assertOk();
    }

    public function test_existing_rules_are_not_implicitly_linked_by_migration(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        DB::table('asset_reminder_rules')->where('id', $rule->id)->update(['calendar_company_id' => null]);
        (require database_path('migrations/2026_11_20_000300_add_asset_reminder_calendars.php'))->up();
        $this->assertNull(DB::table('asset_reminder_rules')->find($rule->id)->calendar_company_id);
    }

    public function test_company_calendars_are_independent_and_deputy_can_edit_with_grant(): void
    {
        $other = $this->company->replicate(); $other->corp_id = 'calendar-other-company'; $other->login_slug = 'calendar-other-company'; $other->name = 'Other company'; $other->save();
        DingTalkCompanyGrant::create(['company_id' => $other->id, 'user_id' => $this->hrActor->id, 'created_by' => 1]);
        $data = $this->values('2026-11', 'rest', 0); $data['company_id'] = $other->id;
        $this->putJson($this->remindersUrl.'/calendar', $data)->assertOk();
        $this->getJson($this->remindersUrl.'/calendar?company_id='.$this->company->id.'&month=2026-11')->assertOk()->assertJsonPath('days.2026-11-19', 'work');
        $this->getJson($this->remindersUrl.'/calendar?company_id='.$other->id.'&month=2026-11')->assertOk()->assertJsonPath('days.2026-11-19', 'rest');
        $deputy = $this->hrMember($this->hrSource, 'deputy');
        DingTalkCompanyGrant::create(['company_id' => $this->company->id, 'user_id' => $deputy->id, 'created_by' => 1]);
        $this->hrActor = $deputy;
        $this->putJson($this->remindersUrl.'/calendar', $this->values())->assertOk();
    }

    public function test_day_selection_converts_utc_to_china_date_at_month_boundary(): void
    {
        $rule = $this->reminderRule($this->reminderGroup());
        $type = app(ReminderCalendarService::class)->dayType($rule, $this->company->id, Carbon::parse('2026-10-31 16:01:00', 'UTC'));
        $this->assertSame('rest', $type); // November 1 in China, not October 31.
    }
}
