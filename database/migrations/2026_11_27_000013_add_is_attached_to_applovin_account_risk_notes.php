<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        $schema->table('applovin_account_risk_notes', function (Blueprint $table): void {
            $table->boolean('is_attached')->nullable()->after('account_status_note')->comment('是否挂户');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        $schema->table('applovin_account_risk_notes', function (Blueprint $table): void {
            $table->dropColumn('is_attached');
        });
    }
};
