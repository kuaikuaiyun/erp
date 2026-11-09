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
        $portalId = $db->table('portals')->where('code', 'daily-report')->value('id');
        if (! $portalId) return;
        $now = now();
        $slug = 'portal.daily-report.internal_messages';
        $values = [
            'parent_id' => 0,
            'title' => '站内消息',
            'title_key' => '',
            'path' => '/operation/internal-messages',
            'icon' => 'fa-envelope',
            'sort' => 90,
            'is_enabled' => 1,
            'updated_at' => $now,
        ];
        if ($schema->hasColumn('portal_menus', 'permission_slug')) $values['permission_slug'] = $slug;
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'daily_report_internal_messages'],
            [...$values, 'created_at' => $now],
        );

        $permissions = config('admin.database.permissions_table');
        $roles = config('admin.database.roles_table');
        $pivot = config('admin.database.role_permissions_table');
        if (! $schema->hasTable($permissions) || ! $schema->hasTable($roles) || ! $schema->hasTable($pivot)) return;
        $permissionId = $db->table($permissions)->where('slug', $slug)->value('id');
        if (! $permissionId) $permissionId = $db->table($permissions)->insertGetId([
            'name' => '[日常提报系统] 站内消息', 'slug' => $slug, 'http_method' => '', 'http_path' => '',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($db->table($roles)->where('slug', '!=', 'administrator')->pluck('id') as $roleId) {
            $db->table($pivot)->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);
        if ($schema->hasTable('portal_menus')) $db->table('portal_menus')->where('code', 'daily_report_internal_messages')->delete();
    }
};
