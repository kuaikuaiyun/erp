<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppLovinSpendScreenshot extends Model
{
    protected $table = 'applovin_spend_screenshots';
    protected $fillable = ['account_key', 'operator_user_id', 'operation_department_id', 'agency', 'media', 'account_name', 'account_number', 'screenshot_path', 'uploaded_by'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    public static function accountKey(int $operatorId, string $agency, string $media, string $accountName, string $accountNumber): string
    {
        return hash('sha256', implode('|', [$operatorId, $agency, $media, $accountName, $accountNumber]));
    }
}
