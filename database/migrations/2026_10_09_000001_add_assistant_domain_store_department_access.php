<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ASSISTANT_DEPARTMENT_CODE = 'dept-000004';

    private const DEFAULT_CHILD_DEPARTMENT_CODE = 'dept-000013';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $database = DB::connection($connection);

        if (!$schema->hasColumn('assistant_domain_stores', 'owner_department_id')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->unsignedInteger('owner_department_id')
                    ->nullable()
                    ->after('id')
                    ->index('assistant_store_owner_department_index');
            });
        }

        if (!$schema->hasTable('assistant_domain_store_department_accesses')) {
            $schema->create('assistant_domain_store_department_accesses', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('domain_store_id');
                $table->unsignedInteger('department_id');
                $table->timestamps();

                $table->unique(['domain_store_id', 'department_id'], 'assistant_store_department_access_unique');
                $table->index('department_id', 'assistant_store_department_access_department_index');
            });
        }

        $rolesTable = config('admin.database.roles_table');
        $assistantDepartmentId = $database->table($rolesTable)
            ->where('slug', self::ASSISTANT_DEPARTMENT_CODE)
            ->whereNull('parent_id')
            ->value('id');
        $defaultChildDepartmentId = $database->table($rolesTable)
            ->where('slug', self::DEFAULT_CHILD_DEPARTMENT_CODE)
            ->where('parent_id', $assistantDepartmentId)
            ->value('id');

        if (!$assistantDepartmentId || !$defaultChildDepartmentId) {
            throw new \RuntimeException('Assistant department hierarchy is incomplete; existing domain-store records cannot be assigned safely.');
        }

        $database->table('assistant_domain_stores')
            ->whereNull('owner_department_id')
            ->update(['owner_department_id' => $defaultChildDepartmentId]);

        $timestamp = now();
        $database->table('assistant_domain_stores')
            ->orderBy('id')
            ->select('id')
            ->chunkById(200, function ($stores) use ($database, $defaultChildDepartmentId, $timestamp): void {
                $rows = $stores->map(fn ($store): array => [
                    'domain_store_id' => $store->id,
                    'department_id' => $defaultChildDepartmentId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all();

                if ($rows !== []) {
                    $database->table('assistant_domain_store_department_accesses')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        $schema->dropIfExists('assistant_domain_store_department_accesses');

        if ($schema->hasColumn('assistant_domain_stores', 'owner_department_id')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->dropIndex('assistant_store_owner_department_index');
                $table->dropColumn('owner_department_id');
            });
        }
    }
};
