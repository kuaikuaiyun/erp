<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

return new class extends Migration
{
    public function up(): void
    {
        $database = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $menusTable = config('admin.database.menu_table');
        $menu = $database->table($menusTable)->where('uri', 'portal/portals')->first(['id']);

        if (!$menu) {
            return;
        }

        $database->table($menusTable)
            ->where('id', $menu->id)
            ->update([
                'parent_id' => 0,
                'order' => 3,
                'title' => (string) Lang::get('portal.menu.management', [], 'zh-CN'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $database = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $menusTable = config('admin.database.menu_table');
        $management = $database->table($menusTable)
            ->where('parent_id', 0)
            ->where(function ($query): void {
                $query->where('uri', '')->orWhereNull('uri');
            })
            ->orderBy('id')
            ->first(['id']);

        if (!$management) {
            return;
        }

        $database->table($menusTable)
            ->where('uri', 'portal/portals')
            ->update([
                'parent_id' => $management->id,
                'order' => 5,
                'updated_at' => now(),
            ]);
    }
};


