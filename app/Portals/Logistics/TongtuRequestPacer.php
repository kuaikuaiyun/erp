<?php

namespace App\Portals\Logistics;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class TongtuRequestPacer
{
    public function waitForTurn(): void
    {
        $lock = Cache::lock('logistics:tongtu:request-pacer-lock', 30);

        try {
            $lock->block(30);
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('等待通途接口限流队列超时，请稍后重试。', previous: $exception);
        }

        try {
            $nextAllowedAt = (float) Cache::get('logistics:tongtu:next-request-at', 0);
            $waitSeconds = $nextAllowedAt - microtime(true);
            if ($waitSeconds > 0) {
                usleep((int) ceil($waitSeconds * 1000000));
            }

            $interval = max(13, (int) config('logistics.tongtu.request_interval_seconds', 13));
            Cache::put('logistics:tongtu:next-request-at', microtime(true) + $interval, now()->addMinutes(5));
        } finally {
            $lock->release();
        }
    }
}
