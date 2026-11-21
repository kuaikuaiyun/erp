<?php

use App\Portals\Finance\FinanceSummaryFacts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schema = DB::connection(config('admin.database.connection') ?: config('database.default'))->getSchemaBuilder();
        if (! $schema->hasTable('finance_summary_publications')) $schema->create('finance_summary_publications', function (Blueprint $table): void {
            $table->engine = 'InnoDB'; $table->id();
            $table->string('month', 7); $table->string('channel', 12); $table->string('timezone', 32);
            $table->unsignedBigInteger('revision'); $table->char('source_version', 64);
            $table->boolean('complete')->default(false); $table->boolean('verified')->default(false); $table->unsignedBigInteger('row_count')->default(0);
            $table->index(['month', 'channel', 'timezone', 'source_version', 'complete'], 'finance_summary_publication_idx');
        });
        if (! $schema->hasColumn('finance_summary_publications', 'verified')) {
            $schema->table('finance_summary_publications', fn (Blueprint $table) => $table->boolean('verified')->default(false));
        }
        if (! $schema->hasTable('finance_summary_facts')) $schema->create('finance_summary_facts', function (Blueprint $table): void {
            $table->engine = 'InnoDB'; $table->id(); $table->unsignedBigInteger('publication_id');
            $table->unsignedBigInteger('position'); $table->bigInteger('record_id')->nullable();
            foreach (FinanceSummaryFacts::DIMENSIONS as $field) $table->string($field, 191)->nullable();
            $table->string('summary_team', 191)->nullable(); $table->string('settlement_currency', 10)->nullable();
            $table->string('native_currency', 10)->nullable();
            foreach (['success', 'failed', 'cost_adjustment', 'xuzong_detail'] as $field) $table->boolean($field)->default(false);
            $table->index(['publication_id', 'payment_channel', 'id'], 'finance_summary_fact_page_idx');
        });
        if (! $schema->hasTable('finance_summary_amounts')) $schema->create('finance_summary_amounts', function (Blueprint $table): void {
            $table->engine = 'InnoDB'; $table->unsignedBigInteger('fact_id'); $table->unsignedTinyInteger('basis');
            foreach (FinanceSummaryFacts::amounts() as $field) $table->decimal($field, 38, 16)->nullable();
            $table->primary(['fact_id', 'basis']);
        });
    }

    public function down(): void
    {
        // Keep verified financial facts for rollback/reconciliation.
    }
};
