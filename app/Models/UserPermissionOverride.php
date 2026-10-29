<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermissionOverride extends Model
{
    public const EFFECT_ALLOW = 'allow';
    public const EFFECT_DENY = 'deny';

    protected $table = 'admin_user_permission_overrides';

    protected $fillable = ['user_id', 'permission_id', 'effect', 'updated_by'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
