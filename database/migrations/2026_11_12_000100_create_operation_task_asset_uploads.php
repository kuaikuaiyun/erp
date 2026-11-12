<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->create('operation_task_asset_uploads', function (Blueprint $table): void {
                $table->id();
                $table->uuid('token')->unique();
                $table->unsignedInteger('user_id')->index();
                $table->string('original_name', 255);
                $table->unsignedBigInteger('size');
                $table->string('status', 20)->default('uploading');
                $table->string('file_path', 512);
                $table->string('mime_type', 100)->nullable();
                $table->dateTime('expires_at');
                $table->timestamps();
                $table->index(['status', 'expires_at'], 'ot_asset_upload_expiry_idx');
            });
    }

    public function down(): void
    {
        // Keep upload ownership records and files when rolling application code back.
    }
};
