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
        if ($profileDatabase || $duration >= self::SLOW_REQUEST_MILLISECONDS) {
            $slow = $duration >= self::SLOW_REQUEST_MILLISECONDS;
            Log::log($slow ? 'warning' : 'info', $slow ? 'Payment portal slow request.' : 'Payment portal request profile.', [
                'route' => (string) optional($request->route())->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
                'duration_ms' => $duration,
                'database_duration_ms' => $databaseDuration,
                'query_count' => $queryCount,
                'status' => $response->getStatusCode(),
                'database_profiled' => $profileDatabase,
                ...($profileDatabase ? [
                    'cache_store' => config('cache.default'),
                    'cache_driver' => config('cache.stores.'.config('cache.default').'.driver'),
                    ...$this->queryDetails($queryLog),
                ] : []),
            ]);
        }

        return $response;
    }

    private function queryDetails(array $queries): array
    {
        $groups = [];
        $slowest = [];
        foreach ($queries as $query) {
            $sql = $this->queryShape((string) $query['query']);
            $key = hash('sha256', $sql);
            $milliseconds = (float) ($query['time'] ?? 0);
            $groups[$key] ??= [
                'sql' => mb_strcut($sql, 0, 6000, 'UTF-8'),
                'calls' => 0,
                'duration_ms' => 0.0,
                'max_ms' => 0.0,
            ];
            $groups[$key]['calls']++;
            $groups[$key]['duration_ms'] += $milliseconds;
            $groups[$key]['max_ms'] = max($groups[$key]['max_ms'], $milliseconds);
            $slowest[] = ['key' => $key, 'duration_ms' => round($milliseconds, 2)];
            usort($slowest, static fn (array $a, array $b): int => $b['duration_ms'] <=> $a['duration_ms']);
            if (count($slowest) > 10) {
                array_pop($slowest);
            }
        }

        $slowestQueries = array_map(static fn (array $query): array => [
            'sql' => $groups[$query['key']]['sql'],
            'duration_ms' => $query['duration_ms'],
            'calls' => $groups[$query['key']]['calls'],
            'group_duration_ms' => round($groups[$query['key']]['duration_ms'], 2),
        ], $slowest);
        foreach ($groups as &$group) {
            $group['duration_ms'] = round($group['duration_ms'], 2);
            $group['max_ms'] = round($group['max_ms'], 2);
        }
        unset($group);
        uasort($groups, static fn (array $a, array $b): int => $b['duration_ms'] <=> $a['duration_ms']);

        return [
            'slowest_queries' => $slowestQueries,
            'query_groups' => array_slice(array_values($groups), 0, 10),
        ];
    }

    private function queryShape(string $sql): string
    {
        // Log SQL structure only. Bindings, literal values and SQL comments are omitted.
        $sql = preg_replace_callback(
            <<<'REGEX'
~'(?:''|\\.|[^'\\])*'|"(?: "" | \\. | [^"\\])*"|/\*[\s\S]*?\*/|--[^\r\n]*|\#[^\r\n]*~x
REGEX,
            static fn (array $match): string => in_array($match[0][0], ["'", '"'], true) ? '?' : ' ',
            $sql,
        );
        $sql = preg_replace('/(?<![\w?])(?:0x[0-9a-f]+|[+-]?\d+(?:\.\d+)?(?:e[+-]?\d+)?)(?!\w)/i', '?', $sql);
        $sql = preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?)', $sql);

        return preg_replace('/\s+/', ' ', trim($sql));
    }
}
