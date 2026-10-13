<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'wuliu';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('logistics_shopify_connections')) {
            $missing = [];
            foreach (['token_obtained_at', 'token_expires_at'] as $column) {
                if (!$schema->hasColumn('logistics_shopify_connections', $column)) {
                    $missing[] = $column;
                }
            }

            if ($missing !== []) {
                $schema->table('logistics_shopify_connections', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column) {
                        $table->dateTime($column)->nullable()->index();
                    }
                });
            }
        }

        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if (!$portalId) {
            return;
        }

        $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'logistics_shopify_stores')
            ->update([
                'title' => 'Shopify 店铺同步',
                'title_key' => 'logistics.shopify_stores.menu',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Keep operational Token timestamps and menu title when rolling back.
    }
};
