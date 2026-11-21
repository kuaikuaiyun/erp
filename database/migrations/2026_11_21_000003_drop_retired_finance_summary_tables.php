<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schema = DB::connection(config('admin.database.connection') ?: config('database.default'))->getSchemaBuilder();
        foreach (['finance_summary_publications', 'finance_summary_facts', 'finance_summary_amounts'] as $table) {
            if (! $schema->hasTable($table)) throw new RuntimeException('请先创建结构化汇总表，再删除旧汇总缓存表。');
        }
        // These are disposable calculated caches, not transaction/statement sources.
        // Readers use verified facts, or the existing versioned source calculator.
        $schema->dropIfExists('finance_summary_buckets');
        $schema->dropIfExists('finance_summary_rows');
    }

    public function down(): void
    {
        // Retired derived caches are not recreated by rollback.
    }
};
