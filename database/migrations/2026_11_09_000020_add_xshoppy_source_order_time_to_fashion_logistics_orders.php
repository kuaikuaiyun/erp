<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('fashion_logistics_orders')) {
            return;
        }

        $hasSourceCreatedAt = $schema->hasColumn('fashion_logistics_orders', 'source_order_created_at');
        $hasSourceTimezone = $schema->hasColumn('fashion_logistics_orders', 'source_order_timezone');
        if ($hasSourceCreatedAt && $hasSourceTimezone) {
            return;
        }

        $schema->table('fashion_logistics_orders', function (Blueprint $table) use ($hasSourceCreatedAt, $hasSourceTimezone): void {
            if (! $hasSourceCreatedAt) {
                $table->dateTime('source_order_created_at')->nullable()->after('customer_note');
            }
            if (! $hasSourceTimezone) {
                $table->string('source_order_timezone', 100)->nullable()->after('source_order_created_at');
            }
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('fashion_logistics_orders')) {
            return;
        }

        $columns = array_values(array_filter([
            $schema->hasColumn('fashion_logistics_orders', 'source_order_created_at') ? 'source_order_created_at' : null,
            $schema->hasColumn('fashion_logistics_orders', 'source_order_timezone') ? 'source_order_timezone' : null,
        ]));
        if ($columns !== []) {
            $schema->table('fashion_logistics_orders', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
