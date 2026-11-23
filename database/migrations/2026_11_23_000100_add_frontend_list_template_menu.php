<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const URI = 'configuration/frontend-list-template';

    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $db->transaction(function () use ($db): void {
            $menus = config('admin.database.menu_table');
            $parent = $db->table($menus)->where('uri', 'configuration/basic')->value('parent_id');
            if (! $parent) {
                $parent = $db->table($menus)->where('title', \Illuminate\Support\Facades\Lang::get('configuration.menu.configuration_center', [], 'zh-CN'))->value('id');
            }
            $role = $db->table(config('admin.database.roles_table'))->where('slug', 'administrator')->value('id');
            if (! $parent || ! $role) {
                throw new RuntimeException(__('frontend_list_template.menu_missing'));
            }
            if ($db->table($menus)->where('uri', self::URI)->exists()) {
                return;
            }
            $id = $db->table($menus)->insertGetId([
                'parent_id'=>$parent, 'order'=>90,
                'title'=>\Illuminate\Support\Facades\Lang::get('frontend_list_template.title', [], 'zh-CN'),
                'icon'=>'fa-table', 'uri'=>self::URI, 'permission'=>null,
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
            $pivot = config('admin.database.role_menu_table');
            $row = ['role_id'=>$role, 'menu_id'=>$id];
            foreach (['created_at', 'updated_at'] as $column) {
                if ($db->getSchemaBuilder()->hasColumn($pivot, $column)) $row[$column] = now();
            }
            $db->table($pivot)->insert($row);
        });
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $db->transaction(function () use ($db): void {
            $menus = config('admin.database.menu_table');
            $ids = $db->table($menus)->where('uri', self::URI)->pluck('id');
            $db->table(config('admin.database.role_menu_table'))->whereIn('menu_id', $ids)->delete();
            $db->table($menus)->whereIn('id', $ids)->delete();
        });
    }
};
