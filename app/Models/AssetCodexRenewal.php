<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class AssetCodexRenewal extends Model
{
    protected $table = 'asset_codex_renewals';

    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function renewalDate(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null || $value === '' ? null : CarbonImmutable::parse($value)->format('Y-m-d'));
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'renewal_date' => 'date'];
    }
}
