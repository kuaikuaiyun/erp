<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CAPTCHA_URI = 'configuration/captcha';
    private const CAPTCHA_PERMISSION = 'configuration.captcha';

    /**
     * Install the configuration menu and its access permission.
     *
     * The migration is idempotent so it can be deployed safely to a server
     * whose administrator role already has part of the configuration.
     */
    public function up(): void
    {
        $connection = $this->getConnection();
        $schema = Schema::connection($connection);

        $this->ensureRequiredTablesExist($schema);

        $database = DB::connection($connection);
        $timestamp = now();
        $parentTitle = $this->translation('configuration.menu.configuration_center');
        $captchaTitle = $this->translation('configuration.menu.login_captcha');

        $parentId = $this->ensureParentMenu($database, $timestamp, $parentTitle);
        $captchaPermissionId = $this->ensureCaptchaPermission($database, $timestamp, $captchaTitle);
        $captchaMenuId = $this->ensureCaptchaMenu($database, $parentId, $timestamp, $captchaTitle);

        $administratorRoleId = $database
            ->table(config('admin.database.roles_table'))
            ->where('slug', 'administrator')
            ->value('id');

        if ($administratorRoleId) {
            $this->ensureRoleMenu($database, (int) $administratorRoleId, $parentId, $timestamp);
            $this->ensureRoleMenu($database, (int) $administratorRoleId, $captchaMenuId, $timestamp);
            $this->ensureRolePermission($database, (int) $administratorRoleId, $captchaPermissionId, $timestamp);
        }
    }

    /**
     * Keep administrator-controlled menus and role assignments on rollback.
     */
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

    private function ensureParentMenu($database, \DateTimeInterface $timestamp, string $title): int
    {
        $menuTable = config('admin.database.menu_table');
        $menu = $database->table($menuTable)
            ->where('parent_id', 0)
            ->where('title', $title)
            ->first(['id']);

        $values = [
            'order' => 100,
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
            'title' => $title,
            ...$values,
            'created_at' => $timestamp,
        ]);
    }

    private function ensureCaptchaPermission($database, \DateTimeInterface $timestamp, string $title): int
    {
        $permissionTable = config('admin.database.permissions_table');
        $permission = $database->table($permissionTable)
            ->where('slug', self::CAPTCHA_PERMISSION)
            ->first(['id']);

        $values = [
            'name' => $title,
            'http_method' => 'GET',
            'http_path' => '/configuration/captcha',
            'updated_at' => $timestamp,
        ];

        if ($permission) {
            $database->table($permissionTable)->where('id', $permission->id)->update($values);

            return (int) $permission->id;
        }

        return (int) $database->table($permissionTable)->insertGetId([
            'slug' => self::CAPTCHA_PERMISSION,
            ...$values,
            'created_at' => $timestamp,
        ]);
    }

    private function ensureCaptchaMenu($database, int $parentId, \DateTimeInterface $timestamp, string $title): int
    {
        $menuTable = config('admin.database.menu_table');
        $menu = $database->table($menuTable)
            ->where('uri', self::CAPTCHA_URI)
            ->first(['id']);

        $values = [
            'parent_id' => $parentId,
            'order' => 101,
            'title' => $title,
            'icon' => 'fa-shield',
            'permission' => self::CAPTCHA_PERMISSION,
            'updated_at' => $timestamp,
        ];

        if ($menu) {
            $database->table($menuTable)->where('id', $menu->id)->update($values);

            return (int) $menu->id;
        }

        return (int) $database->table($menuTable)->insertGetId([
            'uri' => self::CAPTCHA_URI,
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


