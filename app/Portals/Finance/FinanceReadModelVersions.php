<?php

namespace App\Portals\Finance;

use Illuminate\Database\Connection;

/** Compatibility for an older in-flight process; global read-model mode is retired. */
class FinanceReadModelVersions
{
    public static function installed(Connection $db): bool { return false; }

    public static function touch(Connection $db, string $source, array $values): void {}
}
