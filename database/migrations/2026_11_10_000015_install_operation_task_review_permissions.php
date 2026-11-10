<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        $table = config('admin.database.permissions_table', 'admin_permissions');
        if (! $database->getSchemaBuilder()->hasTable($table)) return;
        foreach ([
            'portal.operation-tasks.review.department' => '[运营任务系统] 审核部门审核权限',
        ] as $slug => $name) {
            $database->table($table)->updateOrInsert(['slug' => $slug], ['name' => $name, 'http_method' => '', 'http_path' => '', 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void {}
};
