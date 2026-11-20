<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceRecord extends Model
{
    protected $guarded = ['id'];

    private array $reportCastCache = [];
    private bool $cacheReportCasts = false;
    private array $reportAttributes = [];
    private array $reportAttributeKeys = [];

    public function getAttribute($key)
    {
        // A bounded report batch repeatedly reads these immutable source columns.
        // Cache their normal Eloquent values, including native casts, rather than
        // re-running Eloquent's accessor/cast dispatch for every calculation.
        if (! isset($this->reportAttributeKeys[$key])
            || ! array_key_exists($key, $this->attributes)) {
            return parent::getAttribute($key);
        }
        $raw = $this->attributes[$key];
        if (! array_key_exists($key, $this->reportAttributes) || $this->reportAttributes[$key]['raw'] !== $raw) {
            $this->reportAttributes[$key] = ['raw' => $raw, 'value' => parent::getAttribute($key)];
        }

        return $this->reportAttributes[$key]['value'];
    }

    public function mergeCasts($casts)
    {
        $this->reportAttributes = [];
        $this->reportCastCache = [];
        $result = parent::mergeCasts($casts);
        if ($this->cacheReportCasts) $this->prepareReportAttributes();
        return $result;
    }

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
        $this->prepareReportAttributes();
        return $this;
    }

    private function prepareReportAttributes(): void
    {
        $casts = $this->getCasts();
        $this->reportAttributeKeys = [];
        foreach (['id', 'dataset', 'account', 'reference', 'currency', 'status', 'source_timezone', 'domain',
            'raw_type', 'payload', 'occurred_at', 'logistics_order_id'] as $key) {
            $expected = match ($key) { 'payload' => 'array', 'occurred_at' => 'immutable_datetime', 'id' => 'int', default => null };
            if (($casts[$key] ?? null) === $expected && ! $this->hasGetMutator($key) && ! $this->hasAttributeGetMutator($key)) {
                $this->reportAttributeKeys[$key] = true;
            }
        }
    }

    protected function asJson($value, $flags = 0)
    {
        // Keep original Unicode report headers addressable by JSON path queries.
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE);
    }
}
