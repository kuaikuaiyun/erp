<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'caiwu')->value('id');
        if (! $portalId) {
            return;
        }

        foreach ($db->table('portal_menus')->where('portal_id', $portalId)->whereNotNull('title')->get(['id', 'title']) as $menu) {
            $title = str_replace(['贝宝', '徐总支付'], ['PayPal', '信用卡'], $menu->title);
            if ($title !== $menu->title) {
                $db->table('portal_menus')->where('id', $menu->id)->update(['title' => $title, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Preserve the current channel names and any administrator customizations.
    }
};
