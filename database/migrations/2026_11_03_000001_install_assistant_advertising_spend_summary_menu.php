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
        $portalId = $database->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;

        $database->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'assistant_advertising_spend_summary'],
            [
                'parent_id' => 0,
                'title' => '广告费汇总',
                'title_key' => '',
                'path' => '/assistant/advertising-spend-summary',
                'icon' => 'fa-pie-chart',
                'sort' => 25,
                'is_enabled' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable('portal_menus')) return;
        $portalId = DB::connection($connection)->table('portals')->where('code', 'zhuli')->value('id');
        if ($portalId) {
            DB::connection($connection)->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', 'assistant_advertising_spend_summary')
                ->delete();
        }
    }
};
