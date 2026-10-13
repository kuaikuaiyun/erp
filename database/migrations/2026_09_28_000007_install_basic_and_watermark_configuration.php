<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WATERMARK_PERMISSION = 'configuration.watermark';
    private const CAPTCHA_PERMISSION = 'configuration.captcha';
    private const BASIC_PERMISSION = 'configuration.basic';

    public function up(): void
    {
        $connection = $this->getConnection();
        $schema = Schema::connection($connection);
        $this->ensureRequiredTablesExist($schema);

        $titles = [
            'parent' => $this->translation('configuration.menu.configuration_center'),
            'watermark' => $this->translation('configuration.menu.watermark'),
            'captcha' => $this->translation('configuration.menu.login_captcha'),
            'basic' => $this->translation('configuration.menu.basic'),
            'watermark_permission' => $this->translation('configuration.permission.watermark'),
            'basic_permission' => $this->translation('configuration.permission.basic'),
        ];

        $database = DB::connection($connection);

        $database->transaction(function () use ($database, $titles): void {
            $timestamp = now();
            $parentId = $this->ensureParentMenu($database, $titles['parent'], $timestamp);

            $watermarkPermissionId = $this->ensurePermission(
                $database,
                self::WATERMARK_PERMISSION,
                $titles['watermark_permission'],
                "/configuration/watermark\r\n/configuration/watermark/*",
                $timestamp,
            );
            $captchaPermissionId = $this->ensurePermission(
                $database,
                self::CAPTCHA_PERMISSION,
                $titles['captcha'],
                "/configuration/captcha\r\n/configuration/captcha/*",
                $timestamp,
            );
            $basicPermissionId = $this->ensurePermission(
                $database,
                self::BASIC_PERMISSION,
                $titles['basic_permission'],
                "/configuration/basic\r\n/configuration/basic/*",
                $timestamp,
            );

            $watermarkMenuId = $this->ensureChildMenu(
                $database,
                'configuration/watermark',
                $parentId,
                101,
                $titles['watermark'],
                'fa-tint',
                self::WATERMARK_PERMISSION,
                $timestamp,
            );
            $captchaMenuId = $this->ensureChildMenu(
                $database,
                'configuration/captcha',
                $parentId,
                102,
                $titles['captcha'],
                'fa-shield',
                self::CAPTCHA_PERMISSION,
                $timestamp,
            );
            $basicMenuId = $this->ensureChildMenu(
                $database,
                'configuration/basic',
                $parentId,
                103,
                $titles['basic'],
                'fa-cog',
                self::BASIC_PERMISSION,
                $timestamp,
            );

            $administratorRoleId = $database
                ->table(config('admin.database.roles_table'))
                ->where('slug', 'administrator')
                ->value('id');

            if (!$administratorRoleId) {
                throw new RuntimeException('The administrator role does not exist.');
            }

            foreach ([$parentId, $watermarkMenuId, $captchaMenuId, $basicMenuId] as $menuId) {
                $this->ensureRoleMenu($database, (int) $administratorRoleId, $menuId, $timestamp);
            }

            foreach ([$watermarkPermissionId, $captchaPermissionId, $basicPermissionId] as $permissionId) {
                $this->ensureRolePermission($database, (int) $administratorRoleId, $permissionId, $timestamp);
            }
        });
    }

    /** Keep production menu and permission records on rollback. */
    public function down(): void
    {
        // Intentionally left blank.
    }

    public function getConnection()
    {
        return config('admin.database.connection') ?: config('database.default');
    }

    private function ensureRequiredTablesExist($schema): void
    {
        $tables = [
            config('admin.database.menu_table'),
            config('admin.database.permissions_table'),
            config('admin.database.roles_table'),
            config('admin.database.role_menu_table'),
            config('admin.database.role_permissions_table'),
        ];

        foreach ($tables as $table) {
            if (!$schema->hasTable($table)) {
                throw new RuntimeException("Required administrator table [{$table}] does not exist.");
            }
        }
    }

    private function ensureParentMenu($database, string $title, \DateTimeInterface $timestamp): int
    {
        $menuTable = config('admin.database.menu_table');
        $menu = $database->table($menuTable)
            ->where('parent_id', 0)
            ->where('title', $title)
            ->first(['id']);

        if (!$menu) {
            $menu = $database->table($menuTable)
                ->where('parent_id', 0)
                ->where('icon', 'fa-cogs')
                ->whereNull('uri')
                ->first(['id']);
        }

        $values = [
            'order' => 100,
            'title' => $title,
            'icon' => 'fa-cogs',
            'uri' => null,
            'permission' => null,
            'updated_at' => $timestamp,
        ];

        if ($menu) {
            $database->table($menuTable)->where('id', $menu->id)->update($values);

            return (int) $menu->id;
        }

        return (int) $database->table($menuTable)->insertGetId([
            'parent_id' => 0,
            ...$values,
            'created_at' => $timestamp,
        ]);
    }

    private function ensureChildMenu(
        $database,
        string $uri,
        int $parentId,
        int $order,
        string $title,
        string $icon,
        string $permission,
        \DateTimeInterface $timestamp,
    ): int {
        $menuTable = config('admin.database.menu_table');
        $menu = $database->table($menuTable)->where('uri', $uri)->first(['id']);
        $values = [
            'parent_id' => $parentId,
            'order' => $order,
            'title' => $title,
            'icon' => $icon,
            'permission' => $permission,
            'updated_at' => $timestamp,
        ];

        if ($menu) {
            $database->table($menuTable)->where('id', $menu->id)->update($values);

            return (int) $menu->id;
        }

        return (int) $database->table($menuTable)->insertGetId([
            'uri' => $uri,
            ...$values,
            'created_at' => $timestamp,
        ]);
    }

    private function ensurePermission(
        $database,
        string $slug,
        string $name,
        string $httpPath,
        \DateTimeInterface $timestamp,
    ): int {
        $permissionTable = config('admin.database.permissions_table');
        $permission = $database->table($permissionTable)->where('slug', $slug)->first(['id']);
        $values = [
            'name' => $name,
            'http_method' => 'GET,POST',
            'http_path' => $httpPath,
            'updated_at' => $timestamp,
        ];

        if ($permission) {
            $database->table($permissionTable)->where('id', $permission->id)->update($values);

            return (int) $permission->id;
        }

        return (int) $database->table($permissionTable)->insertGetId([
            'slug' => $slug,
            ...$values,
            'created_at' => $timestamp,
        ]);
    }

    private function ensureRoleMenu($database, int $roleId, int $menuId, \DateTimeInterface $timestamp): void
    {
        $table = config('admin.database.role_menu_table');

        if ($database->table($table)->where('role_id', $roleId)->where('menu_id', $menuId)->exists()) {
            return;
        }

        $database->table($table)->insert([
            'role_id' => $roleId,
            'menu_id' => $menuId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function ensureRolePermission($database, int $roleId, int $permissionId, \DateTimeInterface $timestamp): void
    {
        $table = config('admin.database.role_permissions_table');

        if ($database->table($table)->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
            return;
        }

        $database->table($table)->insert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function translation(string $key): string
    {
        $translation = Lang::get($key, [], 'zh-CN');

        if ($translation === $key) {
            throw new RuntimeException("Required translation [{$key}] does not exist.");
        }

        return (string) $translation;
    }
};


