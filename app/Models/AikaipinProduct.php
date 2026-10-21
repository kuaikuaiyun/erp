<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AikaipinProduct extends Model
{
    protected $fillable = [
        'product_code',
        'name',
        'sku',
        'categories',
        'source_platform',
        'product_type',
        'landing_page_url',
        'images',
        'reference_file',
        'package_file',
        'visible_department_ids',
        'created_by',
        'created_by_name',
        'updated_by',
        'updated_by_name',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'images' => 'array',
            'reference_file' => 'array',
            'package_file' => 'array',
            'visible_department_ids' => 'array',
        ];
    }

    /** @return list<string> */
    public function skuValues(): array
    {
        return $this->normalizeStrings(preg_split('/[,，;；\n]+/u', (string) $this->sku) ?: []);
    }

    /** @return list<string> */
    public function categoryValues(): array
    {
        return $this->normalizeStrings((array) ($this->categories ?? []));
    }

    /** @return list<array{disk:string,path:string,original_name:string,mime_type:?string,size:int,sha256:?string}> */
    public function imageFiles(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $file): ?array => $this->normalizedFilePayload($file),
            (array) ($this->images ?? []),
        )));
    }

    /** @return array{disk:string,path:string,original_name:string,mime_type:?string,size:int,sha256:?string}|null */
    public function referenceFilePayload(): ?array
    {
        return $this->normalizedFilePayload($this->reference_file);
    }

    /** @return array{disk:string,path:string,original_name:string,mime_type:?string,size:int,sha256:?string}|null */
    public function packageFilePayload(): ?array
    {
        return $this->normalizedFilePayload($this->package_file);
    }

    /** @return list<int> */
    public function visibleDepartmentIds(): array
    {
        return collect((array) ($this->visible_department_ids ?? []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    /** @param iterable<mixed> $values
     *  @return list<string>
     */
    private function normalizeStrings(iterable $values): array
    {
        $seen = [];
        $normalized = [];

        foreach ($values as $value) {
            $item = trim(preg_replace('/\s+/u', ' ', (string) $value) ?: '');
            $key = mb_strtolower($item);

            if ($item === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    /** @return array{disk:string,path:string,original_name:string,mime_type:?string,size:int,sha256:?string}|null */
    private function normalizedFilePayload(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', trim((string) ($value['path'] ?? ''))), '/');
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return [
            'disk' => trim((string) ($value['disk'] ?? '')) ?: 'local',
            'path' => $path,
            'original_name' => trim((string) ($value['original_name'] ?? '')) ?: basename($path),
            'mime_type' => ($mimeType = trim((string) ($value['mime_type'] ?? ''))) !== '' ? $mimeType : null,
            'size' => max(0, (int) ($value['size'] ?? 0)),
            'sha256' => ($sha256 = trim((string) ($value['sha256'] ?? ''))) !== '' ? $sha256 : null,
        ];
    }
}
