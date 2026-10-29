<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasColumn('operation_reports', 'operation_department_id')) {
            $schema->table('operation_reports', function (Blueprint $table): void {
                $table->unsignedInteger('operation_department_id')->nullable()->after('operator_name')->index('operation_reports_department_idx');
                $table->string('operation_department_name', 100)->nullable()->after('operation_department_id');
            });
        }

        $rolesTable = config('admin.database.roles_table');
        $roleUsersTable = config('admin.database.role_users_table');
        $database = DB::connection($connection);

        $database->table('operation_reports')->whereNull('operation_department_id')->orderBy('id')->chunkById(200, function ($reports) use ($database, $rolesTable, $roleUsersTable): void {
            foreach ($reports as $report) {
                $department = $database->table($rolesTable)
                    ->join($roleUsersTable, $rolesTable.'.id', '=', $roleUsersTable.'.role_id')
                    ->where($roleUsersTable.'.user_id', $report->operator_user_id)
                    ->where($rolesTable.'.slug', '!=', 'administrator')
                    ->where(function ($query) use ($rolesTable): void {
                        $query->where($rolesTable.'.slug', 'dept-000022')
                            ->orWhereExists(function ($child) use ($rolesTable): void {
                                $child->selectRaw('1')->from($rolesTable.' as operation_root')
                                    ->whereColumn('operation_root.id', $rolesTable.'.parent_id')
                                    ->where('operation_root.slug', 'dept-000022');
                            });
                    })
                    ->orderByDesc($roleUsersTable.'.is_primary')
                    ->orderBy($rolesTable.'.id')
                    ->first([$rolesTable.'.id', $rolesTable.'.name']);

                if ($department) {
                    $database->table('operation_reports')->where('id', $report->id)->update([
                        'operation_department_id' => $department->id,
                        'operation_department_name' => $department->name,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasColumn('operation_reports', 'operation_department_id')) return;

        $schema->table('operation_reports', function (Blueprint $table): void {
            $table->dropIndex('operation_reports_department_idx');
            $table->dropColumn(['operation_department_id', 'operation_department_name']);
        });
    }
};
