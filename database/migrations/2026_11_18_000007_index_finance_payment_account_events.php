<?php

use App\Portals\Finance\FinanceOnlineIndexMigration;

return new class extends FinanceOnlineIndexMigration
{
    protected function operations(): array
    {
        return [
            ['table' => 'finance_payment_transactions', 'kind' => 'index', 'name' => 'finance_payment_account_events_idx',
                'columns' => ['payment_account_id', 'event_code', 'finance_record_id', 'transaction_id']],
        ];
    }
};
