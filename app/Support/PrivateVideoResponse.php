<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateVideoResponse
{
    public function __construct(private readonly BrowserCompatibleVideoService $videoCompatibility) {}

    /**
     * Stream a private video with explicit byte-range support.
     *
     * Serving large media through BinaryFileResponse normally works, but some
     * production FastCGI/reverse-proxy combinations buffer or strip range
     * responses. Browsers then cannot read the MP4 metadata and report 0:00 or
     * a decoding error even though the downloaded file is intact.
     */
    public function inline(
        string $disk,
        string $path,
        string $fileName,
        ?string $storedMimeType = null,
        ?Request $request = null,
    ): StreamedResponse {
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $absolutePath = Storage::disk($disk)->path($path);
        abort_unless(is_file($absolutePath) && is_readable($absolutePath), 404);

        $compatibility = $this->videoCompatibility->normalize($absolutePath);

        $request ??= request();
        $fileSize = (int) filesize($absolutePath);
        abort_unless($fileSize > 0, 404);

        [$start, $end, $partial] = $this->requestedRange(
            (string) $request->headers->get('Range', ''),
            $fileSize,
        );
        $length = $end - $start + 1;
        $mimeType = $this->videoMimeType($absolutePath, $storedMimeType);
        $fallbackName = 'video.'.(pathinfo($fileName, PATHINFO_EXTENSION) ?: 'mp4');

        $response = new StreamedResponse(function () use ($absolutePath, $start, $length): void {
            $handle = fopen($absolutePath, 'rb');
            abort_unless(is_resource($handle), 404);

            try {
                if ($start > 0) {
                    fseek($handle, $start);
                }

                $remaining = $length;
                while ($remaining > 0 && ! feof($handle)) {
                    $chunk = fread($handle, min(1024 * 1024, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                    if (function_exists('flush')) {
                        flush();
                    }
                }
            } finally {
                fclose($handle);
            }
        }, $partial ? 206 : 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $fileName, $fallbackName),
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Encoding' => 'identity',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
            'X-Video-Compatibility' => $compatibility,
        ]);

        if ($partial) {
            $response->headers->set('Content-Range', "bytes {$start}-{$end}/{$fileSize}");
        }

        return $response;
    }

    /** @return array{int,int,bool} */
    private function requestedRange(string $header, int $fileSize): array
    {
        if ($header === '' || ! preg_match('/^bytes=(\d*)-(\d*)$/i', trim($header), $matches)) {
            return [0, $fileSize - 1, false];
        }

        if ($matches[1] === '' && $matches[2] === '') {
            return [0, $fileSize - 1, false];
        }

        if ($matches[1] === '') {
            $suffixLength = max(1, (int) $matches[2]);
            $start = max(0, $fileSize - $suffixLength);
            return [$start, $fileSize - 1, true];
        }

        $start = (int) $matches[1];
        abort_if($start >= $fileSize, 416, 'Requested range is not satisfiable.');
        $end = $matches[2] === '' ? $fileSize - 1 : min((int) $matches[2], $fileSize - 1);
        abort_if($end < $start, 416, 'Requested range is not satisfiable.');

        return [$start, $end, true];
    }

    private function videoMimeType(string $absolutePath, ?string $storedMimeType): string
    {
        $detected = function_exists('mime_content_type') ? mime_content_type($absolutePath) : false;
        if (is_string($detected) && str_starts_with(strtolower($detected), 'video/')) {
            return $detected;
        }

        if ($storedMimeType && str_starts_with(strtolower($storedMimeType), 'video/')) {
            return $storedMimeType;
        }

        return match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'm4v' => 'video/x-m4v',
            default => 'video/mp4',
        };
    }
}
