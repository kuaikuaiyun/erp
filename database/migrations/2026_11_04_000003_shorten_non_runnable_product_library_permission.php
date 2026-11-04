<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'portal.yunying.non_runnable_products';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $permissionsTable = config('admin.database.permissions_table');
        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus') || !$schema->hasTable($permissionsTable)) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (!$portalId) {
            return;
        }

        if ($schema->hasColumn('portal_menus', 'permission_slug')) {
            $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', 'operation_non_runnable_product_library')
                ->update(['permission_slug' => self::PERMISSION, 'updated_at' => now()]);
        }

        $database->table($permissionsTable)->updateOrInsert(
            ['slug' => self::PERMISSION],
            [
                'name' => '[运营系统] 禁跑清单',
                'http_method' => '',
                'http_path' => '',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        $permissionsTable = config('admin.database.permissions_table');
        $schema = $database->getSchemaBuilder();
        if (!$schema->hasTable($permissionsTable)) {
            return;
        }

        $permissionId = $database->table($permissionsTable)->where('slug', self::PERMISSION)->value('id');
        if ($permissionId && $schema->hasTable(config('admin.database.department_permissions_table', 'department_permissions'))) {
            $database->table(config('admin.database.department_permissions_table', 'department_permissions'))
                ->where('permission_id', $permissionId)
                ->delete();
        }
        $database->table($permissionsTable)->where('slug', self::PERMISSION)->delete();
    }
};
