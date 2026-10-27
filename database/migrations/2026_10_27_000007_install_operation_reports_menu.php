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
        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }
        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (!$portalId) {
            return;
        }
        $key = ['portal_id' => $portalId, 'code' => 'operation_reports'];
        $values = ['parent_id' => 0, 'title' => '运营报表', 'title_key' => 'operation.reports.title', 'path' => '/operation/reports', 'icon' => 'fa-bar-chart', 'sort' => 6, 'is_enabled' => 1, 'updated_at' => now()];
        $database->table('portal_menus')->updateOrInsert($key, [...$values, 'created_at' => now()]);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $portalId = DB::connection($connection)->table('portals')->where('code', 'yunying')->value('id');
        if ($portalId) {
            DB::connection($connection)->table('portal_menus')->where('portal_id', $portalId)->where('code', 'operation_reports')->delete();
        }
    }
};
