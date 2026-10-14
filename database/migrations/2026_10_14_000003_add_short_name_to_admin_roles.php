<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $table = config('admin.database.roles_table', 'admin_roles');

        if (!$schema->hasColumn($table, 'short_name')) {
            $schema->table($table, function (Blueprint $blueprint): void {
                // 部门简称，用于列表等位置的简短展示
                $blueprint->string('short_name', 50)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $table = config('admin.database.roles_table', 'admin_roles');

        if ($schema->hasColumn($table, 'short_name')) {
            $schema->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('short_name');
            });
        }
    }
};
