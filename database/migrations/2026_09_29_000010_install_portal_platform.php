<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_PERMISSION = 'portal.management';

    public function up(): void
    {
        $connection = $this->connectionName();
        $schema = Schema::connection($connection);
        $this->ensureAdministratorTables($schema);

        if (!$schema->hasTable('portals')) {
            $schema->create('portals', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 100);
                $table->string('code', 63)->unique();
                $table->text('remark')->nullable();
                $table->string('status', 20)->default('disabled')->index();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('portal_departments')) {
            $schema->create('portal_departments', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('portal_id');
                $table->unsignedInteger('department_id');
                $table->boolean('includes_descendants')->default(true);
                $table->unsignedInteger('granted_by')->nullable();
                $table->timestamp('granted_at')->nullable();
                $table->timestamps();

                $table->unique(['portal_id', 'department_id'], 'portal_department_unique');
                $table->index(['department_id', 'portal_id'], 'portal_department_access_index');
            });
        }

        if (!$schema->hasTable('portal_change_logs')) {
            $schema->create('portal_change_logs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('portal_id');
                $table->unsignedInteger('department_id')->nullable();
                $table->string('department_name_snapshot', 100)->nullable();
                $table->string('event', 80);
                $table->text('payload')->nullable();
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('actor_name_snapshot', 100)->nullable();
                $table->timestamps();

                $table->index(['portal_id', 'created_at'], 'portal_change_portal_index');
                $table->index(['department_id', 'created_at'], 'portal_change_department_index');
                $table->index(['actor_id', 'created_at'], 'portal_change_actor_index');
            });
        }

        if (!$schema->hasTable('portal_menus')) {
            $schema->create('portal_menus', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('portal_id');
                $table->unsignedInteger('parent_id')->default(0);
                $table->string('code', 100);
                $table->string('title', 191)->nullable();
                $table->string('title_key', 191);
                $table->string('path', 191)->default('/');
                $table->string('icon', 50)->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['portal_id', 'code'], 'portal_menu_code_unique');
                $table->index(['portal_id', 'parent_id', 'sort'], 'portal_menu_tree_index');
            });
        }

        $this->installPortalManagementMenu($connection);
    }

    /**
     * Portal records and access history are intentionally retained on rollback.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }

    private function ensureAdministratorTables($schema): void
    {
        foreach ([
            config('admin.database.roles_table'),
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

    private function installPortalManagementMenu(?string $connection): void
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
            $permissionId = $this->ensurePermission($database, $permissionsTable, $timestamp);
            $portalMenuId = $this->ensurePortalMenu($database, $menusTable, $managementMenuId, $timestamp);

            $this->ensureRoleMenu($database, (int) $administratorId, $managementMenuId, $timestamp);
            $this->ensureRoleMenu($database, (int) $administratorId, $portalMenuId, $timestamp);
            $this->ensureRolePermission($database, (int) $administratorId, $permissionId, $timestamp);
        });
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

    private function ensurePermission($database, string $permissionsTable, \DateTimeInterface $timestamp): int
    {
        $permission = $database->table($permissionsTable)
            ->where('slug', self::PORTAL_PERMISSION)
            ->first(['id']);
        $values = [
            'name' => $this->translation('portal.permission.management'),
            'http_method' => 'GET,POST,PUT,PATCH,DELETE',
            'http_path' => "/portal/portals\r\n/portal/portals/*",
            'updated_at' => $timestamp,
        ];

        if ($permission) {
            $database->table($permissionsTable)->where('id', $permission->id)->update($values);

            return (int) $permission->id;
        }

        return (int) $database->table($permissionsTable)->insertGetId([
            'slug' => self::PORTAL_PERMISSION,
            ...$values,
            'created_at' => $timestamp,
        ]);
    }

    private function ensurePortalMenu(
        $database,
        string $menusTable,
        int $parentId,
        \DateTimeInterface $timestamp,
    ): int {
        $menu = $database->table($menusTable)
            ->where('uri', 'portal/portals')
            ->first(['id']);
        $values = [
            'parent_id' => $parentId,
            'order' => 5,
            'title' => $this->translation('portal.menu.management'),
            'icon' => 'fa-th-large',
            'uri' => 'portal/portals',
            'permission' => self::PORTAL_PERMISSION,
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


