<?php

namespace App\Portals\Aikaipin;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AikaipinSelectionProposalStorageService
{
    public const DISK = 'local';

    public const BASE_DIRECTORY = 'portals/aikaipin/selection-proposals';

    /** @return array{path:string,original_name:string,mime_type:?string,size:int,sha256:string} */
    public function storeImage(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) ? $extension : 'bin';
        $path = self::BASE_DIRECTORY.'/images/'.Str::uuid().'/'.Str::uuid().'.'.$extension;
        $disk = Storage::disk(self::DISK);
        $disk->putFileAs(dirname($path), $file, basename($path));

        return [
            'path' => $path,
            'original_name' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
            'mime_type' => $file->getClientMimeType() ?: null,
            'size' => (int) ($disk->size($path) ?: 0),
            'sha256' => hash_file('sha256', $disk->path($path)) ?: '',
        ];
    }

    public function deleteImage(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
