<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $db = $schema->getConnection();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $summaryLock = hash('sha256', 'finance:summaries:refresh:'.$db->getDatabaseName());
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 3) AS acquired', [$summaryLock])->acquired !== 1) {
            throw new RuntimeException('Finance summary refresh is still running. Retry this migration after it finishes.');
        }
        $previousTimeout = (int) $db->selectOne('SELECT @@SESSION.lock_wait_timeout AS timeout')->timeout;
        $db->statement('SET SESSION lock_wait_timeout = 3');
        try {
            foreach (['merchant' => '商户订单号', 'legacy' => '_legacy_order_id'] as $kind => $field) {
                $column = 'finance_xuzong_'.$kind.'_number';
                $index = 'finance_xuzong_'.$kind.'_lookup_idx';
                if (! $schema->hasColumn('finance_records', $column)) {
                    $path = '$."'.$field.'"';
                    $schema->table('finance_records', function (Blueprint $table) use ($column, $path): void {
                        $table->text($column)->nullable()->collation('utf8mb4_bin')->virtualAs(
                            "CASE WHEN dataset = 'xuzong-credit-imported-orders' AND JSON_TYPE(JSON_EXTRACT(payload, '{$path}')) <> 'NULL' THEN JSON_UNQUOTE(JSON_EXTRACT(payload, '{$path}')) ELSE NULL END"
                        );
                    });
                }
                if (! $schema->hasIndex('finance_records', $index)) {
                    $schema->table('finance_records', fn (Blueprint $table) => $table->index(
                        ['dataset', new Expression($column.'(191)')], $index
                    ));
                }
            }
        } finally {
            $db->statement('SET SESSION lock_wait_timeout = '.$previousTimeout);
            $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$summaryLock]);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        foreach (['merchant', 'legacy'] as $kind) {
            $column = 'finance_xuzong_'.$kind.'_number';
            $index = 'finance_xuzong_'.$kind.'_lookup_idx';
            if ($schema->hasIndex('finance_records', $index)) {
                $schema->table('finance_records', fn (Blueprint $table) => $table->dropIndex($index));
            }
            if ($schema->hasColumn('finance_records', $column)) {
                $schema->table('finance_records', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
