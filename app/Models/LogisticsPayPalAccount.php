<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsPayPalAccount extends Model
{
    public const ENVIRONMENT_LIVE = 'live';
    public const ENVIRONMENT_SANDBOX = 'sandbox';

    protected $table = 'logistics_paypal_accounts';

    protected $fillable = [
        'account_name', 'merchant_id', 'account_email', 'environment', 'is_enabled',
        'last_imported_at', 'last_import_from', 'last_import_to',
        'last_import_status', 'last_import_message', 'created_by', 'created_by_name',
        'updated_by', 'updated_by_name',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_imported_at' => 'datetime',
            'last_import_from' => 'datetime',
            'last_import_to' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(LogisticsOrder::class, 'paypal_account_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(LogisticsPayPalRefund::class, 'logistics_paypal_account_id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(LogisticsPayPalDispute::class, 'logistics_paypal_account_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(LogisticsPayPalImport::class, 'logistics_paypal_account_id');
    }
}
