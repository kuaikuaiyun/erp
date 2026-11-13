<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        foreach (['operation_task_recaps', 'operation_test_tasks'] as $name) {
            if (! $schema->hasTable($name)) continue;
            $schema->table($name, function (Blueprint $table): void {
                $table->softDeletes();
                $table->unsignedInteger('deleted_by')->nullable();
            });
        }
        if ($schema->hasTable('operation_task_recaps')) {
            $schema->table('operation_task_recaps', function (Blueprint $table): void {
                $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
                $table->dropUnique('operation_task_recap_user_date_unique');
                $table->unique(['user_id', 'data_date', 'active_slot'], 'operation_task_recap_active_date_unique');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if ($schema->hasTable('operation_task_recaps')) {
            $duplicates = DB::connection($connection)->table('operation_task_recaps')->select('user_id', 'data_date')
                ->groupBy('user_id', 'data_date')->havingRaw('COUNT(*) > 1')->exists();
            if ($duplicates) throw new RuntimeException('Cannot restore the old unique index while archived and active recaps share a date. Preserve and reconcile archived rows first.');
            $schema->table('operation_task_recaps', function (Blueprint $table): void {
                $table->dropUnique('operation_task_recap_active_date_unique');
                $table->dropColumn('active_slot');
                $table->unique(['user_id', 'data_date'], 'operation_task_recap_user_date_unique');
            });
        }
        foreach (['operation_task_recaps', 'operation_test_tasks'] as $name) {
            if ($schema->hasTable($name)) $schema->table($name, function (Blueprint $table): void {
                $table->dropSoftDeletes();
                $table->dropColumn('deleted_by');
            });
        }
    }
};
