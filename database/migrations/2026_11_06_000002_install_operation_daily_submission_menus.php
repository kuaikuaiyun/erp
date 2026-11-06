<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ITEMS = [
        ['code' => 'operation_daily_advertising_recharge', 'title' => '广告充值', 'path' => '/operation/daily-submissions/advertising-recharge', 'icon' => 'fa-credit-card', 'slug' => 'portal.yunying.daily.ad_recharge'],
        ['code' => 'operation_daily_account_opening', 'title' => '开户需求', 'path' => '/operation/daily-submissions/account-opening', 'icon' => 'fa-user-plus', 'slug' => 'portal.yunying.daily.account_opening'],
        ['code' => 'operation_daily_store_launch', 'title' => '下店需求', 'path' => '/operation/daily-submissions/store-launch', 'icon' => 'fa-shopping-bag', 'slug' => 'portal.yunying.daily.store_launch'],
        ['code' => 'operation_daily_ad_account_auth', 'title' => '广告账号授权', 'path' => '/operation/daily-submissions/advertising-account-authorization', 'icon' => 'fa-key', 'slug' => 'portal.yunying.daily.ad_account_auth'],
        ['code' => 'operation_daily_account_store_transfer', 'title' => '账号/店铺弃用转让', 'path' => '/operation/daily-submissions/account-store-transfer', 'icon' => 'fa-exchange', 'slug' => 'portal.yunying.daily.account_transfer'],
    ];

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (! $portalId) {
            return;
        }

        $parentKey = ['portal_id' => $portalId, 'code' => 'operation_daily_submissions'];
        $parentValues = [
            'parent_id' => 0,
            'title' => '日常提报',
            'title_key' => '',
            'path' => '#',
            'icon' => 'fa-calendar-check-o',
            'sort' => 8,
            'is_enabled' => true,
            'updated_at' => now(),
        ];
        $database->table('portal_menus')->updateOrInsert($parentKey, [...$parentValues, 'created_at' => now()]);
        $parentId = (int) $database->table('portal_menus')->where($parentKey)->value('id');

        foreach (self::ITEMS as $sort => $item) {
            $values = [
                'parent_id' => $parentId,
                'title' => $item['title'],
                'title_key' => '',
                'path' => $item['path'],
                'icon' => $item['icon'],
                'sort' => $sort + 1,
                'is_enabled' => true,
                'updated_at' => now(),
            ];
            if ($schema->hasColumn('portal_menus', 'permission_slug')) {
                $values['permission_slug'] = $item['slug'];
            }
            $database->table('portal_menus')->updateOrInsert(
                ['portal_id' => $portalId, 'code' => $item['code']],
                [...$values, 'created_at' => now()],
            );
        }

        $this->installPermissions($database, $schema);
    }

    private function installPermissions($database, $schema): void
    {
        $permissionsTable = config('admin.database.permissions_table');
        $rolesTable = config('admin.database.roles_table');
        $rolePermissionsTable = config('admin.database.role_permissions_table');
        if (! $schema->hasTable($permissionsTable) || ! $schema->hasTable($rolesTable) || ! $schema->hasTable($rolePermissionsTable)) {
            return;
        }

        foreach (self::ITEMS as $item) {
            $permissionId = $database->table($permissionsTable)->where('slug', $item['slug'])->value('id');
            if (! $permissionId) {
                $permissionId = $database->table($permissionsTable)->insertGetId([
                    'name' => '[运营系统] '.$item['title'],
                    'slug' => $item['slug'],
                    'http_method' => '',
                    'http_path' => '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($database->table($rolesTable)->where('slug', '!=', 'administrator')->pluck('id') as $roleId) {
                $database->table($rolePermissionsTable)->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $database = DB::connection($connection);

        if ($schema->hasTable('portal_menus')) {
            $codes = array_column(self::ITEMS, 'code');
            $codes[] = 'operation_daily_submissions';
            $database->table('portal_menus')->whereIn('code', $codes)->delete();
        }

        $permissionsTable = config('admin.database.permissions_table');
        if (! $schema->hasTable($permissionsTable)) {
            return;
        }
        $permissionIds = $database->table($permissionsTable)->whereIn('slug', array_column(self::ITEMS, 'slug'))->pluck('id');
        foreach ([config('admin.database.role_permissions_table'), config('admin.database.user_permissions_table')] as $table) {
            if ($schema->hasTable($table)) {
                $database->table($table)->whereIn('permission_id', $permissionIds)->delete();
            }
        }
        if ($schema->hasTable('admin_user_permission_overrides')) {
            $database->table('admin_user_permission_overrides')->whereIn('permission_id', $permissionIds)->delete();
        }
        $database->table($permissionsTable)->whereIn('id', $permissionIds)->delete();
    }
};
