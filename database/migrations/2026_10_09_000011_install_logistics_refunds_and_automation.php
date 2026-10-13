<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        if ($schema->hasTable('logistics_orders')) {
            $columns = [
                'payment_gateway' => fn (Blueprint $table) => $table->string('payment_gateway', 191)->nullable(),
                'shopify_customer_id' => fn (Blueprint $table) => $table->string('shopify_customer_id', 100)->nullable()->index(),
                'shopify_customer_order_count' => fn (Blueprint $table) => $table->unsignedInteger('shopify_customer_order_count')->nullable(),
                'subscription_link_source' => fn (Blueprint $table) => $table->string('subscription_link_source', 50)->nullable(),
                'subscription_link_status' => fn (Blueprint $table) => $table->string('subscription_link_status', 50)->nullable(),
                'logistics_status' => fn (Blueprint $table) => $table->string('logistics_status', 50)->default('待发货')->index(),
                'fulfillment_payload' => fn (Blueprint $table) => $table->longText('fulfillment_payload')->nullable(),
                'first_fulfilled_at' => fn (Blueprint $table) => $table->dateTime('first_fulfilled_at')->nullable()->index(),
                'paid_match_due_at' => fn (Blueprint $table) => $table->dateTime('paid_match_due_at')->nullable()->index(),
                'paid_match_scheduled_at' => fn (Blueprint $table) => $table->dateTime('paid_match_scheduled_at')->nullable(),
                'auto_match_paid_completed_at' => fn (Blueprint $table) => $table->dateTime('auto_match_paid_completed_at')->nullable(),
                'auto_match_fulfillment_scheduled_at' => fn (Blueprint $table) => $table->dateTime('auto_match_fulfillment_scheduled_at')->nullable(),
                'auto_match_fulfillment_completed_at' => fn (Blueprint $table) => $table->dateTime('auto_match_fulfillment_completed_at')->nullable(),
                'sku_fingerprint' => fn (Blueprint $table) => $table->string('sku_fingerprint', 64)->nullable(),
                'sku_exception_fingerprint' => fn (Blueprint $table) => $table->string('sku_exception_fingerprint', 64)->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (!$schema->hasColumn('logistics_orders', $column)) {
                    $schema->table('logistics_orders', function (Blueprint $table) use ($definition): void {
                        $definition($table);
                    });
                }
            }
        }

        if (!$schema->hasTable('logistics_refund_details')) {
            $schema->create('logistics_refund_details', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_order_id')->nullable()->index();
                $table->string('refund_number', 191)->index();
                $table->decimal('refund_amount_usd', 18, 4);
                $table->string('refund_type', 191)->nullable();
                $table->text('refund_reason')->nullable();
                $table->text('remark')->nullable();
                $table->string('fingerprint', 64)->unique();
                $table->boolean('is_void')->default(false)->index();
                $table->unsignedInteger('imported_by')->nullable();
                $table->string('imported_by_name', 100)->nullable();
                $table->unsignedInteger('voided_by')->nullable();
                $table->string('voided_by_name', 100)->nullable();
                $table->dateTime('voided_at')->nullable();
                $table->timestamps();

                $table->index(['logistics_order_id', 'is_void'], 'logistics_refund_order_void_index');
            });
        }

        if ($schema->hasTable('logistics_tongtu_match_tasks')) {
            foreach ([
                'priority' => fn (Blueprint $table) => $table->unsignedTinyInteger('priority')->default(100)->index(),
                'source' => fn (Blueprint $table) => $table->string('source', 30)->default('manual')->index(),
                'stage' => fn (Blueprint $table) => $table->string('stage', 30)->nullable(),
            ] as $column => $definition) {
                if (!$schema->hasColumn('logistics_tongtu_match_tasks', $column)) {
                    $schema->table('logistics_tongtu_match_tasks', function (Blueprint $table) use ($definition): void {
                        $definition($table);
                    });
                }
            }
        }
    }

    public function down(): void
    {
        // Operational order, refund and automation history is intentionally retained.
    }
};
