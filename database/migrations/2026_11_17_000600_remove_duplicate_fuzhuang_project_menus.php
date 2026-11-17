<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'fuzhuang')->value('id');

        if (! $portalId) {
            return;
        }

        $db->table('portal_menus')
            ->where('portal_id', $portalId)
            ->whereIn('code', [
                'fuzhuang_advertising_expenses',
                'fuzhuang_performance_report',
                'fuzhuang_stores',
            ])
            ->delete();
    }

    public function down(): void
    {
        // 原有 fashion_* 菜单始终保留；回滚时不重新制造重复入口。
    }
};
