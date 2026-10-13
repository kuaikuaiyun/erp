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

        if (!$schema->hasTable('assistant_domain_stores')
            || !$schema->hasColumn('assistant_domain_stores', 'site_builder')) {
            return;
        }

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->string('site_builder', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Do not shrink the column: custom values must not be truncated on rollback.
    }
};
