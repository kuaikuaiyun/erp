<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Events\QueryExecuted;
use Symfony\Component\HttpFoundation\Response;

class MonitorFinancePerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('finance', 'finance/*') && ! str_starts_with(strtolower($request->getHost()), 'caiwu.')) {
            return $next($request);
        }
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $databaseStarted = $db->totalQueryDuration();
        $queries = [];
        if ($request->boolean('_finance_profile')) {
            $db->listen(function (QueryExecuted $query) use (&$queries, $db): void {
                if ($query->connectionName !== $db->getName()) {
                    return;
                }
                // Log query shapes only, never bindings or financial row payloads.
                $sql = preg_replace(["/'(?:''|[^'])*'/", '/\b[0-9]+\b/'], '?', $query->sql);
                $sql = substr($sql, 0, 500);
                $queries[$sql] ??= ['sql' => $sql, 'calls' => 0, 'duration_ms' => 0];
                $queries[$sql]['calls']++;
                $queries[$sql]['duration_ms'] += $query->time;
            });
        }
        $started = hrtime(true);
        $response = $next($request);
        $duration = round((hrtime(true) - $started) / 1_000_000, 2);
        $databaseDuration = round($db->totalQueryDuration() - $databaseStarted, 2);
        $response->headers->set('Server-Timing', 'finance;dur='.$duration.', db;dur='.$databaseDuration);

        if ($duration >= 1000 || $request->boolean('_finance_profile')) {
            uasort($queries, fn ($a, $b) => $b['duration_ms'] <=> $a['duration_ms']);
            Log::info('Finance request performance', [
                'route' => $request->route()?->getName(),
                'path' => $request->path(),
                'operation' => $request->boolean('settlement_total') ? 'count' : ($request->boolean('download') ? 'export' : 'page'),
                'duration_ms' => $duration,
                'database_duration_ms' => $databaseDuration,
                'peak_bytes' => memory_get_peak_usage(true),
                'status' => $response->getStatusCode(),
                ...($request->boolean('_finance_profile') ? [
                    'php_version' => PHP_VERSION,
                    'php_ini' => php_ini_loaded_file(),
                    'opcache_enabled' => function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false),
                    'slowest_queries' => array_slice(array_values($queries), 0, 10),
                ] : []),
            ]);
        }

        return $response;
    }
}
