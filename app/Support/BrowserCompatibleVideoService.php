<?php

namespace App\Support;

use Symfony\Component\Process\Process;
use Throwable;

class BrowserCompatibleVideoService
{
    public const READY = 'ready';
    public const TRANSCODED = 'transcoded';
    public const FAILED = 'failed';

    /**
     * Convert HEVC/H.265 MP4 files to H.264/AAC so Chromium browsers can play
     * them consistently. Existing compatible videos are left untouched.
     */
    public function normalize(string $absolutePath): string
    {
        if (! config('aikaipin_video.auto_transcode', true)
            || ! is_file($absolutePath)
            || ! is_readable($absolutePath)
            || ! $this->containsAnyMarker($absolutePath, ['hvc1', 'hev1'])) {
            return self::READY;
        }

        $lock = fopen($absolutePath.'.browser-compatible.lock', 'c');
        if (! is_resource($lock)) {
            return self::FAILED;
        }

        $temporary = null;
        try {
            // Multiple video metadata/range requests can arrive together. Only
            // one request performs the conversion; the rest reuse its result.
            if (! flock($lock, LOCK_EX)) {
                return self::FAILED;
            }
            clearstatcache(true, $absolutePath);
            if (! $this->containsAnyMarker($absolutePath, ['hvc1', 'hev1'])) {
                return self::READY;
            }

            $requiredSpace = max(32 * 1024 * 1024, (int) filesize($absolutePath) * 3);
            $freeSpace = disk_free_space(dirname($absolutePath));
            if ($freeSpace !== false && $freeSpace < $requiredSpace) {
                report(new \RuntimeException('Insufficient disk space to transcode AI product material video.'));
                return self::FAILED;
            }

            $temporary = $absolutePath.'.h264-'.bin2hex(random_bytes(6)).'.mp4';
            $process = new Process([
                (string) config('aikaipin_video.ffmpeg', 'ffmpeg'),
                '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-i', $absolutePath,
                '-map', '0:v:0', '-map', '0:a?',
                '-c:v', 'libx264', '-preset', 'fast', '-crf', '22',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-b:a', '128k',
                '-movflags', '+faststart',
                $temporary,
            ]);
            $process->setTimeout((int) config('aikaipin_video.timeout', 600));
            $process->mustRun();

            clearstatcache(true, $temporary);
            if (! is_file($temporary)
                || filesize($temporary) < 1024
                || ! $this->containsAnyMarker($temporary, ['avc1', 'avc3'])) {
                throw new \RuntimeException('The transcoded video is not a valid H.264 MP4 file.');
            }

            $permissions = fileperms($absolutePath);
            if ($permissions !== false) {
                @chmod($temporary, $permissions & 0777);
            }
            if (! @rename($temporary, $absolutePath)) {
                throw new \RuntimeException('Unable to replace the HEVC video after transcoding.');
            }
            $temporary = null;
            clearstatcache(true, $absolutePath);

            return self::TRANSCODED;
        } catch (Throwable $exception) {
            report($exception);
            return self::FAILED;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($absolutePath.'.browser-compatible.lock');
        }
    }

    /** @param list<string> $markers */
    private function containsAnyMarker(string $path, array $markers): bool
    {
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return false;
        }

        $tail = '';
        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $buffer = $tail.$chunk;
                foreach ($markers as $marker) {
                    if (str_contains($buffer, $marker)) {
                        return true;
                    }
                }
                $tail = substr($buffer, -16);
            }
        } finally {
            fclose($handle);
        }

        return false;
    }
}
