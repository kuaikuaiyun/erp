<?php

use Illuminate\Database\Migrations\Migration;
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
        $previousTimeout = (int) $db->selectOne('SELECT @@SESSION.lock_wait_timeout AS timeout')->timeout;
        $db->statement('SET SESSION lock_wait_timeout = 3');
        try {
            if (! $schema->hasColumn('finance_records', 'finance_is_conflux_order')) {
                $schema->table('finance_records', function (Blueprint $table): void {
                    $table->boolean('finance_is_conflux_order')->virtualAs(
                        "COALESCE(dataset = 'xuzong2-details' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$._card_channel')) = 'ye', 0)"
                    );
                });
            }
            if (! $schema->hasIndex('finance_records', 'finance_conflux_order_match_idx')) {
                $schema->table('finance_records', fn (Blueprint $table) => $table->index(
                    ['finance_is_conflux_order', 'account', 'reference'], 'finance_conflux_order_match_idx'
                ));
            }
        } finally {
            $db->statement('SET SESSION lock_wait_timeout = '.$previousTimeout);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasIndex('finance_records', 'finance_conflux_order_match_idx')) {
            $schema->table('finance_records', fn (Blueprint $table) => $table->dropIndex('finance_conflux_order_match_idx'));
        }
        if ($schema->hasColumn('finance_records', 'finance_is_conflux_order')) {
            $schema->table('finance_records', fn (Blueprint $table) => $table->dropColumn('finance_is_conflux_order'));
        }
    }
};
