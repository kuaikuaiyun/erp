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

        if ($schema->hasTable('logistics_paypal_transactions')) {
            return;
        }

        $schema->create('logistics_paypal_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('logistics_paypal_account_id');
            $table->unsignedBigInteger('logistics_order_id')->nullable();
            $table->string('payment_transaction_id', 100);
            $table->string('invoice_id', 191)->nullable();
            $table->string('event_code', 20)->nullable();
            $table->string('status', 50)->nullable();
            $table->decimal('payment_amount_usd', 18, 4)->nullable();
            $table->decimal('original_amount', 18, 4)->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->text('payment_subject')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->string('match_source', 40)->nullable();
            $table->dateTime('matched_at')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            // All names are intentionally short: MySQL limits identifiers to 64 characters.
            $table->unique(['logistics_paypal_account_id', 'payment_transaction_id'], 'pp_tx_account_txn_uq');
            $table->index(['logistics_paypal_account_id', 'logistics_order_id'], 'pp_tx_account_order_ix');
            $table->index(['logistics_paypal_account_id', 'paid_at'], 'pp_tx_account_paid_ix');
            $table->index(['logistics_paypal_account_id', 'invoice_id'], 'pp_tx_account_invoice_ix');
        });
    }

    public function down(): void
    {
        // Financial source records must never be removed automatically on rollback.
    }
};
