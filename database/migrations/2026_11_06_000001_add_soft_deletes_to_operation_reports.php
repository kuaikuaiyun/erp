<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasColumn('operation_reports', 'deletion_key')) {
            $schema->table('operation_reports', function (Blueprint $table): void {
                $table->unsignedBigInteger('deletion_key')->default(0)->after('review_reason');
                $table->softDeletes();
            });
        }

        $schema->table('operation_reports', function (Blueprint $table): void {
            $table->dropUnique('operation_report_date_operator_unique');
            $table->unique(
                ['report_date', 'operator_user_id', 'deletion_key'],
                'operation_report_date_operator_deletion_unique',
            );
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        DB::connection($connection)->table('operation_reports')->whereNotNull('deleted_at')->delete();
        $schema->table('operation_reports', function (Blueprint $table): void {
            $table->dropUnique('operation_report_date_operator_deletion_unique');
            $table->dropSoftDeletes();
            $table->dropColumn('deletion_key');
            $table->unique(['report_date', 'operator_user_id'], 'operation_report_date_operator_unique');
        });
    }
};
