<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AikaipinProductUploadSession extends Model
{
    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'upload_token',
        'user_id',
        'client_fingerprint',
        'original_name',
        'mime_type',
        'total_size',
        'total_chunks',
        'received_size',
        'disk',
        'temporary_directory',
        'assembled_path',
        'status',
        'expires_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'total_size' => 'integer',
            'total_chunks' => 'integer',
            'received_size' => 'integer',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'user_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(AikaipinProductUploadPart::class, 'upload_session_id')->orderBy('chunk_index');
    }
}
