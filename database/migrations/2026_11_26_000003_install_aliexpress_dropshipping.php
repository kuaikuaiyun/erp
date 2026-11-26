<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MENUS = [
        'aliexpress_selection_manage' => ['/aliexpress/selection/manage', 'fa-edit', 60],
        'aliexpress_selection_browse' => ['/aliexpress/selection', 'fa-list-alt', 61],
        'aliexpress_procurement' => ['/aliexpress/procurement', 'fa-shopping-cart', 62],
    ];

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);

        if (!$schema->hasTable('aliexpress_selection_products')) {
            $schema->create('aliexpress_selection_products', function (Blueprint $table): void {
                $table->id();
                $table->string('supplier_product_id', 32)->unique();
                $table->string('supplier_url', 512);
                $table->string('name', 191);
                $table->string('sku_prefix', 125);
                $table->text('remark')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('aliexpress_selection_skus')) {
            $schema->create('aliexpress_selection_skus', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')->constrained('aliexpress_selection_products');
                $table->string('sku', 191);
                $table->string('sku_key', 64)->unique();
                $table->string('pinyin', 48);
                $table->string('attribute', 48);
                $table->string('package_label', 32);
                $table->string('supplier_sku', 191);
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('aliexpress_procurement_items')) {
            $schema->create('aliexpress_procurement_items', function (Blueprint $table): void {
                $table->id();
                $table->string('source_key', 64)->unique();
                $table->unsignedBigInteger('logistics_order_id')->index();
                $table->unsignedBigInteger('logistics_order_item_id')->nullable();
                $table->string('shop_domain', 255)->index();
                $table->string('store_name', 255)->nullable();
                $table->string('shopify_order_id', 100);
                $table->string('shopify_order_number', 100)->nullable();
                $table->string('shopify_line_item_id', 100);
                $table->string('shopify_variant_id', 100)->nullable();
                $table->string('sku', 255);
                $table->string('sku_key', 64)->index();
                $table->string('product_title', 255)->nullable();
                $table->unsignedInteger('quantity');
                $table->string('source_issue', 24)->nullable();
                $table->dateTime('order_created_at');
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('aliexpress_order_match_state')) {
            $schema->create('aliexpress_order_match_state', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->boolean('is_enabled')->default(true);
                $table->dateTime('enabled_at');
                $table->dateTime('matched_through_at');
            });
        }

        $db->table('aliexpress_order_match_state')->insertOrIgnore([
            'id' => 1,
            'enabled_at' => now(),
            'matched_through_at' => now(),
        ]);
        $db->table('aliexpress_order_match_state')->where('id', 1)->update(['is_enabled' => true]);

        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $portal = $db->table('portals')->where('code', 'caigou')->value('id');
        if (!$portal) {
            return;
        }

        $permissions = config('admin.database.permissions_table');
        $db->transaction(function () use ($db, $portal, $permissions): void {
            foreach (self::MENUS as $code => [$path, $icon, $sort]) {
                $slug = 'portal.caigou.'.$code;
                $key = 'aliexpress.menus.'.$code;
                $title = Lang::get($key, [], 'zh-CN');
                if (!$db->table($permissions)->where('slug', $slug)->exists()) {
                    $db->table($permissions)->insert([
                        'name' => $title,
                        'slug' => $slug,
                        'http_method' => '',
                        'http_path' => '',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                if (!$db->table('portal_menus')->where('portal_id', $portal)->where('code', $code)->exists()) {
                    $db->table('portal_menus')->insert([
                        'portal_id' => $portal,
                        'code' => $code,
                        'parent_id' => 0,
                        'title' => $title,
                        'title_key' => $key,
                        'path' => $path,
                        'permission_slug' => $slug,
                        'icon' => $icon,
                        'sort' => $sort,
                        'is_enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);

        if ($schema->hasTable('aliexpress_order_match_state')) {
            $db->table('aliexpress_order_match_state')->where('id', 1)->update(['is_enabled' => false]);
        }

        if ($schema->hasTable('portals') && $schema->hasTable('portal_menus')) {
            $portal = $db->table('portals')->where('code', 'caigou')->value('id');
            if ($portal) {
                $db->table('portal_menus')->where('portal_id', $portal)->whereIn('code', array_keys(self::MENUS))->delete();
            }
        }

        // Preserve scoped permissions and business data when this feature is removed.
    }
};
