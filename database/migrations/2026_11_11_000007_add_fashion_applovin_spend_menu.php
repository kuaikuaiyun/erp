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
        $portalId = $db->table('portals')->where('code', 'fuzhuang')->value('id');
        if (! $portalId) return;
        $now = now();
        $slug = 'portal.fuzhuang.applovin_spends';
        $permissions = config('admin.database.permissions_table');
        $permissionId = null;
        if ($schema->hasTable($permissions)) {
            $values = [
                'name' => '[服装运营系统] AppLovin账号消耗',
                'http_method' => 'GET,POST',
                'http_path' => "/fashion/applovin-spends\r\n/fashion/applovin-spends/*",
                'updated_at' => $now,
            ];
            $existing = $db->table($permissions)->where('slug', $slug)->first(['id']);
            if ($existing) {
                $permissionId = (int) $existing->id;
                $db->table($permissions)->where('id', $permissionId)->update($values);
            } else {
                $permissionId = (int) $db->table($permissions)->insertGetId(['slug' => $slug, ...$values, 'created_at' => $now]);
            }
        }

        $menu = [
            'parent_id' => 0, 'title' => 'AppLovin账号消耗', 'title_key' => 'fashion.applovin_spends.menu',
            'path' => '/fashion/applovin-spends', 'permission_slug' => $slug, 'icon' => 'fa-line-chart',
            'sort' => 25, 'is_enabled' => true, 'updated_at' => $now,
        ];
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'fashion_applovin_spends'],
            [...$menu, 'created_at' => $now]
        );

        if ($permissionId) {
            $roleId = $db->table(config('admin.database.roles_table'))->where('slug', 'administrator')->value('id');
            $pivot = config('admin.database.role_permissions_table');
            if ($roleId && ! $db->table($pivot)->where(['role_id' => $roleId, 'permission_id' => $permissionId])->exists()) {
                $db->table($pivot)->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            }
            $sourcePermissionId = $db->table($permissions)->where('slug', 'portal.fuzhuang.advertising_expenses')->value('id');
            if ($sourcePermissionId) {
                foreach ($db->table($pivot)->where('permission_id', $sourcePermissionId)->pluck('role_id') as $sourceRoleId) {
                    $db->table($pivot)->updateOrInsert(
                        ['role_id' => $sourceRoleId, 'permission_id' => $permissionId],
                        ['created_at' => $now, 'updated_at' => $now]
                    );
                }
                $userPivot = config('admin.database.user_permissions_table');
                if ($schema->hasTable($userPivot)) {
                    foreach ($db->table($userPivot)->where('permission_id', $sourcePermissionId)->pluck('user_id') as $sourceUserId) {
                        $db->table($userPivot)->updateOrInsert(
                            ['user_id' => $sourceUserId, 'permission_id' => $permissionId],
                            ['created_at' => $now, 'updated_at' => $now]
                        );
                    }
                }
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'fuzhuang')->value('id');
        if ($portalId) $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'fashion_applovin_spends'])->delete();
    }
};
