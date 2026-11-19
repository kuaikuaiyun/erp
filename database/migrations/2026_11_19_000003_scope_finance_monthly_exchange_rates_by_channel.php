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
        if (! $schema->hasTable('finance_monthly_exchange_rates')
            || $schema->hasColumn('finance_monthly_exchange_rates', 'channel')) {
            return;
        }

        $schema->table('finance_monthly_exchange_rates', function (Blueprint $table): void {
            // Existing global rates remain the fallback for every channel.
            $table->string('channel', 20)->default('combined')->after('month');
        });
        $schema->table('finance_monthly_exchange_rates', function (Blueprint $table): void {
            $table->dropUnique('finance_monthly_exchange_rates_month_currency_unique');
            $table->unique(['month', 'channel', 'currency'], 'finance_monthly_rates_scope_unique');
        });
    }

    public function down(): void
    {
        // Channel-specific rows cannot be collapsed without losing data.
    }
};
