<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        if (!$schema->hasTable('payment_product_alert_assignments')) {
            $schema->create('payment_product_alert_assignments', function (Blueprint $table): void {
                $table->id();
                $table->string('alert_code', 40)->unique();
                $table->string('status', 30)->default('to_assign')->index();
                $table->unsignedInteger('department_id')->nullable()->index();
                $table->string('department_name_snapshot', 100)->nullable();
                $table->unsignedInteger('assignee_id')->nullable()->index();
                $table->string('assignee_name_snapshot', 100)->nullable();
                $table->string('assignee_position', 20)->nullable();
                $table->dateTime('deadline_at')->nullable();
                $table->text('assignment_note')->nullable();
                $table->unsignedInteger('assigned_by')->nullable();
                $table->string('assigned_by_name_snapshot', 100)->nullable();
                $table->dateTime('assigned_at')->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('payment_product_alert_assignment_logs')) {
            $schema->create('payment_product_alert_assignment_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('alert_code', 40)->index();
                $table->string('event', 30);
                $table->unsignedInteger('from_assignee_id')->nullable();
                $table->string('from_assignee_name', 100)->nullable();
                $table->unsignedInteger('to_assignee_id')->nullable();
                $table->string('to_assignee_name', 100)->nullable();
                $table->unsignedInteger('department_id')->nullable();
                $table->string('department_name_snapshot', 100)->nullable();
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('actor_name_snapshot', 100)->nullable();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['alert_code', 'created_at'], 'payment_alert_assignment_log_index');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->dropIfExists('payment_product_alert_assignment_logs');
        $schema->dropIfExists('payment_product_alert_assignments');
    }
};
