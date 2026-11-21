<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceRecord extends Model
{
    protected $guarded = ['id'];
    private ?array $sourceFields = null;
    private mixed $sourceFieldsRaw = null;

    public function getPayloadAttribute($raw)
    {
        if ($this->hasCast('payload') && $this->getCastType('payload') !== 'array') {
            if ($raw !== null) return $this->castAttribute('payload', $raw);
            if ($this->sourceFields !== null) return $this->castAttribute('payload', $this->asJson($this->sourceFields));
        }
        if ($this->sourceFields !== null && $this->sourceFieldsRaw === $raw) return $this->sourceFields;
        if ($raw !== null) {
            $this->sourceFieldsRaw = $raw;
            return $this->sourceFields = $this->fromJson($raw);
        }
        if (! $this->exists) return null;
        $storage = app(\App\Portals\Finance\FinanceSourceStorage::class);
        if (! $storage->active('record')) return null;
        $fields = $storage->load('record', [abs($this->id)]);
        return $this->sourceFields = $fields[abs($this->id)] ?? throw new \RuntimeException('交易来源明细缺失。');
    }

    public function setPayloadAttribute($value): void
    {
        $this->attributes['payload'] = $this->asJson($value);
        $this->sourceFields = null;
    }

    public function originalIsEquivalent($key)
    {
        if ($key === 'payload' && ! array_key_exists('payload', $this->original) && isset($this->original['source_hash'])) {
            return hash_equals($this->original['source_hash'], \App\Portals\Finance\FinanceSourceStorage::hash($this->payload ?? []));
        }
        return parent::originalIsEquivalent($key);
    }

    public function withSourceFields(array $fields): static
    {
        $this->sourceFieldsRaw = $this->attributes['payload'] ?? null;
        $this->sourceFields = $fields;
        return $this;
    }

    /** Report IDs may be negative aliases of persisted finance record IDs. */
    public static function preloadSourceFields(\Illuminate\Support\Collection $records): void
    {
        $missing = $records->filter(fn ($record) => $record instanceof self && $record->exists
            && ($record->attributes['payload'] ?? null) === null && $record->sourceFields === null);
        if ($missing->isEmpty()) return;
        $storage = app(\App\Portals\Finance\FinanceSourceStorage::class);
        if (! $storage->active('record')) return;
        foreach ($missing->chunk(500) as $batch) {
            $fields = $storage->load('record', $batch->map(fn ($record) => abs($record->id))->unique()->values()->all());
            foreach ($batch as $record) $record->withSourceFields($fields[abs($record->id)]
                ?? throw new \RuntimeException('交易来源明细缺失。'));
        }
    }

    protected static function booted(): void
    {
        static::saved(fn (self $record) => app(\App\Portals\Finance\FinanceTransactions::class)->sync(collect([$record])));
        static::deleted(function (self $record): void {
            $storage = app(\App\Portals\Finance\FinanceSourceStorage::class);
            if ($storage->installed()) $storage->delete('record', [$record->id]);
            $transactions = app(\App\Portals\Finance\FinanceTransactions::class);
            if (! $transactions->installed()) return;
            foreach (['finance_transactions' => 'id', 'finance_card_transaction_details' => 'record_id'] as $table => $key) {
                $record->getConnection()->table($table)->where($key, $record->id)->delete();
            }
        });
    }

    public function save(array $options = [])
    {
        $storage = app(\App\Portals\Finance\FinanceSourceStorage::class);
        if (! $storage->installed() && ! app(\App\Portals\Finance\FinanceTransactions::class)->installed()) return parent::save($options);
        return $this->getConnection()->transaction(function () use ($storage, $options) {
            if (! $storage->installed()) return parent::save($options);
            $fields = $this->payload ?? [];
            $attributes = $storage->prepare('record', $this->attributes, $fields);
            $sourceChanged = ! $this->exists || $this->getRawOriginal('source_hash') !== $attributes['source_hash'];
            $this->setRawAttributes($attributes);
            $this->withSourceFields($fields);
            $result = parent::save($options);
            if ($result && $sourceChanged) $storage->write('record', [$this->id => ['document' => $fields, 'dataset' => $this->dataset]]);
            return $result;
        }, 3);
    }

    public function delete()
    {
        if (! app(\App\Portals\Finance\FinanceSourceStorage::class)->installed() && ! app(\App\Portals\Finance\FinanceTransactions::class)->installed()) return parent::delete();
        return $this->getConnection()->transaction(fn () => parent::delete(), 3);
    }

    public function newCollection(array $models = [])
    {
        // Header pagination selects IDs first; load source details only for those IDs.
        if ($models !== [] && array_key_exists('channel_flags', $models[0]->getAttributes())
            && ! array_key_exists('payload', $models[0]->getAttributes()) && ! array_key_exists('source_hash', $models[0]->getAttributes())) {
            $details = self::query()->whereKey(array_map(fn ($model) => abs($model->id), $models))->get()->keyBy('id');
            $models = array_values(array_filter(array_map(function ($model) use ($details) {
                $detail = $details->get(abs($model->id));
                if ($detail && $model->id < 0) {
                    $detail = clone $detail;
                    $detail->setAttribute('id', $model->id);
                    $detail->setAttribute('payment_transactions', null);
                    $detail->setAttribute('match_status', $model->match_status);
                    $detail->syncOriginal();
                }
                if ($detail) foreach ($model->getAttributes() as $field => $value) {
                    if (str_starts_with($field, 'matches_')) $detail->setAttribute($field, $value);
                }
                return $detail;
            }, $models)));
        }
        if ($models !== [] && app(\App\Portals\Finance\FinanceSourceStorage::class)->active('record')) {
            $physical = array_filter($models, fn ($model) => $model->id > 0 && array_key_exists('source_hash', $model->getAttributes())
                && ! array_key_exists('payload', $model->getAttributes()));
            if ($physical !== []) {
                $connection = $physical[array_key_first($physical)]->getConnection();
                $connection->transaction(function () use ($physical, $connection): void {
                    $ids = array_map(fn ($model) => $model->id, $physical);
                    $headers = $connection->table('finance_records')->whereIntegerInRaw('id', $ids)->sharedLock()->get()->keyBy('id');
                    $fields = app(\App\Portals\Finance\FinanceSourceStorage::class)->load('record', $ids);
                    foreach ($physical as $model) {
                        if (! $headers->has($model->id) || ! isset($fields[$model->id])) throw new \RuntimeException('交易正在更新，请重新查询。');
                        $model->setRawAttributes(array_replace($model->getAttributes(), (array) $headers->get($model->id)), true);
                        $model->withSourceFields($fields[$model->id]);
                    }
                }, 3);
            }
        }
        return parent::newCollection($models);
    }

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
