<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\OperationTaskAsset;
use App\Models\OperationTaskAssetUpload;
use App\Models\OperationTaskRectification;
use App\Models\OperationTaskReview;
use App\Models\OperationTaskReviewEvent;
use App\Models\OperationTestTask;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\OperationTasks\OperationTaskAssetUploadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationTaskRectificationUploadTest extends TestCase
{
    private AdminUser $actor;
    private OperationTestTask $task;
    private OperationTaskRectification $rectification;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        DB::purge('sqlite');
        app()->setLocale('zh-CN');
        Storage::fake('local');
        (require database_path('migrations/2026_11_12_000100_create_operation_task_asset_uploads.php'))->up();
        Schema::create('operation_test_tasks', function (Blueprint $table): void {
            $table->id(); $table->string('task_no'); $table->unsignedInteger('owner_user_id'); $table->string('owner_name');
            $table->unsignedInteger('department_id')->nullable(); $table->string('department_name')->nullable();
            $table->string('page_type'); $table->string('task_type'); $table->string('landing_url'); $table->text('selection_objective');
            $table->unsignedInteger('version')->default(1); $table->json('version_history')->nullable();
            $table->string('rectification_status')->default('required'); $table->dateTime('submitted_at'); $table->timestamps();
        });
        Schema::create('operation_task_assets', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('task_id'); $table->unsignedInteger('uploader_user_id'); $table->string('uploader_name');
            $table->text('selling_point'); $table->string('source_type'); $table->string('file_path')->nullable();
            $table->string('original_name')->nullable(); $table->string('external_url')->nullable(); $table->string('mime_type')->nullable();
            $table->boolean('ai_confirmed'); $table->unsignedInteger('version')->default(1); $table->json('version_history')->nullable();
            $table->dateTime('retired_at')->nullable(); $table->timestamps();
        });
        Schema::create('operation_task_rectifications', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('task_id'); $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('review_channel'); $table->string('status'); $table->text('rectification_content')->nullable();
            $table->dateTime('resubmitted_at')->nullable(); $table->unsignedInteger('resubmitted_by')->nullable();
            $table->string('resubmitted_by_name')->nullable(); $table->unsignedInteger('resubmitted_task_version')->nullable();
            $table->unsignedBigInteger('resubmitted_asset_id')->nullable(); $table->timestamps();
        });
        foreach (['operation_task_reviews', 'operation_task_review_events'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id(); $table->unsignedBigInteger('review_id')->nullable(); $table->string('target_type');
                $table->unsignedBigInteger('target_id'); $table->unsignedInteger('target_version'); $table->string('channel');
                $table->string('result'); $table->text('opinion')->nullable(); $table->string('rejection_scope')->nullable();
                $table->unsignedInteger('reviewer_id'); $table->string('reviewer_name'); $table->dateTime('reviewed_at'); $table->timestamps();
            });
        }
        $this->actor = (new class extends AdminUser {
            public function isAdministrator(): bool { return false; }
        })->forceFill(['id' => 7, 'name' => '测试运营', 'username' => 'operator']);
        $portal = (new Portal())->forceFill(['id' => 1, 'code' => 'fuzhuang']);
        $this->mock(PortalEntryController::class)->shouldReceive('portalContext')
            ->andReturnUsing(fn () => ['portal' => $portal, 'user' => $this->actor]);
        $this->task = OperationTestTask::query()->create([
            'task_no' => 'OT-RECTIFICATION-TEST', 'owner_user_id' => 7, 'owner_name' => '测试运营',
            'page_type' => 'single', 'task_type' => 'new', 'landing_url' => 'https://example.test/product',
            'selection_objective' => '测试目标', 'submitted_at' => now(), 'version' => 1,
        ]);
        for ($index = 0; $index < 2; $index++) {
            $file = 'original/'.$index.'.png';
            Storage::disk('local')->put($file, 'original-'.$index);
            OperationTaskAsset::query()->create([
                'task_id' => $this->task->id, 'uploader_user_id' => 7, 'uploader_name' => '测试运营',
                'selling_point' => '原始卖点', 'source_type' => 'ai_created', 'file_path' => $file,
                'original_name' => $index.'.png', 'mime_type' => 'image/png', 'ai_confirmed' => true,
            ]);
        }
        $this->rectification = OperationTaskRectification::query()->create([
            'task_id' => $this->task->id, 'review_channel' => 'leader', 'status' => 'required',
        ]);
    }

    private function payload(): array
    {
        return [
            'form_mode' => 'edit', 'task_id' => $this->task->id, 'task_version' => 1,
            'page_type' => 'single', 'task_type' => 'new', 'landing_url' => 'https://example.test/product',
            'selection_objective' => '修改后的验证目标',
            'assets' => $this->task->assets()->orderBy('id')->get()->map(fn ($asset) => [
                'id' => $asset->id, 'selling_point' => '修改后的卖点', 'source_type' => 'ai_created', 'url' => '',
            ])->all(),
        ];
    }

    private function readyUpload(int $owner = 7, ?int $size = null): array
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aH0sAAAAASUVORK5CYII=');
        $size ??= strlen($png);
        $service = app(OperationTaskAssetUploadService::class);
        $session = $service->begin($owner, 'replacement.png', $size);
        for ($index = 0; $index < (int) ceil($size / $session['chunk_size']); $index++) {
            $length = min($session['chunk_size'], $size - $index * $session['chunk_size']);
            $prefix = $index === 0 ? $png : '';
            $service->part($owner, $session['token'], $index, UploadedFile::fake()->createWithContent('part', $prefix.str_repeat("\0", $length - strlen($prefix))));
        }
        $service->complete($owner, $session['token']);
        return OperationTaskAssetUpload::query()->where('token', $session['token'])->firstOrFail()->toArray();
    }

    private function submit(array $payload)
    {
        return $this->from(route('operation-tasks.tests.index'))->post(route('operation-tasks.rectifications.resubmit', $this->rectification), $payload);
    }

    public function test_original_files_are_kept_without_uploading_them_again(): void
    {
        $this->submit($this->payload())->assertSessionHasNoErrors()->assertSessionHas('success');
        self::assertSame(2, (int) $this->task->fresh()->version);
        foreach ($this->task->assets()->get() as $asset) {
            self::assertSame(2, (int) $asset->version);
            self::assertStringStartsWith('original/', $asset->file_path);
            self::assertSame($asset->file_path, $asset->version_history[0]['snapshot']['file_path']);
            Storage::disk('local')->assertExists($asset->file_path);
        }
        self::assertSame('resubmitted', $this->rectification->fresh()->status);
    }

    public function test_a_file_over_fifty_megabytes_can_replace_an_asset_and_a_new_uploaded_asset_can_be_added(): void
    {
        $replacement = $this->readyUpload(7, 51 * 1024 * 1024);
        $added = $this->readyUpload();
        $payload = $this->payload();
        $payload['assets'][0]['upload_token'] = $replacement['token'];
        $payload['assets'][] = ['selling_point' => '新增卖点', 'source_type' => 'peer_copied', 'upload_token' => $added['token']];
        $this->submit($payload)->assertSessionHasNoErrors()->assertSessionHas('success');
        self::assertSame($replacement['file_path'], OperationTaskAsset::query()->findOrFail($payload['assets'][0]['id'])->file_path);
        self::assertSame(3, $this->task->assets()->count());
        self::assertSame(2, OperationTaskAssetUpload::query()->where('status', 'consumed')->count());
        Storage::disk('local')->assertExists('original/0.png');
    }

    public function test_validation_failure_preserves_ready_tokens_and_form_input(): void
    {
        $file = $this->readyUpload(); $payload = $this->payload();
        $payload['assets'][0]['upload_token'] = $file['token']; $payload['landing_url'] = 'invalid';
        $this->submit($payload)->assertSessionHasErrors('landing_url')->assertSessionHasInput('assets.0.upload_token', $file['token']);
        self::assertSame('ready', OperationTaskAssetUpload::query()->sole()->status);
        self::assertSame(1, (int) $this->task->fresh()->version);
    }

    public function test_a_failed_second_token_rolls_back_the_first_token_and_all_versions(): void
    {
        $valid = $this->readyUpload(); $expired = $this->readyUpload();
        OperationTaskAssetUpload::query()->where('token', $expired['token'])->update(['expires_at' => now()->subDay()]);
        $payload = $this->payload(); $payload['assets'][0]['upload_token'] = $valid['token']; $payload['assets'][1]['upload_token'] = $expired['token'];
        $this->submit($payload)->assertSessionHasErrors('assets.1.upload_token');
        self::assertSame('ready', OperationTaskAssetUpload::query()->where('token', $valid['token'])->firstOrFail()->status);
        self::assertSame(1, (int) $this->task->fresh()->version);
        self::assertSame('required', $this->rectification->fresh()->status);
        self::assertSame(0, OperationTaskReviewEvent::query()->count());
        self::assertSame([1, 1], $this->task->assets()->get()->map(fn ($asset) => (int) $asset->version)->all());
    }

    public function test_foreign_and_reused_upload_tokens_are_rejected(): void
    {
        $foreign = $this->readyUpload(8); $payload = $this->payload(); $payload['assets'][0]['upload_token'] = $foreign['token'];
        $this->submit($payload)->assertSessionHasErrors('assets.0.upload_token');
        $file = $this->readyUpload(); $payload['assets'][0]['upload_token'] = $file['token']; $payload['assets'][1]['upload_token'] = $file['token'];
        $this->submit($payload)->assertSessionHasErrors('assets.1.upload_token');
        self::assertSame(1, (int) $this->task->fresh()->version);
        self::assertSame('ready', OperationTaskAssetUpload::query()->where('token', $file['token'])->firstOrFail()->status);
    }

    public function test_repeated_submission_does_not_consume_the_token_or_advance_versions_twice(): void
    {
        $file = $this->readyUpload(); $payload = $this->payload(); $payload['assets'][0]['upload_token'] = $file['token'];
        $this->submit($payload)->assertSessionHas('success');
        $this->submit($payload)->assertSessionHasNoErrors()->assertSessionHas('warning');
        self::assertSame(2, (int) $this->task->fresh()->version);
        self::assertSame(1, OperationTaskReviewEvent::query()->count());
        self::assertSame('consumed', OperationTaskAssetUpload::query()->sole()->status);
    }

    public function test_stale_task_version_preserves_the_upload_without_saving_changes(): void
    {
        $file = $this->readyUpload(); $payload = $this->payload(); $payload['assets'][0]['upload_token'] = $file['token'];
        $this->task->update(['version' => 2]);
        $this->submit($payload)->assertSessionHasNoErrors()->assertSessionHas('warning')->assertSessionHasInput('assets.0.upload_token', $file['token']);
        self::assertSame('ready', OperationTaskAssetUpload::query()->sole()->status);
        self::assertSame(0, OperationTaskReviewEvent::query()->count());
    }

    public function test_original_asset_ids_cannot_be_dropped_and_direct_file_posts_are_rejected(): void
    {
        $payload = $this->payload(); unset($payload['assets'][0]['id']);
        $this->submit($payload)->assertSessionHasErrors('assets');
        $payload = $this->payload(); $payload['assets'][0]['file'] = UploadedFile::fake()->createWithContent('creative.png', 'file');
        $this->submit($payload)->assertSessionHasErrors('assets.0.file');
        self::assertSame(1, (int) $this->task->fresh()->version);
    }

    public function test_category_minimum_and_a_new_asset_without_a_file_or_link_are_rejected(): void
    {
        $payload = $this->payload(); $payload['page_type'] = 'category';
        $this->submit($payload)->assertSessionHasErrors('assets');
        $payload = $this->payload(); $payload['assets'][] = ['selling_point' => '新增素材', 'source_type' => 'ai_created'];
        $this->submit($payload)->assertSessionHasErrors('assets.2.upload_token');
        self::assertSame(1, (int) $this->task->fresh()->version);
    }

    public function test_department_only_rectification_keeps_existing_leader_approvals(): void
    {
        $this->rectification->update(['review_channel' => 'department']);
        foreach (['task' => [$this->task->id], 'asset' => $this->task->assets()->pluck('id')->all()] as $type => $ids) {
            foreach ($ids as $id) OperationTaskReview::query()->create([
                'target_type' => $type, 'target_id' => $id, 'target_version' => 1, 'channel' => 'leader', 'result' => 'approved',
                'opinion' => '原审核意见', 'reviewer_id' => 9, 'reviewer_name' => '测试主管', 'reviewed_at' => now(),
            ]);
        }
        $this->submit($this->payload())->assertSessionHasNoErrors()->assertSessionHas('success');
        self::assertSame(3, OperationTaskReview::query()->where('target_version', 2)->where('channel', 'leader')->where('result', 'approved')->count());
    }
}
