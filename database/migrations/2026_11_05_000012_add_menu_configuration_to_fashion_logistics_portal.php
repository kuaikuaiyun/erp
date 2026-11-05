<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'fuzhuangwuliu')->value('id');
        if (! $portalId) return;

        $now = now();
        $management = $db->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'backend_management')
            ->first(['id']);

        $managementValues = [
            'parent_id' => 0,
            'title' => null,
            'title_key' => 'portal.menu.backend_management',
            'path' => '#',
            'permission_slug' => null,
            'icon' => 'fa-cogs',
            'sort' => 900,
            'is_enabled' => true,
            'updated_at' => $now,
        ];

        if ($management) {
            $managementId = (int) $management->id;
            $db->table('portal_menus')->where('id', $managementId)->update($managementValues);
        } else {
            $managementId = (int) $db->table('portal_menus')->insertGetId([
                'portal_id' => $portalId,
                'code' => 'backend_management',
                ...$managementValues,
                'created_at' => $now,
            ]);
        }

        $configurationValues = [
            'parent_id' => $managementId,
            'title' => null,
            'title_key' => 'portal.menu.configuration',
            'path' => '/management/menus',
            'permission_slug' => null,
            'icon' => 'fa-list',
            'sort' => 1,
            'is_enabled' => true,
            'updated_at' => $now,
        ];
        $configuration = $db->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'menu_configuration')
            ->first(['id']);

        if ($configuration) {
            $db->table('portal_menus')->where('id', $configuration->id)->update($configurationValues);
        } else {
            $db->table('portal_menus')->insert([
                'portal_id' => $portalId,
                'code' => 'menu_configuration',
                ...$configurationValues,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // 系统菜单配置属于基础能力，回滚业务代码时仍保留。
    }
};
