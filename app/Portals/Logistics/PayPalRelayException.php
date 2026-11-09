<?php

namespace App\Portals\Logistics;

use RuntimeException;

final class PayPalRelayException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
