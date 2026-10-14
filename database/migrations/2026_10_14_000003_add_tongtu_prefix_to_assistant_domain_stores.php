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

        if (!$schema->hasTable('assistant_domain_stores') || $schema->hasColumn('assistant_domain_stores', 'tongtu_prefix')) {
            return;
        }

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->string('tongtu_prefix', 100)->nullable()->after('store_prefix');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('assistant_domain_stores') && $schema->hasColumn('assistant_domain_stores', 'tongtu_prefix')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->dropColumn('tongtu_prefix');
            });
        }
    }
};
