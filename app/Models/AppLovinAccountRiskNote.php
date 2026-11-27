<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppLovinAccountRiskNote extends Model
{
    protected $table = 'applovin_account_risk_notes';

    protected $fillable = ['account_number', 'risk_factors', 'closed_at', 'gift_granted_at', 'account_status_note', 'updated_by'];

    protected $casts = [
        'closed_at' => 'datetime',
        'gift_granted_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    public static function normalizeAccountNumber(string $accountNumber): string
    {
        return mb_strtolower(trim($accountNumber));
    }
}
