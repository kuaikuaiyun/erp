<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $database = DB::connection(config('admin.database.connection') ?: config('database.default'));

        $database->transaction(function () use ($database): void {
            $portalId = $database->table('portals')->where('code', 'baokuan-general')->value('id');
            if (!$portalId) {
                return;
            }

            $menus = $database->table('portal_menus');
            $now = now();
            $defaults = [
                ['home', 'portal.menu.home', '/', 'fa-home', 1],
                ['backend_management', 'portal.menu.backend_management', '#', 'fa-cogs', 900],
                ['menu_configuration', 'portal.menu.configuration', '/management/menus', 'fa-list', 1],
            ];

            foreach ($defaults as [$code, $titleKey, $path, $icon, $sort]) {
                $parentId = $code === 'menu_configuration'
                    ? (int) (clone $menus)->where('portal_id', $portalId)->where('code', 'backend_management')->value('id')
                    : 0;

                $menus->insertOrIgnore([
                    'portal_id' => $portalId,
                    'parent_id' => $parentId,
                    'code' => $code,
                    'title_key' => $titleKey,
                    'path' => $path,
                    'icon' => $icon,
                    'sort' => $sort,
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Preserve system menus and any configuration saved after installation.
    }
};
