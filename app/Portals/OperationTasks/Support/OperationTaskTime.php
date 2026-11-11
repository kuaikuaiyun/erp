<?php

namespace App\Portals\OperationTasks\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

final class OperationTaskTime
{
    public const DISPLAY_TIMEZONE = 'Asia/Shanghai';

    public static function format(mixed $value, string $format = 'Y-m-d H:i', string $fallback = '—'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            $time = $value instanceof DateTimeInterface
                ? Carbon::instance($value)->copy()
                : Carbon::parse((string) $value, 'UTC');

            return $time->setTimezone(self::DISPLAY_TIMEZONE)->format($format);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
