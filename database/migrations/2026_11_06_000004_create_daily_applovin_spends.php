<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MENUS = [
        ['portal' => 'yunying', 'parent' => 'operation_daily_submissions', 'code' => 'operation_daily_applovin_spends', 'title' => 'AppLovin账号消耗', 'path' => '/operation/daily-submissions/applovin-spends', 'slug' => 'portal.yunying.daily.applovin_spends', 'sort' => 6],
        ['portal' => 'zhuli', 'parent' => 'assistant_daily_submissions', 'code' => 'assistant_daily_applovin_spends', 'title' => 'AppLovin账号消耗', 'path' => '/assistant/daily-submissions/applovin-spends', 'slug' => 'portal.zhuli.daily.applovin_spends', 'sort' => 2],
    ];

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('daily_applovin_spends')) {
            $schema->create('daily_applovin_spends', function (Blueprint $table): void {
                $table->id();
                $table->date('report_date')->index();
                $table->unsignedBigInteger('operator_user_id')->index();
                $table->string('operator_name', 100);
                $table->unsignedBigInteger('operation_department_id')->nullable()->index();
                $table->string('operation_department_name', 100)->nullable();
                $table->json('entries');
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->timestamps();
            });
        }
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection);
        foreach (self::MENUS as $menu) {
            $portalId = $db->table('portals')->where('code', $menu['portal'])->value('id');
            if (! $portalId) continue;
            $parentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => $menu['parent']])->value('id');
            if (! $parentId) continue;
            $values = ['parent_id' => $parentId, 'title' => $menu['title'], 'title_key' => '', 'path' => $menu['path'], 'icon' => 'fa-line-chart', 'sort' => $menu['sort'], 'is_enabled' => true, 'updated_at' => now()];
            if ($schema->hasColumn('portal_menus', 'permission_slug')) $values['permission_slug'] = $menu['slug'];
            $db->table('portal_menus')->updateOrInsert(['portal_id' => $portalId, 'code' => $menu['code']], [...$values, 'created_at' => now()]);
            $this->permission($db, $schema, $menu);
        }
    }

    private function permission($db, $schema, array $menu): void
    {
        $permissions = config('admin.database.permissions_table');
        $roles = config('admin.database.roles_table');
        $pivot = config('admin.database.role_permissions_table');
        if (! $schema->hasTable($permissions) || ! $schema->hasTable($roles) || ! $schema->hasTable($pivot)) return;
        $id = $db->table($permissions)->where('slug', $menu['slug'])->value('id');
        if (! $id) $id = $db->table($permissions)->insertGetId(['name' => '['.$menu['portal'].'] '.$menu['title'], 'slug' => $menu['slug'], 'http_method' => '', 'http_path' => '', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($db->table($roles)->where('slug', '!=', 'administrator')->pluck('id') as $roleId) {
            $db->table($pivot)->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection); $db = DB::connection($connection);
        if ($schema->hasTable('portal_menus')) $db->table('portal_menus')->whereIn('code', array_column(self::MENUS, 'code'))->delete();
        $permissions = config('admin.database.permissions_table');
        if ($schema->hasTable($permissions)) {
            $ids = $db->table($permissions)->whereIn('slug', array_column(self::MENUS, 'slug'))->pluck('id');
            foreach ([config('admin.database.role_permissions_table'), config('admin.database.user_permissions_table')] as $table) if ($schema->hasTable($table)) $db->table($table)->whereIn('permission_id', $ids)->delete();
            $db->table($permissions)->whereIn('id', $ids)->delete();
        }
        $schema->dropIfExists('daily_applovin_spends');
    }
};
