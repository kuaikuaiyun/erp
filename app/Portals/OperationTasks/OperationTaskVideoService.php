<?php

namespace App\Portals\OperationTasks;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

class OperationTaskVideoService
{
    public function absolutePath(string $relative): string
    {
        $root = realpath(Storage::disk('local')->path('operation-tasks'));
        $file = realpath(Storage::disk('local')->path($relative));
        abort_unless($root && $file && is_file($file) && str_starts_with(str_replace('\\', '/', $file), rtrim(str_replace('\\', '/', $root), '/').'/'), 404);
        return $file;
    }

    // Caller must have authenticated and authorized access to the owning task.
    public function fileResponse(string $relative): Response
    {
        $file = $this->absolutePath($relative);
        if (! config('operation_task_video.x_accel_enabled')) {
            return response()->file($file, ['Cache-Control' => 'private, no-cache'])->setPrivate();
        }
        $root = realpath(Storage::disk('local')->path(''));
        $suffix = substr(str_replace('\\', '/', $file), strlen(rtrim(str_replace('\\', '/', $root), '/')) + 1);
        $uri = config('operation_task_video.internal_prefix').implode('/', array_map('rawurlencode', explode('/', $suffix)));
        return response('', 200, [
            'X-Accel-Redirect' => $uri,
            'Content-Type' => mime_content_type($file) ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function inspect(string $file): string
    {
        $input = fopen($file, 'rb');
        if (! $input) return 'invalid';
        try {
            $length = filesize($file);
            $position = 0; $moov = null; $mdat = null; $ftyp = false;
            while ($position < $length) {
                if ($length - $position < 8 || fseek($input, $position) !== 0) return 'invalid';
                $header = fread($input, 8);
                if (strlen($header) !== 8) return 'invalid';
                $size = unpack('N', substr($header, 0, 4))[1];
                $type = substr($header, 4, 4); $headerSize = 8;
                if ($size === 1) {
                    $large = fread($input, 8);
                    if (strlen($large) !== 8) return 'invalid';
                    $words = unpack('Nhigh/Nlow', $large);
                    if ($words['high'] > 0x7fffffff) return 'invalid';
                    $size = $words['high'] * 4294967296 + $words['low']; $headerSize = 16;
                } elseif ($size === 0) {
                    $size = $length - $position;
                }
                if ($size < $headerSize || $size > $length - $position) return 'invalid';
                if ($type === 'ftyp') {
                    if ($size < $headerSize + 4 || fread($input, 4) === 'qt  ') return 'unsupported';
                    $ftyp = true;
                }
                if ($type === 'moof') return 'fragmented';
                if ($type === 'moov') $moov = $position;
                if ($type === 'mdat' && $mdat === null) $mdat = $position;
                $position += $size;
            }
            if (! $ftyp || $moov === null || $mdat === null) return 'invalid';
            return $moov < $mdat ? 'ready' : 'needs_faststart';
        } finally {
            fclose($input);
        }
    }

    protected function run(array $arguments): string
    {
        $process = new Process($arguments);
        $process->setTimeout((int) config('operation_task_video.timeout', 300));
        $process->mustRun();
        return $process->getOutput();
    }

    private function packetFingerprint(string $file): array
    {
        $json = $this->run([config('operation_task_video.ffprobe'), '-v', 'error', '-show_packets',
            '-show_entries', 'packet=stream_index,data_hash', '-show_data_hash', 'sha256', '-of', 'json', $file]);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $streams = [];
        foreach ($data['packets'] ?? [] as $packet) {
            if (! isset($packet['stream_index'], $packet['data_hash'])) throw new \RuntimeException('Missing packet hash.');
            $index = (int) $packet['stream_index'];
            $streams[$index] ??= hash_init('sha256');
            hash_update($streams[$index], $packet['data_hash']."\n");
        }
        if ($streams === []) throw new \RuntimeException('No media packets.');
        ksort($streams);
        return array_map(fn ($context) => hash_final($context), $streams);
    }

    public function optimize(string $relative, bool $apply = false): string
    {
        $file = $this->absolutePath($relative);
        $lock = fopen(sys_get_temp_dir().'/erp-mp4-'.hash('sha256', $file).'.lock', 'c');
        if (! $lock) return 'failed';
        $temporary = null;
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) return 'busy';
            $state = $this->inspect($file);
            if ($state !== 'needs_faststart' || ! $apply) return $state;
            $free = disk_free_space(dirname($file));
            if ($free === false || $free < filesize($file) * 2 + 1048576) return 'no_space';
            $temporary = $file.'.faststart-'.bin2hex(random_bytes(8)).'.mp4';
            $before = $this->packetFingerprint($file);
            $this->run([config('operation_task_video.ffmpeg'), '-nostdin', '-v', 'error', '-n', '-i', $file,
                '-map', '0', '-map_metadata', '0', '-c', 'copy', '-movflags', '+faststart', '-f', 'mp4', $temporary]);
            clearstatcache(true, $temporary);
            if ($this->inspect($temporary) !== 'ready' || $before !== $this->packetFingerprint($temporary)) return 'failed';
            if (! chmod($temporary, fileperms($file) & 0777) || ! rename($temporary, $file)) return 'failed';
            $temporary = null;
            clearstatcache(true, $file);
            return 'optimized';
        } catch (\Throwable $exception) {
            report($exception);
            return 'failed';
        } finally {
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
