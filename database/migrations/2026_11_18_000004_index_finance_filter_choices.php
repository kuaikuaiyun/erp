<?php

use App\Portals\Finance\FinanceOnlineIndexMigration;

return new class extends FinanceOnlineIndexMigration
{
    protected function operations(): array
    {
        return [
            ['table' => 'finance_records', 'kind' => 'index', 'name' => 'finance_record_currency_account_idx',
                'columns' => ['dataset', 'currency', 'account']],
            ['table' => 'logistics_orders', 'kind' => 'index', 'name' => 'finance_credit_filter_choices_idx',
                'when_column' => 'finance_is_non_paypal', 'columns' => ['finance_is_non_paypal', 'collection_account', 'payment_status']],
        ];
    }
};
