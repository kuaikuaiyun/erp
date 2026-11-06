<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortalHomeFolder extends Model
{
    protected $fillable = ['name', 'sort'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function portalAssignments(): HasMany
    {
        return $this->hasMany(PortalHomeFolderPortal::class, 'folder_id')->orderBy('sort')->orderBy('id');
    }
}
