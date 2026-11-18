<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceRecord extends Model
{
    protected $guarded = ['id'];

    private array $reportCastCache = [];
    private bool $cacheReportCasts = false;

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected function castAttribute($key, $value)
    {
        // Report matching, FX and ownership read the same large payload/date many
        // times. Keep native casts, but decode each value only once per model.
        if (! $this->cacheReportCasts || ! in_array($key, ['payload', 'occurred_at'], true)) {
            return parent::castAttribute($key, $value);
        }
        $type = $this->getCastType($key);
        if ($type !== ($key === 'payload' ? 'array' : 'immutable_datetime')) {
            return parent::castAttribute($key, $value);
        }
        if (! isset($this->reportCastCache[$key])
            || $this->reportCastCache[$key]['raw'] !== $value
            || $this->reportCastCache[$key]['type'] !== $type) {
            $this->reportCastCache[$key] = ['raw' => $value, 'type' => $type,
                'value' => parent::castAttribute($key, $value)];
        }
        return $this->reportCastCache[$key]['value'];
    }

    public function withCachedReportCasts(): static
    {
        // Only bounded report batches opt in. Large, unbatched exports must not
        // retain decoded copies of every source payload in memory.
        $this->cacheReportCasts = true;
        return $this;
    }

    protected function asJson($value, $flags = 0)
    {
        // Keep original Unicode report headers addressable by JSON path queries.
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE);
    }
}
