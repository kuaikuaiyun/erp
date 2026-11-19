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

        $now = now();
        $fashionParentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_reports'])->value('id');
        if ($fashionParentId) {
            $db->table('portal_menus')->where('id', $fashionParentId)->update([
                'title' => '单包服装项目', 'path' => '#', 'icon' => 'fa-tags', 'sort' => 3, 'is_enabled' => 1, 'updated_at' => $now,
            ]);
            $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_store_analytics'])->update([
                'parent_id' => $fashionParentId, 'title' => '单包服装店铺分析',
                'path' => '/assistant/fashion-store-analytics?project_code=fuzhuang', 'sort' => 1, 'updated_at' => $now,
            ]);
            $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_operation_reports'])->update([
                'parent_id' => $fashionParentId, 'title' => '单包服装运营报表',
                'path' => '/assistant/fashion-operation-reports?project_code=fuzhuang', 'sort' => 2, 'updated_at' => $now,
            ]);
        }

        $permission = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_operation_reports'])->value('permission_slug');
        $parentCode = 'assistant_danbao_general_reports';
        $parentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => $parentCode])->value('id');
        $parentValues = ['parent_id' => 0, 'title' => '单包百货项目', 'title_key' => '', 'path' => '#',
            'permission_slug' => '', 'icon' => 'fa-shopping-bag', 'sort' => 4, 'is_enabled' => 1, 'updated_at' => $now];
        if ($parentId) {
            $db->table('portal_menus')->where('id', $parentId)->update($parentValues);
        } else {
            $parentId = $db->table('portal_menus')->insertGetId(['portal_id' => $portalId, 'code' => $parentCode, ...$parentValues, 'created_at' => $now]);
        }

        foreach ([
            'assistant_danbao_general_store_analytics' => ['单包百货店铺分析', '/assistant/fashion-store-analytics?project_code=danbao-general', 'fa-line-chart', 1],
            'assistant_danbao_general_operation_reports' => ['单包百货运营报表', '/assistant/fashion-operation-reports?project_code=danbao-general', 'fa-bar-chart', 2],
        ] as $code => [$title, $path, $icon, $sort]) {
            $values = ['parent_id' => $parentId, 'title' => $title, 'title_key' => '', 'path' => $path,
                'permission_slug' => $permission, 'icon' => $icon, 'sort' => $sort, 'is_enabled' => 1, 'updated_at' => $now];
            $db->table('portal_menus')->updateOrInsert(['portal_id' => $portalId, 'code' => $code], [...$values, 'created_at' => $now]);
        }
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;

        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_reports'])->update(['title' => '服装项目', 'updated_at' => now()]);
        $db->table('portal_menus')->where('portal_id', $portalId)->whereIn('code', [
            'assistant_danbao_general_store_analytics', 'assistant_danbao_general_operation_reports', 'assistant_danbao_general_reports',
        ])->delete();
    }
};
