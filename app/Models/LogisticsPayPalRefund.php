<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsPayPalRefund extends Model
{
    private const EVENT_LABELS = [
        'T1106' => '付款撤销',
        'T1107' => '付款退款',
        'T1108' => '费用撤销',
        'T1109' => '手续费退款',
        'T1110' => '纠纷调查余额冻结',
        'T1111' => '纠纷解决余额冻结取消',
        'T1115' => '批量付款退款',
        'T1120' => '关联授权交易退款',
        'T1201' => '拒付',
        'T1202' => '拒付撤销',
        'T1205' => '拒付补偿',
    ];

    private const STATUS_LABELS = [
        'D' => '已拒绝',
        'P' => '待处理',
        'S' => '成功完成',
        'V' => '完全撤销/退款',
    ];

    protected $table = 'logistics_paypal_refunds';

    protected $fillable = [
        'logistics_paypal_account_id', 'logistics_order_id', 'refund_transaction_id',
        'original_transaction_id', 'event_code', 'status', 'refund_amount_usd',
        'original_amount', 'currency_code', 'invoice_id', 'refund_reason',
        'refunded_at', 'raw_payload', 'synced_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'refund_amount_usd' => 'decimal:4',
            'original_amount' => 'decimal:4',
            'refunded_at' => 'datetime',
            'synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LogisticsPayPalAccount::class, 'logistics_paypal_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LogisticsOrder::class, 'logistics_order_id');
    }

    public static function eventLabelFor(mixed $eventCode): string
    {
        $eventCode = strtoupper(trim((string) ($eventCode ?? '')));
        if ($eventCode === '') {
            return '—';
        }

        $label = self::EVENT_LABELS[$eventCode] ?? '其他 PayPal 事件';

        return $label.'（'.$eventCode.'）';
    }

    public static function statusLabelFor(mixed $status): string
    {
        $status = strtoupper(trim((string) ($status ?? '')));
        if ($status === '') {
            return '—';
        }

        $label = self::STATUS_LABELS[$status] ?? '未知状态';

        return $label.'（'.$status.'）';
    }

    public function eventLabel(): string
    {
        return self::eventLabelFor($this->event_code);
    }

    public function statusLabel(): string
    {
        return self::statusLabelFor($this->status);
    }
}
