<?php

use App\Portals\Finance\FinanceOnlineIndexMigration;

return new class extends FinanceOnlineIndexMigration
{
    protected function operations(): array
    {
        return [
            ['table' => 'finance_records', 'kind' => 'column', 'name' => 'source_bill_trimmed', 'when_column' => 'source_bill',
                'definition' => 'TEXT COLLATE utf8mb4_bin GENERATED ALWAYS AS (TRIM(source_bill)) VIRTUAL'],
            ['table' => 'finance_records', 'kind' => 'index', 'name' => 'finance_dependency_bill_idx',
                'when_column' => 'source_bill', 'columns' => ['source_bill_trimmed(191)']],
            ['table' => 'finance_records', 'kind' => 'index', 'name' => 'finance_dependency_merchant_idx',
                'when_column' => 'source_merchant', 'columns' => ['source_merchant(191)']],
            ['table' => 'finance_records', 'kind' => 'index', 'name' => 'finance_dependency_shopify_idx',
                'when_column' => 'source_shopify', 'columns' => ['source_shopify(191)']],
        ];
    }
};
