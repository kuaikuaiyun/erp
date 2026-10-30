<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalMessageRecipient extends Model
{
    protected $fillable = ['internal_message_id', 'recipient_user_id', 'read_at'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(InternalMessage::class, 'internal_message_id');
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'recipient_user_id');
    }
}
