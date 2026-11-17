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
        if (! $schema->hasTable('operation_ai_product_reference_videos')) return;

        $schema->table('operation_ai_product_reference_videos', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('operation_ai_product_reference_videos', 'source_kind')) {
                $table->string('source_kind', 30)->default('upload')->after('department_code');
            }
            if (! $schema->hasColumn('operation_ai_product_reference_videos', 'source_reference_index')) {
                $table->unsignedInteger('source_reference_index')->nullable()->after('source_kind');
            }
            if (! $schema->hasColumn('operation_ai_product_reference_videos', 'source_url')) {
                $table->string('source_url', 1000)->nullable()->after('source_reference_index');
            }
        });

        if (! $this->hasIndex($connection, 'operation_ai_product_reference_videos', 'operation_ai_reference_video_source_unique')) {
            $schema->table('operation_ai_product_reference_videos', function (Blueprint $table): void {
                $table->unique(
                    ['aikaipin_product_id', 'source_kind', 'source_reference_index'],
                    'operation_ai_reference_video_source_unique',
                );
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('operation_ai_product_reference_videos')) return;

        if ($this->hasIndex($connection, 'operation_ai_product_reference_videos', 'operation_ai_reference_video_source_unique')) {
            $schema->table('operation_ai_product_reference_videos', fn (Blueprint $table) => $table->dropUnique('operation_ai_reference_video_source_unique'));
        }
        $columns = collect(['source_kind', 'source_reference_index', 'source_url'])
            ->filter(fn (string $column): bool => $schema->hasColumn('operation_ai_product_reference_videos', $column))
            ->all();
        if ($columns !== []) $schema->table('operation_ai_product_reference_videos', fn (Blueprint $table) => $table->dropColumn($columns));
    }

    private function hasIndex(string $connection, string $table, string $index): bool
    {
        $database = Schema::connection($connection)->getConnection();
        $name = $database->getDatabaseName();

        return $database->table('information_schema.statistics')
            ->where('table_schema', $name)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
