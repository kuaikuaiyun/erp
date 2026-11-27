<?php

namespace Tests\Feature;

use App\Portals\Logistics\SkuDatabaseLookupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

final class TongtoolSkuUploadTest extends TestCase
{
    private const URL = '/api/logistics/tongtool-sku?action=';

    private string $directory;

    public function test_upload_projects_aliases_and_partial_updates_without_the_external_service(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->directory = sys_get_temp_dir().'/erp-sku-upload-'.bin2hex(random_bytes(8));
        config([
            'database.connections.tongtool_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'admin.database.connection' => 'tongtool_test',
            'logistics.tongtool_receiver.path' => $this->directory,
            'logistics.tongtool_receiver.writer_hash' => hash('sha256', $token),
            'logistics.tongtool_receiver.local_lookup' => true,
        ]);
        Http::preventStrayRequests();
        Schema::connection('tongtool_test')->create('logistics_order_items', function (Blueprint $table): void {
            $table->id();
            foreach (['shopify_sku', 'tongtu_sku', 'tongtu_product_code', 'tongtu_product_name',
                'tongtu_product_type', 'product_image_url'] as $column) {
                $table->string($column)->nullable();
            }
            $table->decimal('sale_weight_gram', 18, 4)->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_24_000001_create_logistics_tongtu_sku_mappings.php'))->up();
        $db = DB::connection('tongtool_test');
        $db->table('logistics_order_items')->insert(['shopify_sku' => 'ALIAS-1']);

        $row = [
            'sku' => 'SKU-1',
            'productId' => '1234567890',
            'productType' => '1',
            'pcl' => 'PCL-1',
            'guideField' => 'goodsGuideCost',
            'guideCost' => 5.5,
            'weightGrams' => 120,
            'sourceReadAt' => now()->subHour()->toIso8601String(),
        ];
        $this->postJson(self::URL.'admin_import', ['rows' => [$row]])->assertUnauthorized();
        $this->withToken($token)->postJson(self::URL.'admin_order_attributions', [])->assertNotFound();
        $this->postJson(self::URL.'admin_import', ['rows' => [$row]])->assertStatus(400);
        $this->postJson(self::URL.'admin_import', ['rows' => [$row], 'deferred' => true])
            ->assertOk()->assertJsonPath('imported', 1);

        $snapshot = [
            'productId' => $row['productId'],
            'sourceReadAt' => $row['sourceReadAt'],
            'complete' => true,
            'items' => [['sku' => 'SKU-1', 'aliases' => ['ALIAS-1']]],
        ];
        $this->postJson(self::URL.'admin_alias_import', ['snapshots' => [$snapshot], 'deferred' => true])
            ->assertOk()->assertJsonPath('erpMatched', 1);
        $this->assertDatabaseHas('logistics_tongtu_sku_mappings', [
            'shopify_sku' => 'alias-1', 'tongtu_sku' => 'SKU-1',
            'product_guide_cost_cny' => 5.5, 'goods_weight_gram' => 120,
        ], 'tongtool_test');
        $this->assertDatabaseHas('logistics_order_items', [
            'shopify_sku' => 'ALIAS-1', 'tongtu_sku' => 'SKU-1', 'sale_weight_gram' => 120,
        ], 'tongtool_test');

        $partial = array_replace($row, [
            'reason' => 'missing_guide', 'guideCost' => null, 'weightGrams' => 150,
            'sourceReadAt' => now()->toIso8601String(),
        ]);
        $this->postJson(self::URL.'admin_import', ['rows' => [], 'rejected' => [$partial]])
            ->assertOk()->assertJsonPath('rejected', 1);
        $this->assertDatabaseHas('logistics_order_items', [
            'shopify_sku' => 'ALIAS-1', 'sale_weight_gram' => 150,
        ], 'tongtool_test');
        $skuLookup = app(SkuDatabaseLookupService::class);
        $result = $skuLookup->lookup(['ALIAS-1']);
        $this->assertNull($result['alias-1']['guide_cost_cny']);
        $this->assertSame(150.0, $result['alias-1']['weight_gram']);
        $this->assertDatabaseHas('logistics_tongtu_sku_mappings', [
            'shopify_sku' => 'alias-1', 'product_guide_cost_cny' => 5.5, 'goods_weight_gram' => 150,
        ], 'tongtool_test');
        $this->postJson(self::URL.'admin_import', ['rows' => [$row], 'deferred' => true])
            ->assertConflict()->assertJsonPath('errorCode', 'older_import');
        $this->assertSame([], $skuLookup->lookup(['NO-SUCH-SKU']));
        $this->getJson(self::URL.'admin_status')->assertOk()->assertJsonPath('pendingSkus.0', 'NO-SUCH-SKU');
        Http::assertNothingSent();

        config([
            'logistics.tongtool_receiver.local_lookup' => false,
            'logistics.sku_database.url' => 'https://sku-archive.example/lookup',
            'logistics.sku_database.token' => 'test-reader',
        ]);
        Http::fake(['sku-archive.example/*' => Http::response([
            'items' => ['ALIAS-1' => ['sku' => 'SKU-1', 'guideCost' => 1, 'weightGrams' => 80]],
        ])]);
        $skuLookup->lookup(['ALIAS-1'], true);
        $this->assertDatabaseHas('logistics_order_items', [
            'shopify_sku' => 'ALIAS-1', 'sale_weight_gram' => 150,
        ], 'tongtool_test');
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }
}
