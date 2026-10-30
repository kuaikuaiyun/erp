<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        if (Schema::connection($connection)->hasTable('portal_menus')) {
            $database->table('portal_menus')->where('code', 'advertising_reports')->update([
                'title' => '广告费提报', 'updated_at' => now(),
            ]);
        }
        $permissionsTable = config('admin.database.permissions_table');
        if ($permissionsTable && Schema::connection($connection)->hasTable($permissionsTable)) {
            $database->table($permissionsTable)->where('slug', 'portal.yunying.advertising_reports')->update([
                'name' => '[运营系统] 广告费提报', 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        if (Schema::connection($connection)->hasTable('portal_menus')) {
            $database->table('portal_menus')->where('code', 'advertising_reports')->update([
                'title' => '广告提报', 'updated_at' => now(),
            ]);
        }
        $permissionsTable = config('admin.database.permissions_table');
        if ($permissionsTable && Schema::connection($connection)->hasTable($permissionsTable)) {
            $database->table($permissionsTable)->where('slug', 'portal.yunying.advertising_reports')->update([
                'name' => '[运营系统] 广告提报', 'updated_at' => now(),
            ]);
        }
    }
};
