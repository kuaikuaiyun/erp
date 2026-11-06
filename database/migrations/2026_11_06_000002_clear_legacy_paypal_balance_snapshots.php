<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (Schema::connection($connection)->hasTable('logistics_paypal_balance_snapshots')) {
            DB::connection($connection)->table('logistics_paypal_balance_snapshots')->delete();
        }
    }

    public function down(): void
    {
        // Historical import-derived balances are intentionally not recoverable.
    }
};
