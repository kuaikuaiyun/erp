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
        if ($schema->hasTable('daily_account_openings') && ! $schema->hasColumn('daily_account_openings', 'response_text')) {
            $schema->table('daily_account_openings', function (Blueprint $table): void {
                $table->text('response_text')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if ($schema->hasTable('daily_account_openings') && $schema->hasColumn('daily_account_openings', 'response_text')) {
            $schema->table('daily_account_openings', fn (Blueprint $table) => $table->dropColumn('response_text'));
        }
    }
};
