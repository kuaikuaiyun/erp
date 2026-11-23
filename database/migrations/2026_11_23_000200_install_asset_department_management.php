<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portal = $db->table('portals')->where('code', 'asset-manager')->value('id');
        if (!$portal) throw new RuntimeException(__('asset_accounts.install_required', [], 'zh-CN'));
        if (!$db->table('portal_menus')->where('portal_id', $portal)->where('code', 'asset_manager_departments')->exists()) {
            $db->table('portal_menus')->insert(['portal_id' => $portal,'parent_id' => 0,'code' => 'asset_manager_departments','title' => null,
                'title_key' => 'asset_accounts.department_title','path' => '/asset-manager/departments','permission_slug' => 'asset-manager.computers',
                'icon' => 'fa-sitemap','sort' => 75,'is_enabled' => true,'created_at' => now('UTC'),'updated_at' => now('UTC')]);
        }
    }
    public function down(): void
    {
        DB::connection(config('admin.database.connection') ?: config('database.default'))->table('portal_menus')->where('code', 'asset_manager_departments')->update(['is_enabled' => false]);
    }
};
