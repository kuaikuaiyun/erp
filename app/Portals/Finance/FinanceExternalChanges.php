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

    private function write($handle, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (! rewind($handle) || ! ftruncate($handle, 0)) throw new \RuntimeException('无法更新财务来源通知。');
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = fwrite($handle, substr($json, $offset));
            if ($written === false || $written === 0) throw new \RuntimeException('财务来源通知写入不完整。');
            $offset += $written;
        }
        if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) throw new \RuntimeException('财务来源通知未能持久保存。');
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
        if (is_file($ticket['path']) && ! @unlink($ticket['path'])) self::failure(new \RuntimeException('无法清理已回滚的财务来源通知。'));
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
        if ($this->draining) return false;
        if ($db->transactionLevel() > 0) return (glob($this->directory($db).'/change-*.json') ?: []) === [];
        if (! $this->status($db)['writable']) {
            self::failure(new \RuntimeException('财务来源通知目录不可读写，已停止复用旧汇总；来源系统保存不受此错误阻止。'));
            return false;
        }
        $this->draining = true;
        try {
            $processed = 0;
            foreach (glob($this->directory($db).'/change-*.json') ?: [] as $path) {
                if (! preg_match('/^change-[a-f0-9-]{36}\.json$/D', basename($path))) continue;
                $handle = @fopen($path, 'r+b');
                if ($handle === false) { if (is_file($path)) return false; continue; }
                if (! flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); continue; }
                try {
                    $data = json_decode(stream_get_contents($handle), true);
                    if (($data['state'] ?? null) !== 'delivered') {
                        // An unlocked pending/partial file belongs to an exited writer. Never silently reuse its old cache.
                        $events = ($data['state'] ?? null) === 'committed' ? $data['events'] : [['source' => 'scope_user', 'values' => []]];
                        $db->transaction(function () use ($db, $events): void {
                            foreach (array_chunk($events, 250) as $batch) {
                                $rows = array_map(fn ($event) => ['source' => $event['source'],
                                    'payload' => json_encode($event['values'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'scopes_resolved' => false], $batch);
                                if ($rows !== []) $db->table('finance_summary_changes')->insert($rows);
                            }
                        }, 3);
                        $this->write($handle, ['state' => 'delivered']);
                    }
                } catch (Throwable $error) {
                    fclose($handle); self::failure($error); return false;
                }
                fclose($handle);
                if (is_file($path) && ! @unlink($path)) self::failure(new \RuntimeException('无法清理已投递的财务来源通知。'));
                if (++$processed >= $limit) return ! $this->hasReady($db);
            }
            return true;
        } finally { $this->draining = false; }
    }

    private function hasReady(Connection $db): bool
    {
        foreach (glob($this->directory($db).'/change-*.json') ?: [] as $path) {
            $handle = @fopen($path, 'r+b');
            if ($handle === false) { if (is_file($path)) return true; continue; }
            $ready = flock($handle, LOCK_EX | LOCK_NB);
            fclose($handle);
            if ($ready) return true;
        }
        return false;
    }
}
