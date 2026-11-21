<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        if ($schema->hasTable('finance_transaction_storage')
            && (! $schema->hasColumn('finance_transactions', 'status_code') || ! $schema->hasColumn('finance_card_transaction_details', 'is_refunded'))) {
            $db->table('finance_transaction_storage')->where('id', 1)->update(['state' => 'building', 'last_record_id' => 0]);
        }
        if (! $schema->hasTable('finance_transactions')) $schema->create('finance_transactions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('id')->primary();
            $table->string('dataset', 40);
            $table->unsignedTinyInteger('channel_flags');
            $table->string('account', 191);
            $table->string('reference', 191);
            $table->dateTime('occurred_at')->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('status', 100)->nullable();
            $table->string('status_code', 100)->nullable();
            $table->string('raw_type', 191)->nullable();
            $table->string('source_timezone', 40)->nullable();
            $table->string('domain', 191)->nullable();
            $table->index(['channel_flags', 'occurred_at', 'id'], 'finance_tx_channel_page_idx');
            $table->index(['channel_flags', 'account', 'occurred_at', 'id'], 'finance_tx_account_page_idx');
            $table->index(['dataset', 'account', 'reference', 'occurred_at'], 'finance_tx_identity_idx');
            $table->index(['reference', 'id'], 'finance_tx_reference_idx');
            $table->index(['dataset', 'occurred_at', 'id'], 'finance_tx_dataset_page_idx');
            $table->index(['channel_flags', 'status_code', 'occurred_at', 'id'], 'finance_tx_status_page_idx');
        });
        if (! $schema->hasTable('finance_card_transaction_details')) $schema->create('finance_card_transaction_details', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('record_id')->primary();
            $table->string('explicit_channel', 20)->nullable();
            $table->string('merchant_order', 191)->nullable();
            $table->string('shopify_order', 191)->nullable();
            $table->string('gateway', 191)->nullable();
            $table->text('website')->nullable();
            $table->string('legacy_order', 191)->nullable();
            $table->string('platform_order', 191)->nullable();
            $table->string('linked_platform_order', 191)->nullable();
            $table->string('transaction_number', 191)->nullable();
            $table->string('erp_order', 191)->nullable();
            $table->boolean('has_worldpay_events')->default(false);
            $table->boolean('is_refunded')->default(false);
            $table->index(['merchant_order', 'record_id'], 'finance_card_merchant_idx');
            $table->index(['legacy_order', 'record_id'], 'finance_card_legacy_idx');
            $table->index(['gateway', 'record_id'], 'finance_card_gateway_idx');
            $table->index(['shopify_order', 'record_id'], 'finance_card_shopify_idx');
        });
        if (! $schema->hasColumn('finance_card_transaction_details', 'is_refunded')) {
            $schema->table('finance_card_transaction_details', fn (Blueprint $table) => $table->boolean('is_refunded')->default(false));
        }
        if (! $schema->hasIndex('finance_transactions', 'finance_tx_dataset_page_idx')) {
            $schema->table('finance_transactions', fn (Blueprint $table) => $table->index(['dataset', 'occurred_at', 'id'], 'finance_tx_dataset_page_idx'));
        }
        if (! $schema->hasColumn('finance_transactions', 'status_code')) {
            $schema->table('finance_transactions', fn (Blueprint $table) => $table->string('status_code', 100)->nullable());
        }
        if (! $schema->hasIndex('finance_transactions', 'finance_tx_status_page_idx')) {
            $schema->table('finance_transactions', fn (Blueprint $table) => $table->index(['channel_flags', 'status_code', 'occurred_at', 'id'], 'finance_tx_status_page_idx'));
        }
        if (! $schema->hasTable('finance_transaction_storage')) $schema->create('finance_transaction_storage', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedTinyInteger('id')->primary();
            $table->string('state', 20)->default('building');
            $table->unsignedBigInteger('last_record_id')->default(0);
        });
        $db->table('finance_transaction_storage')->insertOrIgnore(['id' => 1, 'state' => 'building', 'last_record_id' => 0]);
        app('request')->attributes->remove('finance.transactions-schema:'.$db->getName().':'.$db->getDatabaseName());
    }

    public function down(): void
    {
        // Preserve migrated business data. Rollback switches readers through the storage state.
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        if ($db->getSchemaBuilder()->hasTable('finance_transaction_storage')) {
            $db->table('finance_transaction_storage')->where('id', 1)->update(['state' => 'building']);
        }
    }
};
