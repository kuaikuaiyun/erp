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

        if (!$schema->hasTable('logistics_order_items')) {
            $schema->create('logistics_order_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_order_id')->index();
                $table->string('source_key', 191);
                $table->string('shopify_line_item_id', 255)->nullable();
                $table->string('shopify_product_id', 255)->nullable()->index();
                $table->string('shopify_variant_id', 255)->nullable()->index();
                $table->string('product_title', 255);
                $table->string('variant_title', 255)->nullable();
                $table->text('product_image_url')->nullable();
                $table->string('shopify_sku', 191)->nullable()->index();
                $table->string('tongtu_sku', 191)->nullable()->index();
                $table->string('tongtu_product_name', 255)->nullable();
                $table->decimal('quantity', 18, 4)->default(0);
                $table->decimal('unit_price_usd', 18, 4)->nullable();
                $table->decimal('line_revenue_usd', 18, 4)->nullable();
                $table->decimal('product_cost_usd', 18, 4)->nullable();
                $table->decimal('handling_fee_usd', 18, 4)->nullable();
                $table->decimal('total_shipping_usd', 18, 4)->nullable();
                $table->decimal('proportional_refund_usd', 18, 4)->nullable();
                $table->decimal('profit_usd', 18, 4)->nullable();
                $table->decimal('profit_rate', 18, 4)->nullable();
                $table->decimal('sale_weight_gram', 18, 4)->nullable();
                $table->timestamps();

                $table->unique(['logistics_order_id', 'source_key'], 'logistics_order_item_source_unique');
            });
        }

        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $portalId = DB::connection($connection)->table('portals')->where('code', 'wuliu')->value('id');
        if (!$portalId) {
            return;
        }

        $timestamp = now();
        $menus = [
            'logistics_shopify_stores' => ['title_key' => 'logistics.shopify_stores.menu'],
            'logistics_orders' => ['title_key' => 'logistics.orders.menu'],
            'logistics_reports' => ['title_key' => 'logistics.dashboard.menu', 'path' => '#'],
            'logistics_product_statistics' => ['title_key' => 'logistics.product_statistics.menu'],
        ];
        foreach ($menus as $code => $values) {
            DB::connection($connection)->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', $code)
                ->update([...$values, 'title' => null, 'updated_at' => $timestamp]);
        }
    }

    public function down(): void
    {
        // Operational order details and translated menu keys are intentionally retained.
    }
};
