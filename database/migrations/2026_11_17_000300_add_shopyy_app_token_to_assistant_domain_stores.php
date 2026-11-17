<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasTable('assistant_domain_stores') && ! $schema->hasColumn('assistant_domain_stores', 'shopyy_app_token')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->longText('shopyy_app_token')->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasColumn('assistant_domain_stores', 'shopyy_app_token')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->dropColumn('shopyy_app_token');
            });
        }
    }
};
