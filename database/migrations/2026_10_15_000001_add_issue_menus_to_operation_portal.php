<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPERATION_PORTAL = 'yunying';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', self::OPERATION_PORTAL)->value('id');
        if (!$portalId) {
            return;
        }

        $timestamp = now();
        $menus = [
            [
                'code' => 'link_review_issues',
                'title_key' => 'product_requests.menus.link_review_issues',
                'path' => '/link-review-issues',
                'icon' => 'fa-chain-broken',
                'sort' => 20,
            ],
            [
                'code' => 'warehouse_issues',
                'title_key' => 'product_requests.menus.warehouse_issues',
                'path' => '/warehouse-issues',
                'icon' => 'fa-exclamation-triangle',
                'sort' => 30,
            ],
        ];

        foreach ($menus as $menu) {
            $values = [
                'parent_id' => 0,
                'title' => (string) Lang::get($menu['title_key'], [], 'zh-CN'),
                'title_key' => $menu['title_key'],
                'path' => $menu['path'],
                'icon' => $menu['icon'],
                'sort' => $menu['sort'],
                'is_enabled' => true,
                'updated_at' => $timestamp,
            ];

            $existing = $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', $menu['code'])
                ->first(['id']);

            if ($existing) {
                $database->table('portal_menus')->where('id', $existing->id)->update($values);
            } else {
                $database->table('portal_menus')->insert([
                    'portal_id' => $portalId,
                    'code' => $menu['code'],
                    ...$values,
                    'created_at' => $timestamp,
                ]);
            }
        }
    }

    public function down(): void
    {
        // 菜单记录保留，不做删除。
    }
};
