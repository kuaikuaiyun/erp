<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'zhuli';

    private const MENU_CODE = 'assistant_shopify_analytics';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('assistant_domain_stores')
            && !$schema->hasTable('assistant_shopify_store_metrics')) {
            $schema->create('assistant_shopify_store_metrics', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assistant_domain_store_id')->unique();
                $table->string('shop_timezone', 100)->nullable()->index();
                $table->date('report_date')->nullable()->index();
                $table->string('currency_code', 3)->default('USD');
                $table->decimal('total_sales', 20, 4)->nullable();
                $table->unsignedInteger('orders_count')->nullable();
                $table->decimal('returning_customer_rate', 12, 6)->nullable();
                $table->unsignedInteger('fulfilled_orders_count')->nullable();
                $table->decimal('refund_amount', 20, 4)->nullable();
                $table->decimal('net_sales', 20, 4)->nullable();
                $table->decimal('average_order_value', 20, 4)->nullable();
                $table->string('sync_status', 30)->default('not_synced')->index();
                $table->dateTime('synced_at')->nullable()->index();
                $table->timestamps();
            });
        }

        $this->installMenu($connection);
    }

    public function down(): void
    {
        // Keep analytics snapshots and the menu during rollback to avoid erasing operational data.
    }

    private function installMenu(?string $connection): void
    {
        $schema = Schema::connection($connection);
        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if (!$portalId) {
            return;
        }

        $timestamp = now();
        $values = [
            'parent_id' => 0,
            'title' => '',
            'title_key' => 'assistant.shopify_analytics.menu',
            'path' => '/shopify-analytics',
            'icon' => 'fa-line-chart',
            'sort' => 20,
            'is_enabled' => true,
            'updated_at' => $timestamp,
        ];

        $existing = $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', self::MENU_CODE)
            ->first(['id']);

        if ($existing) {
            $database->table('portal_menus')->where('id', $existing->id)->update($values);

            return;
        }

        $database->table('portal_menus')->insert([
            'portal_id' => $portalId,
            'code' => self::MENU_CODE,
            ...$values,
            'created_at' => $timestamp,
        ]);
    }
};
