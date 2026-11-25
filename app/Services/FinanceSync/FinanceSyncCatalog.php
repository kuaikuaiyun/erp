<?php

namespace App\Services\FinanceSync;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class FinanceSyncCatalog
{
    public const CONFIG = 'finance_sync';
    public const PENDING = 'finance_sync_pending';
    public const RECORDS = 'finance_sync_records';
    public const STATE = 'finance_sync_state';

    public const ORDERS = 'orders';
    public const REFERENCES = 'order-payment-references';
    public const PAYPAL_LEDGER = 'logistics_paypal_transactions';

    public const RESOURCES = [
        'paypal-accounts' => [
            'logistics_paypal_accounts',
            ['id', 'account_name', 'merchant_id', 'environment', 'is_enabled'],
        ],
        self::ORDERS => [
            'logistics_orders',
            ['id', 'assistant_domain_store_id', 'source_shop_domain', 'shopify_order_id', 'shopify_order_number',
                'tongtu_order_number', 'transaction_id', 'bill_number', 'payment_gateway_order_id', 'paypal_account_id',
                'collection_account', 'store_name', 'payment_gateway', 'payment_method', 'payment_status',
                'order_created_at', 'paid_at', 'paid_amount_usd', 'total_shipping_usd', 'subscription_type',
                'subscription_status', 'subscription_origin_order_id', 'is_add_on', 'assigned_owner_user_id',
                'assigned_owner_name', 'owner_assignment_resolved_at', 'group_names', 'operator_name'],
        ],
        self::REFERENCES => [
            'logistics_order_payment_references',
            ['id', 'logistics_order_id', 'reference_number', 'reference_type', 'gateway_order_id'],
        ],
        'order-items' => [
            'logistics_order_items',
            ['id', 'logistics_order_id', 'product_title', 'shopify_sku', 'tongtu_sku_mapping_id', 'tongtu_sku',
                'tongtu_product_code', 'tongtu_product_name', 'tongtu_product_type', 'quantity', 'unit_price_usd',
                'line_revenue_usd', 'sale_weight_gram', 'tongtu_goods_weight_gram', 'tongtu_package_weight_gram'],
        ],
        'stores' => [
            'assistant_domain_stores',
            ['id', 'domain', 'shop_name', 'owner_user_id', 'user_name', 'owner_operation_department_id',
                'team', 'project', 'site_builder', 'shop_status'],
        ],
        'store-owner-histories' => [
            'assistant_domain_store_owner_histories',
            ['id', 'assistant_domain_store_id', 'owner_user_id', 'owner_name_snapshot', 'operation_department_id',
                'operation_department_name_snapshot', 'event', 'effective_from', 'effective_to'],
        ],
        'tongtu-sku-mappings' => [
            'logistics_tongtu_sku_mappings',
            ['id', 'shopify_sku', 'tongtu_sku', 'tongtu_product_code', 'tongtu_product_name', 'tongtu_product_type', 'status'],
        ],
        'pcl-skus' => [
            'product_request_link_skus',
            ['id', 'pcl_product_no', 'is_runnable'],
        ],
        'paypal-refunds' => [
            'logistics_paypal_refunds',
            ['id', 'logistics_paypal_account_id', 'logistics_order_id', 'refund_transaction_id', 'original_transaction_id',
                'event_code', 'status', 'refund_amount_usd', 'original_amount', 'currency_code', 'refund_reason',
                'manual_reason', 'refunded_at'],
        ],
        'credit-card-refunds' => [
            'logistics_credit_card_refunds',
            ['id', 'logistics_order_id', 'bill_number', 'refund_reference', 'refund_amount_usd', 'refund_reason', 'occurred_at'],
        ],
        'paypal-disputes' => [
            'logistics_paypal_disputes',
            ['id', 'logistics_paypal_account_id', 'logistics_order_id', 'dispute_id', 'disputed_transaction_id', 'reason',
                'status', 'dispute_state', 'life_cycle_stage', 'channel', 'dispute_amount_usd', 'original_amount',
                'currency_code', 'dispute_created_at', 'dispute_updated_at', 'outcome_code'],
        ],
    ];

    public const OPTIONS = [
        'paypal-accounts' => ['include_account_email', ['account_email']],
        'tongtu-sku-mappings' => ['include_sku_cost', [
            'goods_current_cost_cny', 'goods_average_cost_cny', 'package_cost_cny', 'cost_currency',
        ]],
    ];

    public const LOGISTICS = [
        'shipping_company', 'tracking_number', 'logistics_status', 'tracking_latest_event', 'tracking_latest_checkpoint_at',
    ];

    public const LOGS = ['id', 'logistics_order_id', 'source', 'action', 'status', 'created_at'];

    public const AMOUNT = ['payment_original_currency' => null, 'payment_original_amount' => null];

    private const BOOLEAN = ['is_enabled', 'is_add_on', 'is_runnable'];
    private const DATES = [
        'order_created_at', 'paid_at', 'owner_assignment_resolved_at', 'effective_from', 'effective_to',
        'refunded_at', 'occurred_at', 'dispute_created_at', 'dispute_updated_at', 'tracking_latest_checkpoint_at', 'created_at',
    ];
    private const CODES = [
        'environment', 'payment_status', 'subscription_type', 'subscription_status', 'reference_type',
        'shop_status', 'event', 'status', 'event_code', 'dispute_state', 'life_cycle_stage', 'channel',
        'outcome_code', 'logistics_status', 'source', 'action',
    ];
    private const LABELS = [
        'payment_status' => [
            '已付款' => 'paid', '待付款' => 'pending', '部分退款' => 'partially_refunded',
            '已退款' => 'refunded', '部分付款' => 'partially_paid', '已授权' => 'authorized', '已取消' => 'voided',
        ],
        'subscription_type' => ['无订阅' => 'none', '订阅首次购买' => 'first_purchase', '订阅' => 'subscription'],
        'subscription_status' => ['未订阅' => 'not_subscribed', '已订阅' => 'subscribed', '已取消' => 'cancelled'],
        'shop_status' => [
            'enabled' => 'in_use', 'closed' => 'abandoned', 'idle' => 'abandoned', 'banned' => 'stopped',
        ],
        'logistics_status' => [
            '待发货' => 'pending_shipment', '已发货' => 'shipped', '已签收' => 'delivered',
            '物流异常' => 'exception', '待查询' => 'pending_lookup', '物流商待匹配' => 'courier_unmatched',
            '物流商暂不支持' => 'courier_unsupported', '信息已预录' => 'information_received',
            '运输中' => 'in_transit', '暂无物流信息' => 'no_information', '查询过期' => 'lookup_expired',
        ],
        'status' => [
            '成功' => 'success', '失败' => 'failed', '处理中' => 'processing', '已跳过' => 'skipped',
        ],
    ];

    public static function connection(): Connection
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    public static function table(string $resource): string
    {
        return self::RESOURCES[$resource][0];
    }

    public static function columns(string $resource, bool $allOptions = false): array
    {
        [$option, $extra] = self::OPTIONS[$resource] ?? [null, []];
        $columns = self::RESOURCES[$resource][1];
        if ($option && ($allOptions || config(self::CONFIG.'.'.$option))) {
            $columns = [...$columns, ...$extra];
        }

        return $resource === self::ORDERS ? [...$columns, 'shopify_payload'] : $columns;
    }

    public static function publicationProfile(): string
    {
        return hash('sha256', json_encode([
            config(self::CONFIG.'.include_account_email'),
            config(self::CONFIG.'.include_sku_cost'),
            config(self::CONFIG.'.source_timezone'),
        ], JSON_THROW_ON_ERROR));
    }

    public function format(array $columns, object $row, string $resource): array
    {
        $record = [];
        foreach ($columns as $column) {
            if ($column === 'shopify_payload') {
                continue;
            }

            $value = $row->{$column} ?? null;
            $record[$column] = $this->value($resource, $column, $value);
        }

        return $record;
    }

    public function disabled(string $resource, array $record): bool
    {
        return match ($resource) {
            'paypal-accounts' => $record['is_enabled'] === false,
            'stores' => in_array($record['shop_status'], ['abandoned', 'stopped'], true),
            'pcl-skus' => $record['is_runnable'] === false,
            default => false,
        };
    }

    public function date(?string $value, ?string $timezone = null): ?string
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value) || str_starts_with($value, '0000-')) {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone ?? config('finance_sync.source_timezone'))->utc()->toIso8601String();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function decimal(mixed $value): ?string
    {
        if (! is_scalar($value) || ! preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $value)) {
            return null;
        }

        $value = (string) $value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    public function currency(mixed $value): ?string
    {
        $code = is_scalar($value) ? strtoupper(trim((string) $value)) : '';

        return preg_match('/^[A-Z]{3}$/D', $code) ? $code : null;
    }

    private function value(string $resource, string $column, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($column, self::BOOLEAN, true)) {
            return is_bool($value) ? $value : match (strtolower(trim((string) $value))) {
                '1', 'true', 'yes', '已加购' => true,
                '0', 'false', 'no', '未加购' => false,
                default => null,
            };
        }
        if (in_array($column, self::DATES, true)) {
            return $this->date((string) $value);
        }
        if (str_ends_with($column, '_usd') || str_ends_with($column, '_cny') || str_ends_with($column, '_gram')
            || in_array($column, ['original_amount', 'quantity'], true)) {
            return $this->decimal($value);
        }
        if (in_array($column, ['currency_code', 'cost_currency'], true)) {
            return $this->currency($value);
        }
        if (in_array($column, self::CODES, true) || ($resource === 'paypal-disputes' && $column === 'reason')) {
            return $this->code($resource, $column, (string) $value);
        }

        if (in_array($column, ['refund_reason', 'manual_reason', 'tracking_latest_event'], true)) {
            $value = $this->safeText((string) $value);
        } elseif (in_array($column, ['account_name', 'collection_account'], true)) {
            $value = $this->redactEmail((string) $value);
        }

        return trim((string) $value) === '' ? null : (string) $value;
    }

    private function code(string $resource, string $column, string $value): string
    {
        $value = trim($value);
        if ($column === 'subscription_type' && preg_match('/^订阅复购([1-9][0-9]*)次$/D', $value, $matches)) {
            return 'recurring_'.$matches[1];
        }

        $code = self::LABELS[$column][$value] ?? strtolower(str_replace([' ', '-'], '_', $value));

        if ($column === 'status' && $resource === 'paypal-refunds') {
            $code = match ($code) {
                's', 'success' => 'completed',
                'p' => 'pending',
                'd' => 'denied',
                'v' => 'reversed',
                default => $code,
            };
        }

        return preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/D', $code) && strlen($code) <= 100
            ? $code : 'unknown';
    }

    private function safeText(string $text): string
    {
        $text = $this->redactEmail(strip_tags($text));
        $text = preg_replace('~https?://\S+~iu', '***', $text) ?? '';

        return preg_replace('/(?<!\d)\+?\d(?:[\s().-]*\d){6,}(?!\d)/u', '***', $text) ?? '';
    }

    private function redactEmail(string $text): string
    {
        return preg_replace('/[\w.+%\-]+@[\w.\-]+\.[\pL]{2,}/u', '***', $text) ?? '';
    }
}
