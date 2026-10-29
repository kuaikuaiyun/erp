<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MENU_CODE = 'caigou_abnormal_skus';
    private const PERMISSION = 'portal.caigou.abnormal_skus';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus') || !$schema->hasColumn('portal_menus', 'permission_slug')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'caigou')->value('id');
        if (!$portalId) {
            return;
        }

        $timestamp = now();
        $database->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => self::MENU_CODE],
            [
                'parent_id' => 0,
                'title' => '异常SKU',
                'title_key' => 'caigou.abnormal_skus.menu',
                'path' => '/abnormal-skus',
                'permission_slug' => self::PERMISSION,
                'icon' => 'fa-warning',
                'sort' => 50,
                'is_enabled' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        );

        $permissionsTable = config('admin.database.permissions_table');
        $permissionId = $database->table($permissionsTable)->where('slug', self::PERMISSION)->value('id');
        if (!$permissionId) {
            $permissionId = $database->table($permissionsTable)->insertGetId([
                'name' => '[入录审核系统] 异常SKU',
                'slug' => self::PERMISSION,
                'http_method' => '',
                'http_path' => '',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        $rolePermissionsTable = config('admin.database.role_permissions_table');
        $roleIds = $database->table(config('admin.database.roles_table'))
            ->where('slug', '!=', 'administrator')
            ->pluck('id');
        foreach ($roleIds as $roleId) {
            $database->table($rolePermissionsTable)->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => $timestamp, 'updated_at' => $timestamp],
            );
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        $permissionId = $database->table(config('admin.database.permissions_table'))->where('slug', self::PERMISSION)->value('id');
        if ($permissionId) {
            $database->table(config('admin.database.role_permissions_table'))->where('permission_id', $permissionId)->delete();
            $database->table(config('admin.database.user_permissions_table'))->where('permission_id', $permissionId)->delete();
            if (Schema::connection($connection)->hasTable('admin_user_permission_overrides')) {
                $database->table('admin_user_permission_overrides')->where('permission_id', $permissionId)->delete();
            }
            $database->table(config('admin.database.permissions_table'))->where('id', $permissionId)->delete();
        }
        $portalId = $database->table('portals')->where('code', 'caigou')->value('id');
        if ($portalId) {
            $database->table('portal_menus')->where('portal_id', $portalId)->where('code', self::MENU_CODE)->delete();
        }
    }
};
