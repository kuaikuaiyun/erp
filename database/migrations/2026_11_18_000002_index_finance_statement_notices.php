<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $db = $schema->getConnection();
        $previousTimeout = (int) $db->selectOne('SELECT @@SESSION.lock_wait_timeout AS timeout')->timeout;
        $db->statement('SET SESSION lock_wait_timeout = 3');
        try {
            if (! $schema->hasColumn('finance_records', 'finance_has_statement_notice')) {
                $schema->table('finance_records', function (Blueprint $table): void {
                    $table->boolean('finance_has_statement_notice')->virtualAs(
                        "COALESCE(JSON_TYPE(JSON_EXTRACT(payload, '$._xuzong_statement_import.notice')) <> 'NULL', 0)"
                    );
                });
            }
            if (! $schema->hasIndex('finance_records', 'finance_statement_notice_page_idx')) {
                $schema->table('finance_records', fn (Blueprint $table) => $table->index(
                    ['dataset', 'finance_has_statement_notice', 'updated_at', 'id'], 'finance_statement_notice_page_idx'
                ));
            }
        } finally {
            $db->statement('SET SESSION lock_wait_timeout = '.$previousTimeout);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasIndex('finance_records', 'finance_statement_notice_page_idx')) {
            $schema->table('finance_records', fn (Blueprint $table) => $table->dropIndex('finance_statement_notice_page_idx'));
        }
        if ($schema->hasColumn('finance_records', 'finance_has_statement_notice')) {
            $schema->table('finance_records', fn (Blueprint $table) => $table->dropColumn('finance_has_statement_notice'));
        }
    }
};
