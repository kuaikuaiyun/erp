<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsOrder extends Model
{
    public const STATUS_PENDING_AUTHORIZATION = '待授权';
    public const STATUS_PENDING_TONGTU = '已同步待匹配';
    public const STATUS_NORMAL = '正常数据';
    public const STATUS_SKU_EXCEPTION = 'SKU异常';
    public const STATUS_TONGTU_NOT_FOUND = '通途未找到';
    public const STATUS_TONGTU_FAILED = '通途匹配失败';
    public const STATUS_SHOPIFY_FAILED = 'Shopify同步失败';
    public const STATUS_STORE_UNMATCHED = '店铺匹配失败';
    public const STATUS_STORE_CONFIG_EXCEPTION = '店铺配置异常';

    public const TRACKING_PENDING = '待匹配';
    public const TRACKING_SYNCED = '已同步';
    public const TRACKING_EMPTY = '通途无跟踪单号';
    public const TRACKING_NOT_FOUND = '通途未找到订单';
    public const TRACKING_FAILED = '通途查询失败';

    public const LOGISTICS_PENDING = '待发货';
    public const LOGISTICS_SHIPPED = '已发货';
    public const LOGISTICS_DELIVERED = '已签收';
    public const LOGISTICS_EXCEPTION = '物流异常';
    public const LOGISTICS_WAITING_QUERY = '待查询';
    public const LOGISTICS_COURIER_UNMATCHED = '物流商待匹配';
    public const LOGISTICS_COURIER_UNSUPPORTED = '物流商暂不支持';
    public const LOGISTICS_INFORMATION_RECEIVED = '信息已预录';
    public const LOGISTICS_IN_TRANSIT = '运输中';
    public const LOGISTICS_NO_INFORMATION = '暂无物流信息';
    public const LOGISTICS_EXPIRED = '查询过期';

    protected $fillable = [
        'assistant_domain_store_id', 'source_shop_domain', 'shopify_order_id', 'shopify_order_number', 'tongtu_order_number',
        'store_name', 'department_name', 'operator_name', 'group_names', 'staff_status',
        'buyer_name', 'buyer_email', 'buyer_phone', 'is_add_on', 'subscription_type',
        'subscription_origin_order_id', 'subscription_status', 'tongtu_sku', 'tongtu_product_name',
        'sale_quantity', 'sale_product_weight', 'payment_status', 'payment_method', 'payment_gateway', 'paid_amount_usd',
        'paid_at', 'product_cost_usd', 'handling_fee_usd', 'total_shipping_usd',
        'proportional_refund_usd', 'proportional_profit_usd', 'proportional_profit_rate',
        'financial_profit_usd', 'financial_profit_rate', 'operation_profit_usd', 'operation_profit_rate',
        'channel_refund_amount_usd', 'shopify_refund_amount_usd', 'refunded_at', 'refund_reason',
        'received_amount_usd', 'platform_fee_usd', 'net_amount_usd', 'collection_account',
        'collection_account_email', 'transaction_id', 'bill_number', 'destination', 'shipping_company',
        'tracking_number', 'tracking_sync_status', 'order_created_at', 'data_status',
        'sku_exception_reason', 'time_zone', 'shopify_checkout_id', 'shopify_cart_token',
        'payment_gateway_order_id', 'paypal_merchant_id', 'page_source', 'shopify_payload', 'tongtu_payload',
        'shopify_customer_id', 'shopify_customer_order_count', 'subscription_link_source', 'subscription_link_status',
        'logistics_status', 'fulfillment_payload', 'first_fulfilled_at', 'paid_match_due_at',
        'paid_match_scheduled_at', 'auto_match_paid_completed_at', 'auto_match_fulfillment_scheduled_at',
        'auto_match_fulfillment_completed_at',
        'tracking_courier_code', 'tracking_courier_name', 'tracking_api_status', 'tracking_api_substatus',
        'tracking_latest_event', 'tracking_latest_checkpoint_at', 'tracking_signed_by', 'tracking_registered_at',
        'tracking_last_checked_at', 'tracking_next_sync_at', 'tracking_attempts', 'tracking_last_error',
        'tracking_raw_payload', 'tracking_status_source',
        'sku_fingerprint', 'sku_exception_fingerprint',
        'paypal_account_id', 'paypal_association_source', 'paypal_associated_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        $decimalFields = [
            'paid_amount_usd', 'product_cost_usd', 'handling_fee_usd', 'total_shipping_usd',
            'proportional_refund_usd', 'proportional_profit_usd', 'proportional_profit_rate',
            'financial_profit_usd', 'financial_profit_rate', 'operation_profit_usd', 'operation_profit_rate',
            'channel_refund_amount_usd', 'shopify_refund_amount_usd', 'received_amount_usd',
            'platform_fee_usd', 'net_amount_usd',
        ];

        return array_merge(
            array_fill_keys($decimalFields, 'decimal:4'),
            [
                'paid_at' => 'datetime',
                'refunded_at' => 'datetime',
                'order_created_at' => 'datetime',
                'first_fulfilled_at' => 'datetime',
                'paid_match_due_at' => 'datetime',
                'paid_match_scheduled_at' => 'datetime',
                'auto_match_paid_completed_at' => 'datetime',
                'auto_match_fulfillment_scheduled_at' => 'datetime',
                'auto_match_fulfillment_completed_at' => 'datetime',
                'paypal_associated_at' => 'datetime',
                'tracking_latest_checkpoint_at' => 'datetime',
                'tracking_registered_at' => 'datetime',
                'tracking_last_checked_at' => 'datetime',
                'tracking_next_sync_at' => 'datetime',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
            ],
        );
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'assistant_domain_store_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LogisticsSyncLog::class, 'logistics_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LogisticsOrderItem::class, 'logistics_order_id');
    }

    public function refundDetails(): HasMany
    {
        return $this->hasMany(LogisticsRefundDetail::class, 'logistics_order_id');
    }

    public function activeRefundDetails(): HasMany
    {
        return $this->refundDetails()->where('is_void', false);
    }

    public function paypalAccount(): BelongsTo
    {
        return $this->belongsTo(LogisticsPayPalAccount::class, 'paypal_account_id');
    }

    public function paypalRefunds(): HasMany
    {
        return $this->hasMany(LogisticsPayPalRefund::class, 'logistics_order_id');
    }

    public function paypalTransactions(): HasMany
    {
        return $this->hasMany(LogisticsPayPalTransaction::class, 'logistics_order_id');
    }

    public function paypalDisputes(): HasMany
    {
        return $this->hasMany(LogisticsPayPalDispute::class, 'logistics_order_id');
    }

    public function creditCardRefunds(): HasMany
    {
        return $this->hasMany(LogisticsCreditCardRefund::class, 'logistics_order_id');
    }

    public function paymentReferences(): HasMany
    {
        return $this->hasMany(LogisticsOrderPaymentReference::class, 'logistics_order_id');
    }

    /** @return array<string, string> */
    public static function dataStatusOptions(): array
    {
        return [
            self::STATUS_PENDING_AUTHORIZATION => self::STATUS_PENDING_AUTHORIZATION,
            self::STATUS_PENDING_TONGTU => self::STATUS_PENDING_TONGTU,
            self::STATUS_NORMAL => self::STATUS_NORMAL,
            self::STATUS_SKU_EXCEPTION => self::STATUS_SKU_EXCEPTION,
            self::STATUS_TONGTU_NOT_FOUND => self::STATUS_TONGTU_NOT_FOUND,
            self::STATUS_TONGTU_FAILED => self::STATUS_TONGTU_FAILED,
            self::STATUS_SHOPIFY_FAILED => self::STATUS_SHOPIFY_FAILED,
            self::STATUS_STORE_UNMATCHED => self::STATUS_STORE_UNMATCHED,
            self::STATUS_STORE_CONFIG_EXCEPTION => self::STATUS_STORE_CONFIG_EXCEPTION,
        ];
    }

    /** @return array<string, string> */
    public static function trackingStatusOptions(): array
    {
        return [
            self::TRACKING_PENDING => self::TRACKING_PENDING,
            self::TRACKING_SYNCED => self::TRACKING_SYNCED,
            self::TRACKING_EMPTY => self::TRACKING_EMPTY,
            self::TRACKING_NOT_FOUND => self::TRACKING_NOT_FOUND,
            self::TRACKING_FAILED => self::TRACKING_FAILED,
        ];
    }

    /** @return array<string, string> */
    public static function logisticsStatusOptions(): array
    {
        return [
            self::LOGISTICS_PENDING => self::LOGISTICS_PENDING,
            self::LOGISTICS_SHIPPED => self::LOGISTICS_SHIPPED,
            self::LOGISTICS_DELIVERED => self::LOGISTICS_DELIVERED,
            self::LOGISTICS_EXCEPTION => self::LOGISTICS_EXCEPTION,
            self::LOGISTICS_WAITING_QUERY => self::LOGISTICS_WAITING_QUERY,
            self::LOGISTICS_COURIER_UNMATCHED => self::LOGISTICS_COURIER_UNMATCHED,
            self::LOGISTICS_COURIER_UNSUPPORTED => self::LOGISTICS_COURIER_UNSUPPORTED,
            self::LOGISTICS_INFORMATION_RECEIVED => self::LOGISTICS_INFORMATION_RECEIVED,
            self::LOGISTICS_IN_TRANSIT => self::LOGISTICS_IN_TRANSIT,
            self::LOGISTICS_NO_INFORMATION => self::LOGISTICS_NO_INFORMATION,
            self::LOGISTICS_EXPIRED => self::LOGISTICS_EXPIRED,
        ];
    }

    public function logisticsStatusClass(): string
    {
        return match ($this->logistics_status) {
            self::LOGISTICS_SHIPPED, self::LOGISTICS_IN_TRANSIT => 'label-primary',
            self::LOGISTICS_DELIVERED => 'label-success',
            self::LOGISTICS_EXCEPTION => 'label-danger',
            self::LOGISTICS_COURIER_UNSUPPORTED, self::LOGISTICS_COURIER_UNMATCHED, self::LOGISTICS_EXPIRED => 'label-warning',
            default => 'label-default',
        };
    }

    public function statusClass(): string
    {
        return match ($this->data_status) {
            self::STATUS_NORMAL => 'label-success',
            self::STATUS_SKU_EXCEPTION, self::STATUS_TONGTU_FAILED, self::STATUS_SHOPIFY_FAILED,
            self::STATUS_STORE_UNMATCHED, self::STATUS_STORE_CONFIG_EXCEPTION => 'label-danger',
            self::STATUS_TONGTU_NOT_FOUND => 'label-warning',
            default => 'label-default',
        };
    }

    public function trackingStatusClass(): string
    {
        return match ($this->tracking_sync_status) {
            self::TRACKING_SYNCED => 'label-success',
            self::TRACKING_EMPTY, self::TRACKING_NOT_FOUND => 'label-warning',
            self::TRACKING_FAILED => 'label-danger',
            default => 'label-default',
        };
    }

    public function hasFailureRow(): bool
    {
        $dataStatus = trim((string) $this->data_status);
        $logisticsStatus = trim((string) $this->logistics_status);
        $trackingStatus = trim((string) $this->tracking_sync_status);

        return in_array($dataStatus, [
            self::STATUS_SKU_EXCEPTION,
            self::STATUS_TONGTU_NOT_FOUND,
            self::STATUS_TONGTU_FAILED,
            self::STATUS_SHOPIFY_FAILED,
            self::STATUS_STORE_UNMATCHED,
            self::STATUS_STORE_CONFIG_EXCEPTION,
        ], true)
            || $logisticsStatus === self::LOGISTICS_EXCEPTION
            || $trackingStatus === self::TRACKING_FAILED;
    }
}
