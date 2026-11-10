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

        if (! $schema->hasTable('logistics_paypal_disputes')) {
            return;
        }

        $columns = [
            'event_logistics_status',
            'event_tracking_number',
            'event_shipped',
            'event_logistics_captured_at',
        ];
        $missing = array_filter($columns, fn (string $column): bool => ! $schema->hasColumn('logistics_paypal_disputes', $column));

        if ($missing !== []) {
            $schema->table('logistics_paypal_disputes', function (Blueprint $table) use ($missing): void {
                if (in_array('event_logistics_status', $missing, true)) $table->string('event_logistics_status', 50)->nullable()->index();
                if (in_array('event_tracking_number', $missing, true)) $table->text('event_tracking_number')->nullable();
                if (in_array('event_shipped', $missing, true)) $table->boolean('event_shipped')->nullable()->index();
                if (in_array('event_logistics_captured_at', $missing, true)) $table->dateTime('event_logistics_captured_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Historical event-state snapshots are audit data and are retained.
    }
};
