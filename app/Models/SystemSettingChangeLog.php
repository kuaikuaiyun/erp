<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSettingChangeLog extends Model
{
    protected $fillable = [
        'key',
        'action',
        'old_value',
        'new_value',
        'is_encrypted',
        'admin_user_id',
        'admin_username',
        'ip_address',
        'user_agent',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
        ];
    }
}


