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
        $permission = $db->table('portal_menus')->where([
            'portal_id' => $portalId, 'code' => 'assistant_fashion_operation_reports',
        ])->value('permission_slug');

        foreach ([
            'baokuan-fashion' => ['爆款服装项目', 'assistant_baokuan_fashion_reports', 'fa-diamond', 5],
            'baokuan-general' => ['爆款百货项目', 'assistant_baokuan_general_reports', 'fa-star', 6],
        ] as $projectCode => [$parentTitle, $parentCode, $parentIcon, $parentSort]) {
            $parentValues = ['parent_id' => 0, 'title' => $parentTitle, 'title_key' => '', 'path' => '#',
                'permission_slug' => '', 'icon' => $parentIcon, 'sort' => $parentSort, 'is_enabled' => 1, 'updated_at' => $now];
            $parentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => $parentCode])->value('id');
            if ($parentId) $db->table('portal_menus')->where('id', $parentId)->update($parentValues);
            else $parentId = $db->table('portal_menus')->insertGetId(['portal_id' => $portalId, 'code' => $parentCode, ...$parentValues, 'created_at' => $now]);

            $prefix = $projectCode === 'baokuan-fashion' ? 'assistant_baokuan_fashion' : 'assistant_baokuan_general';
            $label = $projectCode === 'baokuan-fashion' ? '爆款服装' : '爆款百货';
            foreach ([
                $prefix.'_store_analytics' => [$label.'店铺分析', '/assistant/fashion-store-analytics?project_code='.$projectCode, 'fa-line-chart', 1],
                $prefix.'_operation_reports' => [$label.'运营报表', '/assistant/fashion-operation-reports?project_code='.$projectCode, 'fa-bar-chart', 2],
            ] as $code => [$title, $path, $icon, $sort]) {
                $values = ['parent_id' => $parentId, 'title' => $title, 'title_key' => '', 'path' => $path,
                    'permission_slug' => $permission, 'icon' => $icon, 'sort' => $sort, 'is_enabled' => 1, 'updated_at' => $now];
                $db->table('portal_menus')->updateOrInsert(['portal_id' => $portalId, 'code' => $code], [...$values, 'created_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;
        $db->table('portal_menus')->where('portal_id', $portalId)->whereIn('code', [
            'assistant_baokuan_fashion_store_analytics', 'assistant_baokuan_fashion_operation_reports', 'assistant_baokuan_fashion_reports',
            'assistant_baokuan_general_store_analytics', 'assistant_baokuan_general_operation_reports', 'assistant_baokuan_general_reports',
        ])->delete();
    }
};
