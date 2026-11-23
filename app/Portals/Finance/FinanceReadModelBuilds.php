<?php

namespace App\Portals\Finance;

/** Let an older process finish safely without enabling the retired global worker. */
class FinanceReadModelBuilds
{
    public static function reader(): bool { return false; }

    public function installed(): bool { return false; }
}
