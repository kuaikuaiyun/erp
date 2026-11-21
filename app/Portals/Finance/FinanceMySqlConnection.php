<?php

namespace App\Portals\Finance;

class FinanceMySqlConnection extends \Illuminate\Database\MySqlConnection
{
    use FinanceTracksApplicationWrites;
}
