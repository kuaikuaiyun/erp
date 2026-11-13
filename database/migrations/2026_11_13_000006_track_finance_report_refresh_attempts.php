<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->table('finance_report_scopes', function (Blueprint $table): void {
            $table->timestamp('failed_at')->nullable();
        });
        $schema->create('finance_report_order_costs', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->bigIncrements('id');
            $table->uuid('run_id');
            $table->unsignedBigInteger('order_id');
            $table->text('payment')->nullable();
            $table->string('purchase')->nullable();
            $table->string('logistics')->nullable();
            $table->unique(['run_id', 'order_id']);
            $table->index(['run_id', 'id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->dropIfExists('finance_report_order_costs');
        $schema->table('finance_report_scopes', function (Blueprint $table): void {
            $table->dropColumn('failed_at');
        });
    }
};
