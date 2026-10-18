<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsOrderPaymentReference extends Model
{
    public const TYPE_PRIMARY = 'primary';
    public const TYPE_UPSELL = 'upsell';

    protected $table = 'logistics_order_payment_references';

    protected $fillable = [
        'logistics_order_id',
        'reference_number',
        'reference_type',
        'gateway_order_id',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LogisticsOrder::class, 'logistics_order_id');
    }
}
