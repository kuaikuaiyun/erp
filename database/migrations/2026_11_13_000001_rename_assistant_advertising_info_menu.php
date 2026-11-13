<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->rename('代理与社媒');
    }

    public function down(): void
    {
        $this->rename('广告信息');
    }

    private function rename(string $title): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;

        $database->table('portal_menus')->where([
            'portal_id' => $portalId,
            'code' => 'assistant_advertising_info',
        ])->update(['title' => $title, 'updated_at' => now()]);
    }
};
