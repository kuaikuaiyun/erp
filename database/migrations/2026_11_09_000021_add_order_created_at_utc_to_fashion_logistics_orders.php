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

        if ($schema->hasColumn('fashion_logistics_orders', 'order_created_at_utc')) return;

        $schema->table('fashion_logistics_orders', function (Blueprint $table): void {
            $table->dateTime('order_created_at_utc')->nullable()->after('source_order_timezone')->index();
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasColumn('fashion_logistics_orders', 'order_created_at_utc')) return;

        $schema->table('fashion_logistics_orders', function (Blueprint $table): void {
            $table->dropIndex(['order_created_at_utc']);
            $table->dropColumn('order_created_at_utc');
        });
    }
};
