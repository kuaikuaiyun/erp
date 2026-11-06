<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantProject extends Model
{
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

    public function websiteSystems(): HasMany
    {
        return $this->hasMany(AssistantWebsiteSystem::class, 'project_id');
    }
}
