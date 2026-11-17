<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationAiProductReferenceVideo extends Model
{
    public const TYPE_FLOW = 'flow';
    public const TYPE_TRAFFIC = 'traffic';
    public const TYPE_REMIX = 'remix';

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_HISTORICAL_HTML = 'historical_html';

    public static function fileTypeCode(string $type): string
    {
        return match ($type) {
            self::TYPE_FLOW => 'flow',
            self::TYPE_TRAFFIC => 'spy',
            self::TYPE_REMIX => 'mix',
            default => 'video',
        };
    }

    public static function fileNumber(?string $fileName, int $fallback = 0): int
    {
        if (preg_match('/-(\d+)\.[A-Za-z0-9]{2,5}$/', (string) $fileName, $matches) === 1) {
            return (int) $matches[1];
        }

        return $fallback;
    }

    protected $fillable = [
        'aikaipin_product_id', 'type', 'sequence', 'file_date', 'disk', 'path',
        'file_name', 'original_name', 'mime_type', 'size', 'department_code',
        'source_kind', 'source_reference_index', 'source_url',
        'uploaded_by', 'uploaded_by_name',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'file_date' => 'date',
        'size' => 'integer',
        'source_reference_index' => 'integer',
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
