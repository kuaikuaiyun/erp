<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\OperationTaskAsset;
use App\Models\OperationTaskDailyPlan;
use App\Models\OperationTestTask;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\OperationTasks\Http\Controllers\OperationTaskController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationTaskSubmissionTest extends TestCase
{
    private AdminUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        DB::purge('sqlite');
        app()->setLocale('zh-CN');
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(12, 0));
        Schema::create('operation_test_tasks', function (Blueprint $table): void {
            $table->id(); $table->string('task_no')->unique(); $table->unsignedInteger('owner_user_id');
            $table->string('owner_name'); $table->unsignedInteger('department_id')->nullable(); $table->string('department_name')->nullable();
            $table->string('page_type'); $table->string('task_type'); $table->string('landing_url'); $table->text('selection_objective');
            $table->dateTime('submitted_at'); $table->unsignedInteger('version')->default(1); $table->timestamps();
        });
        (require database_path('migrations/2026_11_12_000101_add_submission_token_to_operation_test_tasks.php'))->up();
        Schema::create('operation_task_assets', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('task_id'); $table->unsignedInteger('uploader_user_id'); $table->string('uploader_name');
            $table->text('selling_point'); $table->string('source_type'); $table->string('file_path')->nullable();
            $table->string('original_name')->nullable(); $table->string('external_url')->nullable(); $table->string('mime_type')->nullable();
            $table->boolean('ai_confirmed'); $table->json('version_history'); $table->timestamps();
        });
        Schema::create('portal_departments', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('portal_id'); $table->unsignedInteger('department_id'); $table->boolean('includes_descendants');
        });
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('slug'); $table->unsignedBigInteger('parent_id')->nullable(); $table->timestamps();
        });
        Schema::create('admin_role_users', function (Blueprint $table): void {
            $table->unsignedInteger('role_id'); $table->unsignedInteger('user_id'); $table->string('position')->nullable();
            $table->string('position_slot')->nullable(); $table->boolean('is_primary')->default(false); $table->timestamps();
        });
        $this->actor = (new AdminUser())->forceFill(['id' => 7, 'name' => '测试运营', 'username' => 'operator']);
        $portal = (new Portal())->forceFill(['id' => 1, 'code' => 'fuzhuang']);
        $entries = $this->mock(PortalEntryController::class);
        $entries->shouldReceive('portalContext')->andReturnUsing(fn () => ['portal' => $portal, 'user' => $this->actor]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function payload(): array
    {
        return [
            'submission_token' => (string) Str::uuid(), 'page_type' => 'single', 'task_type' => 'new',
            'landing_url' => 'https://example.test/product?same=1', 'selection_objective' => '验证商品卖点',
            'assets' => array_fill(0, 2, ['selling_point' => '素材卖点', 'source_type' => 'ai_created', 'url' => 'https://example.test/creative.mp4']),
        ];
    }

    public function test_same_operator_can_create_three_independent_tasks_for_the_same_url_on_the_same_day(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->travel(1)->seconds();
            $this->post(route('operation-tasks.tests.store'), $this->payload())->assertSessionHasNoErrors()->assertSessionHas('success');
        }
        self::assertSame(3, OperationTestTask::query()->count());
        self::assertSame(1, OperationTestTask::query()->distinct()->count('landing_url'));
        self::assertSame(6, OperationTaskAsset::query()->count());
        self::assertSame([2, 2, 2], OperationTestTask::query()->withCount('assets')->get()->pluck('assets_count')->all());
        $plan = (new OperationTaskDailyPlan())->forceFill(['single_target' => 3, 'category_target' => 0]);
        $method = new \ReflectionMethod(OperationTaskController::class, 'dailyPlanProgress');
        $progress = $method->invoke(app(OperationTaskController::class), $plan, OperationTestTask::query()->with('assets')->get(), now('Asia/Shanghai')->toDateString());
        self::assertSame(3, $progress['single_done']);
        self::assertTrue($progress['complete']);
    }

    public function test_same_url_can_be_used_by_an_iteration_on_a_later_day(): void
    {
        $this->post(route('operation-tasks.tests.store'), $this->payload())->assertSessionHasNoErrors();
        $this->travel(1)->days();
        $payload = $this->payload(); $payload['task_type'] = 'iteration';
        $this->post(route('operation-tasks.tests.store'), $payload)->assertSessionHasNoErrors();
        self::assertSame(2, OperationTestTask::query()->count());
        self::assertSame('iteration', OperationTestTask::query()->latest('id')->first()->task_type);
    }

    public function test_retry_of_the_same_submission_returns_the_saved_task_with_a_visible_warning(): void
    {
        $payload = $this->payload();
        $first = $this->post(route('operation-tasks.tests.store'), $payload)->assertSessionHasNoErrors();
        $task = OperationTestTask::query()->sole();
        $second = $this->post(route('operation-tasks.tests.store'), $payload)->assertSessionHas('warning');
        self::assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        self::assertStringContainsString($task->task_no, session('warning'));
        self::assertSame(1, OperationTestTask::query()->count());
        self::assertSame(2, OperationTaskAsset::query()->count());
    }

    public function test_another_operator_cannot_replay_the_submission_identity(): void
    {
        $payload = $this->payload();
        $this->post(route('operation-tasks.tests.store'), $payload)->assertSessionHasNoErrors();
        $this->actor = (new AdminUser())->forceFill(['id' => 8, 'name' => '其他运营']);
        $this->post(route('operation-tasks.tests.store'), $payload)->assertSessionHasErrors('submission_token');
        self::assertSame(1, OperationTestTask::query()->count());
    }

    public function test_insufficient_category_assets_returns_chinese_feedback_and_preserves_input(): void
    {
        $payload = $this->payload(); $payload['page_type'] = 'category';
        $this->from(route('operation-tasks.tests.index'))->post(route('operation-tasks.tests.store'), $payload)
            ->assertRedirect(route('operation-tasks.tests.index'))->assertSessionHasErrors('assets')
            ->assertSessionHasInput('submission_token', $payload['submission_token']);
        $message = session('errors')->first('assets');
        self::assertStringContainsString('类目页', $message);
        self::assertStringContainsString('3', $message);
        self::assertStringNotContainsString('validation.', $message);
        self::assertSame(0, OperationTestTask::query()->count());
    }

    public function test_invalid_link_or_whitespace_only_objective_is_rejected_before_creating_a_task(): void
    {
        $payload = $this->payload(); $payload['landing_url'] = 'invalid-url'; $payload['selection_objective'] = '   ';
        $this->post(route('operation-tasks.tests.store'), $payload)->assertSessionHasErrors(['landing_url', 'selection_objective']);
        self::assertStringContainsString('落地页链接', session('errors')->first('landing_url'));
        self::assertSame(0, OperationTestTask::query()->count());
    }
}
