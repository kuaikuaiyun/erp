<?php

namespace Tests\Feature;

use App\Models\OperationTaskAssetUpload;
use App\Portals\OperationTasks\OperationTaskAssetUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationTaskAssetUploadTest extends TestCase
{
    private OperationTaskAssetUploadService $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        DB::purge('sqlite');
        Storage::fake('local');
        (require database_path('migrations/2026_11_12_000100_create_operation_task_asset_uploads.php'))->up();
        $this->uploads = app(OperationTaskAssetUploadService::class);
    }

    public function test_chunks_can_be_retried_and_a_valid_file_can_only_be_consumed_once(): void
    {
        // PNG signature plus valid minimal image; padding forces more than one chunk.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aH0sAAAAASUVORK5CYII=');
        $contents = $png.str_repeat("\0", OperationTaskAssetUploadService::CHUNK_SIZE);
        $session = $this->uploads->begin(7, 'creative.png', strlen($contents));
        $first = substr($contents, 0, OperationTaskAssetUploadService::CHUNK_SIZE);
        $this->uploads->part(7, $session['token'], 0, UploadedFile::fake()->createWithContent('part', $first));
        $this->uploads->part(7, $session['token'], 0, UploadedFile::fake()->createWithContent('part', $first));
        $this->uploads->part(7, $session['token'], 1, UploadedFile::fake()->createWithContent('part', substr($contents, strlen($first))));
        $ready = $this->uploads->complete(7, $session['token']);
        self::assertSame('ready', $ready['status']);
        self::assertSame('ready', $this->uploads->complete(7, $session['token'])['status']);
        $file = DB::transaction(fn () => $this->uploads->consume(7, $session['token'], 'assets.0.upload_token'));
        self::assertSame('creative.png', $file['original_name']);
        self::assertSame($contents, Storage::disk('local')->get($file['file_path']));
        self::assertStringStartsWith('image/', $file['mime_type']);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $this->uploads->consume(7, $session['token'], 'assets.0.upload_token'));
    }

    public function test_another_user_cannot_query_upload_or_complete_the_token(): void
    {
        $session = $this->uploads->begin(7, 'creative.png', 4);
        foreach (['status', 'part', 'complete', 'consume'] as $operation) {
            try {
                match ($operation) {
                    'status' => $this->uploads->status(8, $session['token']),
                    'part' => $this->uploads->part(8, $session['token'], 0, UploadedFile::fake()->createWithContent('part', 'abcd')),
                    'complete' => $this->uploads->complete(8, $session['token']),
                    'consume' => DB::transaction(fn () => $this->uploads->consume(8, $session['token'], 'assets.0.upload_token')),
                };
                self::fail('Another user accessed an upload.');
            } catch (ValidationException $exception) {
                self::assertNotEmpty($exception->errors());
            }
        }
        self::assertSame('uploading', $this->uploads->status(7, $session['token'])['status']);
    }

    public function test_missing_chunks_and_forged_image_contents_are_rejected(): void
    {
        $session = $this->uploads->begin(7, 'creative.png', 4);
        try {
            $this->uploads->complete(7, $session['token']);
            self::fail('Incomplete upload was accepted.');
        } catch (ValidationException $exception) {
            self::assertNotEmpty($exception->errors());
        }
        $this->uploads->part(7, $session['token'], 0, UploadedFile::fake()->createWithContent('part', 'abcd'));
        $this->expectException(ValidationException::class);
        $this->uploads->complete(7, $session['token']);
    }

    public function test_wrong_sized_chunks_and_expired_uploads_are_rejected(): void
    {
        $session = $this->uploads->begin(7, 'creative.png', 4);
        try {
            $this->uploads->part(7, $session['token'], 0, UploadedFile::fake()->createWithContent('part', 'abc'));
            self::fail('Wrong sized chunk was accepted.');
        } catch (ValidationException $exception) {
            self::assertNotEmpty($exception->errors());
        }
        OperationTaskAssetUpload::query()->update(['expires_at' => now()->subSecond()]);
        $this->expectException(ValidationException::class);
        $this->uploads->status(7, $session['token']);
    }

    public function test_rollback_preserves_a_ready_upload_and_cleanup_never_deletes_consumed_files(): void
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aH0sAAAAASUVORK5CYII=');
        $session = $this->uploads->begin(7, 'creative.png', strlen($contents));
        $this->uploads->part(7, $session['token'], 0, UploadedFile::fake()->createWithContent('part', $contents));
        $this->uploads->complete(7, $session['token']);
        try {
            DB::transaction(function () use ($session): void {
                $this->uploads->consume(7, $session['token'], 'assets.0.upload_token');
                throw new \RuntimeException('Simulate task save failure');
            });
        } catch (\RuntimeException) {
            self::assertSame('ready', $this->uploads->status(7, $session['token'])['status']);
        }
        $file = DB::transaction(fn () => $this->uploads->consume(7, $session['token'], 'assets.0.upload_token'));
        $expired = $this->uploads->begin(7, 'unused.png', 4);
        $this->uploads->part(7, $expired['token'], 0, UploadedFile::fake()->createWithContent('part', 'abcd'));
        OperationTaskAssetUpload::query()->update(['expires_at' => now()->subDay()]);
        self::assertSame(1, $this->uploads->purge());
        Storage::disk('local')->assertExists($file['file_path']);
        self::assertSame('consumed', OperationTaskAssetUpload::query()->sole()->status);
        Storage::disk('local')->assertMissing('operation-tasks/asset-uploads/'.$expired['token'].'/parts/0');
    }
}
