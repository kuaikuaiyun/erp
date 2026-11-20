<?php

namespace Tests\Feature;

require_once __DIR__.'/AssetRemindersTest.php';

use App\Jobs\SendAssetSubmissionNotification;
use App\Models\DailyAccountOpening;
use App\Models\DailyAdvertisingRecharge;
use App\Models\DailyStoreLaunch;
use App\Portals\AssetManager\ReminderService;
use App\Portals\AssetManager\SubmissionNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssetSubmissionNotificationsTest extends TestCase
{
    use AssetRemindersEnvironment { setUp as private reminderSetUp; }

    protected function setUp(): void
    {
        $this->reminderSetUp(); Queue::fake();
        foreach (['daily_account_openings', 'daily_advertising_recharges', 'daily_store_launches'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('operator_user_id'); $t->string('operator_name');
                $t->unsignedBigInteger('operation_department_id'); $t->string('operation_department_name');
                $t->string('status')->default('pending'); $t->integer('quantity')->nullable();
                $t->json('entries')->nullable(); $t->decimal('total_amount', 15, 2)->nullable();
                $t->string('project_name')->nullable(); $t->string('website_system_name')->nullable(); $t->timestamps();
            });
        }
    }

    private function configuration(int $group): int
    {
        return app(ReminderService::class)->save($this->hrActor, 'rules', [
            'department_ids' => [$this->teamA->id, $this->teamB->id], 'type' => 'submission',
            'group_id' => $group, 'enabled' => true, 'submission_types' => SubmissionNotificationService::TYPES,
        ], null);
    }

    private function requestData(): array
    {
        return ['operator_user_id' => $this->hrMember($this->teamA)->id, 'operator_name' => '测试提报人',
            'operation_department_id' => $this->teamA->id, 'operation_department_name' => $this->teamA->name,
            'status' => 'pending', 'quantity' => 2];
    }

    public function test_all_three_submission_types_are_queued_after_commit_and_sent_once(): void
    {
        $group = $this->reminderGroup(); $id = $this->configuration($group);
        $service = app(SubmissionNotificationService::class);
        foreach ([DailyAccountOpening::class, DailyAdvertisingRecharge::class, DailyStoreLaunch::class] as $model) {
            $data = $this->requestData();
            if ($model === DailyAdvertisingRecharge::class) $data += ['entries' => [['amount' => 20]], 'total_amount' => 20];
            $record = $service->create($model, $data);
            $service->capture($record);
        }
        $this->assertSame(3, DB::table('asset_reminder_runs')->count());
        Queue::assertPushed(SendAssetSubmissionNotification::class);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/send'));
        foreach (DB::table('asset_reminder_runs')->pluck('id') as $runId) { $service->send($runId); $service->send($runId); }
        $this->assertSame(3, DB::table('asset_reminder_runs')->where('status', 'accepted')->count());
        $this->assertCount(3, Http::recorded(fn ($r) => str_contains($r->url(), '/robot/send')));
        $text = DB::table('asset_reminder_runs')->first()->message;
        $this->assertStringContainsString('测试提报人', $text);
        $this->assertStringContainsString('开户数量：2', $text);
        $this->get($this->remindersUrl)->assertOk()->assertSee('日常提报通知');
        $this->getJson($this->remindersUrl.'/rules/'.$id.'/preview')->assertOk();
        $this->postJson($this->remindersUrl.'/rules/'.$id.'/test', ['confirmed' => true])->assertUnprocessable();
    }

    public function test_rollback_and_unselected_department_do_not_send(): void
    {
        $this->configuration($this->reminderGroup()); $service = app(SubmissionNotificationService::class);
        DB::beginTransaction(); $service->create(DailyAccountOpening::class, $this->requestData()); DB::rollBack();
        $this->assertSame(0, DB::table('asset_reminder_runs')->count()); Queue::assertNothingPushed();
        $data = $this->requestData(); $data['operation_department_id'] = $this->outside->id; $data['operator_user_id'] = $this->hrMember($this->outside)->id;
        $service->create(DailyAccountOpening::class, $data);
        $this->assertSame(0, DB::table('asset_reminder_runs')->count());
    }

    public function test_deleting_a_rule_cancels_waiting_messages_preserves_logs_and_frees_the_scope(): void
    {
        $group = $this->reminderGroup(); $id = $this->configuration($group);
        $service = app(SubmissionNotificationService::class); $service->create(DailyAccountOpening::class, $this->requestData());
        $this->deleteJson($this->remindersUrl.'/rules/'.$id.'?permanent=1')->assertOk();
        $this->getJson($this->remindersUrl.'/rules/'.$id)->assertNotFound();
        $row = DB::table('asset_reminder_rules')->find($id); $this->assertNotNull($row->deleted_at);
        $run = DB::table('asset_reminder_runs')->first(); $service->send($run->id);
        $this->assertSame('cancelled', DB::table('asset_reminder_runs')->first()->status);
        $this->assertGreaterThan($id, $this->configuration($group));
        $this->assertSame(1, DB::table('asset_reminder_runs')->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/send'));
    }

    public function test_notification_failure_does_not_undo_submission_and_is_not_retried(): void
    {
        $this->configuration($this->reminderGroup()); $service = app(SubmissionNotificationService::class);
        $record = $service->create(DailyAccountOpening::class, $this->requestData()); $this->sendTimeout = true;
        $run = DB::table('asset_reminder_runs')->first(); $service->send($run->id); $service->send($run->id);
        $this->assertNotNull(DailyAccountOpening::find($record->id));
        $this->assertSame('uncertain', DB::table('asset_reminder_runs')->first()->status);
        $this->assertStringNotContainsString('webhook-test-token', DB::table('asset_reminder_runs')->first()->error);
    }

    public function test_recap_is_disabled_in_scheduler_preview_and_direct_submission(): void
    {
        $group = $this->reminderGroup();
        $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group, ['type' => 'recap']))->assertUnprocessable();
        $this->postJson($this->remindersUrl.'/rules', $this->ruleValues($group, ['type' => 'recap', 'enabled' => false]))->assertUnprocessable();
        $rule = $this->reminderRule($group); DB::table('asset_reminder_rules')->where('id', $rule->id)->update(['type' => 'recap']);
        $this->assertSame(0, app(ReminderService::class)->process(now('Asia/Shanghai')));
        $this->getJson($this->remindersUrl.'/rules/'.$rule->id.'/preview')->assertUnprocessable()->assertJsonPath('message', __('asset_reminders.recap_disabled'));
        $this->postJson($this->remindersUrl.'/rules/'.$rule->id.'/test', ['confirmed' => true])->assertUnprocessable();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/send'));
    }

    public function test_database_queue_runs_the_committed_notification_with_the_default_sync_driver(): void
    {
        // Use a real in-memory database queue, rather than the fake used by the other tests.
        app()->forgetInstance('queue'); Queue::clearResolvedInstance('queue');
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        $this->configuration($this->reminderGroup());
        app(SubmissionNotificationService::class)->create(DailyAccountOpening::class, $this->requestData());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'asset-notifications')->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/send'));
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'asset-notifications', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('accepted', DB::table('asset_reminder_runs')->first()->status);
    }

    public function test_authorization_revoked_after_submission_blocks_delivery(): void
    {
        $this->configuration($this->reminderGroup());
        $service = app(SubmissionNotificationService::class); $service->create(DailyAccountOpening::class, $this->requestData());
        DB::table('asset_computer_department_bindings')->where('target_department_id', $this->teamB->id)->delete();
        $service->send(DB::table('asset_reminder_runs')->first()->id);
        $this->assertSame('cancelled', DB::table('asset_reminder_runs')->first()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/send'));
    }

    public function test_pending_recovery_does_not_requeue_uncertain_messages(): void
    {
        $this->configuration($this->reminderGroup());
        $service = app(SubmissionNotificationService::class); $service->create(DailyAccountOpening::class, $this->requestData());
        $this->assertSame(0, $service->recover());
        $this->travel(3)->minutes();
        $this->assertSame(1, $service->recover());
        $this->assertSame(0, $service->recover());
        $this->sendTimeout = true; $service->send(DB::table('asset_reminder_runs')->first()->id);
        $this->travel(3)->minutes(); $this->assertSame(0, $service->recover());
    }

    public function test_unselected_request_type_is_not_notified_and_stopping_cancels_pending_messages(): void
    {
        $group = $this->reminderGroup(); $id = $this->configuration($group);
        DB::table('asset_reminder_rules')->where('id', $id)->update(['submission_types' => '["store-launch"]']);
        $service = app(SubmissionNotificationService::class);
        $service->create(DailyAccountOpening::class, $this->requestData());
        $this->assertSame(0, DB::table('asset_reminder_runs')->count());
        $service->create(DailyStoreLaunch::class, $this->requestData());
        $this->deleteJson($this->remindersUrl.'/rules/'.$id)->assertOk();
        $this->getJson($this->remindersUrl.'/rules/'.$id)->assertOk();
        $service->send(DB::table('asset_reminder_runs')->first()->id);
        $this->assertSame('cancelled', DB::table('asset_reminder_runs')->first()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/robot/send'));
    }

    public function test_notification_storage_failure_is_diagnosed_without_rolling_back_the_request(): void
    {
        $this->configuration($this->reminderGroup());
        Schema::drop('asset_reminder_runs');
        \Illuminate\Support\Facades\Log::spy();
        $record = app(SubmissionNotificationService::class)->create(DailyAccountOpening::class, $this->requestData());
        $this->assertNotNull(DailyAccountOpening::find($record->id));
        Queue::assertNothingPushed();
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(fn ($event, $metadata) => $event === 'asset_reminder.check_failed' && $metadata['stage'] === 'submission_capture' && ! isset($metadata['sql'], $metadata['bindings'], $metadata['exception']));
    }

    public function test_reminders_and_notifications_share_the_list_filters_and_toolbar(): void
    {
        $group = $this->reminderGroup(); $this->configuration($group); $this->reminderRule($group);
        $response = $this->get($this->remindersUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->assertSame(1, substr_count($response->getContent(), 'data-ar-guide="robot"'));
        $this->assertSame(1, substr_count($response->getContent(), 'data-ar-notification'));
        $response->assertSee('提交后即时通知')->assertSee('提醒与通知规则');
        $this->get($this->remindersUrl.'?type=submission')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->type === 'submission');
        $this->get($this->remindersUrl.'?type=advertising')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->type === 'advertising');
        $this->get($this->remindersUrl.'?department_id='.$this->teamB->id)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    }

    public function test_child_membership_matches_parent_owned_requests_without_notifying_sibling_teams(): void
    {
        $parent = \App\Models\Department::create(['name' => '一级运营', 'slug' => 'notification-root']);
        $this->teamA->update(['parent_id' => $parent->id]);
        $this->outside->update(['parent_id' => $parent->id]);
        $this->configuration($this->reminderGroup());
        $service = app(SubmissionNotificationService::class);
        foreach ([DailyAccountOpening::class, DailyAdvertisingRecharge::class, DailyStoreLaunch::class] as $model) {
            $data = $this->requestData(); $data['operation_department_id'] = $parent->id;
            $data['operation_department_name'] = $parent->name;
            $record = $service->create($model, $data);
            $this->assertSame($parent->id, (int) $record->fresh()->operation_department_id);
        }
        $this->assertSame(3, DB::table('asset_reminder_runs')->count());
        $payload = json_decode(DB::table('asset_reminder_runs')->first()->snapshot, true);
        $this->assertSame([$this->teamA->id], $payload['matched_department_ids']);
        $data = $this->requestData(); $data['operation_department_id'] = $parent->id;
        $data['operator_user_id'] = $this->hrMember($this->outside)->id;
        $service->create(DailyAccountOpening::class, $data);
        $this->assertSame(3, DB::table('asset_reminder_runs')->count());
        foreach (DB::table('asset_reminder_runs')->pluck('id') as $id) $service->send($id);
        $this->assertSame(3, DB::table('asset_reminder_runs')->where('status', 'accepted')->count());
    }

    public function test_submission_membership_snapshot_survives_later_member_transfer(): void
    {
        $group = $this->reminderGroup(); $id = $this->configuration($group);
        $data = $this->requestData(); $service = app(SubmissionNotificationService::class);
        $member = \App\Models\AdminUser::find($data['operator_user_id']);
        $member->roles()->attach($this->teamB->id, ['position' => 'member']);
        $record = $service->create(DailyAccountOpening::class, $data); $service->capture($record);
        $this->assertSame(1, DB::table('asset_reminder_runs')->count());
        $member->roles()->detach([$this->teamA->id, $this->teamB->id]);
        $service->send(DB::table('asset_reminder_runs')->first()->id);
        $this->assertSame('accepted', DB::table('asset_reminder_runs')->first()->status);
    }
}
