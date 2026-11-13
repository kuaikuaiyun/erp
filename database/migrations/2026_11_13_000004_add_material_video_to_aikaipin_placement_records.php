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
        if (! $schema->hasTable('aikaipin_placement_evaluation_records')) return;
        $schema->table('aikaipin_placement_evaluation_records', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('aikaipin_placement_evaluation_records', 'material_name')) $table->string('material_name', 191)->nullable()->after('orders');
            if (! $schema->hasColumn('aikaipin_placement_evaluation_records', 'video_disk')) $table->string('video_disk', 30)->nullable()->after('material_name');
            if (! $schema->hasColumn('aikaipin_placement_evaluation_records', 'video_path')) $table->string('video_path', 500)->nullable()->after('video_disk');
            if (! $schema->hasColumn('aikaipin_placement_evaluation_records', 'video_name')) $table->string('video_name', 255)->nullable()->after('video_path');
            if (! $schema->hasColumn('aikaipin_placement_evaluation_records', 'video_mime_type')) $table->string('video_mime_type', 100)->nullable()->after('video_name');
            if (! $schema->hasColumn('aikaipin_placement_evaluation_records', 'video_size')) $table->unsignedBigInteger('video_size')->nullable()->after('video_mime_type');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if ($schema->hasTable('aikaipin_placement_evaluation_records')) {
            $schema->table('aikaipin_placement_evaluation_records', fn (Blueprint $table) => $table->dropColumn([
                'material_name', 'video_disk', 'video_path', 'video_name', 'video_mime_type', 'video_size',
            ]));
        }
    }
};
