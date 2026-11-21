<?php

namespace App\Portals\Finance;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Throwable;

/** Durable notifications owned by finance. External saves never write finance SQL. */
class FinanceExternalChanges
{
    private bool $draining = false;
    private \WeakMap $memoryIdentities;

    public function __construct() { $this->memoryIdentities = new \WeakMap; }

    public function targets(Connection $source): bool
    {
        $name = config('admin.database.connection') ?: config('database.default');
        if ($source->getName() === $name) return true;
        $finance = app('db')->connection($name); // Resolves configuration without opening PDO.
        if ($source->getDriverName() === 'sqlite') return false;
        foreach (['driver', 'host', 'port', 'database'] as $key) {
            if ($source->getConfig($key) !== $finance->getConfig($key)) return false;
        }
        return true;
    }

    public function directory(Connection $db): string
    {
        $identity = array_map(fn ($key) => $db->getConfig($key), ['driver', 'host', 'port', 'database']);
        if ($db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:') {
            $identity[] = $this->memoryIdentities[$db] ??= (string) Str::uuid();
        }
        $root = config('finance.summaries.external_change_path');
        if (! $root) {
            $source = config('finance.summaries.source_cache_path');
            $root = $source ? rtrim($source, '/\\').'/external-changes' : storage_path('app/finance-source-notifications');
        }
        return rtrim($root, '/\\').'/'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    public static function failure(Throwable $error): void
    {
        try { report($error); } catch (Throwable) { /* Diagnostics must not reject an external business save. */ }
    }

    /** A locked pending file also covers a process exit between source commit and its callback. */
    public function begin(Connection $db): ?array
    {
        $handle = null; $path = null;
        try {
            $directory = $this->directory($db);
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) throw new \RuntimeException('无法创建财务来源通知目录。');
            $path = $directory.'/change-'.Str::uuid().'.json';
            $handle = @fopen($path, 'x+b');
            if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) throw new \RuntimeException('无法持久保存财务来源通知。');
            @chmod($path, 0600);
            $this->write($handle, ['state' => 'pending']);
            return ['path' => $path, 'handle' => $handle];
        } catch (Throwable $error) {
            if (is_resource($handle)) fclose($handle);
            self::failure($error);
            return null;
        }
    }

    private function write($handle, array $data, bool $sample = true, bool $durable = true): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $context = ['file' => stream_get_meta_data($handle)['uri'], 'bytes' => strlen($json)];
        if (! rewind($handle) || ! FinanceQueryDiagnostics::measure('notification.truncate', fn () => ftruncate($handle, 0), $context, $sample)) throw new \RuntimeException('无法更新财务来源通知。');
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = FinanceQueryDiagnostics::measure('notification.write', fn () => fwrite($handle, substr($json, $offset)), $context, $sample);
            if ($written === false || $written === 0) throw new \RuntimeException('财务来源通知写入不完整。');
            $offset += $written;
        }
        if (! FinanceQueryDiagnostics::measure('notification.flush', fn () => fflush($handle), $context, $sample)
            || ($durable && function_exists('fsync') && ! FinanceQueryDiagnostics::measure('notification.fsync', fn () => fsync($handle), $context, $sample))) throw new \RuntimeException('财务来源通知未能持久保存。');
    }

    public function commit(Connection $db, ?array $ticket, array $events): void
    {
        if ($events === []) { $this->discard($ticket); return; }
        $ticket ??= $this->begin($db);
        if ($ticket === null) {
            self::failure(new \RuntimeException('财务来源通知目录不可写；来源保存已成功，需要恢复通知目录后核对来源变更。'));
            return;
        }
        try { $this->write($ticket['handle'], ['state' => 'committed', 'events' => $events]); }
        catch (Throwable $error) { self::failure($error); }
        finally { if (is_resource($ticket['handle'])) fclose($ticket['handle']); }
    }

    public function discard(?array $ticket): void
    {
        if ($ticket === null) return;
        if (is_resource($ticket['handle'])) fclose($ticket['handle']);
        $this->removeDelivered($ticket['path']);
    }

    public function status(Connection $db): array
    {
        $directory = $this->directory($db); $parent = $directory;
        while (! is_dir($parent) && dirname($parent) !== $parent) $parent = dirname($parent);
        return ['directory' => $directory, 'writable' => is_dir($parent) && is_writable($parent) && is_readable($parent),
            'pending' => count(glob($directory.'/change-*.json') ?: [])];
    }

    /** Only finance readers/workers import committed notifications into finance-owned tables. */
    public function drain(Connection $db, int $limit = 1000): bool
    {
        return FinanceQueryDiagnostics::measure('notifications.drain', fn () => $this->drainNotifications($db, $limit), ['limit' => $limit]);
    }

    private function drainNotifications(Connection $db, int $limit): bool
    {
        // Report readers never import notifications or wait for another consumer.
        if (app('request')->attributes->get('finance.report_read_only') || $db->transactionLevel() > 0) return ! $this->hasPending($db);
        if ($this->draining) return false;
        if (! $this->status($db)['writable']) {
            self::failure(new \RuntimeException('财务来源通知目录不可读写，已停止复用旧汇总；来源系统保存不受此错误阻止。'));
            return false;
        }
        $directory = $this->directory($db);
        if (! is_dir($directory)) return true;
        $consumer = @fopen($directory.'/.consumer.lock', 'c+b');
        if ($consumer === false) { $this->fileFailure('consumer_lock', $directory.'/.consumer.lock', error_get_last()['message'] ?? null); return false; }
        if (! flock($consumer, LOCK_EX | LOCK_NB)) { fclose($consumer); return false; }
        $this->draining = true;
        $trace = FinanceQueryDiagnostics::current();
        $stats = ['seen' => 0, 'processed' => 0, 'locked' => 0, 'delivered' => 0, 'already_delivered' => 0, 'cleanup_failed' => 0, 'transactions' => 0];
        $claimed = [];
        $batchBytes = 0;
        $cleanups = 0;
        $limit = max(1, $limit);
        $batchSize = max(1, min(100, (int) config('finance.summaries.notification_batch_size', 50)));
        try {
            $paths = FinanceQueryDiagnostics::measure('notifications.scan', fn () => glob($directory.'/change-*.json') ?: [], ['directory' => $directory]);
            $trace?->event('notifications.queue', ['directory' => $directory, 'file_count' => count($paths), 'limit' => $limit]);
            foreach ($paths as $path) {
                if (! preg_match('/^change-[a-f0-9-]{36}\.json$/D', basename($path))) continue;
                $stats['seen']++;
                $handle = @fopen($path, 'r+b');
                if ($handle === false) {
                    $error = error_get_last()['message'] ?? null;
                    clearstatcache(true, $path);
                    if (is_file($path)) { $this->fileFailure('open', $path, $error); return false; }
                    continue;
                }
                if (! flock($handle, LOCK_EX | LOCK_NB)) { $stats['locked']++; fclose($handle); continue; }
                try {
                    $json = stream_get_contents($handle);
                    $data = json_decode($json, true);
                    if (($data['state'] ?? null) === 'delivered') {
                        $stats['already_delivered']++;
                        fclose($handle);
                        // Cleanup-only files never consume the delivery limit or starve later changes.
                        if ($cleanups++ < 25 && ! $this->removeDelivered($path)) $stats['cleanup_failed']++;
                        continue;
                    }
                    // An unlocked partial/pending file requires conservative invalidation after a writer exit.
                    $events = ($data['state'] ?? null) === 'committed' ? $data['events'] : [['source' => 'scope_user', 'values' => []]];
                    if ($claimed !== [] && $batchBytes + strlen($json) > 2 * 1024 * 1024) {
                        $this->deliverBatch($db, $claimed, $stats);
                        $batchBytes = 0;
                    }
                    $claimed[] = ['path' => $path, 'handle' => $handle, 'events' => $events];
                    $handle = null; // Ownership transfers to deliverBatch/finally.
                    $batchBytes += strlen($json);
                    if (count($claimed) >= $batchSize || $stats['processed'] + count($claimed) >= $limit) {
                        $this->deliverBatch($db, $claimed, $stats);
                        $batchBytes = 0;
                    }
                    if ($stats['processed'] >= $limit) break;
                } finally {
                    if (is_resource($handle)) fclose($handle);
                }
            }
            if ($claimed !== []) $this->deliverBatch($db, $claimed, $stats);
            return ! $this->hasPending($db);
        } catch (Throwable $error) {
            self::failure($error);
            return false;
        } finally {
            foreach ($claimed as $item) if (is_resource($item['handle'])) fclose($item['handle']);
            $this->draining = false;
            fclose($consumer);
            $trace?->event('notifications.summary', $stats);
        }
    }

    private function deliverBatch(Connection $db, array &$claimed, array &$stats): void
    {
        $trace = FinanceQueryDiagnostics::current();
        $context = ['file' => $claimed[0]['path'], 'file_count' => count($claimed),
            'event_count' => array_sum(array_map(fn ($item) => count($item['events']), $claimed))];
        $commitStarted = null;
        FinanceQueryDiagnostics::measure('notification.transaction', function () use ($db, $claimed, $trace, $context, &$commitStarted): void {
            $db->transaction(function () use ($db, $claimed, $trace, $context, &$commitStarted): void {
                FinanceQueryDiagnostics::measure('notification.insert', function () use ($db, $claimed): void {
                    $rows = [];
                    foreach ($claimed as $item) foreach ($item['events'] as $event) {
                        $rows[] = ['source' => $event['source'], 'payload' => json_encode($event['values'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'scopes_resolved' => false];
                        if (count($rows) === 250) { $db->table('finance_summary_changes')->insert($rows); $rows = []; }
                    }
                    if ($rows !== []) $db->table('finance_summary_changes')->insert($rows);
                }, $context);
                $commitStarted = hrtime(true);
                $trace?->phase('notification.commit', $context, true);
            }, 3);
        }, $context);
        $stats['transactions']++;
        $trace?->event('stage.end', ['stage' => 'notification.commit', ...$context,
            'duration_ms' => $commitStarted === null ? 0 : round((hrtime(true) - $commitStarted) / 1e6, 2)]);
        // The database has durably accepted these events before any file is acknowledged.
        // An acknowledgment lost on machine failure can replay invalidations, but cannot lose source changes.
        foreach ($claimed as $item) {
            $this->write($item['handle'], ['state' => 'delivered'], sample: false, durable: false);
            fclose($item['handle']);
            if (! $this->removeDelivered($item['path'])) $stats['cleanup_failed']++;
            $stats['delivered']++;
            $stats['processed']++;
        }
        $claimed = [];
        $trace?->event('notifications.progress', $stats);
    }

    private function removeDelivered(string $path): bool
    {
        clearstatcache(true, $path);
        if (! is_file($path)) return true;
        error_clear_last();
        $removed = FinanceQueryDiagnostics::measure('notification.unlink', fn () => @unlink($path), ['file' => $path]);
        $error = error_get_last()['message'] ?? null;
        clearstatcache(true, $path);
        // Another process may already have removed it between the existence check and unlink.
        if ($removed || ! is_file($path)) return true;
        $this->fileFailure('unlink', $path, $error);
        return false;
    }

    private function fileFailure(string $operation, string $path, ?string $error): void
    {
        if (! config('finance.diagnostics.enabled', true)) return;
        clearstatcache(true, $path);
        $parent = dirname($path);
        $context = ['operation' => $operation, 'file' => $path, 'system_error' => $error,
            'file_exists_after' => is_file($path), 'directory_writable' => is_writable($parent),
            'directory_mode' => ($mode = @fileperms($parent)) === false ? null : substr(sprintf('%o', $mode), -4),
            'directory_owner' => @fileowner($parent), 'directory_group' => @filegroup($parent),
            'euid' => function_exists('posix_geteuid') ? posix_geteuid() : null, 'pid' => getmypid()];
        if ($trace = FinanceQueryDiagnostics::current()) $trace->event('notification.filesystem_failed', $context);
        else {
            try { \Illuminate\Support\Facades\Log::warning('Finance notification filesystem failure', $context); }
            catch (Throwable) { /* Preserve the original notification handling. */ }
        }
    }

    public function hasPending(Connection $db): bool
    {
        $directory = $this->directory($db);
        if (is_dir($directory) && ! is_readable($directory)) return true;
        foreach (glob($directory.'/change-*.json') ?: [] as $path) {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                clearstatcache(true, $path);
                if (is_file($path)) return true;
                continue;
            }
            try {
                // Writers and other consumers may still be changing this file; never certify stale inputs.
                if (! flock($handle, LOCK_SH | LOCK_NB)) return true;
                $data = json_decode(stream_get_contents($handle), true);
                if (($data['state'] ?? null) !== 'delivered') return true;
            } finally { fclose($handle); }
        }
        return false;
    }
}
