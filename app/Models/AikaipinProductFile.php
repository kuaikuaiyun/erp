<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AikaipinProductFile extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_REFERENCE = 'reference';

    public const KIND_PACKAGE = 'package';

    protected $fillable = [
        'product_id',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'sha256',
        'sort',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(AikaipinProduct::class, 'product_id');
    }
}
