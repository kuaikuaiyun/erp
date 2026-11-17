<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\OperationTaskAsset;
use App\Models\OperationTaskRecap;
use App\Models\OperationTestTask;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portal\Services\PortalMenuAuthorizationService;
use App\Portals\ReviewCenter\ReviewCenterAuthorizationService;
use App\Portals\ReviewCenter\Http\Controllers\OperationTaskReviewController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReviewCenterOperationTaskReviewTest extends TestCase
{
    private AdminUser $actor;
    private Portal $portal;
    private bool $menuAllowed = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        DB::purge('sqlite');
        app()->setLocale('zh-CN');
        $this->travelTo(now('UTC')->setDate(2026, 11, 13)->setTime(4, 0));
        foreach ([
            '2026_09_28_000004_create_system_settings_tables.php',
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

        Schema::create('portals', function (Blueprint $table): void { $table->increments('id'); $table->string('code')->unique(); $table->string('name')->nullable(); $table->string('status')->default('enabled'); $table->timestamps(); });
        Schema::create('portal_menus', function (Blueprint $table): void {
            $table->increments('id'); $table->unsignedInteger('portal_id'); $table->unsignedInteger('parent_id')->default(0);
            $table->string('code'); $table->string('title')->nullable(); $table->string('title_key'); $table->string('path');
            $table->string('permission_slug')->nullable(); $table->string('icon'); $table->integer('sort'); $table->boolean('is_enabled'); $table->timestamps();
            $table->unique(['portal_id', 'code']);
        });
        Schema::create('admin_permissions', function (Blueprint $table): void { $table->increments('id'); $table->string('name')->unique(); $table->string('slug')->unique(); $table->string('http_method')->nullable(); $table->string('http_path')->nullable(); $table->timestamps(); });
        DB::table('portals')->insert(['id' => 2, 'code' => 'review-center', 'name' => '运营审核中心']);
        DB::table('admin_roles')->insert([['id' => 3, 'name' => '其他组', 'slug' => 'other-team', 'parent_id' => 1], ['id' => 4, 'name' => '外部部门', 'slug' => 'outside', 'parent_id' => null]]);
        DB::table('admin_users')->insert(['id' => 8, 'name' => '指定审核员', 'username' => 'reviewer']);
        (require database_path('migrations/2026_11_17_000100_install_review_center_operation_task_reviews.php'))->up();
        $this->actor = (new class extends AdminUser { public function isAdministrator(): bool { return (bool) $this->getAttribute('system_admin'); } })->forceFill(['id' => 8, 'name' => '指定审核员', 'username' => 'reviewer']);
        $this->portal = Portal::findOrFail(2);
        $this->mock(PortalEntryController::class)->shouldReceive('portalContext')->andReturnUsing(fn () => ['portal' => $this->portal, 'user' => $this->actor]);
        $this->mock(PortalMenuAuthorizationService::class)->shouldReceive('canAccess')->andReturnUsing(fn () => $this->menuAllowed);
        $this->bind(8, 2);
    }

    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    private function bind(int $user, int $department): void
    {
        DB::table('review_center_department_bindings')->insert(['user_id' => $user, 'department_id' => $department, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function task(int $department = 2): OperationTestTask
    {
        return OperationTestTask::create(['task_no' => 'OT-'.bin2hex(random_bytes(4)), 'owner_user_id' => 7, 'owner_name' => '测试运营', 'department_id' => $department, 'department_name' => '测试组', 'page_type' => 'single', 'task_type' => 'new', 'landing_url' => 'https://example.test', 'selection_objective' => '测试目标', 'submitted_at' => now(), 'version' => 1])->fresh();
    }

    private function asset(OperationTestTask $task): OperationTaskAsset
    {
        return OperationTaskAsset::create(['task_id' => $task->id, 'uploader_user_id' => 7, 'uploader_name' => '测试运营', 'selling_point' => '卖点', 'external_url' => 'https://example.test/creative', 'version' => 1])->fresh();
    }

    private function payload(OperationTestTask $task, OperationTaskAsset $asset): array
    {
        return ['task_id' => $task->id, 'task_version' => $task->version, 'asset_versions' => [$asset->id => $asset->version], 'channel' => 'department', 'assets' => [$asset->id => ['result' => 'approved']], 'task_result' => 'approved'];
    }

    public function test_root_and_secondary_bindings_expand_without_granting_organizational_roles(): void
    {
        $authorization = app(ReviewCenterAuthorizationService::class);
        self::assertSame([2], $authorization->departmentIds($this->actor));
        $this->bind(8, 1);
        self::assertEqualsCanonicalizing([1, 2, 3], $authorization->departmentIds($this->actor));
        $this->bind(7, 2);
        DB::table('review_center_department_bindings')->where('user_id', 8)->delete();
        self::assertSame([], $authorization->departmentIds($this->actor));
        self::assertSame(0, DB::table('admin_role_users')->where('user_id', 8)->count());
    }

    public function test_lists_and_archives_use_bound_departments_and_reject_foreign_task_assets(): void
    {
        $visible = $this->task(); $foreign = $this->task(3); $foreignAsset = $this->asset($foreign);
        $data = app(OperationTaskReviewController::class)->reviews(Request::create('/review-center/operation-tasks/reviews'))->getData();
        self::assertSame([$visible->id], $data['tasks']->pluck('id')->all());
        self::assertSame(['department'], array_keys($data['reviewChannels']));
        self::assertFalse($data['tasks']->first()->can_leader_review);
        $this->get(route('review-center.operation-tasks.tests.show', $foreign))->assertForbidden();
        $this->get(route('review-center.operation-tasks.assets.file', $foreignAsset))->assertForbidden();
    }

    public function test_reviewer_approves_shared_task_and_asset_as_department_only(): void
    {
        $task = $this->task(); $asset = $this->asset($task);
        $this->postJson(route('review-center.operation-tasks.reviews.batch.store'), $this->payload($task, $asset))->assertOk();
        self::assertSame(2, DB::table('operation_task_reviews')->where('channel', 'department')->where('reviewer_id', 8)->count());
        self::assertSame(0, DB::table('operation_task_reviews')->where('channel', 'leader')->count());
        self::assertSame(2, DB::table('operation_task_review_events')->where('channel', 'department')->count());
        self::assertSame('review-center', DB::table('internal_messages')->value('sender_portal_code'));
        $data = $this->payload($task, $asset); $data['channel'] = 'leader';
        $this->postJson(route('review-center.operation-tasks.reviews.batch.store'), $data)->assertForbidden();
    }

    public function test_foreign_task_and_revoked_binding_cannot_write_reviews(): void
    {
        $task = $this->task(3); $asset = $this->asset($task);
        $this->postJson(route('review-center.operation-tasks.reviews.batch.store'), $this->payload($task, $asset))->assertForbidden();
        DB::table('review_center_department_bindings')->where('user_id', 8)->delete();
        $this->get(route('review-center.operation-tasks.reviews.index'))->assertForbidden();
        self::assertSame(0, DB::table('operation_task_reviews')->count());
    }

    public function test_recap_department_review_is_independent_and_duplicate_submission_preserves_history(): void
    {
        $recap = OperationTaskRecap::create(['user_id' => 7, 'user_name' => '运营', 'department_id' => 2, 'data_date' => '2026-11-12', 'submitted_at' => now(), 'finding_answer' => '发现', 'action_answer' => '行动', 'advertising_plan' => '计划', 'workflow_status' => 'pending_leader', 'leader_review_status' => 'pending', 'department_review_status' => 'pending', 'media_items' => [], 'version' => 1])->fresh();
        $payload = ['channel' => 'department', 'version' => $recap->version, 'result' => 'approved'];
        $this->post(route('review-center.operation-tasks.recaps.reviews.store', $recap), $payload)->assertRedirect();
        self::assertSame('approved', $recap->fresh()->department_review_status);
        self::assertSame('pending', $recap->fresh()->leader_review_status);
        $this->post(route('review-center.operation-tasks.recaps.reviews.store', $recap), $payload)->assertRedirect();
        self::assertSame(1, DB::table('operation_task_review_events')->count());
    }

    public function test_rejection_requires_opinion_and_generates_shared_rectifications(): void
    {
        $task = $this->task(); $asset = $this->asset($task); $data = $this->payload($task, $asset);
        $data['task_result'] = 'rejected'; $data['rejection_scopes'] = ['task'];
        $this->postJson(route('review-center.operation-tasks.reviews.batch.store'), $data)->assertUnprocessable();
        self::assertSame(0, DB::table('operation_task_reviews')->count());
        $data['task_opinion'] = '修改落地页';
        $this->postJson(route('review-center.operation-tasks.reviews.batch.store'), $data)->assertOk();
        self::assertSame('required', DB::table('operation_task_rectifications')->value('status'));
        self::assertSame('department', DB::table('operation_task_rectifications')->value('review_channel'));
    }

    public function test_stale_task_or_asset_versions_are_atomic(): void
    {
        $task = $this->task(); $asset = $this->asset($task); $data = $this->payload($task, $asset);
        $data['task_version'] = 999;
        $this->postJson(route('review-center.operation-tasks.reviews.batch.store'), $data)->assertUnprocessable();
        $data = $this->payload($task, $asset); $data['asset_versions'][$asset->id] = 999;
        $this->postJson(route('review-center.operation-tasks.reviews.batch.store'), $data)->assertUnprocessable();
        self::assertSame(0, DB::table('operation_task_reviews')->count());
        self::assertSame(0, DB::table('operation_task_review_events')->count());
    }

    public function test_portal_menu_and_settings_boundaries_are_enforced(): void
    {
        $this->get(route('review-center.department-bindings.index'))->assertForbidden();
        $this->menuAllowed = false;
        $this->get(route('review-center.operation-tasks.reviews.index'))->assertForbidden();
        $this->menuAllowed = true;
        $this->portal->code = 'fuzhuang';
        $this->get(route('review-center.operation-tasks.reviews.index'))->assertNotFound();
        $this->portal->code = 'review-center';
        DB::table('portal_menus')->where('code', 'review_center_operation_tasks')->update(['is_enabled' => false]);
        $this->get(route('review-center.operation-tasks.reviews.index'))->assertForbidden();
    }

    public function test_admin_can_replace_and_clear_bindings_with_an_audit_trail(): void
    {
        $this->actor->setAttribute('system_admin', true);
        $this->get(route('review-center.department-bindings.index'))->assertOk();
        $this->put(route('review-center.department-bindings.update'), ['user_id' => 8, 'department_ids' => [1, 3]])->assertRedirect();
        self::assertEqualsCanonicalizing([1, 3], DB::table('review_center_department_bindings')->where('user_id', 8)->pluck('department_id')->all());
        $this->putJson(route('review-center.department-bindings.update'), ['user_id' => 8, 'department_ids' => [999]])->assertUnprocessable();
        $this->put(route('review-center.department-bindings.update'), ['user_id' => 8])->assertRedirect();
        self::assertSame(0, DB::table('review_center_department_bindings')->where('user_id', 8)->count());
        self::assertSame(2, DB::table('review_center_department_binding_logs')->count());
    }

    public function test_copied_views_compile_and_use_only_review_center_routes(): void
    {
        $compiler = app('blade.compiler');
        foreach (glob(resource_path('views/portals/review-center/operation-tasks/*.blade.php')) as $file) {
            $source = file_get_contents($file);
            self::assertStringNotContainsString("route('operation-tasks.", $source);
            self::assertNotEmpty(token_get_all($compiler->compileString($source), TOKEN_PARSE), $file);
        }
        foreach (glob(resource_path('views/portals/review-center/operation-tasks/partials/*.blade.php')) as $file) self::assertNotEmpty(token_get_all($compiler->compileString(file_get_contents($file)), TOKEN_PARSE), $file);
        self::assertNotEmpty(token_get_all($compiler->compileString(file_get_contents(resource_path('views/portals/review-center/department-bindings.blade.php'))), TOKEN_PARSE));
        $task = $this->task(); $this->asset($task);
        $this->get(route('review-center.operation-tasks.reviews.index'))->assertOk();
        $this->get(route('review-center.operation-tasks.tests.show', $task))->assertOk()->assertDontSee('id="ot-append-asset"', false);
    }
    public function test_installation_and_rollback_preserve_fashion_data_and_custom_menu_titles(): void
    {
        DB::table('portals')->insert(['id' => 1, 'code' => 'fuzhuang', 'name' => '服装运营系统']);
        DB::table('portal_menus')->insert(['portal_id' => 1, 'parent_id' => 0, 'code' => 'fashion_operation_task_reviews', 'title' => '原审核中心', 'title_key' => '', 'path' => '/fashion/operation-tasks/reviews', 'permission_slug' => null, 'icon' => 'fa-check', 'sort' => 1, 'is_enabled' => true]);
        DB::table('portal_menus')->where('code', 'review_center_operation_tasks')->update(['title' => '自定义标题']);
        $task = $this->task();
        $migration = require database_path('migrations/2026_11_17_000100_install_review_center_operation_task_reviews.php');
        $migration->up();
        self::assertSame(3, DB::table('portal_menus')->count());
        self::assertSame('自定义标题', DB::table('portal_menus')->where('code', 'review_center_operation_tasks')->value('title'));
        $migration->down();
        self::assertSame(1, (int) DB::table('portal_menus')->where('portal_id', 1)->value('is_enabled'));
        self::assertNotNull($task->fresh());
        self::assertSame(1, DB::table('review_center_department_bindings')->count());
    }

}
