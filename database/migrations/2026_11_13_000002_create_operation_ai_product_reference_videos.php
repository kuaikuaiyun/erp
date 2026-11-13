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
        if ($schema->hasTable('operation_ai_product_reference_videos')) return;

        $schema->create('operation_ai_product_reference_videos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('aikaipin_product_id');
            $table->string('type', 20);
            $table->unsignedInteger('sequence');
            $table->date('file_date');
            $table->string('disk', 30)->default('local');
            $table->string('path', 500);
            $table->string('file_name', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name', 100)->nullable();
            $table->timestamps();
            $table->unique(['aikaipin_product_id', 'file_date', 'sequence'], 'operation_ai_reference_video_sequence_unique');
            $table->index(['aikaipin_product_id', 'type'], 'operation_ai_reference_video_product_type_index');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->dropIfExists('operation_ai_product_reference_videos');
    }
};
