<?php

namespace App\Portals\Finance;

class FinanceSqliteConnection extends \Illuminate\Database\SQLiteConnection
{
    use FinanceTracksApplicationWrites;

    protected function getDefaultPostProcessor()
    {
        return new FinanceSqliteProcessor;
    }
}
