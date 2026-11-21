<?php

use App\Portals\Finance\FinanceSourceStorage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default')); $schema = $db->getSchemaBuilder();
        foreach (FinanceSourceStorage::definitions() as $definition) {
            foreach ([$definition, ...array_map(fn ($collection) => [...$collection, 'child' => true], array_values($definition['collections']))] as $part) {
                if ($schema->hasTable($part['table'])) continue;
                $schema->create($part['table'], function (Blueprint $table) use ($part, $db): void {
                    $table->engine = in_array($db->getDriverName(), ['mysql', 'mariadb'], true) ? 'InnoDB ROW_FORMAT=DYNAMIC' : 'InnoDB';
                    $table->unsignedBigInteger('entity_id');
                    if (! empty($part['child'])) {
                        $table->string('item_key', 191); $table->boolean('key_type'); $table->unsignedInteger('position');
                        $table->primary(['entity_id', 'position']);
                    } else $table->primary('entity_id');
                    $table->binary('field_types');
                    foreach ($part['paths'] as $column) {
                        $field = $table->mediumText($column)->nullable();
                        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) $field->collation('utf8mb4_bin');
                    }
                });
            }
        }
        if (! $schema->hasTable('finance_source_extensions')) $schema->create('finance_source_extensions', function (Blueprint $table) use ($db): void {
            $table->engine = 'InnoDB'; $table->string('kind', 12); $table->unsignedBigInteger('entity_id');
            $table->text('path'); $table->char('path_hash', 64); $table->unsignedTinyInteger('value_type');
            $value = $table->mediumText('value')->nullable();
            if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) $value->collation('utf8mb4_bin');
            $table->unsignedInteger('position');
            $table->primary(['kind', 'entity_id', 'path_hash']);
        });
        if (! $schema->hasTable('finance_source_storage')) $schema->create('finance_source_storage', function (Blueprint $table): void {
            $table->engine = 'InnoDB'; $table->string('kind', 12)->primary();
            $table->string('state', 20)->default('building'); $table->unsignedBigInteger('last_id')->default(0);
        });
        foreach (FinanceSourceStorage::SOURCES as $kind => [$table]) {
            if (! $schema->hasColumn($table, 'source_hash')) $schema->table($table, fn (Blueprint $blueprint) => $blueprint->char('source_hash', 64)->nullable());
            $db->table('finance_source_storage')->insertOrIgnore(['kind' => $kind, 'state' => 'building', 'last_id' => 0]);
        }
        $recordColumns = array_values(array_diff(['source_channel', 'source_bill', 'source_merchant', 'source_shopify', 'source_parent', 'source_reference', 'source_legacy'], $schema->getColumnListing('finance_records')));
        if ($recordColumns !== []) $schema->table('finance_records', function (Blueprint $table) use ($recordColumns, $db): void {
            foreach ($recordColumns as $column) {
                $field = $column === 'source_channel' ? $table->string($column, 20)->nullable() : $table->text($column)->nullable();
                if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) $field->collation('utf8mb4_bin');
            }
        });
        $recordColumns = array_values(array_diff(['source_notice', 'source_conflux_order', 'amount'], $schema->getColumnListing('finance_records')));
        if ($recordColumns !== []) $schema->table('finance_records', function (Blueprint $table) use ($recordColumns): void {
            foreach ($recordColumns as $column) {
                if ($column === 'amount') $table->decimal($column, 38, 16)->nullable();
                else $table->boolean($column)->default(false);
            }
        });
        $paymentColumns = array_values(array_diff(['related_id', 'related_type', 'invoice_id', 'amount_currency', 'transaction_status', 'transaction_updated_date', 'amount', 'fee_amount'], $schema->getColumnListing('finance_payment_transactions')));
        if ($paymentColumns !== []) $schema->table('finance_payment_transactions', function (Blueprint $table) use ($paymentColumns): void {
            foreach ($paymentColumns as $column) {
                if (in_array($column, ['amount', 'fee_amount'], true)) $table->decimal($column, 38, 16)->nullable();
                else $table->string($column, 191)->nullable();
            }
        });
        foreach (['source_conflux_match_idx' => ['source_conflux_order', 'account', 'reference'],
            'source_statement_notice_idx' => ['dataset', 'source_notice', 'updated_at', 'id']] as $name => $columns) {
            if (! $schema->hasIndex('finance_records', $name)) $schema->table('finance_records', fn (Blueprint $table) => $table->index($columns, $name));
        }
        foreach (['related_id', 'invoice_id'] as $column) {
            $name = 'finance_payment_'.$column.'_idx';
            if (! $schema->hasIndex('finance_payment_transactions', $name)) $schema->table('finance_payment_transactions', fn (Blueprint $table) => $table->index($column, $name));
        }
        app('request')->attributes->remove('finance.source-storage:'.$db->getName().':'.$db->getDatabaseName());
    }

    public function down(): void {}
};
