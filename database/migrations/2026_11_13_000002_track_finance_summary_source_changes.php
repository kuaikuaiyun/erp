<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $db->getSchemaBuilder()->hasTable('finance_summary_changes')) $db->getSchemaBuilder()->create('finance_summary_changes', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('source', 20);
            $table->json('payload');
        });
    }

    public function down(): void
    {
        // Preserve the application outbox.
    }
};
