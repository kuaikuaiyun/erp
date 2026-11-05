<?php

namespace App\Portals\Finance;

class FinanceCatalog
{
    public const PORTAL_CODE = 'caiwu';

    public static function timezoneLabel(string $timezone): string
    {
        return match ($timezone) {
            'UTC' => 'UTC',
            'Asia/Shanghai' => '北京时间',
            'America/Los_Angeles' => '美国太平洋时间',
            '-08:00' => 'UTC-08:00',
            '-07:00' => 'UTC-07:00',
            '+08:00' => 'UTC+08:00',
            default => $timezone,
        };
    }

    public static function reportAmount(mixed $value): string
    {
        if ($value === null) {
            return '待配置';
        }
        $text = (string) $value;
        if (! preg_match('/^(-?\d+)(?:\.(\d+))?$/', $text, $parts)) {
            return $text;
        }
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[1]);
        $fraction = str_pad(rtrim($parts[2] ?? '', '0'), 2, '0');

        return $whole.'.'.$fraction;
    }

    /** Convert visible text without changing stored field keys or filter values. */
    public static function simplified(?string $text): string
    {
        static $characters;
        $characters ??= json_decode(file_get_contents(database_path('data/finance-simplified-characters.json')), true, 512, JSON_THROW_ON_ERROR);

        return strtr($text ?? '', $characters);
    }

    public static function teamLabel(mixed $value): string
    {
        for ($depth = 0; $depth < 3 && is_string($value); $depth++) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || $decoded === $value) {
                break;
            }
            $value = $decoded;
        }
        if (is_array($value)) {
            return implode('、', array_map(self::teamLabel(...), $value));
        }

        return self::simplified((string) ($value ?? ''));
    }

    public static function source(): array
    {
        static $source;

        return $source ??= json_decode(file_get_contents(database_path('data/finance-workbook.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function datasets(): array
    {
        $headers = array_values(self::source()['headers']);

        return [
            'paypal-orders' => ['channel' => 'paypal', 'headers' => [...array_slice($headers[0], 0, 15), ...FinancePayPalDetails::HEADERS], 'reference' => 11, 'date' => 0, 'time' => 1, 'currency' => 6, 'status' => 5, 'type' => 4],
            'xuzong2-orders' => ['channel' => 'xuzong2', 'headers' => ['店铺名称', '收款账户', 'Shopify订单号', '交易单号', '店铺币种', '店铺订单金额', '交易币种', '交易金额', '支付状态', '支付方式', '域名', '通途订单', '订阅类型', '是否加购', '创建时间', '支付时间', '使用人', '团队'], 'reference' => 3, 'date' => 14, 'currency' => 6, 'status' => 8, 'domain' => 10, 'owner' => 16, 'team' => 17],
            'paypal-details' => ['channel' => 'paypal', 'headers' => $headers[0], 'reference' => 11, 'date' => 0, 'time' => 1, 'currency' => 6, 'status' => 5, 'type' => 4, 'domain' => 36, 'owner' => 37, 'team' => 38],
            'xuzong2-details' => ['channel' => 'xuzong2', 'headers' => $headers[1], 'reference' => 3, 'date' => 14, 'currency' => 6, 'status' => 8, 'domain' => 38, 'owner' => 39, 'team' => 40],
            'xuzong1-details' => ['channel' => 'xuzong1', 'headers' => $headers[2], 'reference' => 1, 'date' => 4, 'currency' => 6, 'status' => 8, 'domain' => 32, 'owner' => 33, 'team' => 34],
        ];
    }

    public static function dataset(string $key): array
    {
        abort_unless(isset(self::datasets()[$key]), 404);

        return self::datasets()[$key];
    }

    public static function supportsImport(string $key): bool
    {
        return in_array($key, ['paypal-details', 'xuzong2-details', 'xuzong1-details'], true);
    }

    public const PAYPAL_ORDER_FILTERS = [
        'id' => '编号',
        'shopify_order_number' => 'Shopify订单号', 'shopify_order_id' => '订单ID', 'tongtu_order_number' => '通途订单号',
        'store_name' => '店铺名称', 'domain' => '店铺域名', 'source_shop_domain' => '来源Shopify域名',
        'assigned_owner_name' => '归属人', 'group_names' => '所属组', 'staff_status' => '人员状态',
        'store_assignment_status' => '店铺归属状态', 'is_add_on' => '是否加购', 'subscription_type' => '订阅类型',
        'shopify_sku' => '原始SKU', 'shopify_sku_status' => '原始SKU状态', 'pcl_runnable' => 'PCL SKU 可跑状态',
        'column_19' => '物品 ID', 'column_18' => '商品名称',
        'status' => '支付状态', 'payment_method' => '支付方式', 'column_15' => '收款账号邮箱', 'currency' => '币种',
        'ordered_from' => '下单时间起', 'ordered_to' => '下单时间止', 'paid_from' => '付款时间起', 'paid_to' => '付款时间止',
        'refund_reason' => '实际退款原因',
        'refund_number' => '实际退款编号', 'refunded_from' => '实际退款时间起', 'refunded_to' => '实际退款时间止',
        'column_13' => '账单号',
        'column_3' => '买家名称', 'sender_email' => '付款方邮箱', 'column_37' => '电话',
        'logistics_status' => '物流状态', 'data_status' => '数据状态',
    ];

    public const PAYPAL_LOGISTICS_FILTERS = ['shopify_order_number', 'tongtu_order_number', 'shopify_order_id', 'store_name', 'subscription_type',
        'id', 'shopify_sku', 'shopify_sku_status', 'pcl_runnable', 'assigned_owner_name', 'group_names', 'staff_status', 'source_shop_domain',
        'store_assignment_status', 'is_add_on', 'payment_method', 'logistics_status', 'refund_reason', 'data_status', 'refund_number',
        'refunded_from', 'refunded_to', 'ordered_from', 'ordered_to', 'paid_from', 'paid_to'];

    public static function filterFields(string $key): array
    {
        $definition = self::dataset($key);
        $fields = ['account', 'reference', 'currency', 'status'];
        if (isset($definition['domain'])) {
            $fields[] = 'domain';
        }
        if ($definition['channel'] === 'paypal') {
            $fields = ['category', 'direction', 'raw_type', ...$fields, 'sender_email', 'invoice_number', 'balance_impact'];
        }

        return [...$fields, ...array_map(fn ($index) => 'column_'.$index, array_keys($definition['headers']))];
    }

    public static function filterFieldLabels(string $key): array
    {
        $headers = self::dataset($key)['headers'];
        $labels = [];
        foreach (self::filterFields($key) as $field) {
            $labels[$field] = str_starts_with($field, 'column_')
                ? self::simplified($headers[(int) substr($field, 7)])
                : __('finance.fields.'.$field);
        }

        return $labels;
    }

    public static function numericFilterFields(string $key): array
    {
        static $cache = [];
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $fields = [];
        foreach (self::dataset($key)['headers'] as $index => $header) {
            if (preg_match('/金额|总额|净额|费用|手续费|保证金|成本|汇率|^余额$|^销售税$|^数量$/u', self::simplified($header))) {
                $fields[] = 'column_'.$index;
            }
        }

        return $cache[$key] = $fields;
    }

    public static function displayValue(string $dataset, int $index, mixed $value): string
    {
        $text = (string) ($value ?? '');
        $definition = self::dataset($dataset);
        if (self::simplified($definition['headers'][$index]) === '团队') {
            return self::teamLabel($value);
        }
        if (in_array(self::simplified($definition['headers'][$index]), ['物品标题', '主旨'], true)) {
            $clean = trim(preg_replace('/\x{FFFD}+/u', '', $text));

            return self::simplified($clean === '' && $text !== '' ? '内容编码异常' : $clean);
        }
        if ($definition['channel'] === 'paypal' && $index === $definition['status']) {
            return match ($text) {
                'S' => '已完成', 'P' => '待处理', 'D' => '已拒绝', 'V' => '已撤销',
                default => self::simplified($text),
            };
        }
        if (self::simplified(self::dataset($dataset)['headers'][$index]) === '数量' && trim($text) !== '') {
            $quantities = preg_split('/\R/', trim($text));
            if (count(array_filter($quantities, static fn ($quantity) => ! preg_match('/^\d+$/', $quantity))) === 0) {
                return array_reduce($quantities, static fn ($total, $quantity) => bcadd($total, $quantity, 0), '0');
            }
        }
        if (self::dataset($dataset)['channel'] === 'paypal' && $index === 14) {
            return match (strtoupper(trim($text))) {
                'CREDIT', 'CR' => '贷记（增加余额）',
                'DEBIT', 'DR' => '借记（减少余额）',
                'MEMO' => '不影响余额',
                default => self::simplified($text),
            };
        }
        if (in_array('column_'.$index, self::numericFilterFields($dataset), true)
            && preg_match('/^-?\d+\.\d+$/', $text)) {
            $text = rtrim(rtrim($text, '0'), '.');
            if ($text === '-0') {
                $text = '0';
            }
            $header = self::simplified(self::dataset($dataset)['headers'][$index]);
            if (! str_contains($header, '汇率')) {
                [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
                $text = $whole.'.'.str_pad($fraction, 2, '0');
            }
        }

        return self::simplified($text);
    }

    public static function filterColumn(string $key, string $field): string
    {
        $definition = self::dataset($key);
        if (str_starts_with($field, 'column_') && in_array($field, self::filterFields($key), true)) {
            return 'payload->'.$definition['headers'][(int) substr($field, 7)];
        }
        $payloadFields = ['sender_email' => 10, 'invoice_number' => 13, 'balance_impact' => 14];

        return isset($payloadFields[$field])
            ? 'payload->'.$definition['headers'][$payloadFields[$field]]
            : $field;
    }

    public static function menus(): array
    {
        return [
            ['home', null, '/', 'fa-home'],
            ['orders', null, '#', 'fa-exchange'],
            ['paypal-orders', 'orders', '/finance/records/paypal-orders', 'fa-circle-o'],
            ['xuzong2-orders', 'orders', '/finance/records/xuzong2-orders', 'fa-circle-o'],
            ['paypal-details', 'orders', '/finance/records/paypal-details', 'fa-circle-o'],
            ['xuzong2-details', 'orders', '/finance/records/xuzong2-details', 'fa-circle-o'],
            ['xuzong1-details', 'orders', '/finance/records/xuzong1-details', 'fa-circle-o'],
            ['reports', null, '#', 'fa-bar-chart'],
            ['paypal-settlement', 'reports', '/finance/reports/paypal-settlement', 'fa-list-alt'],
            ['credit-settlement', 'reports', '/finance/reports/credit-settlement', 'fa-list-alt'],
            ['summary', 'reports', '/finance/reports/summary', 'fa-circle-o'],
            ['losses', 'reports', '/finance/reports/losses', 'fa-circle-o'],
            ['settings', null, '#', 'fa-cogs'],
            ['types', 'settings', '/finance/settings/types', 'fa-tags'],
            ['domains', 'settings', '/finance/settings/domains', 'fa-users'],
        ];
    }
}
