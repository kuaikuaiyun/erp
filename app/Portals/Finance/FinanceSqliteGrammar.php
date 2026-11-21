<?php

namespace App\Portals\Finance;

class FinanceSqliteGrammar extends \Illuminate\Database\Query\Grammars\SQLiteGrammar
{
    use FinanceRelationalGrammar;
}
