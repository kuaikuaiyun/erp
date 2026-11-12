<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;
        $parentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_reports'])->value('id');
        if (! $parentId) return;
        $sourceMenu = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_operation_reports'])->first(['permission_slug']);
        $now = now();
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'assistant_fashion_store_analytics'],
            ['parent_id' => $parentId, 'title' => '服装店铺分析', 'title_key' => '',
                'path' => '/assistant/fashion-store-analytics', 'permission_slug' => $sourceMenu?->permission_slug,
                'icon' => 'fa-line-chart', 'sort' => 1, 'is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now]
        );
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_operation_reports'])
            ->update(['sort' => 2, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if ($portalId) $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_store_analytics'])->delete();
    }
};
