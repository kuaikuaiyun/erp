<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->create('finance_report_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('scope', 64)->index();
            $table->char('revision', 64);
            $table->longText('payload');
            $table->timestamp('created_at')->index();
            $table->index(['scope', 'revision']);
        });
        $schema->create('finance_report_scopes', function (Blueprint $table): void {
            $table->char('scope', 64)->primary();
            $table->string('month', 7)->index();
            $table->string('report', 20);
            $table->text('filters');
            $table->timestamp('used_at')->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->dropIfExists('finance_report_scopes');
        $schema->dropIfExists('finance_report_snapshots');
    }
};
