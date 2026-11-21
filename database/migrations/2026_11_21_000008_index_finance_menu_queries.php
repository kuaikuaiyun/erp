<?php

use App\Portals\Finance\FinanceOnlineIndexMigration;

return new class extends FinanceOnlineIndexMigration
{
    protected function operations(): array
    {
        return [
            ['table' => 'finance_summary_facts', 'kind' => 'index', 'name' => 'finance_summary_publication_id_idx',
                'when_column' => 'publication_id', 'columns' => ['publication_id', 'id']],
            ['table' => 'finance_transactions', 'kind' => 'index', 'name' => 'finance_tx_channel_domain_page_idx',
                'when_column' => 'channel_flags', 'columns' => ['channel_flags', 'domain', 'occurred_at', 'id']],
            ['table' => 'finance_payment_transactions', 'kind' => 'index', 'name' => 'finance_payment_category_idx',
                'when_column' => 'category', 'columns' => ['category']],
            ['table' => 'finance_records', 'kind' => 'index', 'name' => 'finance_record_options_idx',
                'when_column' => 'raw_type', 'columns' => ['dataset', 'currency', 'status', 'raw_type']],
            ['table' => 'finance_paypal_list_rows', 'kind' => 'index', 'name' => 'finance_paypal_list_status_idx',
                'when_column' => 'order_status', 'columns' => ['generation', 'order_status', 'position']],
        ];
    }
};
