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

        if (! $schema->hasTable('assistant_domain_stores')
            || $schema->hasColumn('assistant_domain_stores', 'shopline_app_token')) {
            return;
        }

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->string('shopline_app_token', 255)->nullable();
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('assistant_domain_stores')
            && $schema->hasColumn('assistant_domain_stores', 'shopline_app_token')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->dropColumn('shopline_app_token');
            });
        }
    }
};
