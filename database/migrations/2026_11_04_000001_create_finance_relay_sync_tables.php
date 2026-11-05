<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('finance_sync_logs')) {
            $schema->create('finance_sync_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('payment_account_id')->index();
                $table->string('operation', 40);
                $table->dateTime('range_from')->nullable();
                $table->dateTime('range_to')->nullable();
                $table->string('status', 20);
                $table->string('error_code', 40)->nullable();
                $table->unsignedInteger('page_count')->default(0);
                $table->unsignedInteger('record_count')->default(0);
                $table->unsignedInteger('actor_id')->nullable();
                $table->timestamps();
            });
        }
        if (! $schema->hasTable('finance_sync_pages')) {
            $schema->create('finance_sync_pages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('sync_log_id')->index();
                $table->json('query');
                $table->unsignedSmallInteger('http_status');
                $table->longText('raw_response');
                $table->timestamps();
            });
        }
        if (! $schema->hasTable('finance_balance_snapshots')) {
            $schema->create('finance_balance_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('payment_account_id')->index();
                $table->unsignedBigInteger('sync_log_id')->index();
                $table->json('payload');
                $table->dateTime('as_of');
                $table->timestamps();
            });
        }
        if (! $schema->hasTable('finance_payment_transactions')) {
            $schema->create('finance_payment_transactions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('payment_account_id')->index();
                $table->unsignedBigInteger('finance_record_id')->index();
                $table->string('transaction_id', 100);
                $table->string('event_code', 40)->default('');
                $table->json('raw_payload');
                $table->timestamps();
                $table->unique(['payment_account_id', 'transaction_id', 'event_code'], 'finance_transaction_identity');
            });
        }
        if (! $schema->hasColumn('finance_payment_accounts', 'deleted_at')) {
            $schema->table('finance_payment_accounts', fn (Blueprint $table) => $table->softDeletes());
        }
    }

    public function down(): void
    {
        // Keep financial history and signed-response evidence.
    }
};
