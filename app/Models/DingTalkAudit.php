<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DingTalkAudit extends Model
{
    protected $table = 'dingtalk_audits';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
}
