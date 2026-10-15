<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('logistics_orders')) {
            if (!$schema->hasColumn('logistics_orders', 'paypal_association_source')) {
                $schema->table('logistics_orders', function (Blueprint $table): void {
                    $table->string('paypal_association_source', 40)->nullable()->index();
                });
            }
            if (!$schema->hasColumn('logistics_orders', 'paypal_associated_at')) {
                $schema->table('logistics_orders', function (Blueprint $table): void {
                    $table->dateTime('paypal_associated_at')->nullable()->index();
                });
            }
        }

        if ($schema->hasTable('logistics_paypal_refunds')) {
            foreach ([
                'manual_reason' => fn (Blueprint $table) => $table->text('manual_reason')->nullable(),
                'manual_reason_updated_by' => fn (Blueprint $table) => $table->unsignedInteger('manual_reason_updated_by')->nullable(),
                'manual_reason_updated_by_name' => fn (Blueprint $table) => $table->string('manual_reason_updated_by_name', 100)->nullable(),
                'manual_reason_updated_at' => fn (Blueprint $table) => $table->dateTime('manual_reason_updated_at')->nullable(),
            ] as $column => $definition) {
                if (!$schema->hasColumn('logistics_paypal_refunds', $column)) {
                    $schema->table('logistics_paypal_refunds', function (Blueprint $table) use ($definition): void {
                        $definition($table);
                    });
                }
            }
        }

        if (!$schema->hasTable('logistics_credit_card_refunds')) {
            $schema->create('logistics_credit_card_refunds', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_order_id')->index();
                $table->string('bill_number', 191)->index();
                $table->string('refund_reference', 191);
                $table->decimal('refund_amount_usd', 18, 4);
                $table->text('refund_reason')->nullable();
                $table->unsignedInteger('imported_by')->nullable();
                $table->string('imported_by_name', 100)->nullable();
                $table->unsignedInteger('last_updated_by')->nullable();
                $table->string('last_updated_by_name', 100)->nullable();
                $table->dateTime('last_imported_at')->nullable();
                $table->timestamps();

                $table->unique(['logistics_order_id', 'refund_reference'], 'cc_refund_order_reference_unique');
                $table->index(['bill_number', 'refund_reference'], 'cc_refund_bill_reference_index');
            });
        }

        if (!$schema->hasTable('logistics_paypal_dispute_refunds')) {
            $schema->create('logistics_paypal_dispute_refunds', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_paypal_dispute_id');
                $table->unsignedBigInteger('logistics_paypal_refund_id');
                $table->string('match_source', 40);
                $table->string('raw_reference', 191)->nullable();
                $table->dateTime('matched_at')->nullable();
                $table->timestamps();

                $table->index('logistics_paypal_dispute_id', 'ppdr_dispute_id_idx');
                $table->index('logistics_paypal_refund_id', 'ppdr_refund_id_idx');
                $table->unique(['logistics_paypal_dispute_id', 'logistics_paypal_refund_id'], 'ppdr_dispute_refund_uq');
            });
        }

        // MySQL DDL is not transactional. If an earlier run created this table
        // before failing on an overlong automatic index name, finish the schema
        // without deleting the already-created table or any financial data.
        if ($schema->hasTable('logistics_paypal_dispute_refunds')) {
            if (!$this->indexExists($connection, 'logistics_paypal_dispute_refunds', 'ppdr_dispute_id_idx')) {
                $schema->table('logistics_paypal_dispute_refunds', function (Blueprint $table): void {
                    $table->index('logistics_paypal_dispute_id', 'ppdr_dispute_id_idx');
                });
            }

            if (!$this->indexExists($connection, 'logistics_paypal_dispute_refunds', 'ppdr_refund_id_idx')) {
                $schema->table('logistics_paypal_dispute_refunds', function (Blueprint $table): void {
                    $table->index('logistics_paypal_refund_id', 'ppdr_refund_id_idx');
                });
            }

            if (!$this->indexExists($connection, 'logistics_paypal_dispute_refunds', 'ppdr_dispute_refund_uq')) {
                $schema->table('logistics_paypal_dispute_refunds', function (Blueprint $table): void {
                    $table->unique(['logistics_paypal_dispute_id', 'logistics_paypal_refund_id'], 'ppdr_dispute_refund_uq');
                });
            }
        }
    }

    private function indexExists(string $connection, string $table, string $index): bool
    {
        foreach (DB::connection($connection)->select("SHOW INDEX FROM `{$table}`") as $row) {
            if (($row->Key_name ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        // Real financial data is intentionally retained on rollback.
    }
};
