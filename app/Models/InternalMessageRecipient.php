<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InternalMessageRecipient extends Model
{
    protected $fillable = ['internal_message_id', 'recipient_user_id', 'read_at', 'processed_at', 'processed_by'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(InternalMessage::class, 'internal_message_id');
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'recipient_user_id');
    }

    public function processedByUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'processed_by');
    }

    public function managerListingTask(): HasOne
    {
        return $this->hasOne(AiProductListingTask::class, 'manager_recipient_id');
    }

    public function assigneeListingTask(): HasOne
    {
        return $this->hasOne(AiProductListingTask::class, 'assignee_recipient_id');
    }
}
