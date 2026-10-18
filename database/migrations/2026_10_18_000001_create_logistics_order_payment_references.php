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

        if (!$schema->hasTable('logistics_order_payment_references')) {
            $schema->create('logistics_order_payment_references', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_order_id');
                $table->string('reference_number', 191);
                $table->string('reference_type', 20)->default('primary');
                $table->string('gateway_order_id', 191)->nullable();
                $table->timestamps();

                $table->unique(['logistics_order_id', 'reference_number'], 'lopr_order_reference_uq');
                $table->index('reference_number', 'lopr_reference_idx');
                $table->index('logistics_order_id', 'lopr_order_idx');
            });
        }

        if ($schema->hasTable('logistics_order_payment_references')) {
            if (!$this->indexExists($connection, 'logistics_order_payment_references', 'lopr_order_reference_uq')) {
                $schema->table('logistics_order_payment_references', function (Blueprint $table): void {
                    $table->unique(['logistics_order_id', 'reference_number'], 'lopr_order_reference_uq');
                });
            }
            if (!$this->indexExists($connection, 'logistics_order_payment_references', 'lopr_reference_idx')) {
                $schema->table('logistics_order_payment_references', function (Blueprint $table): void {
                    $table->index('reference_number', 'lopr_reference_idx');
                });
            }
            if (!$this->indexExists($connection, 'logistics_order_payment_references', 'lopr_order_idx')) {
                $schema->table('logistics_order_payment_references', function (Blueprint $table): void {
                    $table->index('logistics_order_id', 'lopr_order_idx');
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
        // Payment references are historical accounting evidence and are retained on rollback.
    }
};
