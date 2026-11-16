<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasTable('ai_product_listing_tasks')) return;

        $schema->create('ai_product_listing_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('aikaipin_product_id')->index();
            $table->unsignedInteger('department_id')->index();
            $table->unsignedInteger('manager_user_id')->index();
            $table->unsignedInteger('assignee_user_id')->nullable()->index();
            $table->unsignedBigInteger('manager_recipient_id')->nullable()->index();
            $table->unsignedBigInteger('assignee_recipient_id')->nullable()->index();
            $table->unsignedBigInteger('publication_id')->nullable()->index();
            $table->string('status', 30)->default('pending_assignment')->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['aikaipin_product_id', 'department_id'], 'ai_product_listing_product_department_unique');
        });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->dropIfExists('ai_product_listing_tasks');
    }
};
