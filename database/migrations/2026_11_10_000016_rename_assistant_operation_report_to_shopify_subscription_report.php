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
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) {
            return;
        }

        $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'assistant_operation_reports')
            ->update(['title' => 'Shopify订阅报表', 'updated_at' => now()]);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'zhuli')->value('id');
        if ($portalId) {
            $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', 'assistant_operation_reports')
                ->update(['title' => '运营报表', 'updated_at' => now()]);
        }
    }
};
