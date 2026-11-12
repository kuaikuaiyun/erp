<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');

        if (! $portalId) {
            return;
        }

        $db->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'assistant_applovin_reports')
            ->update([
                'title' => 'Applovin数据',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');

        if (! $portalId) {
            return;
        }

        $db->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'assistant_applovin_reports')
            ->update([
                'title' => 'AppLovin报表',
                'updated_at' => now(),
            ]);
    }
};
