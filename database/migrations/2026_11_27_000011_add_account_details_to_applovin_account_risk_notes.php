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
            $table->dateTime('closed_at')->nullable()->after('risk_factors');
            $table->text('account_status_note')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        $schema->table('applovin_account_risk_notes', function (Blueprint $table): void {
            $table->dropColumn(['closed_at', 'account_status_note']);
        });
    }
};
