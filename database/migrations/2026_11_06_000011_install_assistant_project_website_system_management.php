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
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('assistant_projects')) {
            $schema->create('assistant_projects', function (Blueprint $table): void {
                $table->id(); $table->unsignedBigInteger('assistant_department_id')->index();
                $table->string('name', 100); $table->boolean('is_enabled')->default(true)->index();
                $table->unsignedInteger('sort')->default(0); $table->timestamps();
                $table->unique(['assistant_department_id', 'name'], 'assistant_projects_department_name_unique');
            });
        }
        if (! $schema->hasTable('assistant_website_systems')) {
            $schema->create('assistant_website_systems', function (Blueprint $table): void {
                $table->id(); $table->unsignedBigInteger('assistant_department_id')->index();
                $table->unsignedBigInteger('project_id')->index(); $table->string('name', 100);
                $table->boolean('is_enabled')->default(true)->index(); $table->unsignedInteger('sort')->default(0); $table->timestamps();
                $table->unique(['assistant_department_id', 'project_id', 'name'], 'assistant_website_system_project_name_unique');
                $table->foreign('project_id')->references('id')->on('assistant_projects')->restrictOnDelete();
            });
        }

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection); $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;
        $parentKey = ['portal_id' => $portalId, 'code' => 'assistant_project_website_management'];
        $db->table('portal_menus')->updateOrInsert($parentKey, ['parent_id' => 0, 'title' => '项目和建站系统管理', 'title_key' => '', 'path' => '#', 'icon' => 'fa-sitemap', 'sort' => 31, 'is_enabled' => 1, 'updated_at' => now(), 'created_at' => now()]);
        $parentId = (int) $db->table('portal_menus')->where($parentKey)->value('id');
        foreach ([
            ['code' => 'assistant_projects', 'title' => '项目管理', 'path' => '/assistant-projects', 'slug' => 'portal.zhuli.projects', 'sort' => 10],
            ['code' => 'assistant_website_systems', 'title' => '建站系统管理', 'path' => '/assistant-website-systems', 'slug' => 'portal.zhuli.website_systems', 'sort' => 20],
        ] as $item) {
            $values = ['parent_id' => $parentId, 'title' => $item['title'], 'title_key' => '', 'path' => $item['path'], 'icon' => 'fa-circle-o', 'sort' => $item['sort'], 'is_enabled' => 1, 'updated_at' => now()];
            if ($schema->hasColumn('portal_menus', 'permission_slug')) $values['permission_slug'] = $item['slug'];
            $db->table('portal_menus')->updateOrInsert(['portal_id' => $portalId, 'code' => $item['code']], [...$values, 'created_at' => now()]);
        }

        $permissions = config('admin.database.permissions_table'); $roles = config('admin.database.roles_table'); $pivot = config('admin.database.role_permissions_table');
        if (! $schema->hasTable($permissions) || ! $schema->hasTable($roles) || ! $schema->hasTable($pivot)) return;
        foreach (['portal.zhuli.projects' => '[助理系统] 项目管理', 'portal.zhuli.website_systems' => '[助理系统] 建站系统管理'] as $slug => $name) {
            $permissionId = $db->table($permissions)->where('slug', $slug)->value('id');
            if (! $permissionId) $permissionId = $db->table($permissions)->insertGetId(['name' => $name, 'slug' => $slug, 'http_method' => '', 'http_path' => '', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($db->table($roles)->where('slug', '!=', 'administrator')->pluck('id') as $roleId) $db->table($pivot)->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default'); $schema = Schema::connection($connection); $db = DB::connection($connection);
        if ($schema->hasTable('portal_menus')) $db->table('portal_menus')->whereIn('code', ['assistant_projects', 'assistant_website_systems', 'assistant_project_website_management'])->delete();
        $schema->dropIfExists('assistant_website_systems'); $schema->dropIfExists('assistant_projects');
    }
};
