<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasColumn('operation_test_tasks', 'submission_token')) {
            $schema->table('operation_test_tasks', function (Blueprint $table): void {
                $table->uuid('submission_token')->nullable()->unique('ot_task_submission_token_unique');
            });
        }
    }

    public function down(): void
    {
        // Retain submission identities when application code is rolled back.
    }
};
