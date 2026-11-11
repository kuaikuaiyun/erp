<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->setSort(25);
    }

    public function down(): void
    {
        $this->setSort(50);
    }

    private function setSort(int $sort): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'caiwu')->value('id');
        if ($portalId) {
            $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'finance_xuzong-credit')
                ->update(['sort' => $sort, 'updated_at' => now()]);
        }
    }
};
