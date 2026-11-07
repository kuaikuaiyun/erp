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
        if (! $schema->hasTable('operation_dashboard_department_scopes')) {
            $schema->create('operation_dashboard_department_scopes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('department_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'department_id'], 'operation_dashboard_user_department_unique');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->dropIfExists('operation_dashboard_department_scopes');
    }
};
