<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->table('finance_records', function (Blueprint $table): void {
            $table->index(['dataset', 'occurred_at', 'id'], 'finance_records_dataset_page_idx');
            $table->index(['dataset', 'account', 'reference'], 'finance_records_dataset_account_ref_idx');
        });
        $schema->table('finance_balance_snapshots', function (Blueprint $table): void {
            $table->index(['payment_account_id', 'as_of', 'id'], 'finance_balance_latest_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->table('finance_records', function (Blueprint $table): void {
            $table->dropIndex('finance_records_dataset_page_idx');
            $table->dropIndex('finance_records_dataset_account_ref_idx');
        });
        $schema->table('finance_balance_snapshots', fn (Blueprint $table) => $table->dropIndex('finance_balance_latest_idx'));
    }
};
