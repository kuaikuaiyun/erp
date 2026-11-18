<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'review-center')->value('id');
        if (! $portalId) throw new RuntimeException(__('review_center.portal_missing'));
        $path = '/review-center/operation-tasks/dashboard';
        $slug = 'portal.review-center.operation-tasks.dashboard';
        $menu = [
            'parent_id' => 0, 'title' => null, 'title_key' => 'review_center.dashboard',
            'path' => $path, 'permission_slug' => $slug, 'icon' => 'fa-bar-chart',
            'sort' => 15, 'is_enabled' => true, 'updated_at' => now(),
        ];
        $existing = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'review_center_operation_task_dashboard')->first();
        if ($existing) {
            $db->table('portal_menus')->where('id', $existing->id)->update($menu);
        } else {
            $db->table('portal_menus')->insert([...$menu, 'portal_id' => $portalId, 'code' => 'review_center_operation_task_dashboard', 'created_at' => now()]);
        }
        $db->table(config('admin.database.permissions_table'))->insertOrIgnore([
            'name' => '[review-center] '.__('review_center.dashboard', [], 'zh-CN'), 'slug' => $slug,
            'http_method' => '', 'http_path' => $path, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Page access remains controlled by the existing menu authorization UI.
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'review-center')->value('id');
        if ($portalId) $db->table('portal_menus')->where('portal_id', $portalId)
            ->where('code', 'review_center_operation_task_dashboard')->update(['is_enabled' => false, 'updated_at' => now()]);
    }
};
