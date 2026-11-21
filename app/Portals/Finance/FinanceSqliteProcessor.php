<?php

namespace App\Portals\Finance;

class FinanceSqliteProcessor extends \Illuminate\Database\Query\Processors\SQLiteProcessor
{
    public function processInsertGetId(\Illuminate\Database\Query\Builder $query, $sql, $values, $sequence = null)
    {
        $query->getConnection()->insert($sql, $values);
        $id = $query->getConnection()->financeInsertId();
        return is_numeric($id) ? (int) $id : $id;
    }
}
