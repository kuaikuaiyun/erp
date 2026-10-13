<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACCOUNTS_PERMISSION = 'organization.accounts';

    private const DEPARTMENTS_PERMISSION = 'organization.departments';

    public function up(): void
    {
        $connection = $this->connectionName();
        $schema = Schema::connection($connection);
        $this->ensureAdministratorTables($schema);

        $rolesTable = config('admin.database.roles_table');
        $roleUsersTable = config('admin.database.role_users_table');

        if (!$schema->hasColumn($rolesTable, 'parent_id')) {
            $schema->table($rolesTable, function (Blueprint $table): void {
                $table->unsignedInteger('parent_id')->nullable()->after('slug');
                $table->index('parent_id');
            });
        }

        if (!$schema->hasColumn($rolesTable, 'sort')) {
            $schema->table($rolesTable, function (Blueprint $table): void {
                $table->unsignedInteger('sort')->default(0)->after('parent_id');
            });
        }

        if (!$schema->hasColumn($roleUsersTable, 'position')) {
            $schema->table($roleUsersTable, function (Blueprint $table): void {
                $table->string('position', 20)->default('member')->after('user_id');
                $table->unsignedTinyInteger('position_slot')->nullable()->after('position');
                $table->boolean('is_primary')->default(false)->after('position_slot');
            });

            $schema->table($roleUsersTable, function (Blueprint $table): void {
                $table->unique(['role_id', 'user_id'], 'admin_role_users_role_user_unique');
                $table->unique(['role_id', 'position', 'position_slot'], 'admin_role_users_position_slot_unique');
                $table->index(['user_id', 'is_primary'], 'admin_role_users_primary_index');
            });
        }

        if (!$schema->hasTable('admin_department_permission_scopes')) {
            $schema->create('admin_department_permission_scopes', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('role_id');
                $table->unsignedInteger('permission_id');
                $table->string('scope', 20)->default('self');
                $table->timestamps();

                $table->unique(['role_id', 'permission_id'], 'admin_department_permission_scope_unique');
                $table->index(['permission_id', 'scope'], 'admin_department_permission_scope_index');
            });
        }

        if (!$schema->hasTable('admin_department_change_logs')) {
            $schema->create('admin_department_change_logs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('actor_id')->nullable();
                $table->unsignedInteger('department_id')->nullable();
                $table->string('event', 80);
                $table->text('payload')->nullable();
                $table->timestamps();

                $table->index(['department_id', 'created_at'], 'admin_department_change_department_index');
                $table->index(['actor_id', 'created_at'], 'admin_department_change_actor_index');
            });
        }

        $this->installMenusAndPermissions($connection);
    }

    /**
     * Production organization records are intentionally retained on rollback.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }

    private function installMenusAndPermissions(?string $connection): void
    {
        $database = DB::connection($connection);
        $timestamp = now();
        $rolesTable = config('admin.database.roles_table');
        $permissionsTable = config('admin.database.permissions_table');
        $menusTable = config('admin.database.menu_table');

        $database->transaction(function () use ($database, $timestamp, $rolesTable, $permissionsTable, $menusTable): void {
            $administratorId = $database->table($rolesTable)
                ->where('slug', 'administrator')
                ->value('id');

            if (!$administratorId) {
                throw new RuntimeException('The administrator role does not exist.');
            }

            $managementMenuId = $this->ensureManagementMenu($database, $menusTable, $timestamp);

            $accountPermissionId = $this->ensurePermission(
                $database,
                $permissionsTable,
                self::ACCOUNTS_PERMISSION,
                'organization.permission.accounts',
                "/organization/accounts\r\n/organization/accounts/*",
                $timestamp,
            );
            $departmentPermissionId = $this->ensurePermission(
                $database,
                $permissionsTable,
                self::DEPARTMENTS_PERMISSION,
                'organization.permission.departments',
                "/organization/departments\r\n/organization/departments/*",
                $timestamp,
            );
            $accountMenuId = $this->ensureChildMenu(
                $database,
                $menusTable,
                'organization/accounts',
                'auth/users',
                $managementMenuId,
                3,
                'organization.menu.accounts',
                'fa-users',
                self::ACCOUNTS_PERMISSION,
                $timestamp,
            );
            $departmentMenuId = $this->ensureChildMenu(
                $database,
                $menusTable,
                'organization/departments',
                'auth/roles',
                $managementMenuId,
                4,
                'organization.menu.departments',
                'fa-sitemap',
                self::DEPARTMENTS_PERMISSION,
                $timestamp,
            );
            foreach ([$managementMenuId, $accountMenuId, $departmentMenuId] as $menuId) {
                $this->ensureRoleMenu($database, (int) $administratorId, $menuId, $timestamp);
            }

            foreach ([$accountPermissionId, $departmentPermissionId] as $permissionId) {
                $this->ensureRolePermission($database, (int) $administratorId, $permissionId, $timestamp);
            }
        });
    }

    private function ensureAdministratorTables($schema): void
    {
        foreach ([
            config('admin.database.roles_table'),
            config('admin.database.role_users_table'),
            config('admin.database.permissions_table'),
            config('admin.database.menu_table'),
            config('admin.database.role_permissions_table'),
            config('admin.database.role_menu_table'),
        ] as $table) {
            if (!$schema->hasTable($table)) {
                throw new RuntimeException("Required administrator table [{$table}] does not exist.");
            }
        }
    }

    private function ensureManagementMenu($database, string $menusTable, \DateTimeInterface $timestamp): int
    {
        $menu = $database->table($menusTable)
            ->where('parent_id', 0)
            ->where(function ($query): void {
                $query->where('uri', '')->orWhereNull('uri');
            })
            ->orderBy('id')
            ->first(['id']);

        $values = [
            'parent_id' => 0,
            'order' => 2,
            'title' => $this->translation('organization.menu.management'),
            'icon' => 'fa-tasks',
            'uri' => '',
            'permission' => null,
            'updated_at' => $timestamp,
        ];

        if ($menu) {
            $database->table($menusTable)->where('id', $menu->id)->update($values);

            return (int) $menu->id;
        }

        return (int) $database->table($menusTable)->insertGetId([
            ...$values,
            'created_at' => $timestamp,
        ]);
    }

    private function ensurePermission(
        $database,
        string $permissionsTable,
        string $slug,
        string $translationKey,
        string $path,
        \DateTimeInterface $timestamp,
    ): int {
        $permission = $database->table($permissionsTable)->where('slug', $slug)->first(['id']);
        $values = [
            'name' => $this->translation($translationKey),
            'http_method' => 'GET,POST,PUT,PATCH,DELETE',
            'http_path' => $path,
            'updated_at' => $timestamp,
        ];

        if ($permission) {
            $database->table($permissionsTable)->where('id', $permission->id)->update($values);

            return (int) $permission->id;
        }

        return (int) $database->table($permissionsTable)->insertGetId([
            'slug' => $slug,
            ...$values,
            'created_at' => $timestamp,
        ]);
    }

    private function ensureChildMenu(
        $database,
        string $menusTable,
        string $uri,
        string $legacyUri,
        int $parentId,
        int $order,
        string $translationKey,
        string $icon,
        string $permission,
        \DateTimeInterface $timestamp,
    ): int {
        $menu = $database->table($menusTable)
            ->whereIn('uri', [$uri, $legacyUri])
            ->orderBy('id')
            ->first(['id']);
        $values = [
            'parent_id' => $parentId,
            'order' => $order,
            'title' => $this->translation($translationKey),
            'icon' => $icon,
            'uri' => $uri,
            'permission' => $permission,
            'updated_at' => $timestamp,
        ];

        if ($menu) {
            $database->table($menusTable)->where('id', $menu->id)->update($values);

            return (int) $menu->id;
        }

        return (int) $database->table($menusTable)->insertGetId([
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

    private function connectionName(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
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


