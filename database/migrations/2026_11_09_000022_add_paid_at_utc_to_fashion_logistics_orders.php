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
        if ($schema->hasColumn('fashion_logistics_orders', 'paid_at_utc')) return;

        $schema->table('fashion_logistics_orders', function (Blueprint $table): void {
            $table->dateTime('paid_at_utc')->nullable()->after('paid_at')->index();
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasColumn('fashion_logistics_orders', 'paid_at_utc')) return;

        $schema->table('fashion_logistics_orders', function (Blueprint $table): void {
            $table->dropIndex(['paid_at_utc']);
            $table->dropColumn('paid_at_utc');
        });
    }
};
