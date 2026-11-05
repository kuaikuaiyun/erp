<?php

namespace App\Portals\Finance;

use Illuminate\Support\Arr;

class FinancePayPalDetails
{
    public const HEADERS = ['收件人电邮地址', '运送地址', '地址状态', '物品标题', '物品 ID', '运费与处理费金额', '保险金额', '销售税', '选项 1 名称', '选项 1 的值', '选项 2 名称', '选项 2 的值', '自定义编号', '数量', '收据 ID', '余额', '地址行 1', '地址行 2 / 区 / 邻', '镇或县市', '省、市、自治区、直辖市或特别行政区', '邮递区号', '国家或地区', '联系电话号码', '主旨', '附注', '国家或地区代码'];

    public static function payload(array $raw): array
    {
        $get = static fn ($path) => (string) Arr::get($raw, $path, '');
        $items = Arr::get($raw, 'cart_info.item_details', []);
        $itemValues = static fn ($key) => implode("\n", array_map(static fn ($item) => (string) ($item[$key] ?? ''), $items));
        $quantities = array_map(static fn ($item) => (string) ($item['item_quantity'] ?? ''), $items);
        $quantity = $items !== [] && count(array_filter($quantities, static fn ($value) => ! preg_match('/^\d+$/', $value))) === 0
            ? array_reduce($quantities, static fn ($total, $value) => bcadd($total, $value, 0), '0')
            : $itemValues('item_quantity');
        $address = Arr::get($raw, 'shipping_info.address', []);
        $addressText = implode(', ', array_filter([
            $get('shipping_info.name'), $address['line1'] ?? '', $address['line2'] ?? '',
            $address['city'] ?? '', $address['state'] ?? '', $address['postal_code'] ?? '', $address['country_code'] ?? '',
        ], static fn ($value) => $value !== ''));
        $status = $get('payer_info.address_status');
        $phone = trim($get('payer_info.phone_number.country_code').' '.$get('payer_info.phone_number.national_number'));
        // Keep unavailable report-only fields empty. Do not infer recipient email or parse opaque item options.
        return array_combine(self::HEADERS, [
            '', $address === [] ? '' : $addressText,
            match ($status) { 'Y' => '已确认', 'N' => '未确认', default => $status },
            $itemValues('item_name'), $itemValues('item_code'), $get('transaction_info.shipping_amount.value'),
            $get('transaction_info.insurance_amount.value'), $get('transaction_info.sales_tax_amount.value'),
            '', '', '', '', $get('transaction_info.custom_field'), $quantity, '',
            $get('transaction_info.ending_balance.value'), $address['line1'] ?? '', $address['line2'] ?? '',
            $address['city'] ?? '', $address['state'] ?? '', $address['postal_code'] ?? '', $address['country_code'] ?? '',
            $phone, $get('transaction_info.transaction_subject'), $get('transaction_info.transaction_note'), $address['country_code'] ?? '',
        ]);
    }
}
