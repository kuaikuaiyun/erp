<?php

namespace Tests\Feature;

use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Models\FashionLogisticsSyncState;
use App\Portals\FashionLogistics\XShoppyOrderSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class XShoppyOrderTimezoneSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        app('db')->purge('sqlite');

        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('site_builder', 50)->nullable();
            $table->string('shop_name', 191)->nullable();
            $table->string('domain', 191)->nullable();
            $table->string('shop_login_url', 500)->nullable();
            $table->string('xshoppy_api_key', 191)->nullable();
            $table->string('xshoppy_password', 191)->nullable();
            $table->timestamps();
        });
        Schema::create('fashion_logistics_sync_states', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assistant_domain_store_id')->unique();
            $table->string('status', 30)->nullable();
            $table->dateTime('last_started_at')->nullable();
            $table->dateTime('last_succeeded_at')->nullable();
            $table->dateTime('last_cursor_at')->nullable();
            $table->unsignedInteger('last_created_count')->default(0);
            $table->unsignedInteger('last_updated_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('fashion_logistics_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->string('source_platform', 30);
            $table->string('store_name', 191)->nullable();
            $table->string('source_domain', 191)->nullable();
            $table->string('external_order_id', 191);
            $table->string('order_number', 191)->nullable();
            $table->string('status', 50)->nullable();
            $table->string('currency', 20)->nullable();
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->decimal('shipping_amount', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->string('payment_method', 191)->nullable();
            $table->string('payment_method_title', 191)->nullable();
            $table->string('transaction_id', 191)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name', 191)->nullable();
            $table->string('customer_email', 191)->nullable();
            $table->string('customer_phone', 100)->nullable();
            $table->string('shipping_country', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->text('shipping_address')->nullable();
            $table->text('customer_note')->nullable();
            $table->dateTime('source_order_created_at')->nullable();
            $table->string('source_order_timezone', 100)->nullable();
            $table->dateTime('order_created_at_utc')->nullable();
            $table->dateTime('order_created_at')->nullable();
            $table->dateTime('order_modified_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('paid_at_utc')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->json('source_payload')->nullable();
            $table->dateTime('source_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['assistant_domain_store_id', 'source_platform', 'external_order_id'], 'fashion_order_store_platform_external_unique');
        });
        Schema::create('fashion_logistics_order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('fashion_logistics_order_id');
            $table->string('external_item_id', 191)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->string('product_name', 500)->nullable();
            $table->string('sku', 191)->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('total_tax', 18, 4)->default(0);
            $table->string('image_url', 1000)->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();
        });
    }

    public function test_uses_login_url_host_and_preserves_xshoppy_china_api_times_without_shop_request(): void
    {
        $store = AssistantDomainStore::query()->create([
            'site_builder' => 'xshoppy',
            'shop_name' => 'Timezone Store',
            'domain' => 'public-store.example',
            'shop_login_url' => 'https://api-host.example/admin/',
            'xshoppy_api_key' => 'key',
            'xshoppy_password' => 'secret',
        ]);

        Http::fake(function () {
            return Http::response([[
                'id' => 'order-1',
                'number' => 'order-1',
                'status' => 'processing',
                'currency' => 'USD',
                'total' => '10.00',
                'date_created' => '2026-11-06 02:15:08',
                'date_modified' => '2026-11-06 02:17:41',
                'date_paid' => '2026-11-06 02:17:39',
                'billing' => ['first_name' => 'Cookie', 'last_name' => 'Cremone'],
                'line_items' => [],
            ]], 200);
        });

        app(XShoppyOrderSyncService::class)->syncStore($store);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api-host.example/wp-json/wc/v3/orders'));

        $order = FashionLogisticsOrder::query()->sole();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/wp-json/wc/v3/shop'));
        $this->assertNull($order->source_order_timezone);
        $this->assertSame('2026-11-06 02:15:08', $order->source_order_created_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 18:15:08', $order->order_created_at_utc?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-06 02:15:08', $order->order_created_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-06 02:17:39', $order->paid_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-06 02:17:39', $order->paid_at_utc?->format('Y-m-d H:i:s'));
    }

    public function test_incremental_sync_queries_three_days_before_and_after_its_cursor(): void
    {
        $store = AssistantDomainStore::query()->create([
            'site_builder' => 'xshoppy',
            'shop_name' => 'Cursor Window Store',
            'shop_login_url' => 'https://api-host.example/admin/',
            'xshoppy_api_key' => 'key',
            'xshoppy_password' => 'secret',
        ]);
        FashionLogisticsSyncState::query()->create([
            'assistant_domain_store_id' => $store->getKey(),
            'last_cursor_at' => '2026-11-10 02:31:52',
        ]);
        Http::fake(fn () => Http::response([], 200));

        app(XShoppyOrderSyncService::class)->syncStore($store);

        Http::assertSent(function ($request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

            return ($query['after'] ?? null) === '2026-11-07T02:31:52Z'
                && ($query['before'] ?? null) === '2026-11-13T02:31:52Z';
        });
    }
}
