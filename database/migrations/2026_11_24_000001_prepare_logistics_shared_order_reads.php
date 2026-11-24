<?php

use App\Portals\Logistics\SharedLogisticsOrderProjection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCES = [
        'logistics_orders' => 'id',
        'logistics_order_items' => 'logistics_order_id',
    ];

    public function getConnection(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
    }

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $schema = $db->getSchemaBuilder();
        if ($schema->hasTable('shop_order_logistics') || $schema->hasTable('shop_order_logistics_pending')
            || $schema->hasColumn('shop_order_items', 'legacy_logistics_item_id')) {
            throw new RuntimeException('Logistics shared read schema already exists.');
        }

        try {
            $this->prepare($db);
        } catch (Throwable $exception) {
            $this->down();

            throw $exception;
        }
    }

    private function prepare(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $schema->table('shop_order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_logistics_item_id')->nullable()->index('idx_soi_legacy_logistics');
        });
        $schema->create('shop_order_logistics_pending', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_id')->primary();
            $table->unsignedBigInteger('revision')->default(1);
        });
        // Source ID zero is the initial synchronization barrier.
        $db->table('shop_order_logistics_pending')->insert(['source_id' => 0]);

        // Keep the logistical fields in their original SQL types and collation.
        $columns = array_diff($schema->getColumnListing('logistics_orders'), [
            ...SharedLogisticsOrderProjection::SHARED_FIELDS,
            ...SharedLogisticsOrderProjection::PAYLOAD_FIELDS,
            'finance_is_credit_card', 'finance_credit_occurred_at', 'finance_is_non_paypal',
        ]);
        $grammar = $db->getQueryGrammar();
        $select = implode(', ', array_map($grammar->wrap(...), $columns));
        $db->statement("CREATE TABLE shop_order_logistics ENGINE=InnoDB AS SELECT {$select} FROM logistics_orders WHERE 1 = 0");
        $db->statement('ALTER TABLE shop_order_logistics ADD PRIMARY KEY (id), ADD INDEX idx_sol_order_date (order_created_at, id), ADD INDEX idx_sol_paid_date (paid_at)');

        foreach (self::SOURCES as $table => $key) {
            foreach (['insert', 'update', 'delete'] as $event) {
                $row = $event === 'delete' ? 'OLD' : 'NEW';
                $mark = $this->markDirty("{$row}.{$key}");
                if ($event === 'update' && $key !== 'id') {
                    $old = $this->markDirty("OLD.{$key}");
                    $mark .= " IF NOT (OLD.{$key} <=> NEW.{$key}) THEN {$old} END IF;";
                }
                $db->unprepared("CREATE TRIGGER sw_{$table}_{$event} AFTER {$event} ON {$table} FOR EACH ROW BEGIN {$mark} END");
            }
        }

        $db->statement('INSERT IGNORE INTO shop_order_logistics_pending (source_id) SELECT id FROM logistics_orders');
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (array_keys(self::SOURCES) as $table) {
            foreach (['insert', 'update', 'delete'] as $event) {
                $db->unprepared("DROP TRIGGER IF EXISTS sw_{$table}_{$event}");
            }
        }
        $schema = $db->getSchemaBuilder();
        $schema->dropIfExists('shop_order_logistics_pending');
        $schema->dropIfExists('shop_order_logistics');
        if ($schema->hasIndex('shop_order_items', 'idx_soi_legacy_logistics')) {
            $schema->table('shop_order_items', fn (Blueprint $table) => $table->dropIndex('idx_soi_legacy_logistics'));
        }
        if ($schema->hasColumn('shop_order_items', 'legacy_logistics_item_id')) {
            $schema->table('shop_order_items', fn (Blueprint $table) => $table->dropColumn('legacy_logistics_item_id'));
        }
    }

    private function markDirty(string $id): string
    {
        return "INSERT INTO shop_order_logistics_pending (source_id) VALUES ({$id}) ON DUPLICATE KEY UPDATE revision = revision + 1;";
    }
};
