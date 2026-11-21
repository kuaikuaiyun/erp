<?php

use App\Portals\Finance\FinanceOnlineIndexMigration;

return new class extends FinanceOnlineIndexMigration
{
    protected function operations(): array
    {
        return [
            ['table' => 'finance_records', 'kind' => 'column', 'name' => 'finance_has_statement_notice', 'when_column' => 'payload',
                'definition' => "TINYINT(1) GENERATED ALWAYS AS (COALESCE(JSON_TYPE(JSON_EXTRACT(payload, '$._xuzong_statement_import.notice')) <> 'NULL', 0)) VIRTUAL"],
            ['table' => 'finance_records', 'kind' => 'index', 'name' => 'finance_statement_notice_page_idx', 'when_column' => 'payload',
                'columns' => ['dataset', 'finance_has_statement_notice', 'updated_at', 'id']],
        ];
    }
};
