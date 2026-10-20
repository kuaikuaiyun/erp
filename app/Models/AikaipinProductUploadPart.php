<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AikaipinProductUploadPart extends Model
{
    protected $fillable = [
        'upload_session_id',
        'chunk_index',
        'path',
        'size',
        'sha256',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function uploadSession(): BelongsTo
    {
        return $this->belongsTo(AikaipinProductUploadSession::class, 'upload_session_id');
    }
}
