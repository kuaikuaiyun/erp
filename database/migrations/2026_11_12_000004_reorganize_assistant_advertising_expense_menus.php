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
        $parentId = $db->table('portal_menus')->where([
            'portal_id' => $portalId, 'code' => 'assistant_advertising_expenses',
        ])->value('id');
        if (! $parentId) {
            $parentId = $db->table('portal_menus')->insertGetId([
                'portal_id' => $portalId, 'parent_id' => 0, 'code' => 'assistant_advertising_expenses',
                'title' => '广告费', 'title_key' => '', 'path' => '#', 'permission_slug' => null,
                'icon' => 'fa-money', 'sort' => 4, 'is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $menus = [
            'assistant_operation_report_reviews' => ['title' => 'Shopify订阅广告费审核', 'sort' => 1],
            'assistant_fashion_advertising_reviews' => ['title' => '服装项目广告费审核', 'sort' => 2],
            'assistant_advertising_spend_summary' => ['title' => '广告费汇总', 'sort' => 3],
            'assistant_advertising_spend_analysis' => ['title' => '广告费分析', 'sort' => 4],
        ];
        foreach ($menus as $code => $values) {
            $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => $code])->update([
                'parent_id' => $parentId, 'title' => $values['title'], 'sort' => $values['sort'], 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;
        $shopifyParentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_shopify_operation_reports'])->value('id');
        $fashionParentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_reports'])->value('id');
        $now = now();
        foreach (['assistant_operation_report_reviews' => 2, 'assistant_advertising_spend_summary' => 3, 'assistant_advertising_spend_analysis' => 4] as $code => $sort) {
            $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => $code])
                ->update(['parent_id' => $shopifyParentId ?: 0, 'sort' => $sort, 'updated_at' => $now]);
        }
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_operation_report_reviews'])
            ->update(['title' => '运营广告费审核']);
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_advertising_reviews'])
            ->update(['parent_id' => $fashionParentId ?: 0, 'title' => '运营广告费审核', 'sort' => 1, 'updated_at' => $now]);
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_advertising_expenses'])->delete();
    }
};
