<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable('portal_menus')) return;

        DB::connection($connection)->table('portal_menus')
            ->where('code', 'assistant_operation_report_reviews')
            ->update(['title' => '运营广告费审核', 'updated_at' => now()]);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable('portal_menus')) return;

        DB::connection($connection)->table('portal_menus')
            ->where('code', 'assistant_operation_report_reviews')
            ->update(['title' => '运营报表', 'updated_at' => now()]);
    }
};
