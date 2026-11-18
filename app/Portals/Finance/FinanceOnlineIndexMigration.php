<?php

namespace App\Portals\Finance;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use PDO;
use RuntimeException;
use Throwable;

abstract class FinanceOnlineIndexMigration extends Migration
{
    public $withinTransaction = false;

    private ?PDO $lockedPdo = null;

    private ?array $previousTimeouts = null;

    private bool $heldForBatch = false;

    private bool $preparedForBatch = false;

    /** Only additive, optional performance columns and secondary indexes belong here. */
    abstract protected function operations(): array;

    protected function database(): Connection
    {
        return app(FinanceWorkbookService::class)->database();
    }

    public function shouldRun(): bool
    {
        $db = $this->database();
        if (! $this->supported($db)) {
            return true;
        }
        try {
            $this->acquire($db);
            // Keep the lease through Laravel's subsequent write to the migrations table.
            $this->heldForBatch = true;
            Event::listen(MigrationsEnded::class, function (MigrationsEnded $event) use ($db): void {
                if ($event->method === 'up') {
                    $this->release($db);
                }
            });
            app()->terminating(fn () => $this->release($db));
            // Preparing here lets even a lock race return SKIPPED without aborting the deployment
            // or letting Laravel log unfinished DDL as a successful migration.
            $this->apply(false);
            $this->preparedForBatch = true;
            return true;
        } catch (FinanceIndexMigrationDeferred $exception) {
            $this->release($db);
            echo '  财务性能迁移延后：'.$exception->getMessage().PHP_EOL;
            // Laravel leaves skipped migrations out of the migrations table.
            return false;
        } catch (Throwable $exception) {
            $this->release($db);
            throw $exception;
        }
    }

    public function up(): void
    {
        if (! $this->preparedForBatch || $this->database()->pretending()) {
            $this->apply(false);
        }
    }

    public function down(): void
    {
        $this->apply(true);
    }

    private function supported(Connection $db): bool
    {
        return in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function pending(Connection $db, bool $down): array
    {
        $schema = $db->getSchemaBuilder();
        $operations = $down ? array_reverse($this->operations()) : $this->operations();
        return array_values(array_filter($operations, function (array $operation) use ($schema, $down): bool {
            if (isset($operation['when_column']) && ! $schema->hasColumn($operation['table'], $operation['when_column'])) {
                return false;
            }
            $exists = $operation['kind'] === 'column'
                ? $schema->hasColumn($operation['table'], $operation['name'])
                : $schema->hasIndex($operation['table'], $operation['name']);
            return $down ? $exists : ! $exists;
        }));
    }

    private function acquire(Connection $db): void
    {
        if ($this->lockedPdo !== null) {
            $this->assertConnection($db);
            return;
        }
        if ($db->transactionLevel() !== 0) {
            throw new FinanceIndexMigrationDeferred('当前连接仍在事务中，未执行改表。');
        }
        $acquired = $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$this->lockName($db)]);
        if ((int) ($acquired->acquired ?? 0) !== 1) {
            throw new FinanceIndexMigrationDeferred('另一条财务索引迁移正在运行，未排队改表。');
        }
        $this->lockedPdo = $db->getPdo();
    }

    private function assertConnection(Connection $db): void
    {
        if ($db->getPdo() !== $this->lockedPdo) {
            throw new FinanceIndexMigrationDeferred('数据库连接已变化，等待下一次安全重试。');
        }
    }

    private function apply(bool $down): void
    {
        $db = $this->database();
        if (! $this->supported($db)) {
            return;
        }
        if ($db->pretending()) {
            foreach ($this->operations() as $operation) {
                $db->statement($this->alterSql($db, $operation, $down));
            }
            return;
        }
        $succeeded = false;
        try {
            $this->acquire($db);
            $settings = $db->selectOne('SELECT @@SESSION.lock_wait_timeout AS metadata_timeout, @@SESSION.innodb_lock_wait_timeout AS row_timeout');
            $this->assertConnection($db);
            $this->previousTimeouts = [(int) $settings->metadata_timeout, (int) $settings->row_timeout];
            $db->statement('SET SESSION lock_wait_timeout = 1');
            $db->statement('SET SESSION innodb_lock_wait_timeout = 1');
            // Schema inspection can itself need metadata locks, so bound it as well as ALTER.
            $operations = $this->pending($db, $down);
            foreach ($operations as $operation) {
                $table = $operation['table'];
                $engine = $db->selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$db->getDatabaseName(), $db->getTablePrefix().$table]);
                if (strtoupper($engine->engine ?? '') !== 'INNODB') {
                    throw new RuntimeException('财务在线索引迁移要求 InnoDB 表，未执行锁表或复制表操作：'.$table);
                }
                $this->assertConnection($db);
                // Direct PDO execution prevents an automatic reconnect from losing the session safeguards.
                $this->lockedPdo->exec($this->alterSql($db, $operation, $down));
            }
            $succeeded = true;
        } catch (Throwable $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new FinanceIndexMigrationDeferred('改表期间出现并发事务，已退出并释放迁移锁，稍后自动重试。', previous: $exception);
            }
            // In particular, never fall back to COPY or a stronger table lock on unsupported servers.
            throw $exception;
        } finally {
            try {
                $this->restoreTimeouts($db);
            } finally {
                if (! $succeeded || ! $this->heldForBatch) {
                    $this->release($db);
                }
            }
        }
    }

    private function alterSql(Connection $db, array $operation, bool $down): string
    {
        $grammar = $db->getQueryGrammar();
        $name = $grammar->wrap($operation['name']);
        if ($down) {
            $change = 'DROP '.($operation['kind'] === 'column' ? 'COLUMN ' : 'INDEX ').$name;
        } elseif ($operation['kind'] === 'column') {
            $change = 'ADD COLUMN '.$name.' '.$operation['definition'];
        } else {
            $columns = array_map(function (string $column) use ($grammar): string {
                if (! preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(\([1-9][0-9]*\))?$/D', $column, $parts)) {
                    throw new RuntimeException('Invalid finance index column.');
                }
                return $grammar->wrap($parts[1]).($parts[2] ?? '');
            }, $operation['columns']);
            $change = 'ADD INDEX '.$name.' ('.implode(', ', $columns).')';
        }
        return 'ALTER TABLE '.$grammar->wrapTable($operation['table']).' '.$change.', ALGORITHM=INPLACE, LOCK=NONE';
    }

    private function lockName(Connection $db): string
    {
        return hash('sha256', 'finance:indexes:apply:'.$db->getDatabaseName());
    }

    private function release(Connection $db): void
    {
        if ($this->lockedPdo === null) {
            return;
        }
        try {
            try {
                $this->restoreTimeouts($db);
            } finally {
                if ($db->getPdo() === $this->lockedPdo) {
                    $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->lockName($db)]);
                }
            }
        } finally {
            $this->lockedPdo = null;
            $this->previousTimeouts = null;
            $this->heldForBatch = false;
            $this->preparedForBatch = false;
        }
    }

    private function restoreTimeouts(Connection $db): void
    {
        $settings = $this->previousTimeouts;
        $this->previousTimeouts = null;
        if ($settings !== null && $db->getPdo() === $this->lockedPdo) {
            try {
                $db->statement('SET SESSION lock_wait_timeout = '.$settings[0]);
            } finally {
                $db->statement('SET SESSION innodb_lock_wait_timeout = '.$settings[1]);
            }
        }
    }
}
