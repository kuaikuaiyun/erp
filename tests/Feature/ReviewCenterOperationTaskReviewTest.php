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
use App\Portals\ReviewCenter\ReviewCenterPendingCountService;
use App\Portals\ReviewCenter\Http\Controllers\DepartmentBindingController;
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
            $table->id(); $table->string('name'); $table->string('username'); $table->unsignedTinyInteger('status')->nullable()->default(1); $table->timestamps();
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
        DB::table('portal_departments')->insert(['portal_id' => 2, 'department_id' => 1]);
        DB::table('admin_role_users')->insert(['role_id' => 2, 'user_id' => 8, 'position' => 'member']);
        $this->bind(8, 2);
    }

    public function test_dashboard_uses_bound_departments_and_keeps_all_task_links_inside_review_center(): void
    {
        (require database_path('migrations/2026_11_18_000100_install_review_center_operation_task_dashboard.php'))->up();
        $task = $this->task();
        $this->asset($task);
        // The same member has an out-of-scope historical task: it must not leak through membership.
        $outside = $this->task(3);
        $this->asset($outside);
        $this->get(route('review-center.operation-tasks.dashboard'))
            ->assertOk()->assertSee(__('review_center.dashboard'))
            ->assertViewHas('teamRows', function ($teams) use ($task): bool {
                self::assertSame([2], $teams->pluck('id')->all());
                $member = $teams->first()->members->firstWhere('id', 7);
                self::assertSame(1, $member->single_count);
                self::assertSame(1, $member->initial_assets);
                self::assertSame([$task->id], array_column($member->task_records, 'id'));
                self::assertSame(route('review-center.operation-tasks.tests.show', $task), $member->task_records[0]['url']);
                return true;
            })->assertViewHas('tasks', fn ($tasks) => $tasks->modelKeys() === [$task->id]);
        $this->get(route('review-center.operation-tasks.dashboard', ['team_search' => '无匹配部门']))
            ->assertOk()->assertViewHas('teamRows', fn ($teams) => $teams->isEmpty());
    }

    public function test_dashboard_menu_access_is_independent_and_root_bindings_expand(): void
    {
        $migration = require database_path('migrations/2026_11_18_000100_install_review_center_operation_task_dashboard.php');
        $migration->up();
        $migration->up();
        self::assertSame(1, DB::table('portal_menus')->where('code', 'review_center_operation_task_dashboard')->count());
        DB::table('portal_menus')->where('code', 'review_center_operation_tasks')->update(['is_enabled' => false]);
        $this->get(route('review-center.operation-tasks.dashboard'))->assertOk();
        DB::table('review_center_department_bindings')->delete();
        $this->bind(8, 1);
        $this->get(route('review-center.operation-tasks.dashboard'))->assertOk()
            ->assertViewHas('teamRows', fn ($teams) => $teams->pluck('id')->sort()->values()->all() === [2, 3]);
        $this->menuAllowed = false;
        $this->get(route('review-center.operation-tasks.dashboard'))->assertForbidden();
        $this->menuAllowed = true;
        $migration->down();
        $this->get(route('review-center.operation-tasks.dashboard'))->assertForbidden();
        $migration->up();
        DB::table('review_center_department_bindings')->delete();
        $this->get(route('review-center.operation-tasks.dashboard'))->assertForbidden();
        $this->actor->setAttribute('system_admin', true);
        $this->get(route('review-center.operation-tasks.dashboard'))->assertOk();
    }

    public function test_dashboard_preserves_submission_dates_financial_totals_plans_and_historical_supplements(): void
    {
        (require database_path('migrations/2026_11_18_000100_install_review_center_operation_task_dashboard.php'))->up();
        $first = $this->pendingRecap();
        $first->update(['data_date' => '2026-11-11', 'submitted_at' => '2026-11-12 16:00:00', 'total_revenue_usd' => 120, 'total_spend_usd' => 40, 'total_orders' => 3]);
        $other = $this->pendingRecap();
        $other->update(['data_date' => '2026-11-10', 'department_id' => 3, 'submitted_at' => '2026-11-13 01:00:00', 'total_revenue_usd' => 900]);
        $task = $this->task();
        $task->update(['created_at' => '2026-11-12 01:00:00']);
        $asset = $this->asset($task);
        $asset->update(['created_at' => '2026-11-13 01:00:00']);
        $newTask = $this->task();
        $this->asset($newTask);
        DB::table('operation_task_daily_plans')->insert(['user_id' => 7, 'user_name' => '测试运营', 'department_id' => 2, 'plan_date' => '2026-11-13', 'plan_mode' => 'single', 'single_target' => 1, 'category_target' => 0]);
        $this->get(route('review-center.operation-tasks.dashboard', ['from' => '2026-11-13', 'to' => '2026-11-13', 'person_search' => '测试运营']))
            ->assertOk()->assertViewHas('recaps', fn ($recaps) => $recaps->modelKeys() === [$first->id])
            ->assertViewHas('teamRows', function ($teams): bool {
                $member = $teams->first()->members->sole();
                self::assertSame(120.0, $member->revenue);
                self::assertSame(40.0, $member->spend);
                self::assertSame(3, $member->orders);
                self::assertSame(1, $member->recap_days);
                self::assertSame(1, $member->single_count);
                self::assertSame(1, $member->supplement_assets);
                self::assertSame(1, $member->planned_days);
                self::assertSame(1, $member->completed_days);
                self::assertSame('2026-11-11', $member->recap_day_records[0]['date']);
                self::assertSame('2026-11-13 00:00', $member->recap_day_records[0]['submitted_at']);
                return true;
            });
        $this->get(route('review-center.operation-tasks.dashboard', ['from' => '2026-11-14', 'to' => '2026-11-13']))->assertSessionHasErrors('to');
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
        self::assertSame(['member'], DB::table('admin_role_users')->where('user_id', 8)->pluck('position')->all());
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

    private function createMember(int $id, ?int $departmentId, ?int $status = 1): void
    {
        DB::table('admin_users')->insert(['id' => $id, 'name' => '成员'.$id, 'username' => 'member'.$id, 'status' => $status]);
        if ($departmentId !== null) DB::table('admin_role_users')->insert(['role_id' => $departmentId, 'user_id' => $id, 'position' => 'member']);
    }

    private function pendingRecap(int $departmentId = 2): OperationTaskRecap
    {
        return OperationTaskRecap::create([
            'user_id' => 7, 'user_name' => '测试运营', 'department_id' => $departmentId,
            'data_date' => sprintf('2026-10-%02d', OperationTaskRecap::withTrashed()->count() + 1),
            'submitted_at' => now(), 'media_items' => [], 'finding_answer' => '发现', 'action_answer' => '行动', 'advertising_plan' => '计划',
            'version' => 1, 'workflow_status' => 'pending_leader', 'leader_review_status' => 'pending', 'department_review_status' => 'pending',
        ])->fresh();
    }

    private function taskReview(OperationTestTask $task, string $channel = 'department', string $result = 'approved', ?int $version = null): void
    {
        \App\Models\OperationTaskReview::create([
            'target_type' => 'task', 'target_id' => $task->id, 'target_version' => $version ?? $task->version,
            'channel' => $channel, 'result' => $result, 'reviewer_id' => 8, 'reviewer_name' => '审核员', 'reviewed_at' => now(),
        ]);
    }

    public function test_binding_members_follow_portal_grants_account_status_and_administrator_access(): void
    {
        $this->createMember(10, 3);
        $this->createMember(11, 4);
        $this->createMember(12, 2, 0);
        $this->createMember(13, null);
        $this->createMember(14, 1);
        $this->createMember(15, 2, null);
        DB::table('admin_roles')->insert(['id' => 5, 'name' => '系统管理员', 'slug' => 'administrator', 'parent_id' => null]);
        $this->createMember(16, 5);
        DB::table('admin_role_users')->insert(['role_id' => 3, 'user_id' => 8, 'position' => 'member']);
        $this->actor->setAttribute('system_admin', true);
        $data = app(DepartmentBindingController::class)->index(Request::create('/review-center/department-bindings'))->getData();
        self::assertEqualsCanonicalizing([7, 8, 10, 14, 15, 16], $data['users']->pluck('id')->all());
        self::assertSame(1, $data['users']->where('id', 8)->count());

        // A child-only grant is not a valid root grant in the platform's portal access model.
        DB::table('portal_departments')->where('portal_id', 2)->update(['department_id' => 2]);
        self::assertSame([16], app(ReviewCenterAuthorizationService::class)->authorizedMembers($this->portal)->pluck('id')->all());
    }

    public function test_foreign_disabled_and_revoked_members_cannot_be_selected_or_saved(): void
    {
        $this->createMember(10, 4);
        $this->createMember(11, 2, 0);
        $this->actor->setAttribute('system_admin', true);
        foreach ([10, 11] as $memberId) {
            $this->getJson(route('review-center.department-bindings.index', ['user_id' => $memberId]))->assertUnprocessable()->assertJsonValidationErrors('user_id');
            $this->putJson(route('review-center.department-bindings.update'), ['user_id' => $memberId, 'department_ids' => [2, 3]])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        }
        DB::table('portal_departments')->where('portal_id', 2)->delete();
        $this->putJson(route('review-center.department-bindings.update'), ['user_id' => 8, 'department_ids' => [1]])->assertUnprocessable();
        self::assertSame([2], DB::table('review_center_department_bindings')->where('user_id', 8)->pluck('department_id')->all());
        self::assertSame(0, DB::table('review_center_department_binding_logs')->count());
        $this->get(route('review-center.department-bindings.index'))->assertOk()->assertSee(__('review_center.no_authorized_members'))->assertDontSee('id="binding-member" name="user_id" class="form-control"><option', false);
    }

    public function test_each_authorized_member_can_select_and_reopen_multiple_departments_independently(): void
    {
        $this->actor->setAttribute('system_admin', true);
        $this->put(route('review-center.department-bindings.update'), ['user_id' => 7, 'department_ids' => [2, 3]])->assertRedirect();
        $this->put(route('review-center.department-bindings.update'), ['user_id' => 8, 'department_ids' => [1, 4]])->assertRedirect();
        foreach ([7 => [2, 3], 8 => [1, 4]] as $memberId => $ids) {
            $data = app(DepartmentBindingController::class)->index(Request::create('/review-center/department-bindings', 'GET', ['user_id' => $memberId]))->getData();
            self::assertSame($memberId, $data['selectedUserId']);
            self::assertEqualsCanonicalizing($ids, $data['selectedIds']);
            $html = $this->get(route('review-center.department-bindings.index', ['user_id' => $memberId]))->assertOk()->getContent();
            foreach ($ids as $id) self::assertMatchesRegularExpression('/name="department_ids\[\]" value="'.$id.'"\s+checked/', $html);
        }
        $this->put(route('review-center.department-bindings.update'), ['user_id' => 8])->assertRedirect();
        self::assertSame([2, 3], DB::table('review_center_department_bindings')->where('user_id', 7)->orderBy('department_id')->pluck('department_id')->all());
    }

    public function test_pending_counts_use_bound_scope_current_version_and_department_channel(): void
    {
        $task = $this->task(); $this->asset($task); $this->asset($task); $this->asset($task);
        $this->task(3);
        $old = $this->task(); $old->update(['version' => 2]); $this->taskReview($old, 'department', 'approved', 1);
        $this->taskReview($this->task());
        $this->taskReview($this->task(), 'department', 'rejected');
        $this->taskReview($this->task(), 'leader');
        $this->task()->delete();
        $this->pendingRecap(); $this->pendingRecap(3);
        $this->pendingRecap()->update(['department_review_status' => 'approved']);
        $this->pendingRecap()->update(['department_review_status' => 'rejected']);
        $this->pendingRecap()->update(['leader_review_status' => 'rejected', 'workflow_status' => 'rejected']);
        $this->pendingRecap()->delete();
        $counts = app(ReviewCenterPendingCountService::class)->forUser($this->portal, $this->actor);
        self::assertSame(['recaps' => 2, 'tests' => 3], $counts);
        $this->bind(7, 3);
        self::assertSame(['recaps' => 1, 'tests' => 1], app(ReviewCenterPendingCountService::class)->forUser($this->portal, AdminUser::findOrFail(7)));
        $this->bind(8, 1);
        self::assertSame(['recaps' => 3, 'tests' => 4], app(ReviewCenterPendingCountService::class)->forUser($this->portal, $this->actor));
    }

    public function test_badges_refresh_after_reviews_and_ignore_page_search_and_date_filters(): void
    {
        $task = $this->task(); $asset = $this->asset($task); $recap = $this->pendingRecap();
        $url = route('review-center.operation-tasks.reviews.index', ['from' => '2027-01-01', 'person_search' => '不存在的运营']);
        $this->get($url)->assertOk()
            ->assertSee('class="operation-review-count operation-review-count-recap">复 1', false)
            ->assertSee('class="operation-review-count operation-review-count-test">测 1', false);
        $this->postJson(route('review-center.operation-tasks.reviews.batch.store'), $this->payload($task, $asset))->assertOk();
        $this->get($url)->assertOk()->assertSee('class="operation-review-count operation-review-count-recap">复 1', false)
            ->assertDontSee('class="operation-review-count operation-review-count-test"', false);
        $this->post(route('review-center.operation-tasks.recaps.reviews.store', $recap), ['channel' => 'department', 'version' => 1, 'result' => 'approved'])->assertRedirect();
        $this->get($url)->assertOk()->assertDontSee('class="operation-review-counts"', false);
    }

    public function test_pending_counts_do_not_disclose_data_without_portal_menu_or_binding_access(): void
    {
        $this->task(); $this->pendingRecap();
        $counts = app(ReviewCenterPendingCountService::class);
        self::assertSame(['recaps' => 1, 'tests' => 1], $counts->forUser($this->portal, $this->actor));
        $this->menuAllowed = false;
        self::assertSame(['recaps' => 0, 'tests' => 0], $counts->forUser($this->portal, $this->actor));
        $this->menuAllowed = true;
        DB::table('portal_menus')->where('code', 'review_center_operation_tasks')->update(['is_enabled' => false]);
        self::assertSame(['recaps' => 0, 'tests' => 0], $counts->forUser($this->portal, $this->actor));
        DB::table('portal_menus')->where('code', 'review_center_operation_tasks')->update(['is_enabled' => true]);
        $this->actor->setAttribute('status', 0);
        self::assertSame(['recaps' => 0, 'tests' => 0], $counts->forUser($this->portal, $this->actor));
        $this->actor->setAttribute('status', 1);
        DB::table('review_center_department_bindings')->where('user_id', 8)->delete();
        self::assertSame(['recaps' => 0, 'tests' => 0], $counts->forUser($this->portal, $this->actor));
        $this->bind(8, 2);
        DB::table('portal_departments')->where('portal_id', 2)->delete();
        self::assertSame(['recaps' => 0, 'tests' => 0], $counts->forUser($this->portal, $this->actor));
    }

    public function test_private_menu_badges_survive_menu_grouping_and_are_present_on_the_binding_page(): void
    {
        $this->task();
        $parentId = DB::table('portal_menus')->insertGetId(['portal_id' => 2, 'parent_id' => 0, 'code' => 'review_group', 'title' => '审核分组', 'title_key' => '', 'path' => '#', 'permission_slug' => null, 'icon' => 'fa-folder', 'sort' => 5, 'is_enabled' => true]);
        DB::table('portal_menus')->where('code', 'review_center_operation_tasks')->update(['parent_id' => $parentId]);
        $this->get(route('review-center.operation-tasks.reviews.index'))->assertOk()->assertSee('class="operation-review-count operation-review-count-test">测 1', false)->assertDontSee(__('review_center.department_bindings'));
        $this->actor->setAttribute('system_admin', true);
        $this->get(route('review-center.department-bindings.index'))->assertOk()->assertSee('class="operation-review-count operation-review-count-test">测 1', false);
        foreach (['layout.blade.php', 'partials/menu.blade.php'] as $view) self::assertNotEmpty(token_get_all(app('blade.compiler')->compileString(file_get_contents(resource_path('views/portals/review-center/'.$view))), TOKEN_PARSE));
    }

}
