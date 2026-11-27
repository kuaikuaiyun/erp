<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('applovin_account_risk_notes', function (Blueprint $table): void {
                $table->dateTime('gift_granted_at')->nullable()->after('closed_at');
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('applovin_account_risk_notes', function (Blueprint $table): void {
                $table->dropColumn('gift_granted_at');
            });
    }
};
