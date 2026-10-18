<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (!Schema::connection($connection)->hasTable('portals') || !Schema::connection($connection)->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'caigou')->value('id');
        if (!$portalId) {
            return;
        }

        $timestamp = now();
        $values = [
            'parent_id' => 0,
            'title' => (string) Lang::get('product_requests.menus.link_sku_manager', [], 'zh-CN'),
            'title_key' => 'product_requests.menus.link_sku_manager',
            'path' => '/link-sku-manager',
            'icon' => 'fa-barcode',
            'sort' => 30,
            'is_enabled' => true,
            'updated_at' => $timestamp,
        ];

        $existing = $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'link_sku_manager')
            ->first(['id']);

        if ($existing) {
            $database->table('portal_menus')->where('id', $existing->id)->update($values);
        } else {
            $database->table('portal_menus')->insert([
                'portal_id' => $portalId,
                'code' => 'link_sku_manager',
                ...$values,
                'created_at' => $timestamp,
            ]);
        }
    }

    public function down(): void
    {
        // 菜单记录保留，不回滚删除。
    }
};
