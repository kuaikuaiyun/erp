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

        if (!$schema->hasTable('aikaipin_selection_proposals') || !$schema->hasColumn('aikaipin_selection_proposals', 'product_name')) {
            return;
        }

        DB::connection($connection)->statement('ALTER TABLE `aikaipin_selection_proposals` MODIFY `product_name` VARCHAR(255) NOT NULL');
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('aikaipin_selection_proposals') || !$schema->hasColumn('aikaipin_selection_proposals', 'product_name')) {
            return;
        }

        $hasLongValue = DB::connection($connection)
            ->table('aikaipin_selection_proposals')
            ->whereRaw('CHAR_LENGTH(`product_name`) > 191')
            ->exists();

        if ($hasLongValue) {
            throw new \RuntimeException(__('aikaipin.migrations.selection_proposal_product_name_rollback_unsafe'));
        }

        DB::connection($connection)->statement('ALTER TABLE `aikaipin_selection_proposals` MODIFY `product_name` VARCHAR(191) NOT NULL');
    }
};
