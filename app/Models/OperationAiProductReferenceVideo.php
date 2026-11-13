<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationAiProductReferenceVideo extends Model
{
    public const TYPE_FLOW = 'flow';
    public const TYPE_TRAFFIC = 'traffic';
    public const TYPE_REMIX = 'remix';

    protected $fillable = [
        'aikaipin_product_id', 'type', 'sequence', 'file_date', 'disk', 'path',
        'file_name', 'original_name', 'mime_type', 'size', 'department_code',
        'uploaded_by', 'uploaded_by_name',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'file_date' => 'date',
        'size' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(AikaipinProduct::class, 'aikaipin_product_id');
    }
}
