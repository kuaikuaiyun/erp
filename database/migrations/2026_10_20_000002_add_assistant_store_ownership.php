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

        if (!$schema->hasColumn('assistant_domain_stores', 'owner_user_id')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->unsignedInteger('owner_user_id')
                    ->nullable()
                    ->after('owner_department_id')
                    ->index('ads_owner_user_idx');
            });
        }

        if (!$schema->hasTable('assistant_dept_operation_scopes')) {
            $schema->create('assistant_dept_operation_scopes', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('assistant_department_id');
                $table->unsignedInteger('operation_department_id');
                $table->unsignedInteger('last_operator_id')->nullable();
                $table->timestamps();

                $table->unique(
                    ['assistant_department_id', 'operation_department_id'],
                    'ados_department_unique',
                );
                $table->index('operation_department_id', 'ados_operation_idx');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        $schema->dropIfExists('assistant_dept_operation_scopes');

        if ($schema->hasColumn('assistant_domain_stores', 'owner_user_id')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->dropIndex('ads_owner_user_idx');
                $table->dropColumn('owner_user_id');
            });
        }
    }
};
