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
        if (!Schema::connection($connection)->hasTable('portals') || !Schema::connection($connection)->hasTable('portal_menus')) return;
        $portalId = $database->table('portals')->where('code', 'zhuli')->value('id');
        if (!$portalId) return;
        $database->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'assistant_operation_report_reviews'],
            ['parent_id' => 0, 'title' => '运营报表', 'title_key' => 'assistant.operation_report_reviews.menu', 'path' => '/operation-report-reviews', 'icon' => 'fa-check-square-o', 'sort' => 25, 'is_enabled' => true, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        if (!Schema::connection($connection)->hasTable('portals')) return;
        $portalId = $database->table('portals')->where('code', 'zhuli')->value('id');
        if ($portalId) $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'assistant_operation_report_reviews')->delete();
    }
};
