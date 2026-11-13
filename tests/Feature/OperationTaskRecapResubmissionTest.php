<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\OperationTaskRecap;
use App\Models\OperationTaskReviewEvent;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\OperationTasks\Http\Controllers\OperationTaskController;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationTaskRecapResubmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        DB::purge('sqlite'); app()->setLocale('zh-CN');
        $this->travelTo(now('UTC')->setDate(2026, 11, 13)->setTime(4, 0));
        Schema::create('operation_task_recaps', function (Blueprint $table): void {
            $table->id(); $table->unsignedInteger('user_id'); $table->date('data_date'); $table->string('user_name');
            $table->unsignedInteger('department_id')->nullable(); $table->string('department_name')->nullable();
            $table->json('media_items')->nullable(); $table->json('question_answers')->nullable(); $table->json('version_history')->nullable();
            foreach (['finding_answer', 'action_answer', 'advertising_plan', 'support_needed', 'last_rejection_opinion'] as $column) $table->text($column)->nullable();
            foreach (['total_revenue_usd', 'total_spend_usd'] as $column) $table->decimal($column, 14, 2)->default(0);
            $table->unsignedInteger('total_orders')->default(0); $table->unsignedInteger('version')->default(1);
            $table->string('workflow_status')->default('rejected'); $table->string('leader_review_status')->default('approved');
            $table->string('department_review_status')->default('rejected'); $table->string('last_rejection_channel')->nullable();
            $table->dateTime('last_rejected_at')->nullable(); $table->dateTime('submitted_at')->nullable(); $table->timestamps();
            $table->unique(['user_id', 'data_date']);
        });
        Schema::create('operation_task_recap_questions', function (Blueprint $table): void {
            $table->id(); $table->string('code')->nullable(); $table->string('title'); $table->text('description')->nullable();
            $table->boolean('is_required')->default(false); $table->boolean('is_enabled')->default(true); $table->integer('sort')->default(0); $table->timestamps();
        });
        Schema::create('operation_task_review_events', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('review_id')->nullable(); $table->string('target_type'); $table->unsignedBigInteger('target_id');
            $table->unsignedInteger('target_version'); $table->string('channel'); $table->string('result'); $table->text('opinion')->nullable();
            $table->string('rejection_scope')->nullable(); $table->unsignedInteger('reviewer_id'); $table->string('reviewer_name'); $table->dateTime('reviewed_at'); $table->timestamps();
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
        $actor = (new AdminUser())->forceFill(['id' => 7, 'name' => '测试运营', 'username' => 'operator']);
        $portal = (new Portal())->forceFill(['id' => 1, 'code' => 'fuzhuang']);
        $this->mock(PortalEntryController::class)->shouldReceive('portalContext')->andReturn(['portal' => $portal, 'user' => $actor]);
    }

    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    private function record(string $date, array $attributes = []): OperationTaskRecap
    {
        return OperationTaskRecap::query()->create(array_replace([
            'user_id' => 7, 'user_name' => '测试运营', 'data_date' => $date, 'total_revenue_usd' => 928,
            'total_spend_usd' => 682, 'total_orders' => 22, 'workflow_status' => 'rejected', 'version' => 1,
            'leader_review_status' => 'approved', 'department_review_status' => 'rejected',
        ], $attributes));
    }

    private function payload(?OperationTaskRecap $record = null): array
    {
        return array_merge([
            'recap_mode' => $record ? 'edit' : 'new',
            'media' => [['name' => 'Google', 'spend' => 15, 'orders' => 3, 'revenue' => 60]],
        ], $record ? ['recap_id' => $record->id, 'recap_version' => $record->version] : []);
    }

    private function submit(array $data)
    {
        $url = ($data['recap_mode'] ?? null) === 'edit'
            ? route('operation-tasks.recaps.resubmit', ['recap' => $data['recap_id'] ?? 999])
            : route('operation-tasks.recaps.store');
        return $this->from(route('operation-tasks.recaps.index'))->post($url, $data);
    }

    public function test_historical_resubmission_updates_the_same_record_without_creating_yesterdays_record(): void
    {
        $old = $this->record('2026-11-10');
        $this->submit($this->payload($old))->assertSessionHasNoErrors()->assertSessionHas('success');
        $saved = $old->fresh();
        self::assertSame(1, OperationTaskRecap::query()->count());
        self::assertSame('2026-11-10', $saved->data_date->format('Y-m-d'));
        self::assertSame(2, (int) $saved->version);
        self::assertSame('pending', $saved->leader_review_status);
        self::assertSame('pending', $saved->department_review_status);
        self::assertSame('928.00', $saved->version_history[0]['snapshot']['total_revenue_usd']);
        self::assertSame($old->id, (int) OperationTaskReviewEvent::query()->sole()->target_id);
    }

    public function test_historical_resubmission_does_not_touch_a_current_pending_record(): void
    {
        $old = $this->record('2026-11-10');
        $current = $this->record('2026-11-12', ['workflow_status' => 'pending_leader', 'total_revenue_usd' => 333]);
        $before = $current->getRawOriginal();
        $this->submit($this->payload($old))->assertSessionHas('success');
        self::assertSame($before, $current->fresh()->getRawOriginal());
        self::assertSame(2, OperationTaskRecap::query()->count());
    }

    public function test_new_submission_cannot_silently_edit_an_existing_rejected_record(): void
    {
        $current = $this->record('2026-11-12');
        $this->submit($this->payload())->assertSessionHas('warning');
        self::assertSame(1, (int) $current->fresh()->version);
        self::assertSame('rejected', $current->fresh()->workflow_status);
        self::assertSame(0, OperationTaskReviewEvent::query()->count());
    }

    public function test_new_submission_still_targets_beijing_yesterday(): void
    {
        $this->submit($this->payload())->assertSessionHasNoErrors()->assertSessionHas('success');
        self::assertSame('2026-11-12', OperationTaskRecap::query()->sole()->data_date->format('Y-m-d'));
    }

    public function test_stale_duplicate_and_foreign_edits_never_create_a_fallback_record(): void
    {
        $old = $this->record('2026-11-10', ['version' => 2]); $payload = $this->payload($old); $payload['recap_version'] = 1;
        $this->submit($payload)->assertSessionHasErrors('recap');
        self::assertSame(2, (int) $old->fresh()->version);
        $payload = $this->payload($old); $this->submit($payload)->assertSessionHas('success');
        $this->submit($payload)->assertSessionHas('warning');
        self::assertSame(3, (int) $old->fresh()->version);
        $foreign = $this->record('2026-11-09', ['user_id' => 8]);
        $this->submit($this->payload($foreign))->assertSessionHas('warning');
        self::assertSame(1, (int) $foreign->fresh()->version);
        self::assertSame(2, OperationTaskRecap::query()->count());
        self::assertSame(1, OperationTaskReviewEvent::query()->count());
    }

    public function test_missing_identity_or_invalid_edit_id_does_not_create_a_record(): void
    {
        $payload = $this->payload(); unset($payload['recap_mode']);
        $this->submit($payload)->assertSessionHasErrors('recap_mode');
        $payload = $this->payload(); $payload['recap_mode'] = 'edit'; $payload['recap_id'] = 999; $payload['recap_version'] = 1;
        $this->submit($payload)->assertSessionHas('warning');
        self::assertSame(0, OperationTaskRecap::query()->count());
    }

    public function test_editing_middle_and_oldest_rejected_records_leaves_latest_record_unchanged(): void
    {
        $oldest = $this->record('2026-11-09', ['total_revenue_usd' => 109]);
        $middle = $this->record('2026-11-10', ['total_revenue_usd' => 110]);
        $latest = $this->record('2026-11-12', ['total_revenue_usd' => 112]);
        $oldestBefore = $oldest->getRawOriginal();
        $latestBefore = $latest->getRawOriginal();

        $this->submit($this->payload($middle))->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('operation_tasks.recap_form.updated', ['date' => '2026-11-10', 'version' => 2]));
        self::assertSame($oldestBefore, $oldest->fresh()->getRawOriginal());
        self::assertSame($latestBefore, $latest->fresh()->getRawOriginal());
        self::assertSame('60.00', $middle->fresh()->total_revenue_usd);
        self::assertSame('2026-11-10', $middle->fresh()->data_date->format('Y-m-d'));
        $middleBefore = $middle->fresh()->getRawOriginal();

        $this->submit($this->payload($oldest))->assertSessionHasNoErrors()->assertSessionHas('success');
        self::assertSame($middleBefore, $middle->fresh()->getRawOriginal());
        self::assertSame($latestBefore, $latest->fresh()->getRawOriginal());
        self::assertSame(2, (int) $oldest->fresh()->version);
        self::assertSame('2026-11-09', $oldest->fresh()->data_date->format('Y-m-d'));
        self::assertSame(3, OperationTaskRecap::query()->count());
        self::assertSame([$middle->id, $oldest->id], OperationTaskReviewEvent::query()->orderBy('id')->pluck('target_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_route_id_and_form_id_must_match_without_touching_either_record(): void
    {
        $old = $this->record('2026-11-10');
        $latest = $this->record('2026-11-12');
        $before = OperationTaskRecap::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->from(route('operation-tasks.recaps.edit', ['recap' => $old->id]))
            ->post(route('operation-tasks.recaps.resubmit', ['recap' => $old->id]), $this->payload($latest))
            ->assertSessionHas('warning', __('operation_tasks.recap_form.target_mismatch'));
        self::assertSame($before, OperationTaskRecap::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        self::assertSame(0, OperationTaskReviewEvent::query()->count());
    }

    public function test_old_shared_submission_endpoint_rejects_edit_payload_instead_of_creating_yesterday(): void
    {
        $old = $this->record('2026-11-10');
        $before = $old->getRawOriginal();
        $this->from(route('operation-tasks.recaps.index'))
            ->post(route('operation-tasks.recaps.store'), $this->payload($old))
            ->assertSessionHasErrors('recap_mode');
        self::assertSame($before, $old->fresh()->getRawOriginal());
        self::assertSame(1, OperationTaskRecap::query()->count());
        self::assertSame(0, OperationTaskReviewEvent::query()->count());
    }

    public function test_edit_page_binds_requested_record_and_index_does_not_default_to_latest_rejection(): void
    {
        $old = $this->record('2026-11-10');
        $this->record('2026-11-12');
        $controller = app(OperationTaskController::class);
        $editRequest = Request::create(route('operation-tasks.recaps.edit', ['recap' => $old->id]));
        $editRequest->setLaravelSession(app('session.store'));
        $edit = $controller->editRecap($editRequest, $old->id);
        self::assertSame($old->id, $edit->getData()['editingRecap']->id);
        self::assertSame('2026-11-10', $edit->getData()['editingRecap']->data_date->format('Y-m-d'));
        self::assertTrue($edit->getData()['openRecapModal']);

        // Render the real content after all row loops, without the unrelated portal shell.
        $source = str_replace(["@extends('portal.layout')", "@include('portals.operation-tasks.partials.head')"], '', file_get_contents(resource_path('views/portals/operation-tasks/recaps.blade.php')));
        $html = Blade::render($source."\n@yield('content')", array_merge($edit->getData(), ['errors' => new \Illuminate\Support\ViewErrorBag()]));
        self::assertStringContainsString('action="'.route('operation-tasks.recaps.resubmit', ['recap' => $old->id]).'"', $html);
        self::assertStringContainsString('name="recap_id" value="'.$old->id.'"', $html);
        self::assertStringContainsString(__('operation_tasks.recap_form.edit_title', ['date' => '2026-11-10', 'version' => 1]), $html);

        $indexRequest = Request::create(route('operation-tasks.recaps.index'));
        $indexRequest->setLaravelSession(app('session.store'));
        $index = $controller->recaps($indexRequest);
        self::assertNull($index->getData()['editingRecap']);
        self::assertFalse($index->getData()['openRecapModal']);
    }

    public function test_missing_foreign_and_pending_edit_pages_redirect_without_binding_another_record(): void
    {
        $foreign = $this->record('2026-11-09', ['user_id' => 8]);
        $pending = $this->record('2026-11-11', ['workflow_status' => 'pending_leader']);
        $this->record('2026-11-12');
        foreach ([999, $foreign->id, $pending->id] as $id) {
            $this->get(route('operation-tasks.recaps.edit', ['recap' => $id]))
                ->assertRedirect(route('operation-tasks.recaps.index'))
                ->assertSessionHas('warning');
        }
        self::assertSame(3, OperationTaskRecap::query()->count());
        self::assertSame(0, OperationTaskReviewEvent::query()->count());
    }

    public function test_legacy_edit_link_redirects_to_dedicated_record_url(): void
    {
        $old = $this->record('2026-11-10');
        $this->get(route('operation-tasks.recaps.index', ['edit' => $old->id]))
            ->assertRedirect(route('operation-tasks.recaps.edit', ['recap' => $old->id]));
    }
}
