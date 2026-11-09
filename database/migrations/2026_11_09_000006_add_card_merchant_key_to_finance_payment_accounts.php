<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('finance_payment_accounts', function (Blueprint $table): void {
                $table->string('card_merchant_key', 100)->nullable();
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('finance_payment_accounts', function (Blueprint $table): void {
                $table->dropColumn('card_merchant_key');
            });
    }
};
