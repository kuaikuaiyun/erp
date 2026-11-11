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

        if ($schema->hasTable('operation_task_rectifications')) {
            return;
        }

        $schema->create('operation_task_rectifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id')->index();
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->unsignedBigInteger('source_review_id')->unique();
            $table->string('target_type', 20)->index();
            $table->unsignedBigInteger('target_id')->index();
            $table->unsignedInteger('target_version');
            $table->string('review_channel', 30);
            $table->string('rejection_scope', 30);
            $table->text('rejection_opinion');
            $table->unsignedInteger('reviewer_id');
            $table->string('reviewer_name', 100);
            $table->string('status', 30)->default('required')->index();
            $table->timestamp('stop_confirmed_at')->nullable();
            $table->unsignedInteger('stop_confirmed_by')->nullable();
            $table->string('stop_confirmed_by_name', 100)->nullable();
            $table->string('stop_media', 100)->nullable();
            $table->string('stop_ad_identifier', 191)->nullable();
            $table->text('stop_notes')->nullable();
            $table->text('rectification_content')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->unsignedInteger('resubmitted_by')->nullable();
            $table->string('resubmitted_by_name', 100)->nullable();
            $table->unsignedInteger('resubmitted_task_version')->nullable();
            $table->unsignedBigInteger('resubmitted_asset_id')->nullable()->index();
            $table->timestamp('restored_at')->nullable();
            $table->unsignedInteger('restored_by')->nullable();
            $table->string('restored_by_name', 100)->nullable();
            $table->string('restored_media', 100)->nullable();
            $table->string('restored_ad_identifier', 191)->nullable();
            $table->text('restored_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->dropIfExists('operation_task_rectifications');
    }
};
