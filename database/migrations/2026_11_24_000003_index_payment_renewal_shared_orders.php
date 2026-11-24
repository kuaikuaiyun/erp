<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEXES = [
        ['shop_order_list', ['subscription_origin_order_id', 'id'], 'idx_sopl_renewal_origin'],
        ['shop_order_list', ['order_number'], 'idx_sopl_renewal_number'],
        ['shop_order_logistics', ['shopify_order_id'], 'idx_solg_renewal_shopify'],
        ['shop_order_logistics', ['payment_gateway_order_id'], 'idx_solg_renewal_gateway'],
        ['shop_order_logistics', ['bill_number'], 'idx_solg_renewal_bill'],
    ];

    public function getConnection(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
    }

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        $schema = $db->getSchemaBuilder();
        $mysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        foreach (self::INDEXES as [$name, $columns, $index]) {
            if ($schema->hasIndex($name, $index)) {
                continue;
            }
            // Long references use a prefix for compatibility with older InnoDB key limits.
            $indexColumns = array_map(function (string $column) use ($mysql, $db) {
                return $mysql && in_array($column, ['payment_gateway_order_id', 'bill_number'], true)
                    ? $db->raw($db->getQueryGrammar()->wrap($column).'(100)')
                    : $column;
            }, $columns);
            $schema->table($name, fn (Blueprint $table) => $table->index($indexColumns, $index));
        }
    }

    public function down(): void
    {
        $schema = DB::connection($this->getConnection())->getSchemaBuilder();
        foreach (array_reverse(self::INDEXES) as [$name, $columns, $index]) {
            if ($schema->hasIndex($name, $index)) {
                $schema->table($name, fn (Blueprint $table) => $table->dropIndex($index));
            }
        }
    }
};
