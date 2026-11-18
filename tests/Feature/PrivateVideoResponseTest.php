<?php

namespace Tests\Feature;

use App\Support\PrivateVideoResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateVideoResponseTest extends TestCase
{
    public function test_inline_video_response_supports_byte_ranges_and_safe_headers(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('aikaipin/reference-videos/1/test.mp4', str_repeat('video-data', 10));

        $request = Request::create('/video', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=5-14']);
        $response = app(PrivateVideoResponse::class)->inline(
            'local',
            'aikaipin/reference-videos/1/test.mp4',
            'test.mp4',
            'video/mp4',
            $request,
        );

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 5-14/100', $response->headers->get('Content-Range'));
        self::assertSame('10', $response->headers->get('Content-Length'));
        self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
        self::assertSame('video/mp4', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('identity', $response->headers->get('Content-Encoding'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    public function test_inline_video_response_returns_not_found_for_missing_file(): void
    {
        Storage::fake('local');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        app(PrivateVideoResponse::class)->inline('local', 'missing.mp4', 'missing.mp4', 'video/mp4');
    }
}
