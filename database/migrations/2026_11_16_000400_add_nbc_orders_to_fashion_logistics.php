<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }
        $portalId = $db->table('portals')->where('code', 'fuzhuangwuliu')->value('id');
        if (! $portalId) {
            return;
        }
        $parentId = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'fashion_logistics_orders')->value('id');
        if (! $parentId) {
            return;
        }
        $db->transaction(function () use ($db, $schema, $portalId, $parentId) {
            $key = ['portal_id' => $portalId, 'code' => 'fashion_logistics_nbc_orders'];
            $values = ['parent_id' => $parentId, 'title' => '', 'title_key' => 'nbc_orders.menu',
                'path' => '/fashion-logistics/nbc-orders', 'permission_slug' => 'portal.fuzhuangwuliu.orders',
                'icon' => 'fa-shopping-bag', 'sort' => 30, 'is_enabled' => true, 'updated_at' => now()];
            if ($db->table('portal_menus')->where($key)->exists()) {
                $db->table('portal_menus')->where($key)->update($values);
            } else {
                $db->table('portal_menus')->insert([...$key, ...$values, 'created_at' => now()]);
            }
            $table = config('admin.database.permissions_table');
            if ($table && $schema->hasTable($table)) {
                $permission = $db->table($table)->where('slug', 'portal.fuzhuangwuliu.orders')->first();
                if ($permission) {
                    $paths = preg_split('/\r\n|\r|\n/', (string) $permission->http_path, -1, PREG_SPLIT_NO_EMPTY);
                    $paths = array_values(array_unique([...$paths, '/fashion-logistics/nbc-orders', '/fashion-logistics/nbc-orders/*']));
                    $db->table($table)->where('id', $permission->id)->update(['http_path' => implode("\r\n", $paths), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Keep synchronized business data; disable the NBC menu only.
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        if ($db->getSchemaBuilder()->hasTable('portals') && $db->getSchemaBuilder()->hasTable('portal_menus')) {
            $portalId = $db->table('portals')->where('code', 'fuzhuangwuliu')->value('id');
            if ($portalId) {
                $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'fashion_logistics_nbc_orders')->update(['is_enabled' => false, 'updated_at' => now()]);
            }
        }
    }
};
