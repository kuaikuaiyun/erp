<?php

namespace App\Portals\ProductRequests;

use App\Models\AdminUser;
use App\Models\ProductRequest;
use App\Models\ProductRequestAttachment;
use App\Models\ProductRequestLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductRequestWorkflowService
{
    public function __construct(private readonly ProductRequestAccessService $access)
    {
    }

    /** @param array<int, UploadedFile> $files */
    public function replaceStageAttachments(ProductRequest $productRequest, string $stage, array $files, AdminUser $user): void
    {
        $existing = $productRequest->attachments()->where('stage', $stage)->get();
        foreach ($existing as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $attachment->delete();
        }

        foreach (array_values($files) as $sort => $file) {
            $this->storeAttachment($productRequest, $stage, $file, $sort + 1, $user);
        }
    }

    /** @param array<int, UploadedFile> $files */
    public function appendStageAttachments(ProductRequest $productRequest, string $stage, array $files, AdminUser $user): void
    {
        if ($files === []) {
            return;
        }

        $sort = (int) $productRequest->attachments()->where('stage', $stage)->max('sort');

        foreach (array_values($files) as $file) {
            $this->storeAttachment($productRequest, $stage, $file, ++$sort, $user);
        }
    }

    /** @param array<int, int> $ids */
    public function deleteStageAttachments(ProductRequest $productRequest, string $stage, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $attachments = $productRequest->attachments()
            ->where('stage', $stage)
            ->whereIn('id', $ids)
            ->get();
        foreach ($attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $attachment->delete();
        }
    }

    public function storeStageAttachment(ProductRequest $productRequest, string $stage, UploadedFile $file, AdminUser $user): ProductRequestAttachment
    {
        $sort = (int) $productRequest->attachments()->where('stage', $stage)->max('sort');

        return $this->storeAttachment($productRequest, $stage, $file, $sort + 1, $user);
    }

    public function copyStageAttachments(ProductRequest $source, ProductRequest $target, string $stage, AdminUser $user): void
    {
        $this->replaceStageAttachments($target, $stage, [], $user);
        $source->loadMissing('attachments');

        foreach ($source->attachmentsFor($stage) as $sort => $attachment) {
            $extension = pathinfo((string) $attachment->path, PATHINFO_EXTENSION);
            $path = 'product-requests/'.$target->report_no.'/'.$stage.'/'.Str::uuid().($extension ? '.'.$extension : '');
            if (!Storage::disk($attachment->disk)->copy($attachment->path, $path)) {
                continue;
            }

            ProductRequestAttachment::query()->create([
                'product_request_id' => $target->getKey(),
                'stage' => $stage,
                'sort' => $sort + 1,
                'disk' => $attachment->disk,
                'path' => $path,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'uploaded_by' => $user->getKey(),
                'uploaded_by_name' => $user->name,
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    public function log(ProductRequest $productRequest, string $event, AdminUser $actor, array $payload = []): void
    {
        $snapshot = $this->access->actorSnapshot($actor);

        ProductRequestLog::query()->create([
            'product_request_id' => $productRequest->getKey(),
            'event' => $event,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actor_id' => $actor->getKey(),
            'actor_name' => $actor->name,
            'actor_department_name' => $snapshot['department_name'],
        ]);
    }

    public function normalizeSupplierUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $url;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $scheme.'://'.$host.$path;
    }

    public function supplierUrlHash(string $url): string
    {
        return hash('sha256', $url);
    }

    private function storeAttachment(ProductRequest $productRequest, string $stage, UploadedFile $file, int $sort, AdminUser $user): ProductRequestAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension()) ?: 'img';
        $path = 'product-requests/'.$productRequest->report_no.'/'.$stage.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return ProductRequestAttachment::query()->create([
            'product_request_id' => $productRequest->getKey(),
            'stage' => $stage,
            'sort' => $sort,
            'disk' => 'local',
            'path' => $path,
            'original_name' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $user->getKey(),
            'uploaded_by_name' => $user->name,
        ]);
    }
}
