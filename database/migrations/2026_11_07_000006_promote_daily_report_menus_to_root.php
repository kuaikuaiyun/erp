<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MENU_CODES = [
        'daily_report_advertising_recharge',
        'daily_report_account_opening',
        'daily_report_store_launch',
        'daily_report_ad_account_auth',
        'daily_report_account_store_transfer',
    ];

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'daily-report')->value('id');
        if (! $portalId) return;

        $db->table('portal_menus')
            ->where('portal_id', $portalId)
            ->whereIn('code', self::MENU_CODES)
            ->update(['parent_id' => 0, 'is_enabled' => 1, 'updated_at' => now()]);

        $db->table('portal_menus')
            ->where(['portal_id' => $portalId, 'code' => 'daily_report_submissions'])
            ->update(['is_enabled' => 0, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'daily-report')->value('id');
        if (! $portalId) return;
        $parentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'daily_report_submissions'])->value('id');
        if (! $parentId) return;
        $db->table('portal_menus')->where('portal_id', $portalId)->whereIn('code', self::MENU_CODES)->update(['parent_id' => $parentId, 'updated_at' => now()]);
        $db->table('portal_menus')->where('id', $parentId)->update(['is_enabled' => 1, 'updated_at' => now()]);
    }
};
