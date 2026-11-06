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

        if (! $schema->hasTable('portal_home_folders')) {
            $schema->create('portal_home_folders', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name', 100);
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('portal_home_folder_portals')) {
            $schema->create('portal_home_folder_portals', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('folder_id');
                $table->unsignedInteger('portal_id');
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
                $table->unique('portal_id');
                $table->index(['folder_id', 'sort']);
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $schema->dropIfExists('portal_home_folder_portals');
        $schema->dropIfExists('portal_home_folders');
    }
};
