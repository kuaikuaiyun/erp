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

        if ($schema->hasTable('logistics_orders')) {
            $addPaid = !$schema->hasIndex('logistics_orders', 'pay_orders_paid_ix');
            $addGatewayPaid = !$schema->hasIndex('logistics_orders', 'pay_orders_gateway_paid_ix');
            $addGatewayCreated = !$schema->hasIndex('logistics_orders', 'pay_orders_gateway_created_ix');
            if ($addPaid || $addGatewayPaid || $addGatewayCreated) {
                $schema->table('logistics_orders', function (Blueprint $table) use ($addPaid, $addGatewayPaid, $addGatewayCreated): void {
                    if ($addPaid) {
                        $table->index(['paid_at', 'id'], 'pay_orders_paid_ix');
                    }
                    if ($addGatewayPaid) {
                        $table->index(['payment_gateway', 'paid_at', 'id'], 'pay_orders_gateway_paid_ix');
                    }
                    if ($addGatewayCreated) {
                        $table->index(['payment_gateway', 'order_created_at', 'id'], 'pay_orders_gateway_created_ix');
                    }
                });
            }
        }

        if ($schema->hasTable('logistics_paypal_transactions')) {
            $addPaid = !$schema->hasIndex('logistics_paypal_transactions', 'pay_pp_tx_paid_ix');
            $addAccountEvent = !$schema->hasIndex('logistics_paypal_transactions', 'pay_pp_tx_account_event_ix');
            if ($addPaid || $addAccountEvent) {
                $schema->table('logistics_paypal_transactions', function (Blueprint $table) use ($addPaid, $addAccountEvent): void {
                    if ($addPaid) {
                        $table->index(['paid_at', 'id'], 'pay_pp_tx_paid_ix');
                    }
                    if ($addAccountEvent) {
                        $table->index(['logistics_paypal_account_id', 'event_code', 'status', 'paid_at'], 'pay_pp_tx_account_event_ix');
                    }
                });
            }
        }

        if ($schema->hasTable('logistics_paypal_refunds')) {
            $addEvent = !$schema->hasIndex('logistics_paypal_refunds', 'pay_pp_refund_account_event_ix');
            $addTransaction = !$schema->hasIndex('logistics_paypal_refunds', 'pay_pp_refund_account_tx_ix');
            if ($addEvent || $addTransaction) {
                $schema->table('logistics_paypal_refunds', function (Blueprint $table) use ($addEvent, $addTransaction): void {
                    if ($addEvent) {
                        $table->index(['logistics_paypal_account_id', 'refunded_at'], 'pay_pp_refund_account_event_ix');
                    }
                    if ($addTransaction) {
                        $table->index(['logistics_paypal_account_id', 'original_transaction_id'], 'pay_pp_refund_account_tx_ix');
                    }
                });
            }
        }

        if ($schema->hasTable('logistics_paypal_disputes')) {
            $addEvent = !$schema->hasIndex('logistics_paypal_disputes', 'pay_pp_dispute_account_event_ix');
            $addTransaction = !$schema->hasIndex('logistics_paypal_disputes', 'pay_pp_dispute_account_tx_ix');
            if ($addEvent || $addTransaction) {
                $schema->table('logistics_paypal_disputes', function (Blueprint $table) use ($addEvent, $addTransaction): void {
                    if ($addEvent) {
                        $table->index(['logistics_paypal_account_id', 'dispute_created_at'], 'pay_pp_dispute_account_event_ix');
                    }
                    if ($addTransaction) {
                        $table->index(['logistics_paypal_account_id', 'disputed_transaction_id'], 'pay_pp_dispute_account_tx_ix');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // Indexes are intentionally retained to avoid blocking financial tables during rollback.
    }
};
