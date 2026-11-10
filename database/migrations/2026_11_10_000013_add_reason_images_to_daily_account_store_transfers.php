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

        if ($schema->hasTable('daily_account_store_transfers') && ! $schema->hasColumn('daily_account_store_transfers', 'reason_images')) {
            $schema->table('daily_account_store_transfers', function (Blueprint $table): void {
                $table->json('reason_images')->nullable()->after('reason');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('daily_account_store_transfers') && $schema->hasColumn('daily_account_store_transfers', 'reason_images')) {
            $schema->table('daily_account_store_transfers', function (Blueprint $table): void {
                $table->dropColumn('reason_images');
            });
        }
    }
};
