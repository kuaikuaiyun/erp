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

        if (!$schema->hasTable('aikaipin_product_categories')) {
            $schema->create('aikaipin_product_categories', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name', 80)->unique();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('aikaipin_products')) {
            $schema->create('aikaipin_products', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('product_code', 32)->unique();
                $table->string('name', 191);
                $table->string('source_platform', 30);
                $table->string('product_type', 30);
                $table->text('landing_page_url')->nullable();
                $table->unsignedInteger('created_by')->index();
                $table->string('created_by_name', 100)->nullable();
                $table->unsignedInteger('updated_by')->nullable()->index();
                $table->string('updated_by_name', 100)->nullable();
                $table->timestamps();

                $table->index(['source_platform', 'product_type'], 'aikaipin_product_source_type_index');
                $table->index('updated_at', 'aikaipin_product_updated_at_index');
            });
        }

        if (!$schema->hasTable('aikaipin_product_code_sequences')) {
            $schema->create('aikaipin_product_code_sequences', function (Blueprint $table): void {
                $table->string('period', 6)->primary();
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable('aikaipin_product_skus')) {
            $schema->create('aikaipin_product_skus', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_id');
                $table->string('sku', 191);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();

                $table->unique(['product_id', 'sku'], 'aikaipin_product_sku_unique');
                $table->index(['product_id', 'sort'], 'aikaipin_product_sku_sort_index');
            });
        }

        if (!$schema->hasTable('aikaipin_product_category_assignments')) {
            $schema->create('aikaipin_product_category_assignments', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('category_id');
                $table->timestamps();

                $table->unique(['product_id', 'category_id'], 'aikaipin_product_category_unique');
                $table->index('category_id', 'aikaipin_product_category_category_index');
            });
        }

        if (!$schema->hasTable('aikaipin_product_files')) {
            $schema->create('aikaipin_product_files', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_id');
                $table->string('kind', 20);
                $table->string('disk', 50)->default('local');
                $table->string('path', 1024);
                $table->string('original_name', 255);
                $table->string('mime_type', 191)->nullable();
                $table->unsignedBigInteger('size');
                $table->char('sha256', 64)->nullable();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();

                $table->index(['product_id', 'kind', 'sort'], 'aikaipin_product_file_sort_index');
            });
        }

        if (!$schema->hasTable('aikaipin_product_department_visibilities')) {
            $schema->create('aikaipin_product_department_visibilities', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedInteger('department_id');
                $table->timestamps();

                $table->unique(['product_id', 'department_id'], 'aikaipin_product_visibility_unique');
                $table->index('department_id', 'aikaipin_product_visibility_department_index');
            });
        }

        if (!$schema->hasTable('aikaipin_product_upload_sessions')) {
            $schema->create('aikaipin_product_upload_sessions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->uuid('upload_token')->unique();
                $table->unsignedInteger('user_id')->index();
                $table->char('client_fingerprint', 64);
                $table->string('original_name', 255);
                $table->string('mime_type', 191)->nullable();
                $table->unsignedBigInteger('total_size');
                $table->unsignedInteger('total_chunks');
                $table->unsignedBigInteger('received_size')->default(0);
                $table->string('disk', 50)->default('local');
                $table->string('temporary_directory', 1024);
                $table->string('assembled_path', 1024)->nullable();
                $table->string('status', 20)->default('uploading')->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['user_id', 'client_fingerprint'], 'aikaipin_upload_user_fingerprint_unique');
            });
        }

        if (!$schema->hasTable('aikaipin_product_upload_parts')) {
            $schema->create('aikaipin_product_upload_parts', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('upload_session_id');
                $table->unsignedInteger('chunk_index');
                $table->string('path', 1024);
                $table->unsignedBigInteger('size');
                $table->char('sha256', 64)->nullable();
                $table->timestamps();

                $table->unique(['upload_session_id', 'chunk_index'], 'aikaipin_upload_part_unique');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        $schema->dropIfExists('aikaipin_product_upload_parts');
        $schema->dropIfExists('aikaipin_product_upload_sessions');
        $schema->dropIfExists('aikaipin_product_department_visibilities');
        $schema->dropIfExists('aikaipin_product_files');
        $schema->dropIfExists('aikaipin_product_category_assignments');
        $schema->dropIfExists('aikaipin_product_skus');
        $schema->dropIfExists('aikaipin_product_code_sequences');
        $schema->dropIfExists('aikaipin_products');
        $schema->dropIfExists('aikaipin_product_categories');
    }
};
