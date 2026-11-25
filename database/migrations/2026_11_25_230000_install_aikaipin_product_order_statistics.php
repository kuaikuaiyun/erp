<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $database = DB::connection($connection);

        if ($schema->hasTable('ai_product_shopify_publications')) {
            $schema->table('ai_product_shopify_publications', function (Blueprint $table): void {
                $table->index(['status', 'assistant_domain_store_id', 'published_at'], 'ai_pub_status_store_time_idx');
                $table->index('shopify_variant_id', 'ai_pub_variant_idx');
            });
        }

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $portalId = $database->table('portals')->where('code', 'aikaipin')->value('id');
        if (! $portalId) return;

        $permissionSlug = $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'product_library')
            ->value('permission_slug');
        $values = [
            'parent_id' => 0,
            'title' => '出单统计',
            'title_key' => '',
            'path' => '/product-order-statistics',
            'icon' => 'fa-bar-chart',
            'sort' => 15,
            'is_enabled' => true,
            'updated_at' => now(),
        ];
        if ($schema->hasColumn('portal_menus', 'permission_slug')) $values['permission_slug'] = $permissionSlug;

        $database->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'product_order_statistics'],
            [...$values, 'created_at' => now()],
        );
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $database = DB::connection($connection);

        if ($schema->hasTable('portals') && $schema->hasTable('portal_menus')) {
            $portalId = $database->table('portals')->where('code', 'aikaipin')->value('id');
            if ($portalId) $database->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'product_order_statistics'])->delete();
        }
        if ($schema->hasTable('ai_product_shopify_publications')) {
            $schema->table('ai_product_shopify_publications', function (Blueprint $table): void {
                $table->dropIndex('ai_pub_status_store_time_idx');
                $table->dropIndex('ai_pub_variant_idx');
            });
        }
    }
};
