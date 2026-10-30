<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalMessageImage extends Model
{
    protected $fillable = ['internal_message_id', 'path', 'original_name', 'mime_type', 'sort'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(InternalMessage::class, 'internal_message_id');
    }
}
