<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('operation_task_recaps')) return;

        $schema->table('operation_task_recaps', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('operation_task_recaps', 'workflow_status')) $table->string('workflow_status', 32)->default('approved')->index();
            if (! $schema->hasColumn('operation_task_recaps', 'leader_review_status')) $table->string('leader_review_status', 32)->default('approved');
            if (! $schema->hasColumn('operation_task_recaps', 'department_review_status')) $table->string('department_review_status', 32)->default('approved');
            if (! $schema->hasColumn('operation_task_recaps', 'last_rejection_channel')) $table->string('last_rejection_channel', 32)->nullable();
            if (! $schema->hasColumn('operation_task_recaps', 'last_rejection_opinion')) $table->text('last_rejection_opinion')->nullable();
            if (! $schema->hasColumn('operation_task_recaps', 'last_rejected_at')) $table->timestamp('last_rejected_at')->nullable();
            if (! $schema->hasColumn('operation_task_recaps', 'submitted_at')) $table->timestamp('submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('operation_task_recaps')) return;
        $schema->table('operation_task_recaps', function (Blueprint $table) use ($schema): void {
            foreach (['workflow_status', 'leader_review_status', 'department_review_status', 'last_rejection_channel', 'last_rejection_opinion', 'last_rejected_at', 'submitted_at'] as $column) {
                if ($schema->hasColumn('operation_task_recaps', $column)) $table->dropColumn($column);
            }
        });
    }
};
