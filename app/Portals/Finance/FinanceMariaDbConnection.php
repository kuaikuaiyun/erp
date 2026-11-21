<?php

namespace App\Portals\Finance;

class FinanceMariaDbConnection extends \Illuminate\Database\MariaDbConnection
{
    use FinanceTracksApplicationWrites;
}
