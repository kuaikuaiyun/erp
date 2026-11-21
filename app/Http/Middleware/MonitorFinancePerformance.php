<?php

namespace App\Http\Middleware;

use App\Portals\Finance\FinanceQueryDiagnostics;
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
        // All finance menus, AJAX counts and exports leave notification consumption to the worker.
        $request->attributes->set('finance.report_read_only', true);
        $diagnostic = config('finance.diagnostics.enabled', true)
            && ($request->is('finance/reports/losses') || $request->boolean('_finance_profile'))
            ? FinanceQueryDiagnostics::start($request) : null;
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $databaseStarted = $db->totalQueryDuration();
        $queries = [];
        if ($request->boolean('_finance_profile') || $diagnostic !== null) {
            $db->listen(function (QueryExecuted $query) use (&$queries, $db, $diagnostic): void {
                if ($query->connectionName !== $db->getName()) {
                    return;
                }
                $diagnostic?->query($query->sql, $query->time);
                // Log query shapes only, never bindings or financial row payloads.
                $sql = preg_replace(["/'(?:''|[^'])*'/", '/\b[0-9]+\b/'], '?', $query->sql);
                $sql = substr($sql, 0, 500);
                if (! isset($queries[$sql]) && count($queries) >= 100) return;
                $queries[$sql] ??= ['sql' => $sql, 'calls' => 0, 'duration_ms' => 0];
                $queries[$sql]['calls']++;
                $queries[$sql]['duration_ms'] += $query->time;
            });
        }
        $started = hrtime(true);
        try {
            $response = $next($request);
        } catch (\Throwable $error) {
            $diagnostic?->finish('request.failed', ['exception' => $error::class]);
            throw $error;
        }
        $duration = round((hrtime(true) - $started) / 1_000_000, 2);
        $databaseDuration = round($db->totalQueryDuration() - $databaseStarted, 2);
        $response->headers->set('Server-Timing', 'finance;dur='.$duration.', db;dur='.$databaseDuration);
        if ($diagnostic !== null) $response->headers->set('X-Finance-Trace', $diagnostic->id);

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
                ...($diagnostic !== null ? ['trace_id' => $diagnostic->id] : []),
                ...($request->boolean('_finance_profile') ? [
                    'php_version' => PHP_VERSION,
                    'php_ini' => php_ini_loaded_file(),
                    'opcache_enabled' => function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false),
                    'slowest_queries' => array_slice(array_values($queries), 0, 10),
                ] : []),
            ]);
        }

        $diagnostic?->finish('request.end', ['status' => $response->getStatusCode(), 'duration_ms' => $duration,
            'database_duration_ms' => $databaseDuration]);

        return $response;
    }
}
