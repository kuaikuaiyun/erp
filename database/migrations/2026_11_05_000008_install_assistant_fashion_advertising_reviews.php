<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'portal.zhuli.fashion_advertising_reviews';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if ($schema->hasTable('fashion_advertising_reports')) {
            $columns = [
                'status' => fn (Blueprint $table) => $table->string('status', 20)->default('pending')->index(),
                'reviewed_by' => fn (Blueprint $table) => $table->unsignedInteger('reviewed_by')->nullable()->index(),
                'reviewer_name' => fn (Blueprint $table) => $table->string('reviewer_name', 100)->nullable(),
                'reviewed_at' => fn (Blueprint $table) => $table->timestamp('reviewed_at')->nullable(),
                'review_reason' => fn (Blueprint $table) => $table->text('review_reason')->nullable(),
            ];
            foreach ($columns as $column => $definition) {
                if (!$schema->hasColumn('fashion_advertising_reports', $column)) {
                    $schema->table('fashion_advertising_reports', fn (Blueprint $table) => $definition($table));
                }
            }
        }

        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (!$portalId) return;
        $now = now();

        $parentValues = ['parent_id' => 0, 'title' => '服装运营报表', 'title_key' => 'assistant.fashion_reports.menu', 'path' => '#', 'permission_slug' => null, 'icon' => 'fa-line-chart', 'sort' => 28, 'is_enabled' => true, 'updated_at' => $now];
        $parent = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'assistant_fashion_reports')->first(['id']);
        if ($parent) {
            $db->table('portal_menus')->where('id', $parent->id)->update($parentValues);
            $parentId = (int) $parent->id;
        } else {
            $parentId = (int) $db->table('portal_menus')->insertGetId(['portal_id' => $portalId, 'code' => 'assistant_fashion_reports', ...$parentValues, 'created_at' => $now]);
        }

        $permissionId = null;
        $permissions = config('admin.database.permissions_table');
        if ($schema->hasTable($permissions)) {
            $permission = $db->table($permissions)->where('slug', self::PERMISSION)->first(['id']);
            $permissionValues = ['name' => '[助理系统] 服装运营广告费审核', 'http_method' => 'GET,POST', 'http_path' => "/assistant/fashion-advertising-reviews\r\n/assistant/fashion-advertising-reviews/*", 'updated_at' => $now];
            if ($permission) {
                $db->table($permissions)->where('id', $permission->id)->update($permissionValues);
                $permissionId = (int) $permission->id;
            } else {
                $permissionId = (int) $db->table($permissions)->insertGetId(['slug' => self::PERMISSION, ...$permissionValues, 'created_at' => $now]);
            }
        }

        $childValues = ['parent_id' => $parentId, 'title' => '运营广告费审核', 'title_key' => 'assistant.fashion_advertising_reviews.menu', 'path' => '/assistant/fashion-advertising-reviews', 'permission_slug' => self::PERMISSION, 'icon' => 'fa-check-square-o', 'sort' => 1, 'is_enabled' => true, 'updated_at' => $now];
        $child = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'assistant_fashion_advertising_reviews')->first(['id']);
        if ($child) $db->table('portal_menus')->where('id', $child->id)->update($childValues);
        else $db->table('portal_menus')->insert(['portal_id' => $portalId, 'code' => 'assistant_fashion_advertising_reviews', ...$childValues, 'created_at' => $now]);

        if ($permissionId) {
            $roleId = $db->table(config('admin.database.roles_table'))->where('slug', 'administrator')->value('id');
            $pivot = config('admin.database.role_permissions_table');
            if ($roleId && !$db->table($pivot)->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                $db->table($pivot)->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // 审核记录和业务数据不自动删除。
    }
};
