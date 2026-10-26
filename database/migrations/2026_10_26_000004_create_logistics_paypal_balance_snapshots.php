<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (!$schema->hasTable('logistics_paypal_balance_snapshots')) {
            $schema->create('logistics_paypal_balance_snapshots', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_paypal_account_id');
                $table->unsignedBigInteger('logistics_paypal_import_id')->nullable();
                $table->string('upload_id', 64);
                $table->string('merchant_id', 100);
                $table->char('currency_code', 3);
                $table->decimal('total_balance', 20, 8)->nullable();
                $table->decimal('available_balance', 20, 8)->nullable();
                $table->decimal('withheld_balance', 20, 8)->nullable();
                $table->dateTime('as_of_time')->nullable();
                $table->dateTime('last_refresh_time')->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamps();
                $table->index('logistics_paypal_account_id', 'paypal_balance_account_index');
                $table->index('logistics_paypal_import_id', 'paypal_balance_import_index');
                $table->index('merchant_id', 'paypal_balance_merchant_index');
                $table->index('as_of_time', 'paypal_balance_as_of_index');
                $table->unique(['upload_id', 'currency_code'], 'paypal_balance_upload_currency_unique');
                $table->index(['logistics_paypal_account_id', 'currency_code', 'as_of_time'], 'paypal_balance_latest_index');
            });
        }
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->dropIfExists('logistics_paypal_balance_snapshots');
    }
};
