<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DingTalkBinding extends Model
{
    protected $table = 'dingtalk_bindings';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'version' => 'integer'];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(DingTalkIdentity::class, 'identity_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'account_id');
    }
}
