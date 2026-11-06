<?php

namespace App\Portals\Finance;

class FinancePayPalOrderExport
{
    public const PAGE_HEADERS = ['编号', 'Shopify订单号', 'Shopify订单ID', '通途订单号', '交易号', '账单号',
        '关联状态', '店铺名称', '店铺域名', '登录地址', '使用人', '所属团队', '加购', '订阅类型', '订阅状态',
        '订阅复购原订单ID', 'PCL', '原始SKU', '通途SKU', '数量', '商品重量（克）', '包裹重量（克）', '配货名称',
        '支付状态', '交易类型', '支付渠道', '收款账号', '收款账号邮箱', '币种', '金额', '手续费', '净额',
        '实付金额', '运费', '入库时间', '下单时间', '付款时间', 'PayPal实际退款', '信用卡实际退款',
        '买家名称', 'Email', '国家/地区', '物流公司', '物流单号', '物流状态', '数据状态', '交易分类', '交易时间', '查看时区'];

    public static function pageValues($record, array $metadata, $group, string $account, string $category, string $timezone, iterable $items = [], array $refunds = [], string $dataset = 'paypal-orders'): array
    {
        $definition = FinanceCatalog::dataset('paypal-orders');
        $fields = [];
        foreach ($definition['headers'] as $index => $header) {
            $fields[$index] = $index === $definition['type']
                ? FinancePayPalEventTypes::label((string) ($record->payload[$header] ?? ''))
                : FinanceCatalog::displayValue('paypal-orders', $index, $record->payload[$header] ?? '');
        }
        $order = $metadata['order'] ?? null;
        if ($dataset === 'xuzong2-orders') {
            $fields = array_fill(0, 41, '');
            $fields[5] = $order->payment_status ?? $record->status;
            $fields[6] = $record->currency;
            $fields[7] = $order->paid_amount_usd ?? '';
            $fields[13] = $order->bill_number ?? '';
            $fields[15] = $order->collection_account_email ?? '';
            $fields[18] = $order->tongtu_product_name ?? '';
            $fields[28] = $order->sale_quantity ?? '';
        }
        $get = static fn ($key, $fallback = '') => $order->{$key} ?? $fallback;
        $date = static fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s') : '';
        $upsell = $metadata['upsell'] ?? ($record->payload['是否加购'] ?? '');
        $products = self::productItems($items, $fields[18], $dataset === 'paypal-orders'
            ? (string) ($record->payload[$definition['headers'][28]] ?? '') : (string) $fields[28]);
        $productColumns = self::productColumns($products);

        return [$get('id', $record->id), $get('shopify_order_number'), $get('shopify_order_id'),
            $metadata['erp_order'] ?? ($record->payload['通途订单'] ?? ''), $record->reference, $get('bill_number', $fields[13]),
            $order ? '' : '未匹配物流订单', $get('store_name'), ($metadata['domain'] ?? '') ?: ($record->domain ?? ''),
            $get('source_shop_domain'), $group->owner ?? '', $group->team ?? '',
            $upsell === '' || $upsell === null ? '' : (in_array(strtolower((string) $upsell), ['1', 'true', 'yes', '是', '加购'], true) ? '加购' : '未加购'),
            $metadata['subscription'] ?? ($record->payload['订阅类型'] ?? ''), $get('subscription_status'), $get('subscription_origin_order_id'),
            ...$productColumns, $fields[5], $fields[4], $get('payment_gateway', $dataset === 'paypal-orders' ? 'PayPal' : ''),
            $account, $fields[15], $fields[6], $fields[7], $fields[8], $fields[9], $get('paid_amount_usd', $fields[7]),
            $get('total_shipping_usd'), $date($get('created_at')), $date($get('order_created_at')), $date($get('paid_at')),
            $refunds['paypal'] ?? '', $refunds['credit_card'] ?? '', $get('buyer_name', $fields[3]), $get('buyer_email', $fields[10]),
            $get('destination', $fields[36]), $get('shipping_company'), $get('tracking_number'), $get('logistics_status'),
            $get('data_status'), $category, $record->occurred_at->setTimezone($timezone)->format('Y-m-d H:i:s'), FinanceCatalog::timezoneLabel($timezone)];
    }

    /** Same product rows and fallback rules as the order list. */
    public static function productItems(iterable $items, string $names, string $quantities): \Illuminate\Support\Collection
    {
        $items = collect($items)->values();
        if ($items->isNotEmpty()) return $items;
        $names = collect(preg_split('/\r?\n/', $names) ?: [])->filter(fn ($name) => trim($name) !== '')->values();
        $quantities = preg_split('/\r?\n/', $quantities) ?: [];
        return $names->map(fn ($name, $index) => new \App\Models\LogisticsOrderItem([
            'product_title' => $name,
            'quantity' => count($quantities) === $names->count() && is_numeric($quantities[$index] ?? null) ? $quantities[$index] : null,
        ]));
    }

    /** Each line position identifies the same item across all seven columns. */
    public static function productColumns(\Illuminate\Support\Collection $items): array
    {
        $getters = [
            fn ($item) => $item->tongtu_product_code ?? '', fn ($item) => $item->shopify_sku ?? '',
            fn ($item) => $item->tongtu_sku ?? '', fn ($item) => $item->quantity ?? '',
            fn ($item) => ($item->sale_weight_gram ?? null) ?: ($item->tongtu_goods_weight_gram ?? ''),
            fn ($item) => $item->tongtu_package_weight_gram ?? '',
            fn ($item) => ($item->tongtu_product_name ?? null) ?: ($item->product_title ?? ''),
        ];
        return array_map(fn ($get, $index) => $items->map(function ($item) use ($get, $index) {
            $value = $get($item);
            return $index >= 3 && $index <= 5 && is_numeric($value) ? (string) (float) $value : (string) $value;
        })->implode("\n"), $getters, array_keys($getters));
    }

    public static function download(array $headers, iterable $rows, string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // Use the same streaming XLSX engine and multi-line row layout as logistics.
        return app(\App\Portals\Logistics\StreamedXlsxWriter::class)->response($filename, '订单数据',
            array_map(fn ($header) => ['label' => FinanceCatalog::simplified($header), 'width' => str_contains($header, '配货') ? 44 : 24], $headers),
            function () use ($headers, $rows): iterable {
                foreach ($rows as $row) {
                    $lines = 1;
                    $cells = [];
                    foreach (array_values($row) as $index => $value) {
                        $text = FinanceCatalog::simplified((string) ($value ?? ''));
                        $lines = max($lines, substr_count($text, "\n") + 1);
                        $numeric = preg_match('/金额|运费|净额|手续费/u', $headers[$index])
                            && is_numeric($text) && strlen(str_replace(['-', '.'], '', $text)) <= 15;
                        $cells[] = ['value' => $numeric ? (float) $text : $text, 'type' => $numeric ? 'money' : 'text', 'wrap' => true];
                    }
                    yield ['height' => min(120, max(36, $lines * 18)), 'cells' => $cells];
                }
            });
    }

    public const COLUMNS = [
        'shopify_order_number' => 'Shopify订单编号', 'shopify_order_id' => 'Shopify订单ID',
        'tongtu_order_number' => '通途订单号', 'store_name' => '店铺名称', 'source_shop_domain' => '店铺域名',
        'subscription_type' => '订阅类型（关联订单）', 'subscription_status' => '订阅状态',
        'subscription_origin_order_id' => '订阅复购原订单ID', 'is_add_on' => '是否加购（关联订单）',
        'tongtu_sku' => '通途SKU', 'tongtu_product_name' => '出单产品名称',
        'sale_quantity' => '出单数量', 'sale_product_weight' => '商品重量',
        'payment_gateway' => '支付渠道', 'paid_amount_usd' => '实付金额（美元）',
        'total_shipping_usd' => '运费（美元）', 'order_created_at' => '订单创建时间', 'paid_at' => '支付时间',
        'shipping_company' => '物流公司', 'tracking_number' => '物流单号',
        'logistics_status' => '物流状态', 'data_status' => '数据状态', '__products' => '出单产品明细',
    ];

    public static function values(?object $order, string $timezone, iterable $items = []): array
    {
        return array_map(static function ($field) use ($order, $timezone, $items) {
            if ($field === '__products') {
                $lines = [];
                foreach ($items as $item) {
                    $lines[] = implode('；', [
                        'PCL: '.($item->tongtu_product_code ?? ''), '原始SKU: '.($item->shopify_sku ?? ''),
                        '通途SKU: '.($item->tongtu_sku ?? ''), '数量: '.($item->quantity ?? ''),
                        '商品重量: '.(($item->sale_weight_gram ?? null) ?: ($item->tongtu_goods_weight_gram ?? '')),
                        '包裹重量: '.($item->tongtu_package_weight_gram ?? ''),
                        '配货: '.(($item->tongtu_product_name ?? null) ?: ($item->product_title ?? '')),
                    ]);
                }
                return implode("\n", $lines);
            }
            $value = $order->{$field} ?? null;
            if ($field === 'is_add_on' && $value !== null && $value !== '') {
                return in_array(strtolower((string) $value), ['1', 'true', 'yes', '是', '加购'], true) ? '加购' : '未加购';
            }
            if (in_array($field, ['order_created_at', 'paid_at'], true) && is_string($value) && $value !== '') {
                $value = \Carbon\CarbonImmutable::parse($value, 'UTC');
            }
            if ($value instanceof \Carbon\CarbonInterface) {
                return $value->copy()->setTimezone($timezone)->format('Y-m-d H:i:s');
            }

            return $value ?? '';
        }, array_keys(self::COLUMNS));
    }
}
