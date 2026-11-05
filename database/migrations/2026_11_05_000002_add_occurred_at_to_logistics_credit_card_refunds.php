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

        if (! $schema->hasTable('logistics_credit_card_refunds') || $schema->hasColumn('logistics_credit_card_refunds', 'occurred_at')) {
            return;
        }

        $schema->table('logistics_credit_card_refunds', function (Blueprint $table): void {
            $table->dateTime('occurred_at')->nullable()->index('cc_refund_occurred_at_idx');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('logistics_credit_card_refunds') || ! $schema->hasColumn('logistics_credit_card_refunds', 'occurred_at')) {
            return;
        }

        $schema->table('logistics_credit_card_refunds', function (Blueprint $table): void {
            $table->dropIndex('cc_refund_occurred_at_idx');
            $table->dropColumn('occurred_at');
        });
    }
};
