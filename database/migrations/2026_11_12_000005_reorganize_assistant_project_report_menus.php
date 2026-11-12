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
        $shopifyParentId = $db->table('portal_menus')->where([
            'portal_id' => $portalId, 'code' => 'assistant_shopify_operation_reports',
        ])->value('id');
        if ($shopifyParentId) {
            $db->table('portal_menus')->where('id', $shopifyParentId)->update([
                'parent_id' => 0, 'title' => 'Shopify订阅项目', 'path' => '#',
                'icon' => 'fa-shopping-bag', 'sort' => 2, 'is_enabled' => 1, 'updated_at' => $now,
            ]);
            $db->table('portal_menus')->where([
                'portal_id' => $portalId, 'code' => 'assistant_shopify_analytics',
            ])->update([
                'parent_id' => $shopifyParentId, 'title' => 'Shopify店铺分析',
                'sort' => 1, 'updated_at' => $now,
            ]);
            $db->table('portal_menus')->where([
                'portal_id' => $portalId, 'code' => 'assistant_operation_reports',
            ])->update([
                'parent_id' => $shopifyParentId, 'title' => 'Shopify运营报表',
                'sort' => 2, 'updated_at' => $now,
            ]);
        }

        $fashionParentId = $db->table('portal_menus')->where([
            'portal_id' => $portalId, 'code' => 'assistant_fashion_reports',
        ])->value('id');
        if ($fashionParentId) {
            $db->table('portal_menus')->where('id', $fashionParentId)->update([
                'parent_id' => 0, 'title' => '服装项目', 'path' => '#',
                'icon' => 'fa-tags', 'sort' => 3, 'is_enabled' => 1, 'updated_at' => $now,
            ]);
            $db->table('portal_menus')->where([
                'portal_id' => $portalId, 'code' => 'assistant_fashion_operation_reports',
            ])->update([
                'parent_id' => $fashionParentId, 'title' => '服装运营报表',
                'sort' => 1, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;
        $now = now();

        $shopifyParentId = $db->table('portal_menus')->where([
            'portal_id' => $portalId, 'code' => 'assistant_shopify_operation_reports',
        ])->value('id');
        $db->table('portal_menus')->where('id', $shopifyParentId)->update(['title' => 'Shopify运营报表', 'sort' => 3, 'updated_at' => $now]);
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_shopify_analytics'])
            ->update(['parent_id' => 0, 'sort' => 2, 'updated_at' => $now]);
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_operation_reports'])
            ->update(['parent_id' => $shopifyParentId ?: 0, 'title' => 'Shopify订阅报表', 'sort' => 1, 'updated_at' => $now]);

        $fashionParentId = $db->table('portal_menus')->where([
            'portal_id' => $portalId, 'code' => 'assistant_fashion_reports',
        ])->value('id');
        $db->table('portal_menus')->where('id', $fashionParentId)->update(['title' => '服装运营报表', 'sort' => 4, 'updated_at' => $now]);
    }
};
