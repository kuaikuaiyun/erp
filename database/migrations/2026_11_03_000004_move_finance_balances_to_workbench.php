<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'caiwu')->value('id');
        if ($portalId) {
            $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'finance_balances')
                ->update(['is_enabled' => false, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Keep the retired entry hidden; the old URL still redirects to the workbench.
    }
};
