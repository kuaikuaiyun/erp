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
        $menusTable = config('admin.database.menu_table');
        $permissionsTable = config('admin.database.permissions_table');
        $hasScopesTable = $schema->hasTable('admin_department_permission_scopes');

        if (!$schema->hasTable($menusTable) || !$schema->hasTable($permissionsTable)) {
            return;
        }

        DB::connection($connection)->transaction(function () use ($connection, $menusTable, $permissionsTable, $hasScopesTable): void {
            $database = DB::connection($connection);
            $menuIds = $database->table($menusTable)
                ->whereIn('uri', ['organization/department-permissions', 'auth/permissions'])
                ->pluck('id');

            if ($menuIds->isNotEmpty()) {
                $database->table(config('admin.database.role_menu_table'))
                    ->whereIn('menu_id', $menuIds)
                    ->delete();
                $database->table($menusTable)->whereIn('id', $menuIds)->delete();
            }

            $permissionId = $database->table($permissionsTable)
                ->where('slug', 'organization.department_permissions')
                ->value('id');

            if ($permissionId) {
                $database->table(config('admin.database.role_permissions_table'))
                    ->where('permission_id', $permissionId)
                    ->delete();
                $database->table(config('admin.database.user_permissions_table'))
                    ->where('permission_id', $permissionId)
                    ->delete();
                if ($hasScopesTable) {
                    $database->table('admin_department_permission_scopes')
                        ->where('permission_id', $permissionId)
                        ->delete();
                }
                $database->table($permissionsTable)->where('id', $permissionId)->delete();
            }
        });
    }

    public function down(): void
    {
        // The old standalone entry is intentionally not restored.
    }
};


