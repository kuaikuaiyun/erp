<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class AssistantAdvertisingAgency extends Model
{
    protected $table = 'assistant_advertising_agencies';

    protected $fillable = ['assistant_department_id', 'name', 'advertising_media_id', 'settlement_coefficient', 'is_enabled', 'sort'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['assistant_department_id' => 'integer', 'advertising_media_id' => 'integer', 'settlement_coefficient' => 'decimal:4', 'is_enabled' => 'boolean', 'sort' => 'integer'];
    }

    public function advertisingMedia(): BelongsTo
    {
        return $this->belongsTo(AssistantAdvertisingMedia::class, 'advertising_media_id');
    }

    /** @return array<string, float> */
    public static function enabledOptions(?array $assistantDepartmentIds = null): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable((new static)->getTable())) return [];

        return static::query()->where('is_enabled', true)
            ->when($assistantDepartmentIds !== null, fn ($query) => $query->whereIn('assistant_department_id', $assistantDepartmentIds))
            ->with('advertisingMedia')->orderBy('sort')->orderBy('id')
            ->get()
            ->mapWithKeys(fn (self $agency) => [$agency->optionKey() => (float) $agency->settlement_coefficient])
            ->all();
    }

    /** @return array<string, string> */
    public static function enabledMediaNames(?array $assistantDepartmentIds = null): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable((new static)->getTable())
            || ! $schema->hasColumn((new static)->getTable(), 'advertising_media_id')) return [];

        return static::query()->where('is_enabled', true)
            ->when($assistantDepartmentIds !== null, fn ($query) => $query->whereIn('assistant_department_id', $assistantDepartmentIds))
            ->with('advertisingMedia')
            ->orderBy('sort')->orderBy('id')->get()
            ->mapWithKeys(fn (self $agency) => [$agency->optionKey() => (string) ($agency->advertisingMedia?->name ?? '')])
            ->all();
    }

    /** @return array<string, string> */
    public static function enabledNames(?array $assistantDepartmentIds = null): array
    {
        return static::query()->where('is_enabled', true)
            ->when($assistantDepartmentIds !== null, fn ($query) => $query->whereIn('assistant_department_id', $assistantDepartmentIds))
            ->with('advertisingMedia')->orderBy('sort')->orderBy('id')
            ->get()
            ->mapWithKeys(fn (self $agency) => [$agency->optionKey() => (string) $agency->name])
            ->all();
    }

    private function optionKey(): string
    {
        return (string) $this->getKey();
    }
}
