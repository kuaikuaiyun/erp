<?php

use App\Portals\Finance\FinanceOnlineIndexMigration;

return new class extends FinanceOnlineIndexMigration
{
    protected function operations(): array
    {
        return [
            ['table' => 'finance_records', 'kind' => 'column', 'name' => 'finance_is_conflux_order',
                'definition' => "TINYINT(1) GENERATED ALWAYS AS (COALESCE(dataset = 'xuzong2-details' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$._card_channel')) = 'ye', 0)) VIRTUAL"],
            ['table' => 'finance_records', 'kind' => 'index', 'name' => 'finance_conflux_order_match_idx',
                'columns' => ['finance_is_conflux_order', 'account', 'reference']],
        ];
    }
};
