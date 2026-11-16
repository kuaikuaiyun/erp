<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('finance_credit_settlement_entries', function (Blueprint $table): void {
                $table->index(['platform', 'settlement_date', 'payment_account_id'], 'finance_card_entries_period');
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('finance_credit_settlement_entries', fn (Blueprint $table) => $table->dropIndex('finance_card_entries_period'));
    }
};
