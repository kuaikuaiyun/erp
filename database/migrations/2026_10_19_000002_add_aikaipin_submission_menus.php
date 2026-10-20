<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (!Schema::connection($connection)->hasTable('portals') || !Schema::connection($connection)->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'aikaipin')->value('id');
        if (!$portalId) {
            return;
        }

        $timestamp = now();
        foreach ([
            [
                'code' => 'selection_proposals',
                'title_key' => 'aikaipin.menus.selection_proposals',
                'path' => '/selection-proposals',
                'icon' => 'fa-lightbulb-o',
                'sort' => 20,
            ],
            [
                'code' => 'placement_evaluations',
                'title_key' => 'aikaipin.menus.placement_evaluations',
                'path' => '/placement-evaluations',
                'icon' => 'fa-line-chart',
                'sort' => 30,
            ],
        ] as $menu) {
            $values = [
                'parent_id' => 0,
                'title' => (string) Lang::get($menu['title_key'], [], 'zh-CN'),
                'title_key' => $menu['title_key'],
                'path' => $menu['path'],
                'icon' => $menu['icon'],
                'sort' => $menu['sort'],
                'is_enabled' => true,
                'updated_at' => $timestamp,
            ];

            $existing = $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', $menu['code'])
                ->first(['id']);

            if ($existing) {
                $database->table('portal_menus')->where('id', $existing->id)->update($values);

                continue;
            }

            $database->table('portal_menus')->insert([
                'portal_id' => $portalId,
                'code' => $menu['code'],
                ...$values,
                'created_at' => $timestamp,
            ]);
        }
    }

    public function down(): void
    {
        // Menu records are retained on rollback.
    }
};
