<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DingTalkIdentity extends Model
{
    protected $table = 'dingtalk_identities';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'version' => 'integer'];

    public function bindings(): HasMany
    {
        return $this->hasMany(DingTalkBinding::class, 'identity_id');
    }
}
