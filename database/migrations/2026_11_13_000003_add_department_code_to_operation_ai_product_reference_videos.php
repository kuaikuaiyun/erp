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
        if (! $schema->hasTable('operation_ai_product_reference_videos')
            || $schema->hasColumn('operation_ai_product_reference_videos', 'department_code')) {
            return;
        }

        $schema->table('operation_ai_product_reference_videos', function (Blueprint $table): void {
            $table->string('department_code', 30)->nullable()->after('size');
        });

        $database = DB::connection($connection);
        $rolesTable = config('admin.database.roles_table', 'admin_roles');
        $roleUsersTable = config('admin.database.role_users_table', 'admin_role_users');
        $subscriptionRootId = $database->table($rolesTable)->where('slug', 'dept-000022')->value('id');
        $videos = $database->table('operation_ai_product_reference_videos')->whereNotNull('uploaded_by')->get(['id', 'uploaded_by']);
        foreach ($videos as $video) {
            $query = $database->table($roleUsersTable)
                ->join($rolesTable, $roleUsersTable.'.role_id', '=', $rolesTable.'.id')
                ->where($roleUsersTable.'.user_id', $video->uploaded_by)
                ->where($rolesTable.'.slug', '!=', 'administrator')
                ->whereNotNull($rolesTable.'.short_name')
                ->where($rolesTable.'.short_name', '!=', '');
            $code = (clone $query)
                ->when($subscriptionRootId, fn ($builder) => $builder->where($rolesTable.'.parent_id', $subscriptionRootId))
                ->orderBy($rolesTable.'.id')
                ->value($rolesTable.'.short_name');
            $code ??= $query->orderBy($rolesTable.'.id')->value($rolesTable.'.short_name');
            $database->table('operation_ai_product_reference_videos')->where('id', $video->id)->update([
                'department_code' => $code ?: 'AI',
            ]);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if ($schema->hasTable('operation_ai_product_reference_videos')
            && $schema->hasColumn('operation_ai_product_reference_videos', 'department_code')) {
            $schema->table('operation_ai_product_reference_videos', fn (Blueprint $table) => $table->dropColumn('department_code'));
        }
    }
};
