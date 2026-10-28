<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AssistantAdvertisingMedia extends Model
{
    protected $table = 'assistant_advertising_media';

    protected $fillable = ['name', 'is_enabled', 'sort'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'sort' => 'integer'];
    }

    /** @return list<string> */
    public static function enabledNames(): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable((new static)->getTable())) return [];

        return static::query()->where('is_enabled', true)->orderBy('sort')->orderBy('id')->pluck('name')->all();
    }
}
