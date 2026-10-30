<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MonitorPaymentPerformance
{
    private const SLOW_REQUEST_MILLISECONDS = 2000;

    public function handle(Request $request, Closure $next): Response
    {
        if (!str_starts_with(strtolower($request->getHost()), 'ppay.')) {
            return $next($request);
        }

        $profileDatabase = $request->boolean('_profile');
        $connection = null;
        if ($profileDatabase) {
            $connectionName = config('admin.database.connection') ?: config('database.default');
            $connection = DB::connection($connectionName);
            $connection->flushQueryLog();
            $connection->enableQueryLog();
        }
        $startedAt = hrtime(true);
        try {
            $response = $next($request);
        } finally {
            $queryLog = $connection?->getQueryLog() ?? [];
            $connection?->disableQueryLog();
            $connection?->flushQueryLog();
        }

        $duration = round((hrtime(true) - $startedAt) / 1_000_000, 2);
        $databaseDuration = round(array_sum(array_map(
            static fn (array $query): float => (float) ($query['time'] ?? 0),
            $queryLog,
        )), 2);
        $queryCount = count($queryLog);

        $serverTiming = 'payment;dur='.$duration;
        if ($profileDatabase) {
            $serverTiming .= ', db;dur='.$databaseDuration.';desc="'.$queryCount.' queries"';
        }
        $response->headers->set('Server-Timing', $serverTiming);
        if ($duration >= self::SLOW_REQUEST_MILLISECONDS) {
            Log::warning('Payment portal slow request.', [
                'route' => (string) optional($request->route())->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
                'duration_ms' => $duration,
                'database_duration_ms' => $databaseDuration,
                'query_count' => $queryCount,
                'status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }
}
