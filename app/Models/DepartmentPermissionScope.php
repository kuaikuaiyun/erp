<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentPermissionScope extends Model
{
    public const SCOPE_SELF = 'self';

    public const SCOPE_DESCENDANTS = 'descendants';

    protected $table = 'admin_department_permission_scopes';

    protected $fillable = ['role_id', 'permission_id', 'scope'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'role_id');
    }
}


