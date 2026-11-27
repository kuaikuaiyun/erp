<?php

namespace App\Portals\Logistics\TongtoolSource;

use RuntimeException;

final class SourceResponse extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly array $body)
    {
        parent::__construct((string) ($body['errorCode'] ?? 'tongtool_source_error'));
    }
}
