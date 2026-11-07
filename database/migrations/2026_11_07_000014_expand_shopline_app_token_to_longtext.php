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

        if (! $schema->hasTable('assistant_domain_stores')
            || ! $schema->hasColumn('assistant_domain_stores', 'shopline_app_token')) {
            return;
        }

        DB::connection($connection)->statement(
            'ALTER TABLE `assistant_domain_stores` MODIFY `shopline_app_token` LONGTEXT NULL'
        );
    }

    public function down(): void
    {
        // Deliberately retain LONGTEXT on rollback so stored application tokens are never truncated.
    }
};
