<?php

namespace App\Portals\Finance;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Bounded, request-local diagnostics. Never records bindings or notification contents. */
class FinanceQueryDiagnostics
{
    private const ATTRIBUTE = 'finance.query_diagnostics';
    private int $started;
    private int $events = 0;
    private int $suppressed = 0;
    private int $queryCount = 0;
    private float $queryMs = 0;
    private bool $finished = false;
    private string $stage = 'request';
    private array $stageContext = [];
    private array $slowQueries = [];
    private mixed $logger = null;
    private ?string $reserve;
    public readonly string $id;

    private function __construct()
    {
        $this->started = hrtime(true);
        $this->id = bin2hex(random_bytes(8));
        $this->reserve = str_repeat('x', 65536);
    }

    public static function start(Request $request): self
    {
        $trace = new self;
        $request->attributes->set(self::ATTRIBUTE, $trace);
        $filters = [];
        foreach (['month', 'month_from', 'month_to', 'loss_scope', 'loss_category'] as $field) {
            if (is_string($value = $request->query($field))) $filters[$field] = mb_substr($value, 0, 80);
        }
        $trace->event('request.start', ['path' => $request->path(), 'filters' => $filters,
            'plan' => $request->boolean('_finance_plan'), 'prepare' => $request->boolean('_source_prepare'),
            'php_version' => PHP_VERSION, 'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')]);
        register_shutdown_function(function () use ($trace): void {
            if ($trace->finished) return;
            $trace->reserve = null;
            $error = error_get_last();
            $fatal = $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
            // SIGTERM/SIGKILL need not run PHP shutdown callbacks. Earlier stage.start records remain available.
            $trace->finish('request.shutdown', ['fatal' => $fatal ? [
                'message' => mb_substr($error['message'], 0, 500), 'file' => $error['file'], 'line' => $error['line'],
            ] : null]);
        });
        return $trace;
    }

    public static function current(): ?self
    {
        $trace = app('request')->attributes->get(self::ATTRIBUTE);
        return $trace instanceof self && ! $trace->finished ? $trace : null;
    }

    public static function measure(string $stage, callable $action, array $context = [], bool $sample = true): mixed
    {
        $trace = self::current();
        if ($trace === null) return $action();
        $previous = [$trace->stage, $trace->stageContext];
        $trace->stage = $stage;
        $trace->stageContext = $context;
        $started = hrtime(true);
        if ($sample) $trace->event('stage.start', ['stage' => $stage, ...$context]);
        try {
            $result = $action();
            $duration = round((hrtime(true) - $started) / 1e6, 2);
            if ($sample || $duration >= 250) $trace->event('stage.end', ['stage' => $stage, ...$context, 'duration_ms' => $duration]);
            return $result;
        } catch (Throwable $error) {
            $trace->event('stage.failed', ['stage' => $stage, ...$context, 'exception' => $error::class,
                'duration_ms' => round((hrtime(true) - $started) / 1e6, 2)]);
            throw $error;
        } finally {
            [$trace->stage, $trace->stageContext] = $previous;
        }
    }

    public function query(string $sql, float $milliseconds): void
    {
        if ($this->finished) return;
        $this->queryCount++;
        $this->queryMs += $milliseconds;
        if ($milliseconds < 250) return;
        // QueryExecuted does not include PDO commit/fsync time; notification spans measure those separately.
        $shape = substr(preg_replace(["/'(?:''|[^'])*'/", '/\b[0-9]+\b/'], '?', $sql), 0, 500);
        $key = hash('sha256', $shape);
        if (isset($this->slowQueries[$key]) || count($this->slowQueries) < 20) {
            $this->slowQueries[$key] ??= ['sql' => $shape, 'calls' => 0, 'duration_ms' => 0];
            $this->slowQueries[$key]['calls']++;
            $this->slowQueries[$key]['duration_ms'] += $milliseconds;
        }
        $this->event('query.slow', ['sql' => $shape, 'duration_ms' => $milliseconds]);
    }

    public function phase(string $stage, array $context, bool $sample): void
    {
        $this->stage = $stage;
        $this->stageContext = $context;
        if ($sample) $this->event('stage.start', ['stage' => $stage, ...$context]);
    }

    public function event(string $event, array $context = [], bool $force = false): void
    {
        if (! $force && $this->events >= 200) { $this->suppressed++; return; }
        $this->events++;
        try {
            $this->logger ??= Log::build(['driver' => 'single', 'path' => config('finance.diagnostics.log_path', storage_path('logs/finance-query.log')),
                'level' => 'info', 'locking' => true]);
            $this->logger->info('Finance query diagnostic', ['trace_id' => $this->id, 'pid' => getmypid(),
                'event' => $event, 'stage' => $this->stage, 'elapsed_ms' => round((hrtime(true) - $this->started) / 1e6, 2),
                'memory_bytes' => memory_get_usage(true), 'peak_bytes' => memory_get_peak_usage(true), ...$context]);
        } catch (Throwable) { /* Diagnostics must never change the query result. */ }
    }

    public function finish(string $event, array $context = []): void
    {
        if ($this->finished) return;
        $this->reserve = null;
        uasort($this->slowQueries, fn ($a, $b) => $b['duration_ms'] <=> $a['duration_ms']);
        $this->event($event, [...$this->stageContext, ...$context, 'query_count' => $this->queryCount,
            'query_ms' => round($this->queryMs, 2), 'slow_queries' => array_values($this->slowQueries),
            'suppressed_events' => $this->suppressed], force: true);
        $this->finished = true;
    }
}
