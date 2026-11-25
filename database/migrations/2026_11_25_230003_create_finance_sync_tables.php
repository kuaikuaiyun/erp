<?php

use App\Services\FinanceSync\FinanceSyncCatalog as Catalog;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function getConnection()
    {
        return config('admin.database.connection') ?: config('database.default');
    }

    public function up(): void
    {
        $db = Catalog::connection();
        $schema = $db->getSchemaBuilder();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException('finance_sync_unsupported_database');
        }

        $sources = $this->sources();
        foreach ($sources as [$table, $columns]) {
            if (! $schema->hasColumns($table, $columns)) {
                throw new RuntimeException('finance_sync_missing_source: '.$table);
            }
        }

        $tables = [];
        $triggers = [];
        try {
            $schema->create(Catalog::PENDING, function (Blueprint $table): void {
                $table->id();
                $table->string('resource', 32);
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('revision')->default(1);
                $table->unique(['resource', 'source_id'], 'fs_pending_resource_source_uq');
            });
            $tables[] = Catalog::PENDING;

            $schema->create(Catalog::RECORDS, function (Blueprint $table): void {
                $table->string('resource', 32);
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('version');
                $table->string('change_type', 12);
                $table->boolean('is_deleted');
                $table->longText('payload')->nullable();
                $table->dateTime('changed_at');
                $table->primary(['resource', 'source_id']);
                $table->index(['resource', 'is_deleted', 'source_id'], 'fs_records_snapshot_ix');
                $table->index(['resource', 'version'], 'fs_records_changes_ix');
            });
            $tables[] = Catalog::RECORDS;

            $schema->create(Catalog::STATE, function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->uuid('dataset');
                $table->unsignedBigInteger('version')->default(0);
                $table->unsignedInteger('source_index')->default(0);
                $table->unsignedBigInteger('source_after_id')->default(0);
                $table->unsignedBigInteger('source_until_id')->nullable();
                $table->string('publication_profile', 64)->nullable();
                $table->boolean('ready')->default(false);
            });
            $tables[] = Catalog::STATE;
            $db->table(Catalog::STATE)->insert(['id' => 1, 'dataset' => (string) Str::uuid()]);

            foreach ($sources as $resource => [$table, $columns]) {
                foreach (['insert', 'update', 'delete'] as $operation) {
                    $name = $this->triggerName($table, $operation);
                    $this->createTrigger($db, $name, $table, $operation, $resource, $columns);
                    $triggers[] = $name;
                }
            }
        } catch (Throwable $error) {
            // MySQL DDL commits implicitly. Clean up this migration's partial installation.
            foreach (array_reverse($triggers) as $trigger) {
                $this->dropTrigger($db, $trigger);
            }
            foreach (array_reverse($tables) as $table) {
                $schema->dropIfExists($table);
            }

            throw $error;
        }
    }

    public function down(): void
    {
        $db = Catalog::connection();
        foreach ($this->sources() as [$table]) {
            foreach (['insert', 'update', 'delete'] as $operation) {
                $this->dropTrigger($db, $this->triggerName($table, $operation));
            }
        }

        $schema = $db->getSchemaBuilder();
        foreach ([Catalog::STATE, Catalog::RECORDS, Catalog::PENDING] as $table) {
            $schema->dropIfExists($table);
        }
    }

    private function sources(): array
    {
        $sources = [];
        foreach (Catalog::RESOURCES as $resource => [$table]) {
            $sources[$resource] = [$table, Catalog::columns($resource, true)];
        }

        // The ledger is used only as a source for the matching order's payment fields.
        $sources[Catalog::PAYPAL_LEDGER] = [Catalog::PAYPAL_LEDGER, [
            'logistics_order_id', 'logistics_paypal_account_id', 'payment_transaction_id',
            'status', 'original_amount', 'currency_code',
        ]];

        return $sources;
    }

    private function triggerName(string $table, string $operation): string
    {
        return 'fs_'.$table.'_'.$operation;
    }

    private function createTrigger(
        Connection $db,
        string $name,
        string $table,
        string $operation,
        string $resource,
        array $columns,
    ): void {
        $grammar = $db->getQueryGrammar();
        $sqlite = $db->getDriverName() === 'sqlite';
        $equal = $sqlite ? 'IS' : '<=>';
        $change = $operation === 'update'
            ? 'NOT ('.implode(' AND ', array_map(function (string $column) use ($grammar, $equal, $sqlite): string {
                $column = $grammar->wrap($column);

                return $sqlite ? "OLD.$column COLLATE BINARY $equal NEW.$column COLLATE BINARY"
                    : "BINARY OLD.$column $equal BINARY NEW.$column";
            }, $columns)).')'
            : '1 = 1';
        $current = $operation === 'delete' ? 'OLD' : 'NEW';
        $statements = [];

        if ($resource !== Catalog::PAYPAL_LEDGER) {
            $statements[] = $this->queueSql($db, $resource, "$current.id", $change);
            if ($operation === 'update') {
                $statements[] = $this->queueSql($db, $resource, 'OLD.id', 'OLD.id <> NEW.id');
            }
        }
        if (in_array($resource, [Catalog::REFERENCES, Catalog::PAYPAL_LEDGER], true)) {
            $statements[] = $this->queueSql($db, Catalog::ORDERS, "$current.logistics_order_id", $change);
            if ($operation === 'update') {
                $statements[] = $this->queueSql(
                    $db, Catalog::ORDERS, 'OLD.logistics_order_id', "NOT (OLD.logistics_order_id $equal NEW.logistics_order_id)",
                );
            }
        }

        $table = $grammar->wrapTable($table);
        $trigger = $grammar->wrap($name);
        $body = implode(' ', $statements);
        $db->unprepared("CREATE TRIGGER $trigger AFTER $operation ON $table FOR EACH ROW BEGIN $body END");
    }

    private function queueSql(Connection $db, string $resource, string $id, string $condition): string
    {
        $table = $db->getQueryGrammar()->wrapTable(Catalog::PENDING);
        $sqlite = $db->getDriverName() === 'sqlite';
        $from = $sqlite ? '' : ' FROM DUAL';
        $upsert = $sqlite ? 'ON CONFLICT(resource, source_id) DO UPDATE SET revision = revision + 1'
            : 'ON DUPLICATE KEY UPDATE revision = revision + 1';

        return "INSERT INTO $table (resource, source_id, revision) SELECT '$resource', $id, 1$from"
            ." WHERE $id IS NOT NULL AND ($condition) $upsert;";
    }

    private function dropTrigger(Connection $db, string $name): void
    {
        $trigger = $db->getQueryGrammar()->wrap($name);
        $db->unprepared("DROP TRIGGER IF EXISTS $trigger");
    }
};
