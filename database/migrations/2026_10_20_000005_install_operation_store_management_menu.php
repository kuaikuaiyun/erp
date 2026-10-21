<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

/**
 * 在运营门户 yunying 安装「店铺管理」父菜单 + 「店铺列表」子菜单。
 *
 * 父菜单：店铺管理（code=operation_store_management，icon=fa-shopping-bag，sort=60）
 * 子菜单：店铺列表（code=operation_domain_stores，icon=fa-list，sort=1），
 *         path=/operation/domain-stores，对应 route operation.domain-stores.index
 */
return new class extends Migration {
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $timestamp = now();
        $yunyingId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (!$yunyingId) {
            return;
        }

        $parent = $database->table('portal_menus')
            ->where('portal_id', $yunyingId)
            ->where('code', 'operation_store_management')
            ->first(['id']);

        if (!$parent) {
            $database->table('portal_menus')->insert([
                'portal_id' => $yunyingId,
                'parent_id' => 0,
                'code' => 'operation_store_management',
                'title' => (string) Lang::get('store_management.menus.store_management', [], 'zh-CN'),
                'title_key' => 'store_management.menus.store_management',
                'path' => '#',
                'icon' => 'fa-shopping-bag',
                'sort' => 60,
                'is_enabled' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            $parent = $database->table('portal_menus')
                ->where('portal_id', $yunyingId)
                ->where('code', 'operation_store_management')
                ->first(['id']);
        }

        if ($database->table('portal_menus')
            ->where('portal_id', $yunyingId)
            ->where('code', 'operation_domain_stores')
            ->exists()) {
            return;
        }

        $database->table('portal_menus')->insert([
            'portal_id' => $yunyingId,
            'parent_id' => (int) $parent->id,
            'code' => 'operation_domain_stores',
            'title' => (string) Lang::get('store_management.menus.domain_stores', [], 'zh-CN'),
            'title_key' => 'store_management.menus.domain_stores',
            'path' => '/operation/domain-stores',
            'icon' => 'fa-list',
            'sort' => 1,
            'is_enabled' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);

        if (!$database->getSchemaBuilder()->hasTable('portals') || !$database->getSchemaBuilder()->hasTable('portal_menus')) {
            return;
        }

        $yunyingId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (!$yunyingId) {
            return;
        }

        $database->table('portal_menus')
            ->where('portal_id', $yunyingId)
            ->whereIn('code', ['operation_domain_stores', 'operation_store_management'])
            ->delete();
    }
};