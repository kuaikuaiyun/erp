<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppLovinAccountRiskNote extends Model
{
    protected $table = 'applovin_account_risk_notes';

    protected $fillable = ['account_number', 'risk_factors', 'updated_by'];

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
