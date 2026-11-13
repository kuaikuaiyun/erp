<?php

namespace App\Portals\Finance;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

class FinanceSummaryTriggers
{
    public static function available(Connection $db): bool
    {
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }

        $names = [];
        foreach (['record', 'supplement', 'payment', 'ledger', 'account', 'type', 'rate', 'statement',
            'dependency_order', 'dependency_reference', 'dependency_item', 'dependency_sku',
            'dependency_fashion', 'dependency_fashion_item', 'dependency_store', 'dependency_owner'] as $source) {
            foreach (['insert', 'update', 'delete'] as $event) {
                $names[] = "finance_summary_{$source}_{$event}";
            }
        }

        return $db->table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->whereIn('TRIGGER_NAME', $names)->count() === count($names);
    }

    public static function install(Connection $db, string $sql): void
    {
        try {
            $db->unprepared($sql);
        } catch (QueryException $exception) {
            if (! in_array((int) ($exception->errorInfo[1] ?? 0), [1142, 1227, 1359, 1419], true)) {
                throw $exception;
            }
        }
    }
}
