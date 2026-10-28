<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class AssistantAdvertisingAgency extends Model
{
    protected $table = 'assistant_advertising_agencies';

    protected $fillable = ['name', 'advertising_media_id', 'settlement_coefficient', 'is_enabled', 'sort'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['advertising_media_id' => 'integer', 'settlement_coefficient' => 'decimal:4', 'is_enabled' => 'boolean', 'sort' => 'integer'];
    }

    public function advertisingMedia(): BelongsTo
    {
        return $this->belongsTo(AssistantAdvertisingMedia::class, 'advertising_media_id');
    }

    /** @return array<string, float> */
    public static function enabledOptions(): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable((new static)->getTable())) return [];

        return static::query()->where('is_enabled', true)->orderBy('sort')->orderBy('id')
            ->get(['name', 'settlement_coefficient'])
            ->mapWithKeys(fn (self $agency) => [$agency->name => (float) $agency->settlement_coefficient])
            ->all();
    }

    /** @return array<string, string> */
    public static function enabledMediaNames(): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable((new static)->getTable())
            || ! $schema->hasColumn((new static)->getTable(), 'advertising_media_id')) return [];

        return static::query()->where('is_enabled', true)->with('advertisingMedia')
            ->orderBy('sort')->orderBy('id')->get()
            ->mapWithKeys(fn (self $agency) => [$agency->name => (string) ($agency->advertisingMedia?->name ?? '')])
            ->all();
    }
}
