<?php

namespace App\Portals\Assistant;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Throwable;

final class DomainStoreSaveDiagnostic
{
    public const ATTRIBUTE = 'assistant_domain_store_save_diagnostic';
    private Logger $logger;
    private string $stage = 'request_received';
    private string $commitState = 'not_started';
    private ?int $storeId = null;
    private bool $finished = false;
    private bool $logWritable = true;
    private ?string $reserveMemory;
    private float $startedAt;

    public readonly string $id;

    public function __construct(Request $request, ?string $logPath = null)
    {
        $this->id = bin2hex(random_bytes(8));
        $this->startedAt = microtime(true);
        $this->reserveMemory = str_repeat('x', 65536);
        $store = $request->route('domainStore');
        $storeId = is_object($store) && method_exists($store, 'getKey') ? $store->getKey() : $store;
        $this->storeId = is_numeric($storeId) ? (int) $storeId : null;
        $this->logger = new Logger('domain-store-save');
        $handler = new StreamHandler($logPath ?? storage_path('logs/domain-store-save.log'), Logger::DEBUG, true, 0660, true);
        $handler->setFormatter(new JsonFormatter());
        $this->logger->pushHandler($handler);
        $request->attributes->set(self::ATTRIBUTE, $this);
        $this->write('info', 'request_received', ['method' => $request->method()]);
    }

    public static function mark(Request $request, string $stage, ?int $storeId = null): void
    {
        $trace = $request->attributes->get(self::ATTRIBUTE);
        if ($trace instanceof self) $trace->phase($stage, $storeId);
    }

    public function phase(string $stage, ?int $storeId = null): void
    {
        if ($storeId !== null) $this->storeId = $storeId;
        $this->stage = $stage;
        $this->write('info', 'stage');
    }

    /** Execute the original transaction once, without changing its rollback or retry policy. */
    public static function transaction(Request $request, Connection $connection, Closure $callback): mixed
    {
        $trace = $request->attributes->get(self::ATTRIBUTE);
        if (! $trace instanceof self) return $connection->transaction($callback);
        $level = $connection->transactionLevel();
        $trace->commitState = 'in_progress';
        $trace->phase('transaction_started');
        $readyToCommit = false;
        try {
            $result = $connection->transaction(function () use ($callback, $trace, &$readyToCommit) {
                try {
                    $result = $callback();
                } catch (Throwable $error) {
                    // Capture before rollback handling or Laravel's exception
                    // handler can replace the original business exception.
                    $trace->exception($error, false, 'transaction_callback_exception');
                    throw $error;
                }
                $readyToCommit = true;
                $trace->phase('transaction_ready_to_commit');

                return $result;
            });
        } catch (Throwable $error) {
            // A failure before commit with a completed rollback is distinguishable
            // from a failed commit, where the database outcome may be uncertain.
            $trace->commitState = ! $readyToCommit && $connection->transactionLevel() === $level
                ? ($level === 0 ? 'rolled_back' : 'nested_rollback')
                : 'unconfirmed';
            $trace->write('warning', 'transaction_failed');
            $trace->exception($error, false, 'transaction_exception');
            throw $error;
        }
        $trace->commitState = $level === 0 ? 'committed' : 'awaiting_outer_commit';
        $trace->phase('transaction_committed');

        return $result;
    }

    public function state(): string { return $this->commitState; }
    public function writable(): bool { return $this->logWritable; }

    public function exception(Throwable $error, bool $expected, string $event = 'exception'): void
    {
        $chain = [];
        do {
            // Never log exception messages, SQL, arguments, bindings, or form values.
            $entry = ['class' => get_class($error), 'file' => $error->getFile(), 'line' => $error->getLine()];
            $entry['frames'] = array_map(static fn (array $frame): array => array_intersect_key(
                $frame, array_flip(['file', 'line', 'class', 'function', 'type']),
            ), array_slice($error->getTrace(), 0, 20));
            if ($error instanceof QueryException || $error instanceof \PDOException) {
                $info = $error->errorInfo ?? [];
                $entry['sql_state'] = isset($info[0]) && preg_match('/^[A-Z0-9]{5}$/', (string) $info[0]) ? $info[0] : null;
                $entry['driver_code'] = isset($info[1]) && is_numeric($info[1]) ? (int) $info[1] : null;
            }
            $fileLoad = $this->fileLoadFailure($error);
            if ($fileLoad !== null) $entry['file_load'] = $fileLoad;
            $chain[] = $entry;
            $error = $error->getPrevious();
        } while ($error !== null && count($chain) < 4);
        $this->write($expected ? 'notice' : 'error', $expected ? 'request_rejected' : $event, ['exceptions' => $chain]);
    }

    /**
     * Only structured Composer load failures are extracted. Raw messages,
     * include_path settings, SQL and arbitrary request values stay excluded.
     */
    private function fileLoadFailure(Throwable $error): ?array
    {
        if (! $error instanceof \ErrorException) return null;
        $source = str_replace(chr(92), '/', $error->getFile());
        $suffix = 'vendor/composer/ClassLoader.php';
        if (! str_ends_with($source, '/'.$suffix)) return null;
        $message = $error->getMessage();
        $lower = strtolower($message);
        $reason = match (true) {
            str_contains($lower, 'open_basedir restriction') => 'open_basedir_restriction',
            str_contains($lower, 'permission denied') => 'permission_denied',
            str_contains($lower, 'no such file or directory') => 'file_not_found',
            str_contains($lower, 'too many open files') => 'too_many_open_files',
            str_contains($lower, 'input/output error') => 'io_error',
            str_contains($lower, 'failed to open stream'), str_contains($lower, 'failed opening') => 'open_failed',
            default => null,
        };
        if ($reason === null) return null;
        $result = ['reason' => $reason];
        $target = null;
        if (preg_match('/\b(?:include|include_once|require|require_once)\(([^)\r\n]+)\)/', $message, $matches)) {
            $target = trim($matches[1], " \"'");
        } elseif (preg_match("/Failed opening ['\"]([^'\"]+)['\"] for inclusion/i", $message, $matches)) {
            $target = $matches[1];
        }
        // open_basedir warnings use File(...) instead of include(path).
        if ($target === null && preg_match('/\bFile\(([^)\r\n]+)\)/', $message, $matches)) $target = $matches[1];
        if ($target === null) return $result;
        $target = str_replace(chr(92), '/', $target);
        $root = substr($source, 0, -strlen($suffix));
        if (strlen($target) <= 2048
            && str_starts_with($target, $root)
            && str_ends_with(strtolower($target), '.php')
            && ! preg_match('/[\x00-\x1f?#]/', $target)
            && ! str_contains($target, '://')
            && ! str_contains($target, '/../')) {
            $result['target_file'] = $target;
        }

        return $result;
    }

    public function finish(int $status): void
    {
        $this->finished = true;
        $this->reserveMemory = null;
        $this->write($status >= 500 ? 'error' : 'info', 'request_finished', ['http_status' => $status]);
    }

    public function registerShutdown(): void
    {
        register_shutdown_function(function (): void {
            if ($this->finished) return;
            $this->reserveMemory = null;
            $error = error_get_last();
            if (! $error || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
            $this->write('error', 'fatal_shutdown', [
                'error_type' => $error['type'], 'file' => $error['file'], 'line' => $error['line'],
                'category' => str_contains($error['message'], 'Allowed memory') ? 'memory_limit'
                    : (str_contains($error['message'], 'Maximum execution') ? 'execution_timeout' : 'fatal_error'),
            ]);
        });
    }

    private function write(string $level, string $event, array $extra = []): void
    {
        $context = [
            'diagnostic_id' => $this->id, 'event' => $event, 'store_id' => $this->storeId,
            'stage' => $this->stage, 'commit_state' => $this->commitState,
            'elapsed_ms' => round((microtime(true) - $this->startedAt) * 1000, 2),
        ] + $extra;
        try {
            $this->logger->log($level, 'domain_store_save', $context);
        } catch (Throwable) {
            // Diagnostics must not break an otherwise valid save.
            $this->logWritable = false;
            try {
                @error_log('domain_store_save '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
            } catch (Throwable) {
                // Neither logging destination may interrupt the business request.
            }
        }
    }
}
