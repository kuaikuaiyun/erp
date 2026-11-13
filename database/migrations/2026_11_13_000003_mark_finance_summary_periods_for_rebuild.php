<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('finance_summary_periods')->increment('revision');
    }

    public function down(): void
    {
        $this->up();
    }
};
