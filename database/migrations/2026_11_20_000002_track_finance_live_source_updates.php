<?php

use App\Portals\Finance\FinanceLiveSourceChanges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        FinanceLiveSourceChanges::install(DB::connection(config('admin.database.connection') ?: config('database.default')));
    }

    public function down(): void
    {
        FinanceLiveSourceChanges::install(DB::connection(config('admin.database.connection') ?: config('database.default')), legacy: true);
    }
};
