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
            throw new RuntimeException(__('asset_accounts.install_required', [], 'zh-CN'));
        }
        if (! $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'asset_manager_accounts')->exists()) {
            $db->table('portal_menus')->insert([
                'portal_id' => $portalId, 'parent_id' => 0, 'code' => 'asset_manager_accounts', 'title' => null,
                'title_key' => 'asset_accounts.title', 'path' => '/asset-manager/accounts', 'permission_slug' => 'asset-manager.computers',
                'icon' => 'fa-users', 'sort' => 70, 'is_enabled' => true, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        }
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'asset-manager')->value('id');
        if ($portalId) {
            $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'asset_manager_accounts')->update(['is_enabled' => false, 'updated_at' => now('UTC')]);
        }
        // Existing shared accounts, memberships and DingTalk data are never removed.
    }
};
