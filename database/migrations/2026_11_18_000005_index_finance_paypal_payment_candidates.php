<?php

use App\Portals\Finance\FinanceOnlineIndexMigration;

return new class extends FinanceOnlineIndexMigration
{
    protected function operations(): array
    {
        return [
            ['table' => 'finance_records', 'kind' => 'index', 'name' => 'finance_paypal_candidate_idx',
                'columns' => ['dataset', 'raw_type', 'account', 'occurred_at', 'id']],
        ];
    }
};
