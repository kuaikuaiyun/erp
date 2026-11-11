<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        if (! $schema->hasColumn('assistant_domain_stores', 'owner_operation_department_id')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->unsignedInteger('owner_operation_department_id')
                    ->nullable()
                    ->after('owner_user_id')
                    ->index('ads_owner_operation_department_idx');
            });
        }

        if (! $schema->hasColumn('assistant_domain_store_owner_histories', 'operation_department_id')) {
            $schema->table('assistant_domain_store_owner_histories', function (Blueprint $table): void {
                $table->unsignedInteger('operation_department_id')
                    ->nullable()
                    ->after('owner_name_snapshot')
                    ->index('ads_own_hist_operation_department_idx');
                $table->string('operation_department_name_snapshot', 100)
                    ->nullable()
                    ->after('operation_department_id');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        if ($schema->hasColumn('assistant_domain_store_owner_histories', 'operation_department_id')) {
            $schema->table('assistant_domain_store_owner_histories', function (Blueprint $table): void {
                $table->dropIndex('ads_own_hist_operation_department_idx');
                $table->dropColumn(['operation_department_id', 'operation_department_name_snapshot']);
            });
        }

        if ($schema->hasColumn('assistant_domain_stores', 'owner_operation_department_id')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->dropIndex('ads_owner_operation_department_idx');
                $table->dropColumn('owner_operation_department_id');
            });
        }
    }
};
