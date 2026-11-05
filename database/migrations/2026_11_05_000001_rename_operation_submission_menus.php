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

        $portalId = DB::connection($connection)->table('portals')->where('code', 'yunying')->value('id');
        if (! $portalId) return;

        DB::connection($connection)->table('portal_menus')
            ->where('portal_id', $portalId)
            ->whereIn('code', ['operation_submission_group', 'product_requests'])
            ->update(['title' => '开品提报', 'updated_at' => now()]);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable('portal_menus')) return;

        $portalId = DB::connection($connection)->table('portals')->where('code', 'yunying')->value('id');
        if (! $portalId) return;

        DB::connection($connection)->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'operation_submission_group')
            ->update(['title' => '提报', 'updated_at' => now()]);
        DB::connection($connection)->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'product_requests')
            ->update(['title' => '自己开品提报', 'updated_at' => now()]);
    }
};
