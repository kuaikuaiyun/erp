<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $database = DB::connection($connection);

        foreach ([
            'daily_advertising_recharges',
            'daily_account_openings',
            'daily_store_launches',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $database->table($table)->delete();
            }
        }
    }

    public function down(): void
    {
        // Deleted submission data cannot be restored automatically.
    }
};
