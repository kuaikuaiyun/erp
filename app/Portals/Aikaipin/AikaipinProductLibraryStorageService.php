<?php

namespace App\Portals\Aikaipin;

use App\Models\AikaipinProductUploadPart;
use App\Models\AikaipinProductUploadSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AikaipinProductLibraryStorageService
{
    public const DISK = 'local';

    public const BASE_DIRECTORY = 'portals/aikaipin/product-library';

    public const PACKAGE_CHUNK_SIZE = 5 * 1024 * 1024;

    /** @return array{path:string,original_name:string,mime_type:?string,size:int,sha256:string} */
    public function storeUploadedFile(UploadedFile $file, string $directory): array
    {
        $path = $this->newLibraryPath($directory, $file->getClientOriginalExtension());
        $disk = Storage::disk(self::DISK);
        $disk->putFileAs(dirname($path), $file, basename($path));

        return $this->filePayload($path, (string) $file->getClientOriginalName(), $file->getClientMimeType());
    }

    /** @return array{path:string,original_name:string,mime_type:?string,size:int,sha256:string} */
    public function copyStoredFile(string $sourcePath, string $directory, string $originalName, ?string $mimeType): array
    {
        $disk = Storage::disk(self::DISK);
        $path = $this->newLibraryPath($directory, pathinfo($originalName, PATHINFO_EXTENSION));

        if (!$disk->copy($sourcePath, $path)) {
            throw new RuntimeException('Unable to copy the completed product-package archive.');
        }

        return $this->filePayload($path, $originalName, $mimeType);
    }

    public function storeUploadPart(AikaipinProductUploadSession $session, int $chunkIndex, UploadedFile $file): AikaipinProductUploadPart
    {
        $disk = Storage::disk(self::DISK);
        $path = $this->partPath($session, $chunkIndex);
        $existing = $session->parts()->where('chunk_index', $chunkIndex)->first();

        if ($existing?->path && $disk->exists($existing->path)) {
            $disk->delete($existing->path);
        }

        $disk->putFileAs(dirname($path), $file, basename($path));

        return $session->parts()->updateOrCreate(
            ['chunk_index' => $chunkIndex],
            [
                'path' => $path,
                'size' => (int) ($disk->size($path) ?: 0),
                'sha256' => hash_file('sha256', $disk->path($path)) ?: null,
            ],
        );
    }

    /**
     * @param iterable<AikaipinProductUploadPart> $parts
     * @return array{path:string,size:int,sha256:string}
     */
    public function assembleUpload(AikaipinProductUploadSession $session, iterable $parts): array
    {
        $disk = Storage::disk(self::DISK);
        $assembledPath = $session->temporary_directory.'/assembled/'.Str::uuid().'.'.$this->extension($session->original_name);
        $disk->makeDirectory(dirname($assembledPath));
        $destination = fopen($disk->path($assembledPath), 'wb');

        if ($destination === false) {
            throw new RuntimeException('Unable to create the product-package archive.');
        }

        try {
            foreach ($parts as $part) {
                if (!$disk->exists($part->path)) {
                    throw new RuntimeException('A product-package upload part is unavailable.');
                }

                $source = fopen($disk->path($part->path), 'rb');
                if ($source === false) {
                    throw new RuntimeException('Unable to read a product-package upload part.');
                }

                try {
                    stream_copy_to_stream($source, $destination);
                } finally {
                    fclose($source);
                }
            }
        } finally {
            fclose($destination);
        }

        $size = (int) ($disk->size($assembledPath) ?: 0);
        if ($size !== (int) $session->total_size) {
            $disk->delete($assembledPath);

            throw new RuntimeException('The product-package archive size does not match the selected file.');
        }

        return [
            'path' => $assembledPath,
            'size' => $size,
            'sha256' => hash_file('sha256', $disk->path($assembledPath)) ?: '',
        ];
    }

    public function deleteUploadTemporaryFiles(AikaipinProductUploadSession $session): void
    {
        Storage::disk(self::DISK)->deleteDirectory($session->temporary_directory);
    }

    public function deleteFile(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    public function newTemporaryDirectory(string $uploadToken): string
    {
        return self::BASE_DIRECTORY.'/chunks/'.$uploadToken;
    }

    private function partPath(AikaipinProductUploadSession $session, int $chunkIndex): string
    {
        return $session->temporary_directory.'/parts/'.str_pad((string) $chunkIndex, 8, '0', STR_PAD_LEFT).'.part';
    }

    private function newLibraryPath(string $directory, ?string $extension): string
    {
        return self::BASE_DIRECTORY.'/'.$directory.'/'.Str::uuid().'/'.Str::uuid().'.'.$this->extension($extension);
    }

    /** @return array{path:string,original_name:string,mime_type:?string,size:int,sha256:string} */
    private function filePayload(string $path, string $originalName, ?string $mimeType): array
    {
        $disk = Storage::disk(self::DISK);

        return [
            'path' => $path,
            'original_name' => Str::limit($originalName, 255, ''),
            'mime_type' => $mimeType ?: null,
            'size' => (int) ($disk->size($path) ?: 0),
            'sha256' => hash_file('sha256', $disk->path($path)) ?: '',
        ];
    }

    private function extension(?string $extension): string
    {
        $extension = strtolower(trim((string) $extension));

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) ? $extension : 'bin';
    }
}
