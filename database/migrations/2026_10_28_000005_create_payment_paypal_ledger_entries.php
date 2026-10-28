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

        if (!$schema->hasTable('payment_paypal_ledger_entries')) {
            $schema->create('payment_paypal_ledger_entries', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_paypal_account_id');
                $table->unsignedBigInteger('logistics_paypal_import_id')->nullable();
                $table->string('upload_id', 64)->nullable();
                $table->string('merchant_id', 100);
                $table->string('transaction_id', 100);
                $table->string('reference_id', 100)->nullable();
                $table->string('reference_id_type', 50)->nullable();
                $table->string('origin_payment_transaction_id', 100)->nullable();
                $table->string('event_code', 20)->nullable();
                $table->string('status', 50)->nullable();
                $table->string('currency_code', 10)->nullable();
                $table->decimal('transaction_amount', 18, 4)->nullable();
                $table->decimal('fee_amount', 18, 4)->nullable();
                $table->decimal('net_amount', 18, 4)->nullable();
                $table->string('invoice_id', 191)->nullable();
                $table->text('subject')->nullable();
                $table->dateTime('event_at')->nullable();
                $table->dateTime('source_updated_at')->nullable();
                $table->longText('raw_payload')->nullable();
                $table->timestamps();

                $table->unique(['logistics_paypal_account_id', 'transaction_id'], 'pp_ledger_account_txn_uq');
                $table->index(['logistics_paypal_account_id', 'event_at'], 'pp_ledger_account_event_ix');
                $table->index(['logistics_paypal_account_id', 'event_code', 'event_at'], 'pp_ledger_account_code_ix');
                $table->index(['logistics_paypal_account_id', 'origin_payment_transaction_id'], 'pp_ledger_account_origin_ix');
                $table->index('logistics_paypal_import_id', 'pp_ledger_import_ix');
                $table->index('reference_id', 'pp_ledger_reference_ix');
            });
        }

        if (!$schema->hasTable('payment_paypal_ledger_import_runs')) {
            $schema->create('payment_paypal_ledger_import_runs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_paypal_import_id');
                $table->unsignedBigInteger('logistics_paypal_account_id');
                $table->unsignedInteger('entry_count')->default(0);
                $table->unsignedInteger('error_count')->default(0);
                $table->dateTime('processed_at');
                $table->timestamps();

                $table->unique('logistics_paypal_import_id', 'pp_ledger_run_import_uq');
                $table->index(['logistics_paypal_account_id', 'processed_at'], 'pp_ledger_run_account_ix');
            });
        }
    }

    public function down(): void
    {
        // 支付资金流水属于财务审计数据，代码回滚时保留历史记录。
    }
};
