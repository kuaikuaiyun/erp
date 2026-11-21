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
        if (! $schema->hasTable('portal_menus')) {
            return;
        }

        DB::connection($connection)->table('portal_menus')
            ->where('code', 'dashboard_applovin_personal_situations')
            ->update(['title' => '订阅AppLovin个人情况', 'updated_at' => now()]);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable('portal_menus')) {
            return;
        }

        DB::connection($connection)->table('portal_menus')
            ->where('code', 'dashboard_applovin_personal_situations')
            ->update(['title' => 'AppLovin个人情况', 'updated_at' => now()]);
    }
};
