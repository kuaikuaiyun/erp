<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalMessage extends Model
{
    protected $fillable = ['title', 'content', 'sender_user_id', 'sender_name', 'sender_portal_code', 'sender_portal_name', 'published_at'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'sender_user_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(InternalMessageImage::class)->orderBy('sort')->orderBy('id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(InternalMessageRecipient::class);
    }
}
