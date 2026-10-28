<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AssistantAdvertisingAgency extends Model
{
    protected $table = 'assistant_advertising_agencies';

    protected $fillable = ['name', 'settlement_coefficient', 'is_enabled', 'sort'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['settlement_coefficient' => 'decimal:4', 'is_enabled' => 'boolean', 'sort' => 'integer'];
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
}
