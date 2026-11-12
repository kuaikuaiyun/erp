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

        $schema->dropIfExists('operation_task_delivery_rounds');

        if ($schema->hasTable('operation_task_assets') && $schema->hasColumn('operation_task_assets', 'first_live_at')) {
            $schema->table('operation_task_assets', function (Blueprint $table): void {
                $table->dropColumn('first_live_at');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('operation_task_assets') && ! $schema->hasColumn('operation_task_assets', 'first_live_at')) {
            $schema->table('operation_task_assets', function (Blueprint $table): void {
                $table->timestamp('first_live_at')->nullable()->after('version');
            });
        }

        if (! $schema->hasTable('operation_task_delivery_rounds')) {
            $schema->create('operation_task_delivery_rounds', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('task_id')->index();
                $table->unsignedBigInteger('asset_id')->index();
                $table->string('media', 100);
                $table->string('ad_identifier', 191)->nullable();
                $table->text('delivery_purpose')->nullable();
                $table->timestamp('launched_at');
                $table->timestamp('stopped_at')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by');
                $table->string('created_by_name', 100);
                $table->unsignedInteger('stopped_by')->nullable();
                $table->string('stopped_by_name', 100)->nullable();
                $table->timestamps();
            });
        }
    }
};
