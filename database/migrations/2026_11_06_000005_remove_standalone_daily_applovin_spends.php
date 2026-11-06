<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))->dropIfExists('daily_applovin_spends');
    }

    public function down(): void
    {
        // AppLovin 消耗由 operation_reports 自动统计，不再维护独立录入表。
    }
};
