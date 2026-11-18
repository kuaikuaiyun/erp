<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'asset-manager')->value('id');
        if (! $portalId) {
            throw new RuntimeException(__('asset_manager.missing_portal', [], 'zh-CN'));
        }
        $menu = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'asset_manager_discipline');
        if (! (clone $menu)->exists()) {
            throw new RuntimeException(__('asset_manager.discipline_menu_required', [], 'zh-CN'));
        }
        $menu->update(['permission_slug' => 'asset-manager.computers', 'updated_at' => now('UTC')]);
        // Preserve each menu's title, enabled state, grouping and all existing grants.
    }

    // Keep the unified access policy and all historical permissions on rollback.
    public function down(): void {}
};
