<?php

namespace App\Portals\Finance;

class FinanceMySqlGrammar extends \Illuminate\Database\Query\Grammars\MySqlGrammar
{
    use FinanceRelationalGrammar;
}
