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

        if (!$schema->hasTable('aikaipin_placement_evaluations')) {
            $schema->create('aikaipin_placement_evaluations', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('evaluation_code', 32)->unique();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('product_code', 32)->index();
                $table->string('product_name', 191);
                $table->date('test_started_on');
                $table->date('data_ended_on');
                $table->text('remark')->nullable();
                $table->unsignedInteger('created_by')->index();
                $table->string('created_by_name', 100)->nullable();
                $table->unsignedInteger('updated_by')->nullable()->index();
                $table->string('updated_by_name', 100)->nullable();
                $table->timestamps();

                $table->index(['created_by', 'updated_at'], 'aikaipin_placement_creator_updated_index');
                $table->index('updated_at', 'aikaipin_placement_updated_at_index');
            });
        }

        if (!$schema->hasTable('aikaipin_placement_evaluation_records')) {
            $schema->create('aikaipin_placement_evaluation_records', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('evaluation_id');
                $table->string('platform', 32);
                $table->string('currency', 10);
                $table->decimal('actual_spend', 16, 2);
                $table->unsignedInteger('orders');
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();

                $table->index(['evaluation_id', 'sort'], 'aikaipin_placement_record_sort_index');
            });
        }

        if (!$schema->hasTable('aikaipin_placement_evaluation_code_sequences')) {
            $schema->create('aikaipin_placement_evaluation_code_sequences', function (Blueprint $table): void {
                $table->string('period', 6)->primary();
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        $schema->dropIfExists('aikaipin_placement_evaluation_records');
        $schema->dropIfExists('aikaipin_placement_evaluation_code_sequences');
        $schema->dropIfExists('aikaipin_placement_evaluations');
    }
};
