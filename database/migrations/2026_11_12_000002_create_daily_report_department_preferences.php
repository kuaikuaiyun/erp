<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('daily_report_department_preferences')) {
            $schema->create('daily_report_department_preferences', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('operation_department_id');
                $table->timestamps();
                $table->unique(['user_id', 'operation_department_id'], 'daily_report_user_department_unique');
                $table->index('user_id', 'daily_report_pref_user_idx');
                $table->index('operation_department_id', 'daily_report_pref_department_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->dropIfExists('daily_report_department_preferences');
    }
};
