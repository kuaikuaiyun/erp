<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->create('finance_credit_settlement_entries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('payment_account_id');
                $table->string('platform', 20);
                $table->date('settlement_date');
                $table->string('reference', 191)->nullable();
                $table->string('kind', 24);
                $table->string('source_sheet', 100);
                $table->unsignedInteger('source_row');
                $table->char('report_hash', 64);
                $table->json('payload');
                $table->unsignedInteger('imported_by');
                $table->timestamp('created_at');
                $table->index(['payment_account_id', 'platform', 'settlement_date'], 'finance_card_entries_report');
                $table->index(['platform', 'payment_account_id', 'reference'], 'finance_card_entries_order');
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->dropIfExists('finance_credit_settlement_entries');
    }
};
