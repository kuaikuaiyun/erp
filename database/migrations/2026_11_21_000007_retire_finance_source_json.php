<?php

use App\Portals\Finance\FinanceSourceMigration;
use App\Portals\Finance\FinanceSourceStorage;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('production') && ! app()->isDownForMaintenance()) {
            throw new RuntimeException('财务来源切换需要暂停业务写入：请先 php artisan down，迁移及核对完成后 php artisan up。');
        }
        $db = app(FinanceWorkbookService::class)->database(); $schema = $db->getSchemaBuilder();
        $migration = app(FinanceSourceMigration::class); $storage = app(FinanceSourceStorage::class);
        if ($db->table('finance_source_storage')->whereIn('kind', array_keys(FinanceSourceStorage::SOURCES))
            ->whereNotIn('state', ['ready', 'active'])->exists()) $migration->migrate();
        // Check every source before issuing any irreversible column removal.
        foreach (array_keys(FinanceSourceStorage::SOURCES) as $kind) $migration->verify($kind);
        $generated = ['finance_has_statement_notice', 'finance_is_conflux_order', 'finance_xuzong_merchant_number', 'finance_xuzong_legacy_number'];
        $indexes = [];
        foreach ($schema->getIndexes('finance_records') as $index) {
            if (array_intersect($generated, $index['columns']) !== []) $indexes[] = $index['name'];
        }
        $generated = array_values(array_intersect($generated, $schema->getColumnListing('finance_records')));
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $grammar = $db->getQueryGrammar();
            $drops = array_map(fn ($index) => 'DROP INDEX '.$grammar->wrap($index), $indexes);
            // MySQL 5.7 requires generated dependencies to be retired in an earlier ALTER.
            foreach ($generated as $column) $drops[] = 'DROP COLUMN '.$grammar->wrap($column);
            if ($drops !== []) $db->statement('ALTER TABLE '.$grammar->wrapTable('finance_records').' '.implode(', ', $drops));
        } else {
            foreach ($indexes as $index) $schema->table('finance_records', fn (Blueprint $table) => $table->dropIndex($index));
            foreach ($generated as $column) $schema->table('finance_records', fn (Blueprint $table) => $table->dropColumn($column));
        }
        foreach (FinanceSourceStorage::SOURCES as $kind => [$table, $field]) {
            if ($schema->hasColumn($table, $field)) $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($field));
            $storage->setState($kind, 'active');
        }
        // No trigger DDL or server configuration is used by this migration.
    }

    public function down(): void {} // Source data now lives in the ordinary detail tables; do not discard it.
};
