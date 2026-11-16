<?php

namespace Tests\Feature;

use App\Portals\OperationTasks\OperationTaskVideoService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationTaskVideoServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['operation_task_video.x_accel_enabled' => false]);
    }

    private function box(string $type, string $body): string
    {
        return pack('N', 8 + strlen($body)).$type.$body;
    }

    private function file(bool $ready): string
    {
        $ftyp = $this->box('ftyp', 'isom'.str_repeat("\0", 4));
        $moov = $this->box('moov', 'index');
        $mdat = $this->box('mdat', 'media');
        return $ftyp.($ready ? $moov.$mdat : $mdat.$moov);
    }

    public function test_inspection_is_read_only_and_detects_both_index_positions(): void
    {
        foreach ([false, true] as $ready) {
            $relative = 'operation-tasks/video-'.(int) $ready;
            Storage::disk('local')->put($relative, $this->file($ready));
            $service = new OperationTaskVideoService();
            $before = hash_file('sha256', Storage::disk('local')->path($relative));
            self::assertSame($ready ? 'ready' : 'needs_faststart', $service->optimize($relative));
            self::assertSame($before, hash_file('sha256', Storage::disk('local')->path($relative)));
        }
    }

    public function test_invalid_and_fragmented_files_are_not_rewritten(): void
    {
        $disk = Storage::disk('local');
        $service = new OperationTaskVideoService();
        $disk->put('operation-tasks/bad', pack('N', 500).'mdat');
        self::assertSame('invalid', $service->optimize('operation-tasks/bad', true));
        $disk->put('operation-tasks/fragmented', $this->box('ftyp', 'isom').$this->box('moof', 'fragment'));
        self::assertSame('fragmented', $service->optimize('operation-tasks/fragmented', true));
    }

    public function test_conversion_failure_preserves_original_and_cleans_temporary_output(): void
    {
        $disk = Storage::disk('local');
        $relative = 'operation-tasks/slow.mp4';
        $disk->put($relative, $this->file(false));
        $before = $disk->get($relative);
        $service = new class extends OperationTaskVideoService {
            protected function run(array $arguments): string {
                if (in_array('-show_packets', $arguments, true)) return '{"packets":[{"stream_index":0,"data_hash":"SHA256:original"}]}';
                file_put_contents(end($arguments), 'incomplete');
                throw new \RuntimeException('Simulated conversion failure');
            }
        };
        self::assertSame('failed', $service->optimize($relative, true));
        self::assertSame($before, $disk->get($relative));
        self::assertCount(1, $disk->allFiles('operation-tasks'));
    }

    public function test_packet_verification_failure_keeps_original(): void
    {
        $disk = Storage::disk('local');
        $relative = 'operation-tasks/slow.mp4';
        $disk->put($relative, $this->file(false));
        $before = $disk->get($relative);
        $output = $this->file(true);
        $service = new class($output) extends OperationTaskVideoService {
            public function __construct(private string $output) {}
            protected function run(array $arguments): string {
                if (in_array('-show_packets', $arguments, true)) {
                    $hash = str_contains(end($arguments), '.faststart-') ? 'changed' : 'original';
                    return json_encode(['packets' => [['stream_index' => 0, 'data_hash' => $hash]]]);
                }
                file_put_contents(end($arguments), $this->output);
                return '';
            }
        };
        self::assertSame('failed', $service->optimize($relative, true));
        self::assertSame($before, $disk->get($relative));
        self::assertCount(1, $disk->allFiles('operation-tasks'));
    }

    public function test_default_response_supports_private_range_requests(): void
    {
        Storage::disk('local')->put('operation-tasks/test.mp4', $this->file(true));
        $response = (new OperationTaskVideoService())->fileResponse('operation-tasks/test.mp4');
        $response->prepare(\Illuminate\Http\Request::create('/video', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=0-7']));
        self::assertSame(206, $response->getStatusCode());
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertSame('8', $response->headers->get('Content-Length'));
    }

    public function test_accelerated_response_uses_only_internal_uri_and_rejects_other_private_files(): void
    {
        config(['operation_task_video.x_accel_enabled' => true]);
        Storage::disk('local')->put('operation-tasks/test file.mp4', $this->file(true));
        $response = (new OperationTaskVideoService())->fileResponse('operation-tasks/test file.mp4');
        self::assertSame('/_operation_private/operation-tasks/test%20file.mp4', $response->headers->get('X-Accel-Redirect'));
        self::assertSame('', $response->getContent());
        Storage::disk('local')->put('secret.txt', 'secret');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new OperationTaskVideoService())->fileResponse('operation-tasks/../secret.txt');
    }

    public function test_verified_output_replaces_original_at_same_path_without_a_video_copy(): void
    {
        $disk = Storage::disk('local');
        $relative = 'operation-tasks/slow.mp4';
        $disk->put($relative, $this->file(false));
        $output = $this->file(true);
        $service = new class($output) extends OperationTaskVideoService {
            public function __construct(private string $output) {}
            protected function run(array $arguments): string {
                if (in_array('-show_packets', $arguments, true)) return '{"packets":[{"stream_index":0,"data_hash":"SHA256:unchanged"}]}';
                file_put_contents(end($arguments), $this->output);
                return '';
            }
        };
        self::assertSame('optimized', $service->optimize($relative, true));
        self::assertSame($output, $disk->get($relative));
        self::assertSame('ready', $service->inspect($disk->path($relative)));
        self::assertCount(1, $disk->allFiles('operation-tasks'));
        self::assertSame('ready', $service->optimize($relative, true));
    }
}
