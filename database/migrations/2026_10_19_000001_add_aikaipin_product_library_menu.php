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
        $values = [
            'parent_id' => 0,
            'title' => (string) Lang::get('aikaipin.menus.product_library', [], 'zh-CN'),
            'title_key' => 'aikaipin.menus.product_library',
            'path' => '/product-library',
            'icon' => 'fa-cubes',
            'sort' => 10,
            'is_enabled' => true,
            'updated_at' => $timestamp,
        ];

        $existing = $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'product_library')
            ->first(['id']);

        if ($existing) {
            $database->table('portal_menus')->where('id', $existing->id)->update($values);

            return;
        }

        $database->table('portal_menus')->insert([
            'portal_id' => $portalId,
            'code' => 'product_library',
            ...$values,
            'created_at' => $timestamp,
        ]);
    }

    public function down(): void
    {
        // Menu records are retained on rollback.
    }
};
