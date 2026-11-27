<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceDingTalkSession;
use App\Models\AdminUser;
use App\Models\AliExpressProcurementItem;
use App\Models\AliExpressSelectionProduct;
use App\Models\Department;
use App\Models\Portal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AliExpressSelectionAndMatchingTest extends TestCase
{
    private const HOST = 'http://caigou.erp.test';
    private const MANAGE = '/aliexpress/selection/manage';
    private const LINK = 'https://www.aliexpress.com/item/1005007770001.html';
    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    private Portal $portal;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'admin.database.connection' => null,
            'portals.main_domain' => 'erp.test',
            'portals.root_domain' => 'erp.test',
            'portals.scheme' => 'http',
        ]);
        DB::purge('sqlite');
        $this->withoutMiddleware(EnforceDingTalkSession::class);
        $this->travelTo(Carbon::parse('2026-11-26 01:00:00', 'UTC'));

        require_once database_path('migrations/2016_01_04_173148_create_admin_tables.php');
        (new \CreateAdminTables())->up();
        DB::table('admin_roles')->insert(['name' => 'Administrator', 'slug' => 'administrator']);
        Schema::table('admin_users', fn (Blueprint $table) => $table->integer('status')->default(1));
        foreach ([
            '2026_09_28_000004_create_system_settings_tables.php',
            '2026_09_28_000008_install_department_organization_management.php',
            '2026_09_29_000010_install_portal_platform.php',
            '2026_10_14_000003_add_short_name_to_admin_roles.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->portal = Portal::create(['code' => 'caigou', 'name' => '入录审核系统', 'status' => Portal::STATUS_ENABLED]);
        (require database_path('migrations/2026_10_29_000003_add_portal_menu_page_permissions.php'))->up();
        (require database_path('migrations/2026_11_26_000003_install_aliexpress_dropshipping.php'))->up();

        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('source_shop_domain');
            $table->string('store_name')->nullable();
            $table->string('shopify_order_id');
            $table->string('shopify_order_number');
            $table->text('shopify_payload');
            $table->timestamps();
        });
        Schema::create('logistics_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('logistics_order_id');
            $table->string('shopify_line_item_id');
            $table->string('shopify_variant_id')->nullable();
            $table->string('shopify_sku')->nullable();
        });
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_selection_creates_stable_skus_and_resolves_duplicate_product_links(): void
    {
        $this->signIn('entry', ['aliexpress_selection_manage']);
        $this->get(self::HOST.self::MANAGE)->assertOk()
            ->assertSee('<section class="content-header"><h1>速卖通选品维护 <small>', false)
            ->assertSee('data-ae-form-url=', false)->assertSee('data-ae-dialog', false);
        $this->get(self::HOST.self::MANAGE.'/create')->assertRedirect(self::HOST.self::MANAGE);
        $this->get(self::HOST.self::MANAGE.'/create', self::AJAX)->assertOk()
            ->assertSee('SMT-TEAM-20261126-')->assertSee('data-ae-editor', false)->assertDontSee('<html', false);

        $data = ['supplier_url' => self::LINK.'?spm=first', 'name' => '保温水杯', 'rows' => [$this->row('heise'), $this->row('baise')]];
        $this->postJson(self::HOST.self::MANAGE, $data, self::AJAX)->assertOk()->assertExactJson(['saved' => true]);
        $this->get(self::HOST.self::MANAGE.'?keyword=保温水杯&per_page=20&page=1')->assertOk()
            ->assertSee('已保存速卖通商品资料。')->assertSee('保温水杯');
        $product = AliExpressSelectionProduct::query()->sole();
        $black = $product->skus->first();
        $white = $product->skus->last();
        $this->assertSame(self::LINK, $product->supplier_url);
        $this->assertSame('SMT-TEAM-20261126-baowenbei-heise-1pc', $black->sku);
        $this->assertSame('SMT-TEAM-20261126-baowenbei-baise-1pc', $white->sku);

        $url = self::HOST.self::MANAGE.'/lookup?'.http_build_query(['supplier_url' => self::LINK.'?utm_source=second']);
        $this->getJson($url)->assertOk()->assertJsonPath('existing.id', $product->id);
        $this->postJson(self::HOST.self::MANAGE, $data)->assertUnprocessable()->assertJsonValidationErrors('supplier_url');
        $this->postJson(self::HOST.self::MANAGE, array_replace($data, ['supplier_url' => str_replace('7770001', '7770002', self::LINK)]))
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.pinyin');
        $this->assertDatabaseCount('aliexpress_selection_products', 1);

        $this->travel(1)->day();
        $this->get(self::HOST.self::MANAGE.'/'.$product->id.'/edit', self::AJAX)->assertOk()
            ->assertSee($black->sku)->assertDontSee('<html', false);
        $this->putJson(self::HOST.self::MANAGE.'/'.$product->id, array_replace($data, ['rows' => [
            ['id' => $black->id, 'supplier_sku' => 'sku-100', 'is_enabled' => '1'],
            ['id' => $white->id, 'supplier_sku' => 'sku-200', 'is_enabled' => '0'],
            $this->row('lanse'),
        ]]), self::AJAX)->assertOk()->assertExactJson(['saved' => true]);
        $this->assertDatabaseHas('aliexpress_selection_skus', ['id' => $white->id, 'sku' => $white->sku, 'is_enabled' => false]);
        $this->assertDatabaseHas('aliexpress_selection_skus', ['sku' => 'SMT-TEAM-20261126-baowenbei-lanse-1pc']);

        $this->signIn('browse', ['aliexpress_selection_browse']);
        $this->get(self::HOST.'/aliexpress/selection')->assertOk()->assertSee($black->sku)->assertDontSee($white->sku)
            ->assertSee('<section class="content-header"><h1>速卖通选品查看 <small>', false)
            ->assertSee('data-ae-details-template', false)->assertSee('复制 SKU')->assertDontSee('录入商品');
        $this->postJson(self::HOST.self::MANAGE, $data)->assertForbidden();
        $this->get(self::HOST.self::MANAGE.'/'.$product->id.'/edit', self::AJAX)->assertForbidden();
        $this->getJson($url)->assertForbidden();
    }

    public function test_matcher_uses_raw_lines_respects_cutoff_and_never_duplicates_store_order_lines(): void
    {
        $this->signIn('entry', ['aliexpress_selection_manage', 'aliexpress_procurement']);
        $this->post(self::HOST.self::MANAGE, ['supplier_url' => self::LINK, 'name' => '保温水杯', 'rows' => [
            $this->row('heise'), $this->row('baise', '0'),
        ]])->assertRedirect(self::HOST.self::MANAGE);
        $black = 'SMT-TEAM-20261126-baowenbei-heise-1pc';
        $white = 'SMT-TEAM-20261126-baowenbei-baise-1pc';

        $this->travel(2)->minutes();
        $order = $this->order('store-a.myshopify.com', '2026-11-25T20:01:00-05:00', [$black, 'SMT-NOT-RECORDED', 'ORDINARY']);
        $this->order('store-b.myshopify.com', '2026-11-26T09:01:00+08:00', [$black, $white]);
        $this->order('old.myshopify.com', '2026-11-26T08:59:59+08:00', [$black]);

        $this->artisan('aliexpress:match-orders')->assertSuccessful();
        $this->artisan('aliexpress:match-orders')->assertSuccessful();
        $this->assertDatabaseCount('aliexpress_procurement_items', 4);
        $this->assertDatabaseHas('aliexpress_procurement_items', [
            'logistics_order_id' => $order, 'logistics_order_item_id' => 1, 'shopify_order_id' => '98765432101234567',
            'shopify_line_item_id' => '700', 'sku' => $black, 'shop_domain' => 'store-a.myshopify.com', 'quantity' => 2,
        ]);
        $this->assertDatabaseMissing('aliexpress_procurement_items', ['shop_domain' => 'old.myshopify.com']);

        $this->get(self::HOST.'/aliexpress/procurement?status=ready')->assertOk()
            ->assertSee('<section class="content-header"><h1>速卖通待采购 <small>', false)
            ->assertViewHas('summary', ['ready' => 2, 'exception' => 2])
            ->assertSee('store-a.myshopify.com')->assertSee('store-b.myshopify.com')->assertDontSee('SMT-NOT-RECORDED');
        $this->get(self::HOST.'/aliexpress/procurement?status=exception')->assertOk()
            ->assertSee('选品规格已停用')->assertSee('未找到对应的有效 SKU 映射');

        $payload = json_decode(DB::table('logistics_orders')->where('id', $order)->value('shopify_payload'), true);
        $payload['displayFinancialStatus'] = 'PARTIALLY_REFUNDED';
        DB::table('logistics_orders')->where('id', $order)->update(['shopify_payload' => json_encode($payload), 'updated_at' => now()]);
        $this->artisan('aliexpress:match-orders')->assertSuccessful();
        $this->assertSame('order_refunded', AliExpressProcurementItem::query()->where('logistics_order_id', $order)->firstOrFail()->source_issue);
        $this->get(self::HOST.'/aliexpress/procurement')->assertOk()->assertViewHas('summary', ['ready' => 1, 'exception' => 3]);
    }

    private function signIn(string $name, array $codes): void
    {
        $department = Department::create(['name' => $name, 'short_name' => 'TEAM', 'slug' => $name]);
        $user = new AdminUser();
        $user->forceFill(['username' => $name, 'name' => $name, 'password' => 'unused', 'status' => 1])->save();
        $user->departments()->attach($department->id, ['position' => 'member', 'is_primary' => true]);
        $this->portal->departmentGrants()->create(['department_id' => $department->id, 'includes_descendants' => false]);
        $permissions = DB::table('admin_permissions')->whereIn('slug', array_map(fn ($code) => 'portal.caigou.'.$code, $codes))->pluck('id');
        foreach ($permissions as $permission) {
            DB::table('admin_role_permissions')->insert(['role_id' => $department->id, 'permission_id' => $permission]);
        }
        $this->actingAs($user, config('admin.auth.guard'));
    }

    private function row(string $attribute, string $enabled = '1'): array
    {
        return ['pinyin' => 'baowenbei', 'attribute' => $attribute, 'package_label' => '1pc', 'supplier_sku' => 'supplier-'.$attribute, 'is_enabled' => $enabled];
    }

    private function order(string $domain, string $createdAt, array $skus): int
    {
        $lines = [];
        foreach ($skus as $index => $sku) {
            $lines[] = ['id' => 'gid://shopify/LineItem/'.(700 + $index), 'sku' => $sku, 'quantity' => 2, 'title' => '水杯'];
        }
        $id = DB::table('logistics_orders')->insertGetId([
            'source_shop_domain' => $domain, 'shopify_order_id' => '98765432101234567', 'shopify_order_number' => '1001',
            'shopify_payload' => json_encode(['createdAt' => $createdAt, 'displayFinancialStatus' => 'PAID', 'lineItems' => ['nodes' => $lines]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $index => $line) {
            DB::table('logistics_order_items')->insert([
                'logistics_order_id' => $id, 'shopify_line_item_id' => $line['id'], 'shopify_variant_id' => 'gid://shopify/ProductVariant/33',
                'shopify_sku' => $index === 0 ? 'CORRECTED-IN-ERP' : 'SMT-CHANGED-IN-ERP',
            ]);
        }

        return $id;
    }
}
