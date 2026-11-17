<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class PrivateVideoResponse
{
    public function inline(string $disk, string $path, string $fileName, ?string $storedMimeType = null): BinaryFileResponse
    {
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $absolutePath = Storage::disk($disk)->path($path);
        abort_unless(is_file($absolutePath) && is_readable($absolutePath), 404);

        $mimeType = $this->videoMimeType($absolutePath, $storedMimeType);
        $response = response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $fileName, 'video.'.pathinfo($fileName, PATHINFO_EXTENSION)),
            'Cache-Control' => 'private, max-age=300, must-revalidate',
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setAutoEtag();
        $response->setAutoLastModified();

        return $response;
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
