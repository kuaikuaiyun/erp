<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'flo_store_platform_paid_idx';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('fashion_logistics_orders')
            || $schema->hasIndex('fashion_logistics_orders', self::INDEX)) {
            return;
        }

        $schema->table('fashion_logistics_orders', function (Blueprint $table): void {
            $table->index(
                ['assistant_domain_store_id', 'source_platform', 'paid_at_utc'],
                self::INDEX,
            );
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('fashion_logistics_orders')
            || ! $schema->hasIndex('fashion_logistics_orders', self::INDEX)) {
            return;
        }

        $schema->table('fashion_logistics_orders', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
