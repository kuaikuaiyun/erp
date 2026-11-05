<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasTable('finance_payment_balances')) {
            return;
        }
        $schema->create('finance_payment_balances', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 40);
            $table->string('account', 100);
            $table->string('currency', 3);
            $table->decimal('withdrawable', 18, 4);
            $table->decimal('pending', 18, 4);
            $table->decimal('reserve', 18, 4);
            $table->decimal('usd_rate', 18, 8)->nullable();
            $table->dateTime('as_of');
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'account', 'currency'], 'finance_balance_account_currency_unique');
        });
    }

    public function down(): void
    {
        // Retain financial balances when rolling back application code.
    }
};
