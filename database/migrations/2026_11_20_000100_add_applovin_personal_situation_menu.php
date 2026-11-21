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
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'yunying-kanban')->value('id');
        if (! $portalId) return;
        $parentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'dashboard_applovin_data'])->value('id');
        if (! $parentId) return;

        $now = now();
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'dashboard_applovin_personal_situations'],
            ['parent_id' => $parentId, 'title' => 'AppLovin个人情况', 'title_key' => '', 'path' => '/operation-dashboard/applovin-personal-situations',
                'permission_slug' => 'portal.yunying-kanban.applovin_spends', 'icon' => 'fa-user-circle-o', 'sort' => 5, 'is_enabled' => true,
                'created_at' => $now, 'updated_at' => $now]
        );

        $permissions = config('admin.database.permissions_table');
        if (! $schema->hasTable($permissions)) return;
        $permission = $db->table($permissions)->where('slug', 'portal.yunying-kanban.applovin_spends')->first(['id', 'http_path']);
        if (! $permission) return;
        $paths = collect(preg_split('/\R+/', (string) $permission->http_path))
            ->merge(['/operation-dashboard/applovin-personal-situations'])->map(fn ($path) => trim((string) $path))->filter()->unique()->implode("\r\n");
        $db->table($permissions)->where('id', $permission->id)->update(['http_method' => 'GET,POST', 'http_path' => $paths, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $portalId = DB::connection($connection)->table('portals')->where('code', 'yunying-kanban')->value('id');
        if ($portalId) DB::connection($connection)->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'dashboard_applovin_personal_situations'])->delete();
    }
};
