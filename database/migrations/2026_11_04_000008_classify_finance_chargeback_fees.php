<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Apply the expanded defaults, preserving custom categories and closed periods.
        (require __DIR__.'/2026_11_04_000007_map_finance_paypal_event_types.php')->up();
    }

    public function down(): void {}
};
