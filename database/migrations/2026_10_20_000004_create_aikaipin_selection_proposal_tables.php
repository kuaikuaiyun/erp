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

        if (!$schema->hasTable('aikaipin_selection_proposals')) {
            $schema->create('aikaipin_selection_proposals', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('proposal_code', 32)->unique();
                $table->string('product_name', 191);
                $table->text('link_1688')->nullable();
                $table->text('competitor_url')->nullable();
                $table->text('remark')->nullable();
                $table->unsignedInteger('created_by')->index();
                $table->string('created_by_name', 100)->nullable();
                $table->unsignedInteger('updated_by')->nullable()->index();
                $table->string('updated_by_name', 100)->nullable();
                $table->timestamp('withdrawn_at')->nullable()->index();
                $table->unsignedInteger('withdrawn_by')->nullable()->index();
                $table->string('withdrawn_by_name', 100)->nullable();
                $table->timestamps();

                $table->index(['created_by', 'withdrawn_at'], 'aikaipin_selection_creator_status_index');
                $table->index('updated_at', 'aikaipin_selection_updated_at_index');
            });
        }

        if (!$schema->hasTable('aikaipin_selection_proposal_code_sequences')) {
            $schema->create('aikaipin_selection_proposal_code_sequences', function (Blueprint $table): void {
                $table->string('period', 6)->primary();
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable('aikaipin_selection_proposal_tags')) {
            $schema->create('aikaipin_selection_proposal_tags', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('proposal_id');
                $table->string('type', 20);
                $table->string('value', 80);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();

                $table->unique(['proposal_id', 'type', 'value'], 'aikaipin_selection_tag_unique');
                $table->index(['proposal_id', 'type', 'sort'], 'aikaipin_selection_tag_sort_index');
            });
        }

        if (!$schema->hasTable('aikaipin_selection_proposal_images')) {
            $schema->create('aikaipin_selection_proposal_images', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('proposal_id');
                $table->string('disk', 50)->default('local');
                $table->string('path', 1024);
                $table->string('original_name', 255);
                $table->string('mime_type', 191)->nullable();
                $table->unsignedBigInteger('size');
                $table->char('sha256', 64)->nullable();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();

                $table->index(['proposal_id', 'sort'], 'aikaipin_selection_image_sort_index');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        $schema->dropIfExists('aikaipin_selection_proposal_images');
        $schema->dropIfExists('aikaipin_selection_proposal_tags');
        $schema->dropIfExists('aikaipin_selection_proposal_code_sequences');
        $schema->dropIfExists('aikaipin_selection_proposals');
    }
};
