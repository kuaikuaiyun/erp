<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $db = DB::connection($connection);
        $schema = Schema::connection($connection);
        $portalId = $db->table('portals')->where('code', 'review-center')->value('id');
        if (! $portalId) throw new RuntimeException(__('review_center.portal_missing'));
        if (! $schema->hasTable('review_center_department_bindings')) $schema->create('review_center_department_bindings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'department_id'], 'review_center_member_department_unique');
            $table->index('department_id');
        });
        if (! $schema->hasTable('review_center_department_binding_logs')) $schema->create('review_center_department_binding_logs', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('user_name');
            $table->text('before_ids'); $table->text('after_ids');
            $table->unsignedBigInteger('actor_id'); $table->string('actor_name'); $table->timestamp('created_at');
            $table->index(['user_id', 'id']);
        });
        foreach ([
            ['review_center_operation_tasks', 'review_center.operation_tasks', '/review-center/operation-tasks/reviews', 'portal.review-center.operation-tasks', 'fa-check-square-o', 10],
            ['review_center_department_bindings', 'review_center.department_bindings', '/review-center/department-bindings', 'portal.review-center.department-bindings', 'fa-link', 20],
        ] as [$code, $titleKey, $path, $slug, $icon, $sort]) {
            $db->table('portal_menus')->insertOrIgnore([
                'portal_id' => $portalId, 'parent_id' => 0, 'code' => $code, 'title' => null, 'title_key' => $titleKey,
                'path' => $path, 'permission_slug' => $slug, 'icon' => $icon, 'sort' => $sort,
                'is_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $db->table(config('admin.database.permissions_table'))->insertOrIgnore([
                'name' => '[review-center] '.__($titleKey, [], 'zh-CN'), 'slug' => $slug,
                'http_method' => '', 'http_path' => $path, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Access is granted by the existing department/page-permission UI, never to every user automatically.
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'review-center')->value('id');
        if ($portalId) $db->table('portal_menus')->where('portal_id', $portalId)
            ->whereIn('code', ['review_center_operation_tasks', 'review_center_department_bindings'])->update(['is_enabled' => false, 'updated_at' => now()]);
        // Preserve binding audit history and all shared fashion business data.
    }
};
