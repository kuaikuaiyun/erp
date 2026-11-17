<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (! $portalId) return;

        $parentKey = ['portal_id' => $portalId, 'code' => 'operation_ai_opening'];
        $parentValues = [
            'parent_id' => 0, 'title' => 'AI开品', 'title_key' => '', 'path' => '#',
            'icon' => 'fa-cubes', 'sort' => 6, 'is_enabled' => 1, 'updated_at' => now(),
        ];
        $database->table('portal_menus')->updateOrInsert($parentKey, [...$parentValues, 'created_at' => now()]);
        $parentId = (int) $database->table('portal_menus')->where($parentKey)->value('id');

        $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'operation_ai_product_library')
            ->update(['parent_id' => $parentId, 'sort' => 1, 'icon' => 'fa-database', 'updated_at' => now()]);

        $database->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'operation_ai_placement_evaluations'],
            [
                'parent_id' => $parentId, 'title' => '投放测评', 'title_key' => '',
                'path' => '/operation/ai-product-library/placement-evaluations',
                'icon' => 'fa-line-chart', 'sort' => 2, 'is_enabled' => 1,
                'updated_at' => now(), 'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (! $portalId) return;

        $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'operation_ai_placement_evaluations')->delete();
        $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'operation_ai_product_library')
            ->update(['parent_id' => 0, 'sort' => 6, 'icon' => 'fa-cubes', 'updated_at' => now()]);
        $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'operation_ai_opening')->delete();
    }
};
