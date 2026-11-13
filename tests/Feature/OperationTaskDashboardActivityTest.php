<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\OperationTaskAsset;
use App\Models\OperationTaskRecap;
use App\Models\OperationTestTask;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\OperationTasks\Http\Controllers\OperationTaskController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationTaskDashboardActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        DB::purge('sqlite');
        app()->setLocale('zh-CN');
        $this->travelTo(now('UTC')->setDate(2026, 11, 13)->setTime(4, 0));
        foreach ([
            '2026_11_10_000014_install_operation_task_system.php',
            '2026_11_10_000017_add_operation_task_rectification_flow.php',
            '2026_11_11_000003_add_operation_task_recap_question_templates.php',
            '2026_11_11_000004_upgrade_operation_task_workflow.php',
            '2026_11_11_000006_add_recap_review_workflow.php',
            '2026_11_13_000100_add_operation_task_deletion_tracking.php',
        ] as $migration) (require database_path('migrations/'.$migration))->up();
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('username'); $table->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('slug'); $table->unsignedInteger('parent_id')->nullable(); $table->integer('sort')->default(0); $table->timestamps();
        });
        Schema::create('admin_role_users', function (Blueprint $table): void {
            $table->unsignedInteger('role_id'); $table->unsignedInteger('user_id'); $table->string('position'); $table->string('position_slot')->nullable(); $table->boolean('is_primary')->default(true); $table->timestamps();
        });
        Schema::create('portal_departments', function (Blueprint $table): void {
            $table->id(); $table->unsignedInteger('portal_id'); $table->unsignedInteger('department_id'); $table->boolean('includes_descendants')->default(false);
        });

        Schema::create('internal_messages', function (Blueprint $table): void {
            $table->id(); $table->string('title'); $table->text('content'); $table->unsignedInteger('sender_user_id');
            $table->string('sender_name'); $table->string('sender_portal_code'); $table->string('sender_portal_name'); $table->timestamp('published_at'); $table->timestamps();
        });
        Schema::create('internal_message_recipients', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('internal_message_id'); $table->unsignedInteger('recipient_user_id'); $table->timestamps();
        });
        DB::table('admin_users')->insert(['id' => 7, 'name' => '测试运营', 'username' => 'operator']);
        DB::table('admin_roles')->insert([['id' => 1, 'name' => '服装部', 'slug' => 'fashion', 'parent_id' => null], ['id' => 2, 'name' => '测试组', 'slug' => 'team', 'parent_id' => 1]]);
        DB::table('admin_role_users')->insert(['role_id' => 2, 'user_id' => 7, 'position' => 'member']);
        DB::table('portal_departments')->insert(['portal_id' => 1, 'department_id' => 2]);
        $actor = (new class extends AdminUser { public function isAdministrator(): bool { return true; } })->forceFill(['id' => 99, 'name' => '管理员']);
        $portal = (new Portal())->forceFill(['id' => 1, 'code' => 'fuzhuang']);
        $this->mock(PortalEntryController::class)->shouldReceive('portalContext')->andReturn(['portal' => $portal, 'user' => $actor]);
    }

    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    private function task(string $created, string $submitted): OperationTestTask
    {
        return OperationTestTask::query()->create([
            'task_no' => 'OT-'.bin2hex(random_bytes(4)), 'owner_user_id' => 7, 'owner_name' => '测试运营', 'department_id' => 2, 'department_name' => '测试组',
            'page_type' => 'single', 'task_type' => 'new', 'landing_url' => 'https://example.test', 'selection_objective' => '验证卖点',
            'created_at' => $created, 'submitted_at' => $submitted,
        ]);
    }

    private function asset(OperationTestTask $task, string $created): void
    {
        OperationTaskAsset::query()->create(['task_id' => $task->id, 'uploader_user_id' => 7, 'uploader_name' => '测试运营', 'selling_point' => '卖点', 'external_url' => 'https://example.test/creative', 'created_at' => $created]);
    }

    private function recap(string $businessDate, string $submitted): OperationTaskRecap
    {
        return OperationTaskRecap::query()->create(['user_id' => 7, 'user_name' => '测试运营', 'department_id' => 2, 'data_date' => $businessDate,
            'submitted_at' => $submitted, 'media_items' => [], 'finding_answer' => '发现', 'action_answer' => '动作', 'advertising_plan' => '计划', 'total_revenue_usd' => 100]);
    }

    private function dashboard(array $filters): array
    {
        $request = Request::create(route('operation-tasks.dashboard'), 'GET', $filters);
        return app(OperationTaskController::class)->dashboard($request)->getData();
    }

    public function test_historical_additions_do_not_become_new_products_in_daily_or_multi_day_ranges(): void
    {
        $task = $this->task('2026-11-11 03:00:00', '2026-11-12 04:00:00');
        $this->asset($task, '2026-11-11 03:00:00');
        $this->asset($task, '2026-11-12 02:00:00');
        $this->asset($task, '2026-11-12 03:00:00');
        $daily = $this->dashboard(['from' => '2026-11-12', 'to' => '2026-11-12']);
        $member = $daily['teamRows']->sole()->members->sole();
        self::assertSame(0, $member->single_count);
        self::assertSame(0, $member->initial_assets);
        self::assertSame(2, $member->supplement_assets);
        self::assertSame($task->task_no, $member->task_records[0]['task_no']);
        self::assertSame(2, $member->task_records[0]['supplement_count']);
        $range = $this->dashboard(['from' => '2026-11-11', 'to' => '2026-11-12']);
        $member = $range['teamRows']->sole()->members->sole();
        self::assertSame(1, $member->single_count);
        self::assertSame(1, $member->initial_assets);
        self::assertSame(2, $member->supplement_assets);
    }

    public function test_beijing_submission_day_finds_yesterdays_recap_and_keeps_multiple_business_dates(): void
    {
        $this->recap('2026-11-12', '2026-11-12 16:00:00');
        $this->recap('2026-11-10', '2026-11-13 15:59:59');
        $this->recap('2026-11-09', '2026-11-12 15:59:59');
        $this->recap('2026-11-08', '2026-11-13 16:00:00');
        $result = $this->dashboard(['from' => '2026-11-13', 'to' => '2026-11-13']);
        self::assertSame(2, $result['recaps']->count());
        $member = $result['teamRows']->sole()->members->sole();
        self::assertSame(1, $member->recap_days);
        self::assertSame(200.0, $member->revenue);
        self::assertCount(2, $member->recap_day_records);
        self::assertSame(['2026-11-10', '2026-11-12'], array_column($member->recap_day_records, 'date'));
        $source = str_replace(["@extends('portal.layout')", "@include('portals.operation-tasks.partials.head')"], '', file_get_contents(resource_path('views/portals/operation-tasks/dashboard.blade.php')));
        $html = \Illuminate\Support\Facades\Blade::render($source."\n@yield('content')", array_merge($result, ['errors' => new \Illuminate\Support\ViewErrorBag()]));
        self::assertStringContainsString('2026-11-13 00:00', $html);
        self::assertStringContainsString('2026-11-12', $html);
    }

    public function test_all_time_has_no_artificial_start_and_includes_tasks_without_assets(): void
    {
        $this->task('2026-11-01 00:00:00', '2026-11-01 00:00:00');
        $result = $this->dashboard(['from' => '', 'to' => '', 'all_time' => 1]);
        self::assertSame('', $result['from']);
        self::assertSame('', $result['to']);
        self::assertSame(1, $result['teamRows']->sole()->members->sole()->single_count);
    }

    public function test_incremental_migration_keeps_old_rows_and_rollback_refuses_to_destroy_duplicate_history(): void
    {
        $old = $this->recap('2026-11-12', '2026-11-13 01:00:00');
        $old->update(['active_slot' => null, 'deleted_by' => 7]); $old->delete();
        $this->recap('2026-11-12', '2026-11-13 02:00:00');
        self::assertSame(2, OperationTaskRecap::withTrashed()->count());
        try {
            (require database_path('migrations/2026_11_13_000100_add_operation_task_deletion_tracking.php'))->down();
            self::fail('Rollback should preserve duplicate archived history.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('archived', $exception->getMessage());
        }
        self::assertTrue(Schema::hasColumn('operation_task_recaps', 'deleted_at'));
        self::assertSame(2, OperationTaskRecap::withTrashed()->count());
    }

    private function batchPayload(OperationTestTask $task): array
    {
        $task = $task->fresh();
        $assets = $task->assets()->get();
        return [
            'task_id' => $task->id, 'task_version' => $task->version, 'channel' => 'leader', 'task_result' => 'approved',
            'assets' => $assets->mapWithKeys(fn ($asset) => [$asset->id => ['result' => 'approved']])->all(),
            'asset_versions' => $assets->mapWithKeys(fn ($asset) => [$asset->id => $asset->version])->all(),
        ];
    }

    public function test_review_payload_rejects_a_stale_task_or_changed_asset_list_without_saving(): void
    {
        $task = $this->task('2026-11-11 03:00:00', '2026-11-11 03:00:00');
        $this->asset($task, '2026-11-11 03:00:00');
        $payload = $this->batchPayload($task);
        $task->update(['version' => 2]);
        $this->postJson(route('operation-tasks.reviews.batch.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('task_id');
        $payload = $this->batchPayload($task->fresh());
        $this->asset($task, '2026-11-12 03:00:00');
        $this->postJson(route('operation-tasks.reviews.batch.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('assets');
        self::assertSame(0, DB::table('operation_task_reviews')->count());
    }

    public function test_review_center_sends_versioned_forms_and_leader_approval_opens_daily(): void
    {
        $task = $this->task('2026-11-11 03:00:00', '2026-11-11 03:00:00');
        $this->asset($task, '2026-11-11 03:00:00');
        $request = Request::create(route('operation-tasks.reviews.index'));
        $data = app(OperationTaskController::class)->reviews($request)->getData();
        $source = str_replace(["@extends('portal.layout')", "@include('portals.operation-tasks.partials.head')"], '', file_get_contents(resource_path('views/portals/operation-tasks/reviews.blade.php')));
        $html = \Illuminate\Support\Facades\Blade::render($source."\n@yield('content')", array_merge($data, ['errors' => new \Illuminate\Support\ViewErrorBag()]));
        self::assertStringContainsString('name="task_version" value="1"', $html);
        self::assertStringContainsString('name="asset_versions[', $html);
        self::assertLessThan(strpos($html, 'data-review-tab="recaps"'), strpos($html, 'data-review-tab="tests"'));
        $this->postJson(route('operation-tasks.reviews.batch.store'), $this->batchPayload($task))->assertOk()->assertJson(['ok' => true]);
        $asset = $task->assets()->first();
        self::assertTrue(\App\Portals\OperationTasks\Support\OperationTaskRules::dailyAllowed($task->fresh(), $asset));
    }
}
