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

        if (! $schema->hasTable('assistant_domain_stores')) {
            return;
        }

        $columns = array_filter([
            'xshoppy_api_key' => ! $schema->hasColumn('assistant_domain_stores', 'xshoppy_api_key'),
            'xshoppy_password' => ! $schema->hasColumn('assistant_domain_stores', 'xshoppy_password'),
        ]);

        if ($columns === []) {
            // The option is still inserted below when the credential columns
            // were added by a prior deployment.
        } else {
            $schema->table('assistant_domain_stores', function (Blueprint $table) use ($columns): void {
                if ($columns['xshoppy_api_key'] ?? false) {
                    $table->string('xshoppy_api_key', 255)->nullable();
                }
                if ($columns['xshoppy_password'] ?? false) {
                    $table->string('xshoppy_password', 255)->nullable();
                }
            });
        }

        if ($schema->hasTable('assistant_domain_store_quick_options')) {
            DB::connection($connection)->table('assistant_domain_store_quick_options')->updateOrInsert(
                ['field' => 'site_builder', 'value' => 'xshoppy'],
                ['updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('assistant_domain_stores')) {
            return;
        }

        $columns = array_values(array_filter([
            $schema->hasColumn('assistant_domain_stores', 'xshoppy_api_key') ? 'xshoppy_api_key' : null,
            $schema->hasColumn('assistant_domain_stores', 'xshoppy_password') ? 'xshoppy_password' : null,
        ]));

        if ($columns !== []) {
            $schema->table('assistant_domain_stores', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
