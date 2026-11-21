<?php

use App\Portals\Finance\FinancePayPalListStorage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schema = DB::connection(config('admin.database.connection') ?: config('database.default'))->getSchemaBuilder();
        if (! $schema->hasTable('finance_paypal_list_publications')) $schema->create('finance_paypal_list_publications', function (Blueprint $table): void {
            $table->engine = 'InnoDB'; $table->unsignedTinyInteger('id')->primary();
            $table->uuid('generation'); $table->char('source_revision', 64); $table->unsignedBigInteger('row_count'); $table->timestamp('published_at');
        });
        if (! $schema->hasTable('finance_paypal_list_rows')) $schema->create('finance_paypal_list_rows', function (Blueprint $table): void {
            $table->engine = 'InnoDB'; $table->uuid('generation'); $table->unsignedBigInteger('position');
            $table->unsignedBigInteger('record_id'); $table->boolean('has_payment');
            $table->string('date', 30); $table->string('month', 7);
            foreach (FinancePayPalListStorage::LABELS as $field) {
                if ($field === 'review') $table->text($field)->nullable();
                else $table->string($field, 191)->nullable();
            }
            foreach (FinancePayPalListStorage::FLAGS as $field) $table->boolean($field)->nullable();
            $table->primary(['generation', 'position']);
        });
        if (! $schema->hasTable('finance_paypal_list_amounts')) $schema->create('finance_paypal_list_amounts', function (Blueprint $table): void {
            $table->engine = 'InnoDB'; $table->uuid('generation'); $table->unsignedBigInteger('position');
            foreach (FinancePayPalListStorage::amounts() as $field) $table->decimal($field, 38, 16)->nullable();
            $table->primary(['generation', 'position']);
        });
        // The old projection contains only reproducible list calculations.
        $schema->dropIfExists('finance_paypal_settlement_rows');
        $schema->dropIfExists('finance_paypal_settlement_projections');
    }

    public function down(): void {}
};
