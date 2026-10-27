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
        $schema = Schema::connection($connection);
        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (!$portalId) {
            return;
        }

        $key = ['portal_id' => $portalId, 'code' => 'operation_ai_product_library'];
        $values = [
            'parent_id' => 0,
            'title' => (string) Lang::get('aikaipin.menus.operation_ai_product_library', [], 'zh-CN'),
            'title_key' => 'aikaipin.menus.operation_ai_product_library',
            'path' => '/operation/ai-product-library',
            'icon' => 'fa-cubes',
            'sort' => 6,
            'is_enabled' => 1,
            'updated_at' => now(),
        ];

        if ($database->table('portal_menus')->where($key)->exists()) {
            $database->table('portal_menus')->where($key)->update($values);
        } else {
            $database->table('portal_menus')->insert([...$key, ...$values, 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        if (!$database->getSchemaBuilder()->hasTable('portals') || !$database->getSchemaBuilder()->hasTable('portal_menus')) {
            return;
        }

        $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
        if ($portalId) {
            $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'operation_ai_product_library')->delete();
        }
    }
};
