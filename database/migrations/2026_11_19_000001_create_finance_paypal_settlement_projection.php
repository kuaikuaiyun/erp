<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_paypal_settlement_projections', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('generation');
            $table->char('source_revision', 64);
            $table->unsignedBigInteger('row_count');
            $table->timestamp('published_at');
        });
        Schema::create('finance_paypal_settlement_rows', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('generation');
            $table->unsignedBigInteger('position');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('account')->nullable();
            $table->string('reference')->nullable();
            $table->string('team', 100)->nullable();
            $table->string('subscription', 100)->nullable();
            $table->string('domain')->nullable();
            $table->string('order_status', 100)->nullable();
            $table->json('payload');
            $table->primary(['generation', 'position']);
            $table->index(['generation', 'record_id']);
            $table->index(['generation', 'occurred_at']);
            $table->index(['generation', 'account']);
            $table->index(['generation', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_paypal_settlement_rows');
        Schema::dropIfExists('finance_paypal_settlement_projections');
    }
};
