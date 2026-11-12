<?php

namespace App\Portals\OperationTasks;

use App\Models\OperationTaskAssetUpload;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OperationTaskAssetUploadService
{
    public const CHUNK_SIZE = 2 * 1024 * 1024;
    public const MAX_SIZE = 50 * 1024 * 1024;

    public function begin(int $userId, string $name, int $size): array
    {
        $token = (string) Str::uuid();
        $upload = OperationTaskAssetUpload::query()->create([
            'token' => $token, 'user_id' => $userId, 'original_name' => basename(str_replace('\\', '/', $name)),
            'size' => $size, 'status' => 'uploading',
            'file_path' => $this->directory($token).'/file', 'expires_at' => now()->addDay(),
        ]);

        return $this->payload($upload);
    }

    public function status(int $userId, string $token): array
    {
        return $this->payload($this->owned($userId, $token));
    }

    public function part(int $userId, string $token, int $index, UploadedFile $file): array
    {
        return $this->connection()->transaction(function () use ($userId, $token, $index, $file): array {
            $upload = $this->owned($userId, $token, true);
            $expected = min(self::CHUNK_SIZE, $upload->size - $index * self::CHUNK_SIZE);
            if ($upload->status !== 'uploading' || $index < 0 || $expected <= 0 || $file->getSize() !== $expected) {
                $this->invalid('invalid_part');
            }
            $stream = fopen($file->getRealPath(), 'rb');
            try {
                if ($stream === false || ! Storage::disk('local')->put($this->directory($token).'/parts/'.$index, $stream)) {
                    $this->invalid('storage_failed');
                }
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
            $upload->forceFill(['expires_at' => now()->addDay()])->save();

            return $this->payload($upload);
        });
    }

    public function complete(int $userId, string $token): array
    {
        return $this->connection()->transaction(function () use ($userId, $token): array {
            $upload = $this->owned($userId, $token, true);
            if ($upload->status === 'ready') return $this->payload($upload);
            if ($upload->status !== 'uploading') $this->invalid('invalid_token');
            $disk = Storage::disk('local');
            $parts = (int) ceil($upload->size / self::CHUNK_SIZE);
            for ($index = 0; $index < $parts; $index++) {
                $part = $this->directory($token).'/parts/'.$index;
                $expected = min(self::CHUNK_SIZE, $upload->size - $index * self::CHUNK_SIZE);
                if (! $disk->exists($part) || $disk->size($part) !== $expected) $this->invalid('incomplete');
            }
            $output = fopen($disk->path($upload->file_path), 'wb');
            if ($output === false) $this->invalid('storage_failed');
            try {
                for ($index = 0; $index < $parts; $index++) {
                    $input = $disk->readStream($this->directory($token).'/parts/'.$index);
                    try {
                        if (! is_resource($input) || stream_copy_to_stream($input, $output) === false) $this->invalid('storage_failed');
                    } finally {
                        if (is_resource($input)) fclose($input);
                    }
                }
            } finally {
                fclose($output);
            }
            $file = new File($disk->path($upload->file_path));
            if ($file->getSize() !== $upload->size) $this->invalid('incomplete');
            $validator = Validator::make(['file' => $file], [
                'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:51200'],
            ]);
            if ($validator->fails()) {
                $disk->delete($upload->file_path);
                $this->invalid('invalid_file');
            }
            $upload->forceFill(['status' => 'ready', 'mime_type' => $file->getMimeType(), 'expires_at' => now()->addDay()])->save();
            // Keep parts until commit. A failed commit can then safely retry completion.
            $this->connection()->afterCommit(fn () => $disk->deleteDirectory($this->directory($token).'/parts'));

            return $this->payload($upload);
        });
    }

    /** Called within the task transaction; rollback makes the token usable again. */
    public function consume(int $userId, string $token, string $field): array
    {
        $upload = $this->owned($userId, $token, true, $field);
        if ($upload->status !== 'ready' || ! Storage::disk('local')->exists($upload->file_path)) {
            $this->invalid('invalid_token', $field);
        }
        $upload->forceFill(['status' => 'consumed'])->save();

        return $upload->only(['file_path', 'original_name', 'mime_type']);
    }

    public function purge(int $limit = 200): int
    {
        $ids = OperationTaskAssetUpload::query()->where('status', '!=', 'consumed')
            ->where('expires_at', '<=', now())->orderBy('id')->limit(max(1, min(1000, $limit)))->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += $this->connection()->transaction(function () use ($id): int {
                $upload = OperationTaskAssetUpload::query()->lockForUpdate()->find($id);
                if (! $upload || $upload->status === 'consumed' || $upload->expires_at->isFuture()) return 0;
                if (Storage::disk('local')->directoryExists($this->directory($upload->token))
                    && ! Storage::disk('local')->deleteDirectory($this->directory($upload->token))) return 0;
                $upload->delete();
                return 1;
            });
        }
        return $count;
    }

    private function owned(int $userId, string $token, bool $lock = false, string $field = 'upload_token'): OperationTaskAssetUpload
    {
        $query = OperationTaskAssetUpload::query()->where('token', $token)->where('user_id', $userId);
        if ($lock) $query->lockForUpdate();
        $upload = $query->first();
        if (! $upload || $upload->status === 'consumed' || ! $upload->expires_at->isFuture()) $this->invalid('invalid_token', $field);
        return $upload;
    }

    private function payload(OperationTaskAssetUpload $upload): array
    {
        return ['token' => $upload->token, 'status' => $upload->status, 'name' => $upload->original_name, 'chunk_size' => self::CHUNK_SIZE];
    }

    private function directory(string $token): string
    {
        return 'operation-tasks/asset-uploads/'.$token;
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    private function invalid(string $key, string $field = 'upload_token'): never
    {
        throw ValidationException::withMessages([$field => __('operation_tasks.upload.'.$key)]);
    }
}
