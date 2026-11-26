<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpLoginSite extends Model
{
    protected $fillable = ['domain', 'root_domain', 'company_name', 'display_name', 'logo_path', 'password_enabled', 'dingtalk_enabled', 'dingtalk_company_id'];

    protected $casts = [
        'password_enabled' => 'boolean',
        'dingtalk_enabled' => 'boolean',
        'password_version' => 'integer',
        'dingtalk_version' => 'integer',
    ];

    public function rootDomain(): string
    {
        return $this->root_domain ?? $this->domain;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(DingTalkCompany::class, 'dingtalk_company_id');
    }
}
