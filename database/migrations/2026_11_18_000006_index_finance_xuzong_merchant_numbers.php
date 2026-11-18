<?php

use App\Portals\Finance\FinanceOnlineIndexMigration;

return new class extends FinanceOnlineIndexMigration
{
    protected function operations(): array
    {
        $operations = [];
        foreach (['merchant' => '商户订单号', 'legacy' => '_legacy_order_id'] as $kind => $field) {
            $column = 'finance_xuzong_'.$kind.'_number';
            $path = '$."'.$field.'"';
            $operations[] = ['table' => 'finance_records', 'kind' => 'column', 'name' => $column,
                'definition' => "TEXT COLLATE utf8mb4_bin GENERATED ALWAYS AS (CASE WHEN dataset = 'xuzong-credit-imported-orders' AND JSON_TYPE(JSON_EXTRACT(payload, '{$path}')) <> 'NULL' THEN JSON_UNQUOTE(JSON_EXTRACT(payload, '{$path}')) ELSE NULL END) VIRTUAL"];
            $operations[] = ['table' => 'finance_records', 'kind' => 'index', 'name' => 'finance_xuzong_'.$kind.'_lookup_idx',
                'columns' => ['dataset', $column.'(191)']];
        }
        return $operations;
    }
};
