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

        $parentId = $db->table('portal_menus')->where([
            'portal_id' => $portalId,
            'code' => 'assistant_advertising_expenses',
        ])->value('id');
        if (! $parentId) return;

        $now = now();
        $permission = 'portal.zhuli.fashion_advertising_reviews';
        $menus = [
            'assistant_fashion_advertising_reviews' => [
                'title' => '单包服装广告费审核',
                'path' => '/assistant/fashion-advertising-reviews?project_code=fuzhuang',
                'sort' => 2,
            ],
            'assistant_baokuan_fashion_advertising_reviews' => [
                'title' => '爆款服装广告费审核',
                'path' => '/assistant/fashion-advertising-reviews?project_code=baokuan-fashion',
                'sort' => 3,
            ],
            'assistant_baokuan_general_advertising_reviews' => [
                'title' => '爆款百货广告费审核',
                'path' => '/assistant/fashion-advertising-reviews?project_code=baokuan-general',
                'sort' => 4,
            ],
            'assistant_danbao_general_advertising_reviews' => [
                'title' => '单包百货广告费审核',
                'path' => '/assistant/fashion-advertising-reviews?project_code=danbao-general',
                'sort' => 5,
            ],
        ];

        foreach ($menus as $code => $values) {
            $menuValues = [
                'parent_id' => $parentId,
                'title' => $values['title'],
                'title_key' => '',
                'path' => $values['path'],
                'permission_slug' => $permission,
                'icon' => 'fa-check-square-o',
                'sort' => $values['sort'],
                'is_enabled' => 1,
                'updated_at' => $now,
            ];
            $existing = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => $code])->value('id');
            if ($existing) {
                $db->table('portal_menus')->where('id', $existing)->update($menuValues);
            } else {
                $db->table('portal_menus')->insert(['portal_id' => $portalId, 'code' => $code, ...$menuValues, 'created_at' => $now]);
            }
        }

        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_advertising_spend_summary'])
            ->update(['sort' => 6, 'updated_at' => $now]);
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_advertising_spend_analysis'])
            ->update(['sort' => 7, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;

        $db->table('portal_menus')->where('portal_id', $portalId)->whereIn('code', [
            'assistant_baokuan_fashion_advertising_reviews',
            'assistant_baokuan_general_advertising_reviews',
            'assistant_danbao_general_advertising_reviews',
        ])->delete();
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_advertising_reviews'])
            ->update(['title' => '服装项目广告费审核', 'path' => '/assistant/fashion-advertising-reviews', 'sort' => 2, 'updated_at' => now()]);
    }
};
