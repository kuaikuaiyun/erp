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

        if (! $schema->hasTable('fashion_logistics_orders')
            || $schema->hasColumn('fashion_logistics_orders', 'marketing_source')) {
            return;
        }

        $schema->table('fashion_logistics_orders', function (Blueprint $table): void {
            $table->string('marketing_source', 40)->nullable()->after('source_domain')->index();
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('fashion_logistics_orders')
            && $schema->hasColumn('fashion_logistics_orders', 'marketing_source')) {
            $schema->table('fashion_logistics_orders', function (Blueprint $table): void {
                $table->dropColumn('marketing_source');
            });
        }
    }
};
