<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        // These are reproducible report caches, not balances or closed-period records.
        $schema->dropIfExists('finance_report_scopes');
        $schema->dropIfExists('finance_report_snapshots');
        $schema->dropIfExists('finance_report_order_costs');
    }

    public function down(): void
    {
        (require __DIR__.'/2026_11_13_000004_create_finance_report_snapshots.php')->up();
        (require __DIR__.'/2026_11_13_000006_track_finance_report_refresh_attempts.php')->up();
    }
};
