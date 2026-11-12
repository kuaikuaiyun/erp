<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;

        $parentId = $db->table('portal_menus')->where([
            'portal_id' => $portalId, 'code' => 'assistant_daily_submissions',
        ])->value('id');
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'assistant_daily_report_department_settings'],
            ['parent_id' => $parentId ?: 0, 'title' => '日常提报部门设置', 'title_key' => '',
                'path' => '/assistant/daily-submission-departments', 'permission_slug' => null,
                'icon' => 'fa-filter', 'sort' => 6, 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if ($portalId) $db->table('portal_menus')->where([
            'portal_id' => $portalId, 'code' => 'assistant_daily_report_department_settings',
        ])->delete();
    }
};
