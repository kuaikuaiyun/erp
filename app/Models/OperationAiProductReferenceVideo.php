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

    public const SOURCE_OPERATOR_UPLOAD = 'operator_upload';

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

    public static function operatorCode(int $userId): string
    {
        return 'op'.max(1, $userId);
    }

    public static function isOperatorMaterialFile(?string $fileName): bool
    {
        $code = strtolower(pathinfo((string) $fileName, PATHINFO_FILENAME));

        return preg_match('/(?:^|-)op\d+-min-\d{6}-\d+(?:$|-)/', $code) === 1
            || preg_match('/-\d{6}-placement-[a-z0-9]+$/', $code) === 1;
    }

    public static function operatorFileName(
        string $productCode,
        int $userId,
        string $date,
        int $number,
        string $extension,
    ): string {
        return implode('-', [
            $productCode,
            self::operatorCode($userId),
            'min',
            $date,
            str_pad((string) max(1, $number), 3, '0', STR_PAD_LEFT),
        ]).'.'.ltrim(strtolower($extension), '.');
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
