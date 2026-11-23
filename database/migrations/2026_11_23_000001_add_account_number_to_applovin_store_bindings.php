<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasColumn('applovin_account_store_bindings', 'account_number')) {
            $schema->table('applovin_account_store_bindings', function (Blueprint $table): void {
                $table->string('account_number', 191)->nullable()->after('account_key')->index();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasColumn('applovin_account_store_bindings', 'account_number')) {
            $schema->table('applovin_account_store_bindings', fn (Blueprint $table) => $table->dropColumn('account_number'));
        }
    }
};
