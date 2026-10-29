<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class AssistantAdvertisingMedia extends Model
{
    protected $table = 'assistant_advertising_media';

    protected $fillable = ['assistant_department_id', 'name', 'is_enabled', 'sort'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['assistant_department_id' => 'integer', 'is_enabled' => 'boolean', 'sort' => 'integer'];
    }

    public function assistantDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assistant_department_id');
    }

    /** @return list<string> */
    public static function enabledNames(?array $assistantDepartmentIds = null): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable((new static)->getTable())) return [];

        return static::query()->where('is_enabled', true)
            ->when($assistantDepartmentIds !== null, fn ($query) => $query->whereIn('assistant_department_id', $assistantDepartmentIds))
            ->orderBy('sort')->orderBy('id')->pluck('name')->unique()->values()->all();
    }
}
