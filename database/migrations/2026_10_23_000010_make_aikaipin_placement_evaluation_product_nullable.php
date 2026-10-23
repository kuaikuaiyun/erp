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

        if (!$schema->hasTable('aikaipin_placement_evaluations')
            || !$schema->hasColumn('aikaipin_placement_evaluations', 'product_id')) {
            return;
        }

        DB::connection($connection)->statement(
            'ALTER TABLE aikaipin_placement_evaluations MODIFY product_id BIGINT UNSIGNED NULL'
        );
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('aikaipin_placement_evaluations')
            || !$schema->hasColumn('aikaipin_placement_evaluations', 'product_id')) {
            return;
        }

        if (DB::connection($connection)->table('aikaipin_placement_evaluations')->whereNull('product_id')->exists()) {
            throw new RuntimeException(__('aikaipin.migrations.placement_evaluation_product_id_rollback_unsafe'));
        }

        DB::connection($connection)->statement(
            'ALTER TABLE aikaipin_placement_evaluations MODIFY product_id BIGINT UNSIGNED NOT NULL'
        );
    }
};
