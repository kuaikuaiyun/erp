<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('assistant_domain_stores')) {
            return;
        }

        $columns = [
            'domain_check_root' => fn (Blueprint $table) => $table->string('domain_check_root', 191)->nullable(),
            'domain_checked_at' => fn (Blueprint $table) => $table->timestamp('domain_checked_at')->nullable(),
            'domain_check_http_status' => fn (Blueprint $table) => $table->unsignedSmallInteger('domain_check_http_status')->nullable(),
            'domain_check_message' => fn (Blueprint $table) => $table->string('domain_check_message', 100)->nullable(),
        ];
        $missingColumns = array_filter(
            $columns,
            fn (\Closure $definition, string $column): bool => !$schema->hasColumn('assistant_domain_stores', $column),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missingColumns !== []) {
            $schema->table('assistant_domain_stores', function (Blueprint $table) use ($missingColumns): void {
                foreach ($missingColumns as $definition) {
                    $definition($table);
                }
            });
        }

        $database = DB::connection($connection);
        $database->table('assistant_domain_stores')
            ->where('domain_check_status', 'no')
            ->update(['domain_check_status' => 'normal']);
        $database->table('assistant_domain_stores')
            ->whereIn('domain_check_status', ['yes', 'blocked'])
            ->update(['domain_check_status' => 'abnormal']);

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->string('domain_check_status', 20)->default('not_checked')->change();
            $table->index('domain_checked_at', 'assistant_store_domain_checked_index');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: historical check results must remain available.
    }
};
