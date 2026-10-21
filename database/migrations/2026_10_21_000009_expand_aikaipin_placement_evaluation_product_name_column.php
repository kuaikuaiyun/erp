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

        if (!$schema->hasTable('aikaipin_placement_evaluations') || !$schema->hasColumn('aikaipin_placement_evaluations', 'product_name')) {
            return;
        }

        DB::connection($connection)->statement('ALTER TABLE aikaipin_placement_evaluations MODIFY product_name VARCHAR(255) NOT NULL');
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('aikaipin_placement_evaluations') || !$schema->hasColumn('aikaipin_placement_evaluations', 'product_name')) {
            return;
        }

        $hasLongValue = DB::connection($connection)
            ->table('aikaipin_placement_evaluations')
            ->whereRaw('CHAR_LENGTH(product_name) > 191')
            ->exists();

        if ($hasLongValue) {
            throw new \RuntimeException(__('aikaipin.migrations.placement_evaluation_product_name_rollback_unsafe'));
        }

        DB::connection($connection)->statement('ALTER TABLE aikaipin_placement_evaluations MODIFY product_name VARCHAR(191) NOT NULL');
    }
};
