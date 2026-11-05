<?php

namespace App\Portals\Finance;

use RuntimeException;

class FinanceRelayException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        // Never include HTTP bodies, credentials, URLs or chained transport exceptions.
        parent::__construct(__('finance.sync.errors.'.$reason));
    }
}
