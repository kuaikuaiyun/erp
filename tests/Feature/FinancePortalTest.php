<?php

namespace Tests\Feature;

use App\Models\FinancePaymentBalance;
use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portal\Services\PortalDomainService;
use App\Portal\Services\PortalReturnUrlService;
use App\Portals\Finance\FinanceCatalog;
use App\Portals\Finance\FinanceExchangeRateService;
use App\Portals\Finance\FinancePaymentBalanceService;
use App\Portals\Finance\FinancePayPalBalanceService;
use App\Portals\Finance\FinanceWorkbookService;
use App\Services\AdminWatermarkService;
use App\Services\SystemSettingService;
use Encore\Admin\Facades\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\FinanceTestUser;
use Tests\TestCase;

class FinancePortalTest extends TestCase
{
    private FinanceWorkbookService $workbooks;

    private FinanceTestUser $user;

    private string $portalCode = 'caiwu';

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Queue::fake();
        config(['app.url' => 'http://caiwu.kky.com']);
        config(['finance.relay.ca_bundle' => null]);
        $this->app['url']->forceRootUrl('http://caiwu.kky.com');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        DB::purge('sqlite');
        Schema::create('portals', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status');
            $table->text('remark')->nullable();
            $table->timestamps();
        });
        Schema::create('portal_menus', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('portal_id');
            $table->unsignedInteger('parent_id')->default(0);
            foreach (['code', 'title_key', 'path', 'icon'] as $field) {
                $table->string($field);
            }
            $table->string('title')->nullable();
            $table->string('permission_slug')->nullable();
            $table->unsignedInteger('sort');
            $table->boolean('is_enabled');
            $table->timestamps();
            $table->unique(['portal_id', 'code']);
        });
        Schema::create(config('admin.database.permissions_table'), function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('http_method')->nullable();
            $table->text('http_path')->nullable();
            $table->timestamps();
        });
        $migration = require database_path('migrations/2026_11_03_000002_install_finance_portal.php');
        $migration->up();
        (require database_path('migrations/2026_11_03_000003_create_finance_payment_balances.php'))->up();
        (require database_path('migrations/2026_11_03_000004_move_finance_balances_to_workbench.php'))->up();
        (require database_path('migrations/2026_11_03_000005_rename_finance_payment_channels.php'))->up();
        (require database_path('migrations/2026_11_03_000006_create_finance_exchange_rates.php'))->up();
        (require database_path('migrations/2026_11_03_000007_create_finance_paypal_account_profiles.php'))->up();
        (require database_path('migrations/2026_11_03_000008_create_finance_payment_accounts.php'))->up();
        (require database_path('migrations/2026_11_03_000009_link_finance_payment_accounts_to_legacy_cards.php'))->up();
        (require database_path('migrations/2026_11_04_000001_create_finance_relay_sync_tables.php'))->up();
        (require database_path('migrations/2026_11_04_000002_create_finance_accounting_tables.php'))->up();
        (require database_path('migrations/2026_11_04_000003_align_finance_business_menus.php'))->up();
        (require database_path('migrations/2026_11_04_000005_release_deleted_finance_account_names.php'))->up();
        (require database_path('migrations/2026_11_04_000011_add_finance_settlement_reports.php'))->up();
        (require database_path('migrations/2026_11_05_000009_scope_finance_account_names_by_type.php'))->up();
        (require database_path('migrations/2026_11_09_000004_add_card_platform_to_finance_payment_accounts.php'))->up();
        (require database_path('migrations/2026_11_09_000005_create_finance_credit_settlement_entries.php'))->up();
        (require database_path('migrations/2026_11_09_000006_add_card_merchant_key_to_finance_payment_accounts.php'))->up();
        (require database_path('migrations/2026_11_09_000007_create_finance_credit_account_groups.php'))->up();
        (require database_path('migrations/2026_11_10_000002_create_finance_credit_usd_rates.php'))->up();
        $this->workbooks = app(FinanceWorkbookService::class);
        $this->user = new FinanceTestUser(['id' => 1, 'name' => 'Finance tester']);
        $this->user->forceFill(['id' => 1]);
        $entries = Mockery::mock(PortalEntryController::class);
        $entries->shouldReceive('portalContext')->andReturnUsing(function () {
            $portal = Portal::query()->where('code', 'caiwu')->firstOrFail();
            $portal->code = $this->portalCode;
            $portal->load('menus');

            return ['portal' => $portal, 'user' => $this->user];
        });
        $this->app->instance(PortalEntryController::class, $entries);
        $this->mock(AdminWatermarkService::class)->shouldReceive('scriptForAdministrator')->andReturnNull();
        $this->mock(SystemSettingService::class)->shouldReceive('footer')->andReturn(['copyright_text' => '', 'contact_information' => '']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_order_menu_keeps_only_paypal_and_credit_card_orders(): void
    {
        $migration = require database_path('migrations/2026_11_04_000006_simplify_finance_order_menus.php');
        $migration->up();
        $migration->up();
        $ordersId = DB::table('portal_menus')->where('code', 'finance_orders')->value('id');
        $this->assertSame(['finance_paypal-orders', 'finance_xuzong2-orders'], DB::table('portal_menus')
            ->where('parent_id', $ordersId)->where('is_enabled', true)->orderBy('sort')->pluck('code')->all());
        $this->assertDatabaseHas('portal_menus', ['code' => 'finance_xuzong2-orders', 'title' => '信用卡订单数据']);
    }

    public function test_finance_displays_simplified_headers_without_changing_source_keys(): void
    {
        $headers = \App\Portals\Finance\FinanceCatalog::dataset('paypal-orders')['headers'];
        $this->assertContains('餘額影響', $headers);
        $this->assertSame('类型 余额影响 启盛', \App\Portals\Finance\FinanceCatalog::simplified('類型 餘額影響 啟盛'));
        $this->get('/finance/records/paypal-orders')->assertOk()
            ->assertSee('<th scope="col" data-settlement-column="6" class="logistics-payment-information-heading">支付信息</th>', false);
        $labels = \App\Portals\Finance\FinanceCatalog::filterFieldLabels('paypal-orders');
        $this->assertStringStartsWith('余额影响', $labels['column_14']);
        $this->assertSame('payload->餘額影響', \App\Portals\Finance\FinanceCatalog::filterColumn('paypal-orders', 'column_14'));
    }

    public function test_order_account_choices_follow_workbench_accounts_and_keep_activity_identity(): void
    {
        $account = $this->financePayPalAccount('工作台显示名称', ['activity_account' => '原始活动账户']);
        $deleted = $this->financePayPalAccount('已删除账户');
        $deleted->delete();
        $this->get('/finance/reports/summary?view=transactions&month=2026-09')->assertOk()
            ->assertViewHas('reportAccounts', fn ($accounts) => $accounts->contains('id', $account->id) && ! $accounts->contains('id', $deleted->id));
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], '历史孤立账户', 'UTC', 1);
        $response = $this->get('/finance/records/paypal-orders')->assertOk()
            ->assertViewHas('accounts', fn ($accounts) => $accounts->all() === [$account->activity_account]);
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $options = (new \DOMXPath($document))->query('//select[@id="filter-account"]/option');
        $this->assertSame(2, $options->length);
        $this->assertSame('原始活动账户', $options->item(1)->getAttribute('value'));
        $this->assertSame('工作台显示名称', $options->item(1)->textContent);
    }

    public function test_event_code_categories_follow_workbook_without_overwriting_custom_settings(): void
    {
        DB::table('finance_transaction_types')->insert([
            ['raw_type' => 'T0006', 'category' => null],
            ['raw_type' => 'T0005', 'category' => '自定义分类'],
        ]);
        $migration = require database_path('migrations/2026_11_04_000007_map_finance_paypal_event_types.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'T0006', 'category' => '结账付款']);
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'T0005', 'category' => '自定义分类']);
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'T1110', 'category' => '争议冻结']);
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'T2104', 'category' => '返还冻结']);
        $this->assertSame('PayPal Checkout付款', \App\Portals\Finance\FinancePayPalEventTypes::label('T0006'));
        $this->assertSame('待确认交易类型（T9999）', \App\Portals\Finance\FinancePayPalEventTypes::label('T9999'));
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'T0106', 'category' => '退款费用']);
    }

    public function test_condition_choices_use_real_records_and_configured_types_with_original_values(): void
    {
        $this->financePayPalAccount('工作台名称', ['activity_account' => '账户标识']);
        $row = [...$this->recordRow(), '類型' => 'T0006', '狀態' => 'S', '餘額影響' => 'Credit'];
        $this->workbooks->importRecords('paypal-orders', [2 => $row], '账户标识', 'UTC', 1);
        $response = $this->get('/finance/records/paypal-orders')->assertOk();
        $choices = $response->viewData('conditionChoices');
        $this->assertContains(['value' => 'T0006', 'label' => 'PayPal Checkout付款'], $choices['raw_type']);
        $this->assertContains(['value' => 'S', 'label' => '成功'], $choices['status']);
        $this->assertSame([['value' => '账户标识', 'label' => '工作台名称']], $choices['account']);
        $this->assertSame($choices['raw_type'], $choices['column_4']);
        $this->get('/finance/records/paypal-orders?'.http_build_query(['conditions' => [
            ['field' => 'raw_type', 'operator' => 'equals', 'value' => 'T0006'],
            ['field' => 'status', 'operator' => 'equals', 'value' => 'S'],
        ]]))->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1);
    }

    public function test_amount_comparisons_use_numbers_and_apply_to_export(): void
    {
        foreach (['9.50', '20.00', '100.00', '-5.00'] as $index => $amount) {
            $this->workbooks->importRecords('paypal-orders', [2 => [...$this->recordRow(), '交易 ID' => 'COMPARE-'.$index, '總額' => $amount]], 'A', 'UTC', 1);
        }
        foreach (['gte' => 2, 'gt' => 1, 'lte' => 3, 'lt' => 2] as $operator => $count) {
            $query = http_build_query(['conditions' => [['field' => 'column_7', 'operator' => $operator, 'value' => '20']]]);
            $this->get('/finance/records/paypal-orders?'.$query)->assertOk()
                ->assertViewHas('records', fn ($records) => $records->total() === $count);
        }
        $query = http_build_query(['conditions' => [['field' => 'column_7', 'operator' => 'gte', 'value' => '20']], 'export_scope' => 'filtered', 'export_format' => 'csv']);
        $csv = $this->get('/finance/records/paypal-orders/export?'.$query)->assertOk()->streamedContent();
        $this->assertStringContainsString('COMPARE-1', $csv);
        $this->assertStringContainsString('COMPARE-2', $csv);
        $this->assertStringNotContainsString('COMPARE-0', $csv);
        $this->getJson('/finance/records/paypal-orders?'.http_build_query(['conditions' => [['field' => 'category', 'operator' => 'gte', 'value' => '20']]]))
            ->assertUnprocessable();
    }

    public function test_amount_display_removes_padding_without_rounding_or_changing_identifiers(): void
    {
        $this->assertSame('36.01', \App\Portals\Finance\FinanceCatalog::displayValue('paypal-orders', 9, '36.01000000'));
        $this->assertSame('26.80', \App\Portals\Finance\FinanceCatalog::displayValue('paypal-orders', 9, '26.80000000'));
        $this->assertSame('-0.30', \App\Portals\Finance\FinanceCatalog::displayValue('paypal-orders', 8, '-0.30000000'));
        $this->assertSame('1.12345678', \App\Portals\Finance\FinanceCatalog::displayValue('paypal-orders', 9, '1.12345678'));
        $this->assertSame('001.0000', \App\Portals\Finance\FinanceCatalog::displayValue('paypal-orders', 11, '001.0000'));
    }

    public function test_order_account_display_and_export_follow_renames(): void
    {
        $account = $this->financePayPalAccount('原显示名称', ['activity_account' => '固定账户标识']);
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], '固定账户标识', 'UTC', 1);
        $account->update(['account_name' => '最新账户名称']);
        $this->get('/finance/records/paypal-orders?'.http_build_query(['account' => '固定账户标识']))
            ->assertOk()->assertSee('>最新账户名称</dd>', false)
            ->assertDontSee('>固定账户标识</dd>', false)
            ->assertDontSee('原始数据');
        $response = $this->get('/finance/records/paypal-orders/export?'.http_build_query([
            'account' => '固定账户标识', 'export_scope' => 'filtered', 'export_format' => 'csv',
        ]))->assertOk();
        $this->assertStringContainsString('最新账户名称', $response->streamedContent());
        $this->assertStringContainsString('PayPal账户', $response->streamedContent());
        $this->assertDatabaseHas('finance_records', ['account' => '固定账户标识']);
    }

    public function test_excel_exports_use_simplified_chinese_headers_and_values(): void
    {
        foreach (['xls', 'xlsx'] as $format) {
            $response = $this->workbooks->download(['類型', '餘額影響'], [['糾紛費用', '貸記']], 'test.'.$format, $format);
            ob_start();
            $response->sendContent();
            $sheet = $this->readDownload(ob_get_clean());
            $this->assertSame(['类型', '余额影响'], $sheet->rangeToArray('A1:B1')[0]);
            $this->assertSame(['纠纷费用', '贷记'], $sheet->rangeToArray('A2:B2')[0]);
        }
    }

    public function test_excel_export_has_compact_rows_and_preserves_numeric_and_reference_types(): void
    {
        foreach (['xls', 'xlsx'] as $format) {
            $description = str_repeat("商品详情\n", 40);
            $response = $this->workbooks->download(['交易 ID', '总额', '费用', '物品标题', '姓名'], [
                ['00001234567890123456', '1,234.50', '-1.34', $description, '=SUM(1,2)'],
            ], 'orders.'.$format, $format);
            ob_start();
            $response->sendContent();
            $sheet = $this->readDownload(ob_get_clean());
            $this->assertSame('00001234567890123456', $sheet->getCell('A2')->getValue());
            $this->assertSame('s', $sheet->getCell('A2')->getDataType());
            $this->assertEquals(1234.5, $sheet->getCell('B2')->getValue());
            $this->assertSame('n', $sheet->getCell('B2')->getDataType());
            $this->assertEquals(-1.34, $sheet->getCell('C2')->getValue());
            $this->assertSame($description, $sheet->getCell('D2')->getValue());
            $this->assertSame('=SUM(1,2)', $sheet->getCell('E2')->getValue());
            $this->assertFalse($sheet->getCell('E2')->isFormula());
            $this->assertEquals(36, $sheet->getRowDimension(2)->getRowHeight());
            $this->assertSame('center', $sheet->getStyle('B2')->getAlignment()->getVertical());
            $this->assertGreaterThan($sheet->getColumnDimension('B')->getWidth(), $sheet->getColumnDimension('D')->getWidth());
            $this->assertSame('A2', $sheet->getFreezePane());
            if ($format === 'xlsx') {
                $this->assertSame('A1:E2', $sheet->getAutoFilter()->getRange());
            }
        }
    }

    public function test_shared_finance_xlsx_export_writes_all_rows_from_a_large_generator(): void
    {
        $rows = (function () {
            for ($index = 1; $index <= 15000; $index++) {
                yield ['0000'.$index, '123.45', '0.12345678', '=SUM(1,2)'];
            }
        })();
        $response = $this->workbooks->download(['交易ID', '金额', '汇率', '备注'], $rows, 'bulk.xlsx');
        ob_start();
        $response->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'finance-stream-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, ob_get_clean());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $styles = $zip->getFromName('xl/styles.xml');
        $zip->close();
        $this->assertSame(15001, substr_count($xml, '<row '));
        $this->assertStringContainsString('000015000', $xml);
        $this->assertStringContainsString('<v>123.45</v>', $xml);
        $this->assertStringContainsString('<v>0.12345678</v>', $xml);
        $this->assertStringContainsString('=SUM(1,2)', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringContainsString('formatCode="#,##0.00########"', $styles);
    }

    public function test_reversed_search_dates_show_empty_results_without_validation_banner(): void
    {
        $this->get('/finance/records/xuzong2-orders?from=2026-11-04&to=2026-11-01')
            ->assertOk()->assertSessionHasNoErrors()->assertSee('无符合条件的记录')
            ->assertDontSee('validation.after_or_equal')
            ->assertViewHas('records', fn ($records) => $records->total() === 0)
            ->assertViewHas('filters', fn ($filters) => $filters['from'] === '2026-11-04' && $filters['to'] === '2026-11-01');
    }

    public function test_type_settings_paginate_ten_rows_and_use_dialog_editing(): void
    {
        foreach (range(1, 25) as $index) {
            $this->workbooks->registerType('Pagination type '.$index);
        }
        $this->get('/finance/settings/types')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->perPage() === 10 && $rows->count() === 10)
            ->assertSee('id="finance-setting-dialog"', false)->assertSee('data-new-setting', false);
        $this->get('/finance/settings/types?page=2')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->currentPage() === 2 && $rows->count() === 10);
        $this->get('/finance/settings/types?per_page=20')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->perPage() === 20 && $rows->count() === 20)
            ->assertSee('显示 1-20 条')->assertSee('每页显示');
        $this->get('/finance/records/paypal-orders?per_page=50')->assertOk()
            ->assertViewHas('records', fn ($rows) => $rows->perPage() === 50);
    }

    public function test_type_settings_merge_aliases_and_update_all_historical_keys(): void
    {
        $this->workbooks->registerType('T0006');
        $this->get('/finance/settings/types?'.http_build_query(['q' => 'Checkout']))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->category === '结账付款')
            ->assertSee('list="finance-category-options"', false);
        $this->post('/finance/settings/types', ['raw_type' => 'T0006', 'category' => '自定义收款'])->assertRedirect()->assertSessionHasNoErrors();
        foreach (['T0006', 'Express Checkout Payment', 'PayPal Checkout付款'] as $alias) {
            $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => $alias, 'category' => '自定义收款']);
        }
        $this->get('/finance/settings/types?'.http_build_query(['category' => '自定义收款']))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $sheet = $this->readDownload($this->get('/finance/settings/types/export')->assertOk()->streamedContent());
        $matches = array_filter($sheet->toArray(), fn ($row) => ($row[0] ?? '') === 'PayPal Checkout付款');
        $this->assertCount(1, $matches);
    }

    public function test_new_types_remain_distinct_and_can_use_existing_or_new_categories(): void
    {
        $this->workbooks->registerType('T9998');
        $this->workbooks->registerType('T9999');
        $this->get('/finance/settings/types?category=__unclassified')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->post('/finance/settings/types', ['raw_type' => 'T9998', 'category' => '结账付款'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/finance/settings/types', ['raw_type' => 'T9999', 'category' => '新业务分类'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/finance/settings/types')->assertOk()
            ->assertViewHas('categoryOptions', fn ($options) => $options->contains('新业务分类'));
        $this->get('/finance/settings/types?category=__unclassified')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    public function test_alias_migration_preserves_categories_and_backfills_only_unclassified_aliases(): void
    {
        DB::table('finance_transaction_types')->where('raw_type', 'PayPal Checkout付款')->update(['category' => '已有分类']);
        DB::table('finance_transaction_types')->where('raw_type', 'Express Checkout Payment')->update(['category' => null]);
        $migration = require database_path('migrations/2026_11_04_000012_preserve_finance_type_alias_categories.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'PayPal Checkout付款', 'category' => '已有分类']);
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'Express Checkout Payment', 'category' => '已有分类']);
        $this->workbooks->registerType('T0006');
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'T0006', 'category' => '已有分类']);
    }

    public function test_type_category_filter_and_delete_require_manage_permission_and_preserve_orders(): void
    {
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], 'A', 'UTC', 1);
        $id = DB::table('finance_transaction_types')->where('raw_type', 'PayPal Checkout付款')->value('id');
        $this->get('/finance/settings/types?'.http_build_query(['category' => '结账付款']))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->every(fn ($row) => $row->category === '结账付款'))
            ->assertSee('data-delete-type', false);
        $this->user->manage = false;
        $this->delete('/finance/settings/types/'.$id)->assertForbidden();
        $this->assertDatabaseHas('finance_transaction_types', ['id' => $id]);
        $this->user->manage = true;
        $this->delete('/finance/settings/types/'.$id.'?'.http_build_query(['category' => '结账付款']))
            ->assertRedirect(route('finance.settings', ['kind' => 'types', 'category' => '结账付款']));
        $this->assertDatabaseMissing('finance_transaction_types', ['id' => $id]);
        $this->assertDatabaseHas('finance_records', ['account' => 'A', 'raw_type' => 'PayPal Checkout付款']);
    }

    public function test_legacy_queue_job_without_progress_property_can_still_execute(): void
    {
        $account = $this->financePayPalAccount('Legacy queued account');
        $job = (new \ReflectionClass(\App\Jobs\SyncFinancePayPalOrders::class))->newInstanceWithoutConstructor();
        $job->accountId = $account->id;
        $job->actorId = 1;
        $this->assertNull($job->progressId);
        $sync = \Mockery::mock(\App\Portals\Finance\FinancePayPalSyncService::class);
        $sync->shouldReceive('refreshOrders')->once()->andReturn(['ok'=>true,'count'=>0,'error'=>null]);
        $job->handle($sync);
        $id = app(\App\Portals\Finance\FinanceOrderRefreshProgress::class)->enqueue($account, 1);
        $task = app(\App\Portals\Finance\FinanceOrderRefreshProgress::class)->get($id);
        app(\App\Portals\Finance\FinanceOrderRefreshProgress::class)->update($id, ['queued_at'=>time()-180]);
        $this->assertSame('queued', $task['status']);
        $this->assertNotEmpty(app(\App\Portals\Finance\FinanceOrderRefreshProgress::class)->get($id)['warning']);
    }

    public function test_refresh_progress_survives_navigation_and_accepts_more_accounts_while_running(): void
    {
        $a=$this->financePayPalAccount('Append A',['relay_certificate_sha1'=>str_repeat('AB',20)]);
        $b=$this->financePayPalAccount('Append B');
        $c=$this->financePayPalAccount('Append C');
        $url='/finance/orders/paypal/refresh';
        $first=$this->postJson($url,['accounts'=>[$a->id]])->assertStatus(202);
        $batch=$first->json('batch');
        $this->postJson($url,['accounts'=>[$b->id,$c->id]])->assertStatus(202)->assertJsonPath('batch',$batch);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncFinancePayPalOrders::class,3);
        $this->postJson($url,['accounts'=>[$a->id,$b->id]])->assertStatus(202);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncFinancePayPalOrders::class,3);
        $this->get('/finance')->assertOk();
        $latest=$this->getJson($url)->assertOk()->assertJsonPath('batch',$batch)->assertJsonPath('done',false);
        $this->assertCount(3,$latest->json('results'));
        $task=$latest->json('results.0.id');
        app(\App\Portals\Finance\FinanceOrderRefreshProgress::class)->update($task,['status'=>'success','count'=>12]);
        $this->get('/finance/records/paypal-orders')->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('results.0.count',12)->assertJsonPath('results.0.status','success');
        Http::fake(fn($request)=>$this->signedRelayResponse($request,$a,['balances'=>[],'as_of_time'=>now('UTC')->format('Y-m-d\TH:i:s\Z')]));
        $this->postJson('/finance/payment-accounts/finance-'.$a->id.'/refresh')->assertOk()->assertJsonPath('orders_batch',$batch);
        $this->getJson($url)->assertOk()->assertJsonPath('results.0.status','queued');
        $this->postJson($url,['accounts'=>[]])->assertUnprocessable();
        $this->postJson($url,['accounts'=>[$a->id,999999]])->assertUnprocessable();
    }

    public function test_order_refresh_progress_reports_each_account_counts_and_failures(): void
    {
        $first = $this->financePayPalAccount('Progress success');
        $second = $this->financePayPalAccount('Progress failure');
        $response = $this->postJson('/finance/orders/paypal/refresh', ['account' => 'all'])->assertStatus(202);
        $batch = $response->json('batch');
        $url = '/finance/orders/paypal/refresh/'.$batch;
        $status = $this->getJson($url)->assertOk()->assertJsonPath('done', false);
        $this->assertSame(['queued', 'queued'], array_column($status->json('results'), 'status'));
        $jobs = \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\SyncFinancePayPalOrders::class)->values();
        $this->assertCount(2, $jobs);
        $progress = app(\App\Portals\Finance\FinanceOrderRefreshProgress::class);
        $progress->update($jobs[0]->progressId, ['status' => 'running', 'baseline' => 0, 'started_at' => time()]);
        DB::table('finance_sync_logs')->insert(['payment_account_id' => $first->id, 'operation' => 'transaction_search',
            'status' => 'running', 'page_count' => 1, 'record_count' => 4, 'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($url)->assertOk()->assertJsonPath('results.0.status', 'running')->assertJsonPath('results.0.count', 4);
        $sync = \Mockery::mock(\App\Portals\Finance\FinancePayPalSyncService::class);
        $sync->shouldReceive('refreshOrders')->once()->withArgs(fn ($a, $actor) => $a->id === $first->id)
            ->andReturn(['ok' => true, 'count' => 7, 'error' => null]);
        $sync->shouldReceive('refreshOrders')->once()->withArgs(fn ($a, $actor) => $a->id === $second->id)
            ->andReturn(['ok' => false, 'count' => 3, 'error' => '分页数量不一致']);
        foreach ($jobs as $job) $job->handle($sync);
        $this->getJson($url)->assertOk()->assertJsonPath('done', true)
            ->assertJsonPath('results.0.account', 'Progress success')->assertJsonPath('results.0.status', 'success')->assertJsonPath('results.0.count', 7)
            ->assertJsonPath('results.1.account', 'Progress failure')->assertJsonPath('results.1.status', 'failed')
            ->assertJsonPath('results.1.count', 3)->assertJsonPath('results.1.error', '分页数量不一致');
        $progress->update($jobs[0]->progressId, ['status' => 'running', 'baseline' => 0, 'started_at' => time() - 1000]);
        $this->getJson($url)->assertOk()->assertJsonPath('results.0.status', 'failed')->assertJsonPath('results.0.count', 4);
        $second->update(['status' => 'disabled']);
        $jobs[1]->handle($sync);
        $this->getJson($url)->assertOk()->assertJsonPath('results.1.status', 'skipped');
        $this->getJson('/finance/orders/paypal/refresh/00000000-0000-0000-0000-000000000000')->assertNotFound();
        $this->user->manage = false;
        $this->getJson($url)->assertForbidden();
    }

    public function test_order_list_can_queue_one_or_all_enabled_paypal_accounts_without_balance_calls(): void
    {
        Http::fake();
        $first = $this->financePayPalAccount('Order refresh A');
        $second = $this->financePayPalAccount('Order refresh B');
        $disabled = $this->financePayPalAccount('Disabled refresh', ['status' => 'disabled']);
        $deleted = $this->financePayPalAccount('Deleted refresh');
        $deleted->delete();
        $this->financePayPalAccount('Card refresh', ['account_type' => 'credit_card']);
        $this->financePayPalAccount('Unconfigured refresh', ['relay_url' => null]);
        $this->get('/finance/records/paypal-orders')->assertOk()->assertSee('id="finance-order-refresh-open"', false)
            ->assertViewHas('refreshablePayPalAccounts', fn ($accounts) => $accounts->modelKeys() === [$first->id, $second->id]);
        $url = '/finance/orders/paypal/refresh';
        $this->postJson($url, ['account' => (string) $second->id])->assertStatus(202)->assertJsonPath('queued', ['Order refresh B']);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncFinancePayPalOrders::class, fn ($job) => $job->accountId === $second->id);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncFinancePayPalOrders::class, 1);
        \Illuminate\Support\Facades\Queue::fake();
        $this->postJson($url, ['account' => 'all'])->assertStatus(202)->assertJsonPath('queued', ['Order refresh A', 'Order refresh B']);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncFinancePayPalOrders::class, 1);
        $this->postJson($url, ['account' => (string) $disabled->id])->assertUnprocessable();
        $this->postJson($url, ['account' => (string) $deleted->id])->assertUnprocessable();
        $this->postJson($url, ['account' => 'invalid'])->assertUnprocessable();
        Http::assertNothingSent();
        $this->user->manage = false;
        $this->get('/finance/records/paypal-orders')->assertOk()->assertDontSee('id="finance-order-refresh-open"', false);
        $this->postJson($url, ['account' => 'all'])->assertForbidden();
        $this->user->manage = true;
        DB::table('portal_menus')->where('code', 'finance_paypal-orders')->update(['is_enabled' => false]);
        $this->postJson($url, ['account' => 'all'])->assertForbidden();
    }

    public function test_account_refresh_returns_balance_before_background_orders_and_resumes_with_overlap(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-04 08:00:00', 'UTC'));
        $account = $this->financePayPalAccount('联动刷新', ['relay_certificate_sha1' => str_repeat('AB', 20)]);
        $starts = [];
        Http::fake(function ($request) use ($account, &$starts) {
            if ($request['operation'] === 'balances') {
                return $this->signedRelayResponse($request, $account, ['as_of_time' => '2026-11-04T08:00:00Z', 'balances' => []]);
            }
            $starts[] = $request['query']['start_date'];
            return $this->signedRelayResponse($request, $account, ['page' => 1, 'total_pages' => 0, 'total_items' => 0, 'transaction_details' => []]);
        });
        $url = '/finance/payment-accounts/finance-'.$account->id.'/refresh';
        $this->postJson($url)->assertOk()->assertJsonPath('orders_queued', true)->assertJsonMissingPath('orders');
        $this->assertSame([], $starts);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncFinancePayPalOrders::class, fn ($job) =>
            $job->accountId === $account->id && $job->connection === 'finance_orders' && $job->queue === 'finance-orders');
        (new \App\Jobs\SyncFinancePayPalOrders($account->id, 1))->handle(app(\App\Portals\Finance\FinancePayPalSyncService::class));
        $this->assertSame('2026-05-04T08:00:00Z', $starts[0]);
        $starts = [];
        $this->travelTo(\Carbon\Carbon::parse('2026-11-05 08:00:00', 'UTC'));
        $this->postJson($url)->assertOk()->assertJsonPath('orders_queued', true);
        $this->assertSame([], $starts);
        (new \App\Jobs\SyncFinancePayPalOrders($account->id, 1))->handle(app(\App\Portals\Finance\FinancePayPalSyncService::class));
        $this->assertSame('2026-11-04T08:00:00Z', $starts[0]);
        $this->travelBack();
    }

    public function test_balance_failure_does_not_queue_orders_and_disabled_jobs_do_not_call_relay(): void
    {
        $account = $this->financePayPalAccount('Background guard', ['relay_certificate_sha1' => str_repeat('AB', 20)]);
        Http::fake(fn () => Http::response('', 503));
        $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh')->assertStatus(422);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\SyncFinancePayPalOrders::class);
        Http::fake();
        $account->update(['status' => 'disabled']);
        (new \App\Jobs\SyncFinancePayPalOrders($account->id, 1))->handle(app(\App\Portals\Finance\FinancePayPalSyncService::class));
        Http::assertNothingSent();
    }

    public function test_paypal_details_map_multiple_items_and_unavailable_values(): void
    {
        $details = \App\Portals\Finance\FinancePayPalDetails::payload([
            'transaction_info' => ['ending_balance' => ['value' => '125.50'], 'shipping_amount' => ['value' => '2.00']],
            'shipping_info' => ['name' => '收货人', 'address' => ['line1' => '测试地址', 'city' => '测试城市', 'country_code' => 'US']],
            'cart_info' => ['item_details' => [['item_name' => '商品甲', 'item_quantity' => '2'], ['item_name' => '商品乙', 'item_quantity' => '1']]],
        ]);
        $this->assertCount(26, $details);
        $this->assertSame("商品甲\n商品乙", $details['物品标题']);
        $this->assertSame('3', $details['数量']);
        $this->assertSame('125.50', $details['余额']);
        $this->assertSame('', $details['收件人电邮地址']);
        $this->assertStringContainsString('测试地址', $details['运送地址']);
        $this->assertCount(41, FinanceCatalog::dataset('paypal-orders')['headers']);
        foreach (['xls', 'xlsx'] as $format) {
            $response = $this->workbooks->download(array_keys($details), [array_values($details)], 'details.'.$format, $format);
            ob_start();
            $response->sendContent();
            $sheet = $this->readDownload(ob_get_clean());
            $this->assertSame('物品标题', $sheet->getCell('D1')->getValue());
            $this->assertSame("商品甲\n商品乙", $sheet->getCell('D2')->getValue());
        }
    }

    public function test_finance_audit_regressions_for_display_search_and_quantity(): void
    {
        $team = json_encode(['蒋总团队', '深圳团队']);
        $this->assertSame('蒋总团队、深圳团队', FinanceCatalog::teamLabel($team));
        $this->assertSame('蒋总团队、深圳团队', FinanceCatalog::teamLabel(json_encode($team)));
        $this->assertSame('蒋总团队、深圳团队', FinanceCatalog::displayValue('xuzong2-orders', 17, $team));
        $this->assertSame('已完成', FinanceCatalog::displayValue('paypal-orders', 5, 'S'));
        $headers = FinanceCatalog::dataset('paypal-orders')['headers'];
        $this->assertSame('SALE⚡商品', FinanceCatalog::displayValue('paypal-orders', array_search('物品标题', $headers), "\u{FFFD}\u{FFFD}SALE⚡商品\u{FFFD}"));
        $this->assertSame('3', FinanceCatalog::displayValue('paypal-orders', array_search('数量', $headers), "1\n2"));
        DB::table('finance_transaction_types')->insert(['raw_type' => 'T0006', 'category' => '结账付款']);
        $this->get('/finance/settings/types?'.http_build_query(['q' => 'Checkout付款']))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && in_array('T0006', $rows->first()->aliases, true));
        $id = DB::table('finance_transaction_types')->where('raw_type', 'T0006')->value('id');
        $this->delete('/finance/settings/types/'.$id.'?page=99')->assertRedirect(route('finance.settings', ['kind' => 'types']));
    }

    public function test_paypal_settlement_filters_cover_table_columns_and_original_currency(): void
    {
        $account = $this->financePayPalAccount('结算账户');
        $this->workbooks->importRecords('paypal-orders', [
            2 => $this->recordRow(),
            3 => [...$this->recordRow(), '交易 ID' => 'GBP-PAYMENT', '貨幣' => 'GBP', '總額' => '1234.50'],
        ], $account->activity_account, 'UTC', 1);
        $url = '/finance/reports/paypal-settlement';
        $view = $this->get($url)->assertOk()->assertViewHas('numericConditionFields', fn ($fields) => in_array('hold', $fields, true));
        $this->assertSame([], array_diff_key($view->viewData('columns'), $view->viewData('conditionFields')));
        $this->getJson($url.'?suggestion_field=paypal_6')->assertExactJson([
            ['value' => 'GBP', 'label' => 'GBP'], ['value' => 'USD', 'label' => 'USD'],
        ]);
        $query = http_build_query(['conditions' => [
            ['field' => 'paypal_6', 'operator' => 'equals', 'value' => 'GBP'],
            ['field' => 'paypal_7', 'operator' => 'gte', 'value' => '1,234.50'],
        ]]);
        $this->get($url.'?'.$query)->assertOk()->assertViewHas('rows', fn ($rows) =>
            $rows->total() === 1 && $rows->first()['reference'] === 'GBP-PAYMENT');
        $sheet = $this->readDownload($this->get($url.'?'.$query.'&download=1&export_columns[]=paypal_6')->assertOk()->streamedContent());
        $this->assertSame('GBP', $sheet->getCell('A2')->getValue());
        $this->assertSame(2, $sheet->getHighestDataRow());
    }

    public function test_paypal_settlement_uses_the_configured_brush_order_domain_in_list_and_export(): void
    {
        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->id();
            $table->string('domain');
            $table->string('shop_name');
            $table->string('user_name');
            $table->text('team');
        });
        DB::table('assistant_domain_stores')->insert([
            ['domain' => 'brush.example.com', 'shop_name' => 'xm-67', 'user_name' => '尚云pp刷单', 'team' => '["厦门总部"]'],
            ['domain' => 'duplicate.example.com', 'shop_name' => 'duplicate', 'user_name' => '尚云pp刷单', 'team' => '[]'],
            ['domain' => 'ordinary.example.com', 'shop_name' => 'duplicate', 'user_name' => '普通使用人', 'team' => '[]'],
        ]);
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '交易 ID' => 'BRUSH-PAYMENT'],
            3 => [...$this->recordRow(), '交易 ID' => 'ORDINARY-PAYMENT', '自定义编号' => 'xm-670'],
            4 => [...$this->recordRow(), '交易 ID' => 'AUTO-BRUSH', '自定义编号' => 'xm-67'],
            5 => [...$this->recordRow(), '交易 ID' => 'AMBIGUOUS-PAYMENT', '自定义编号' => 'duplicate'],
            6 => [...$this->recordRow(), '交易 ID' => 'MANUAL-BRUSH'],
        ], 'Domain classification', 'UTC', 1);
        $record = FinanceRecord::where('reference', 'BRUSH-PAYMENT')->sole();
        $this->post('/finance/reports/paypal-settlement/save', [
            'id' => $record->id, 'domain' => 'brush.example.com', 'upsell' => '已加购',
            'subscription' => '订阅', 'owner' => '原使用人', 'team' => '原团队',
        ])->assertRedirect();
        $this->post('/finance/reports/paypal-settlement/save', [
            'id' => FinanceRecord::where('reference', 'MANUAL-BRUSH')->sole()->id, 'owner' => '尚云pp刷单',
        ])->assertRedirect();

        $url = '/finance/reports/paypal-settlement';
        $rows = $this->get($url)->assertOk()->viewData('rows')->getCollection()->keyBy('reference');
        $this->assertSame('未匹配', $rows['ORDINARY-PAYMENT']['subscription']);
        $this->assertSame('未匹配', $rows['AMBIGUOUS-PAYMENT']['subscription']);
        $this->assertSame('', $rows['ORDINARY-PAYMENT']['operation_region']);
        $this->assertSame('刷单', $rows['MANUAL-BRUSH']['team']);
        $this->assertSame('刷单', $rows['MANUAL-BRUSH']['operation_region']);
        $labels = ['brush.example.com', '刷单', '尚云pp刷单', '刷单', '刷单'];
        foreach (['BRUSH-PAYMENT', 'AUTO-BRUSH'] as $reference) {
            $brush = $rows[$reference];
            $this->assertSame($labels, [$brush['domain'], $brush['subscription'], $brush['owner'], $brush['team'], $brush['operation_region']]);
        }

        $sheet = $this->readDownload($this->get($url.'?'.http_build_query([
            'subscription' => '刷单', 'download' => 1, 'export_columns' => ['reference', 'domain', 'subscription', 'owner', 'team', 'operation_region'],
        ]))->assertOk()->streamedContent())->toArray();
        $this->assertEqualsCanonicalizing([['BRUSH-PAYMENT', ...$labels], ['AUTO-BRUSH', ...$labels]], array_slice($sheet, 1));
    }

    public function test_paypal_settlement_matches_a_unique_custom_reference_with_the_same_paid_amount(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('bill_number')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('tongtu_order_number');
            $table->decimal('paid_amount_usd', 18, 4);
        });
        DB::table('logistics_orders')->insert([
            ['bill_number' => null, 'transaction_id' => '0648195064384248627243', 'tongtu_order_number' => 'store-1010', 'paid_amount_usd' => '10.00'],
            ['bill_number' => 'DIRECT', 'transaction_id' => null, 'tongtu_order_number' => 'store-1011', 'paid_amount_usd' => '10.00'],
            ['bill_number' => null, 'transaction_id' => 'duplicate-reference', 'tongtu_order_number' => 'store-1012', 'paid_amount_usd' => '10.00'],
            ['bill_number' => null, 'transaction_id' => 'duplicate-reference', 'tongtu_order_number' => 'store-1013', 'paid_amount_usd' => '11.00'],
        ]);
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '交易 ID' => 'FALLBACK', '自定义编号' => '0648195064384248627243'],
            3 => [...$this->recordRow(), '交易 ID' => 'DIRECT', '自定义编号' => '0648195064384248627243'],
            4 => [...$this->recordRow(), '交易 ID' => 'AMBIGUOUS', '自定义编号' => 'duplicate-reference'],
            5 => [...$this->recordRow(), '交易 ID' => 'WRONG-AMOUNT', '自定义编号' => '0648195064384248627243', '總額' => '10.01'],
        ], 'Custom reference', 'UTC', 1);

        $url = '/finance/reports/paypal-settlement';
        $rows = $this->get($url)->assertOk()->viewData('rows')->getCollection()->keyBy('reference');
        $this->assertSame('store-1010', $rows['FALLBACK']['erp_order']);
        $this->assertSame('store-1011', $rows['DIRECT']['erp_order']);
        $this->assertSame('', $rows['AMBIGUOUS']['erp_order']);
        $this->assertSame('', $rows['WRONG-AMOUNT']['erp_order']);

        $sheet = $this->readDownload($this->get($url.'?download=1&export_columns[]=reference&export_columns[]=erp_order')
            ->assertOk()->streamedContent())->toArray();
        $this->assertSame(['FALLBACK', 'store-1010'], collect($sheet)->slice(1)->keyBy(0)['FALLBACK']);
    }

    public function test_paypal_settlement_shows_a_candidate_without_linking_the_order(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('bill_number')->nullable();
            $table->string('buyer_email');
            $table->dateTime('paid_at');
            $table->decimal('paid_amount_usd', 18, 4);
            $table->string('tongtu_order_number');
            $table->string('payment_gateway');
        });
        Schema::create('logistics_order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('logistics_order_id');
            $table->string('product_title');
            $table->string('variant_title')->nullable();
            $table->decimal('quantity', 18, 4);
        });
        DB::table('logistics_orders')->insert(['id' => 1, 'buyer_email' => 'buyer@example.test', 'paid_at' => '2026-09-09 00:10:00',
            'paid_amount_usd' => '10.01', 'tongtu_order_number' => 'test-1010', 'payment_gateway' => 'PayPal']);
        DB::table('logistics_order_items')->insert(['logistics_order_id' => 1, 'product_title' => 'Sample item',
            'variant_title' => 'Blue', 'quantity' => 2]);
        $account = $this->financePayPalAccount('候选核对');
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '交易 ID' => 'CANDIDATE', '類型' => 'T0006'],
        ], $account->activity_account, 'UTC', 1);
        $record = FinanceRecord::where('reference', 'CANDIDATE')->sole();
        DB::table('finance_payment_transactions')->insert([
            'payment_account_id' => $account->id, 'finance_record_id' => $record->id, 'transaction_id' => $record->reference,
            'event_code' => 'T0006', 'raw_payload' => json_encode([
                'payer_info' => ['email_address' => 'buyer@example.test'],
                'cart_info' => ['item_details' => [['item_name' => 'Sample item-Blue', 'item_quantity' => '2']]],
            ]),
        ]);

        $url = '/finance/reports/paypal-settlement?reference=CANDIDATE';
        $this->get($url)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['erp_order'] === ''
            && str_contains($rows->first()['review'], '候选通途订单 test-1010，待人工确认'));
        $sheet = $this->readDownload($this->get($url.'&download=1&export_columns[]=review')->assertOk()->streamedContent());
        $this->assertStringContainsString('候选通途订单 test-1010，待人工确认', $sheet->getCell('A2')->getValue());

        DB::table('logistics_orders')->where('id', 1)->update(['paid_amount_usd' => '10.02']);
        $this->get($url)->assertOk()->assertViewHas('rows', fn ($rows) => ! str_contains($rows->first()['review'], '候选通途订单'));

        DB::table('logistics_orders')->where('id', 1)->update(['paid_amount_usd' => '10.01', 'bill_number' => 'OTHER-PAYMENT']);
        $this->get($url)->assertOk()->assertViewHas('rows', fn ($rows) => ! str_contains($rows->first()['review'], '候选通途订单'));
    }

    public function test_paypal_settlement_and_export_use_store_owner_history_at_order_time(): void
    {
        $users = (new \App\Models\AdminUser)->getTable();
        Schema::create($users, function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username');
        });
        DB::table($users)->insert([
            ['id' => 10, 'name' => '钱锦山', 'username' => 'original-owner'],
            ['id' => 11, 'name' => '陈志霖', 'username' => 'new-owner'],
            ['id' => 12, 'name' => '张清洪（现名）', 'username' => 'historical-owner'],
        ]);
        $roles = config('admin.database.roles_table');
        Schema::create($roles, function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->integer('sort')->default(0);
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        DB::table($roles)->insert([
            ['id' => 21, 'name' => '运营部', 'slug' => 'dept-000022', 'parent_id' => null],
            ['id' => 22, 'name' => '厦门运营部', 'slug' => 'test-operations-xm', 'parent_id' => 21],
            ['id' => 23, 'name' => '深圳运营部', 'slug' => 'test-operations-sz', 'parent_id' => 21],
            ['id' => 24, 'name' => '郑州运营部', 'slug' => 'test-operations-zz', 'parent_id' => 21],
        ]);
        $memberships = config('admin.database.role_users_table');
        Schema::create($memberships, function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->string('position')->nullable();
            $table->unsignedInteger('position_slot')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
        DB::table($memberships)->insert([
            ['user_id' => 10, 'role_id' => 22, 'is_primary' => true],
            ['user_id' => 11, 'role_id' => 23, 'is_primary' => true],
            ['user_id' => 12, 'role_id' => 22, 'is_primary' => false],
            ['user_id' => 12, 'role_id' => 24, 'is_primary' => true],
        ]);
        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->id();
            $table->string('domain');
            $table->string('user_name');
            $table->text('team')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('shop_status')->default('in_use');
        });
        DB::table('assistant_domain_stores')->insert([
            ['id' => 1, 'domain' => 'transfer.example.com', 'user_name' => '备用曾11', 'team' => '厦门总部', 'owner_user_id' => 10],
            ['id' => 2, 'domain' => 'transfer.example.com', 'user_name' => '陈志霖', 'team' => '深圳团队', 'owner_user_id' => 11],
        ]);
        (require database_path('migrations/2026_10_30_000003_create_assistant_domain_store_owner_histories.php'))->up();
        $history = DB::table('assistant_domain_store_owner_histories');
        $history->insert([
            ['id' => 4, 'assistant_domain_store_id' => 1, 'owner_user_id' => 11, 'owner_name_snapshot' => '陈志霖', 'event' => 'baseline',
                'effective_from' => '2026-10-27 00:00:00', 'effective_to' => '2026-10-28 00:00:00'],
            ['id' => 1, 'assistant_domain_store_id' => 1, 'owner_user_id' => null, 'owner_name_snapshot' => null, 'event' => 'cleared',
                'effective_from' => '2026-10-28 00:00:00', 'effective_to' => '2026-11-01 03:00:00'],
            ['id' => 2, 'assistant_domain_store_id' => 1, 'owner_user_id' => 12, 'owner_name_snapshot' => '张清洪', 'event' => 'assigned',
                'effective_from' => '2026-11-01 03:00:00', 'effective_to' => '2026-11-01 03:00:10'],
            ['id' => 3, 'assistant_domain_store_id' => 1, 'owner_user_id' => 10, 'owner_name_snapshot' => '钱锦山', 'event' => 'transferred',
                'effective_from' => '2026-11-01 03:00:10', 'effective_to' => null],
        ]);
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('bill_number');
            $table->string('source_shop_domain');
            $table->dateTime('order_created_at')->nullable();
            $table->string('assigned_owner_name')->nullable();
            $table->string('operator_name')->nullable();
            $table->string('group_names')->nullable();
            $table->unsignedBigInteger('assistant_domain_store_id');
        });
        DB::table('logistics_orders')->insert([
            ['bill_number' => 'BEFORE-TRANSFER', 'source_shop_domain' => 'transfer.example.com',
                'assistant_domain_store_id' => 1, 'operator_name' => '订单运营', 'group_names' => '郑州团队',
                'assigned_owner_name' => '旧快照', 'order_created_at' => '2026-11-01 03:00:09'],
            ['bill_number' => 'NO-OWNER', 'source_shop_domain' => 'transfer.example.com',
                'assistant_domain_store_id' => 1, 'operator_name' => '备用曾11', 'group_names' => null,
                'assigned_owner_name' => null, 'order_created_at' => null],
            ['bill_number' => 'AT-TRANSFER', 'source_shop_domain' => 'transfer.example.com',
                'assistant_domain_store_id' => 1, 'operator_name' => '备用曾11', 'group_names' => null,
                'assigned_owner_name' => '张清洪', 'order_created_at' => '2026-11-01 03:00:10'],
            ['bill_number' => 'UNASSIGNED', 'source_shop_domain' => 'transfer.example.com',
                'assistant_domain_store_id' => 1, 'operator_name' => '备用曾11', 'group_names' => null,
                'assigned_owner_name' => null, 'order_created_at' => '2026-11-01 02:59:59'],
            ['bill_number' => 'BEFORE-HISTORY', 'source_shop_domain' => 'transfer.example.com',
                'assistant_domain_store_id' => 1, 'operator_name' => '备用曾11', 'group_names' => null,
                'assigned_owner_name' => null, 'order_created_at' => '2026-10-26 23:59:59'],
        ]);
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '日期' => '2026-11-01', '時間' => '03:10:23', '交易 ID' => 'BEFORE-TRANSFER'],
            3 => [...$this->recordRow(), '日期' => '2026-11-01', '時間' => '03:10:24', '交易 ID' => 'NO-OWNER'],
            4 => [...$this->recordRow(), '日期' => '2026-11-01', '時間' => '03:10:25', '交易 ID' => 'AT-TRANSFER'],
            5 => [...$this->recordRow(), '日期' => '2026-11-01', '時間' => '03:10:26', '交易 ID' => 'UNASSIGNED'],
            6 => [...$this->recordRow(), '日期' => '2026-11-01', '時間' => '03:10:27', '交易 ID' => 'BEFORE-HISTORY'],
        ], 'PayPal owners', 'UTC', 1);

        $url = '/finance/reports/paypal-settlement?timezone=-04%3A00';
        $page = $this->get($url)->assertOk()->assertSee('运营所属部门');
        $this->assertSame('运营所属部门', $page->viewData('columns')['team']);
        $this->assertSame('地区', $page->viewData('columns')['operation_region']);
        $rows = $page->viewData('rows')->getCollection()->keyBy('reference');
        $this->assertSame('张清洪', $rows['BEFORE-TRANSFER']['owner']);
        $this->assertSame('2026-10-31 23:10:23', $rows['BEFORE-TRANSFER']['date']);
        $this->assertSame('钱锦山', $rows['NO-OWNER']['owner']);
        $this->assertSame('厦门运营部', $rows['NO-OWNER']['team']);
        $this->assertSame('钱锦山', $rows['AT-TRANSFER']['owner']);
        $this->assertSame('', $rows['UNASSIGNED']['owner']);
        $this->assertSame('陈志霖', $rows['BEFORE-HISTORY']['owner']);
        $this->assertSame('郑州运营部', $rows['BEFORE-TRANSFER']['team']);
        $this->assertSame('郑州', $rows['BEFORE-TRANSFER']['operation_region']);
        $this->assertSame('深圳', $rows['BEFORE-HISTORY']['operation_region']);
        $this->get($url.'&team='.rawurlencode('郑州运营部'))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('reference')->all() === ['BEFORE-TRANSFER']);
        $exportUrl = $url.'&download=1&export_columns[]=reference&export_columns[]=owner&export_columns[]=team&export_columns[]=operation_region';
        $export = function () use ($exportUrl) {
            $sheet = $this->readDownload($this->get($exportUrl)->assertOk()->streamedContent())->toArray();
            $this->assertSame('运营所属部门', $sheet[0][2]);
            $this->assertSame('地区', $sheet[0][3]);

            return collect($sheet)->slice(1)->keyBy(0);
        };
        $exported = $export();
        $this->assertSame(['BEFORE-TRANSFER', '张清洪', '郑州运营部', '郑州'], $exported['BEFORE-TRANSFER']);
        $this->assertSame(['NO-OWNER', '钱锦山', '厦门运营部', '厦门'], $exported['NO-OWNER']);
        $this->assertSame(['AT-TRANSFER', '钱锦山', '厦门运营部', '厦门'], $exported['AT-TRANSFER']);
        $this->assertEmpty($exported['UNASSIGNED'][1]);
        $this->assertEmpty($exported['UNASSIGNED'][2]);
        $this->assertSame(['BEFORE-HISTORY', '陈志霖', '深圳运营部', '深圳'], $exported['BEFORE-HISTORY']);

        Schema::table('logistics_orders', function (Blueprint $table): void {
            foreach (['collection_account', 'transaction_id', 'payment_gateway_order_id', 'shopify_order_number', 'store_name',
                'paid_amount_usd', 'payment_status', 'payment_method', 'payment_gateway', 'tongtu_order_number',
                'subscription_type', 'is_add_on', 'paid_at', 'shopify_payload', 'buyer_email'] as $column) {
                $table->text($column)->nullable();
            }
        });
        DB::table('logistics_orders')->update(['payment_gateway_order_id' => DB::raw('bill_number'), 'payment_gateway' => 'FintrixV2']);
        (require database_path('migrations/2026_11_10_000003_add_xuzong_credit_orders_report_menu.php'))->up();
        $creditUrl = '/finance/reports/xuzong-credit';
        $this->get($creditUrl)->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 0);
        $this->get($creditUrl.'/export?export_scope=filtered&report_columns[]=3')
            ->assertRedirect()->assertSessionHasErrors('selected_ids');

        DB::table($roles)->insert(['id' => 25, 'name' => '杭州市运营一部', 'slug' => 'test-operations-hz', 'parent_id' => 21]);
        DB::table($memberships)->where('user_id', 11)->update(['role_id' => 25]);
        $regionFilter = http_build_query(['conditions' => [['field' => 'operation_region', 'operator' => 'equals', 'value' => '杭州']]]);
        $this->get($url.'&'.$regionFilter)->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('reference')->all() === ['BEFORE-HISTORY']);
        $this->assertSame(['BEFORE-HISTORY', '陈志霖', '杭州市运营一部', '杭州'], $export()['BEFORE-HISTORY']);

        $history->clone()->where('id', 2)->update(['effective_to' => '2026-11-01 03:00:09']);
        $history->clone()->where('id', 3)->update(['effective_from' => '2026-11-01 03:00:09']);
        $this->assertSame(['BEFORE-TRANSFER', '钱锦山', '厦门运营部', '厦门'], $export()['BEFORE-TRANSFER']);

        $record = FinanceRecord::where('reference', 'BEFORE-TRANSFER')->sole();
        $this->post('/finance/reports/paypal-settlement/save', ['id' => $record->id, 'owner' => '财务补录'])
            ->assertSessionHasNoErrors();
        $this->get($url.'&reference=BEFORE-TRANSFER')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->first()['owner'] === '财务补录');

        // The old shop stopped and a different shop still uses the same domain.
        DB::table('assistant_domain_stores')->where('id', 1)->update(['shop_status' => 'stopped', 'owner_user_id' => null]);
        $history->clone()->where('id', 3)->update(['effective_to' => '2026-11-07 00:00:00']);
        $history->insert(['assistant_domain_store_id' => 1, 'event' => 'cleared', 'effective_from' => '2026-11-07 00:00:00']);
        DB::table($memberships)->where('user_id', 10)->delete();
        DB::table('logistics_orders')->where('bill_number', 'AT-TRANSFER')
            ->update(['assigned_owner_name' => '钱锦山', 'group_names' => '厦门运营部']);
        DB::table('logistics_orders')->where('bill_number', 'NO-OWNER')
            ->update(['operator_name' => '钱锦山', 'group_names' => '厦门运营部']);

        $rows = $this->get($url)->assertOk()->viewData('rows')->getCollection()->keyBy('reference');
        $exported = $export();
        foreach (['AT-TRANSFER', 'NO-OWNER'] as $reference) {
            $this->assertSame('钱锦山', $rows[$reference]['owner']);
            $this->assertSame('厦门运营部', $rows[$reference]['team']);
            $this->assertSame([$reference, '钱锦山', '厦门运营部', '厦门'], $exported[$reference]);
        }
        $this->assertSame('', $rows['UNASSIGNED']['owner']);
        $this->assertSame('', $rows['UNASSIGNED']['team']);

        // Older orders can have an operator and team even when formal ownership started empty.
        DB::table('assistant_domain_stores')->where('id', 1)->update(['shop_status' => 'in_use', 'user_name' => '七七']);
        $history->clone()->where('id', 1)->update(['event' => 'baseline']);
        DB::table('logistics_orders')->whereIn('bill_number', ['NO-OWNER', 'UNASSIGNED'])
            ->update(['operator_name' => '七七', 'group_names' => '深圳团队']);
        DB::table('logistics_orders')->where('bill_number', 'NO-OWNER')->update(['order_created_at' => '2026-10-31 00:00:00']);
        DB::table('logistics_orders')->where('bill_number', 'UNASSIGNED')->update(['order_created_at' => '2026-11-08 00:00:00']);

        $rows = $this->get($url)->assertOk()->viewData('rows')->getCollection()->keyBy('reference');
        $this->assertSame('七七', $rows['NO-OWNER']['owner']);
        $this->assertSame('深圳团队', $rows['NO-OWNER']['team']);
        $this->assertSame('深圳', $rows['NO-OWNER']['operation_region']);
        $this->assertSame('', $rows['UNASSIGNED']['owner']);
        $this->assertSame(['NO-OWNER', '七七', '深圳团队', '深圳'], $export()['NO-OWNER']);

        // A stopped shop has an empty initial interval, followed by an owner and then a transfer.
        DB::table('assistant_domain_stores')->where('id', 1)->update(['shop_status' => 'stopped', 'owner_user_id' => 10]);
        DB::table('logistics_orders')->where('bill_number', 'NO-OWNER')
            ->update(['operator_name' => null, 'group_names' => null]);
        $rows = $this->get($url)->assertOk()->viewData('rows')->getCollection()->keyBy('reference');
        $this->assertSame('张清洪', $rows['NO-OWNER']['owner']);
        $this->assertSame('郑州运营部', $rows['NO-OWNER']['team']);
        $this->assertSame('郑州', $rows['NO-OWNER']['operation_region']);
        $this->assertSame('', $rows['UNASSIGNED']['owner']);
        $this->assertSame(['NO-OWNER', '张清洪', '郑州运营部', '郑州'], $export()['NO-OWNER']);

        // An active store can also have an empty initial row followed by an assigned owner.
        DB::table('assistant_domain_stores')->where('id', 1)->update(['shop_status' => 'in_use']);
        foreach (DB::table('logistics_orders')->whereIn('bill_number', ['NO-OWNER', 'UNASSIGNED'])->get() as $source) {
            FinanceRecord::query()->create([
                'dataset' => \App\Portals\Finance\FinanceLogisticsCreditOrders::IMPORTED_DATASET,
                'account' => 'SHANGYUN-v2', 'reference' => $source->bill_number,
                'occurred_at' => $source->order_created_at, 'source_timezone' => 'UTC',
                'currency' => 'USD', 'status' => 'SUCCESS', 'domain' => $source->source_shop_domain,
                'fingerprint' => hash('sha256', $source->bill_number),
                'payload' => ['_linked_logistics_id' => $source->id, '交易金额' => '10'],
            ]);
        }
        $activeCreditRows = $this->get($creditUrl)->assertOk()
            ->assertSeeInOrder(['>运营所属部门</th>', '>地区</th>', '>通途订单</th>'], false)
            ->viewData('xuzongRows')->keyBy('平台订单号');
        $this->assertSame('张清洪', $activeCreditRows['NO-OWNER']['使用人']);
        $this->assertSame('郑州', $activeCreditRows['NO-OWNER']['区域']);
        $this->assertSame('七七', $activeCreditRows['UNASSIGNED']['使用人']);
        $this->assertSame('深圳运营部', $activeCreditRows['UNASSIGNED']['运营所属部门']);
        $this->assertSame('深圳', $activeCreditRows['UNASSIGNED']['区域']);
        $this->assertSame(['NO-OWNER', '张清洪', '郑州运营部', '郑州'], $export()['NO-OWNER']);
    }

    public function test_settlement_reports_extract_checkout_and_save_without_changing_orders(): void
    {
        DB::table('finance_domain_groups')->insert(['domain' => 'shopyayashi.com', 'owner' => 'Test owner', 'team' => '厦门']);
        $account = $this->financePayPalAccount('结算账户');
        $this->workbooks->importRecords('paypal-orders', [2 => [...$this->recordRow(), '日期' => '2026-11-02', '費用' => '-0.50'],
            3 => [...$this->recordRow(), '日期' => '2026-11-02', '交易 ID' => 'REFUND-ROW', '類型' => '付款退款']], $account->activity_account, 'UTC', 1);
        $record = FinanceRecord::where('reference', '0000123')->sole();
        $original = $record->payload;
        $this->get('/finance/reports/paypal-settlement')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
            ->assertSee('导入数据')->assertSee('添加筛选条件');
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $values = array_fill_keys(array_keys($service->moneyFields('paypal')), '0');
        $values = [...$values, 'usd_rate' => '1', 'fee' => '0.50', 'refund' => '2', 'fee_return' => '0.10', 'logistics' => '6.7628', 'purchase' => '13.5256', 'domain' => 'shopyayashi.com', 'subscription' => '订阅'];
        $this->post('/finance/reports/paypal-settlement/save', ['id' => $record->id, ...$values])->assertRedirect();
        $rows = $service->rows('paypal', []);
        $this->assertArrayNotHasKey('receivable', $rows[0]);
        $this->assertSame('厦门', $rows[0]['team']);
        $this->assertSame($original, $record->fresh()->payload);
        $this->get('/finance/reports/paypal-settlement?'.http_build_query(['conditions' => [['field' => 'settlement', 'operator' => 'gte', 'value' => '10']]]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get('/finance/reports/paypal-settlement?'.http_build_query(['conditions' => [['field' => 'settlement', 'operator' => 'gt', 'value' => '10']]]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $db = $this->workbooks->database();
        $db->table('finance_monthly_exchange_rates')->insert(['month' => '2026-11', 'currency' => 'USD', 'cny_rate' => '6.7628']);
        $this->get('/finance/reports/losses?month=2026-11')->assertOk()
            ->assertViewHas('groups', fn ($groups) => $groups[0]['total_ratio'] === '54.00000000');
        foreach (['xls', 'xlsx'] as $format) {
            $sheet = $this->readDownload($this->get('/finance/reports/paypal-settlement?download=1&format='.$format)->assertOk()->streamedContent());
            $this->assertSame(2, $sheet->getHighestDataRow());
            $this->assertSame('Shopify订单号', $sheet->getCell('A1')->getValue());
            $headers = $sheet->toArray()[0];
            $release = array_search('返还冻结', $headers, true);
            $this->assertSame(['返还冻结', '应结算（USD）', '物流-元'], array_slice($headers, $release, 3));
            $this->assertSame('日期', $sheet->getCell('J1')->getValue());
        }
        $this->user->manage = false;
        $this->post('/finance/reports/paypal-settlement/save', ['id' => $record->id, ...$values])->assertForbidden();
        $this->user->manage = true;
        $db->table('finance_period_locks')->where('month', '2026-11')->update(['report_snapshot' => '[]']);
        $this->post('/finance/reports/paypal-settlement/save', ['id' => $record->id, ...$values])->assertSessionHasErrors('month');
    }

    public function test_paypal_new_import_creates_records_and_skips_only_duplicate_rows(): void
    {
        $account = $this->financePayPalAccount('新增测试账户');
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], $account->activity_account, 'UTC', 1);
        $original = FinanceRecord::sole();
        $originalPayload = $original->payload;
        $headers = ['记录ID', '支付账户', '交易 ID', '日期', '时间', '时区', '姓名', '类型', '状态', '货币', '总额', '费用', '净额',
            '寄件人电邮地址', '偏好设定 Txn ID', '帐单号码', '余额影响', '订阅类型', '是否加购', '域名', '使用人', '团队', '通途订单', '备注', '物流-元'];
        $base = ['自动生成', $account->account_name, 'NEW-PAYPAL-1', '2026-11-06', '04:00:00', 'UTC', '新增买家', 'PayPal Checkout付款', 'S', 'USD', '21', '-0.81', '20.19',
            'new@example.test', 'NEW-RELATED', 'NEW-INVOICE', 'Credit', '订阅', '否', 'new.example.test', '新增使用人', '新增团队', 'NEW-ERP', '新记录备注', '11'];
        $existing = $base; $existing[2] = $original->reference; $existing[10] = '999';
        $second = $base; $second[2] = 'NEW-PAYPAL-2';
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('贝宝导入模版')->fromArray([$headers, $existing, $base, $base, $second], null, 'A1');
        $this->post('/finance/reports/paypal-settlement/import', ['import_mode' => 'create', 'file' => $this->upload($book)])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($text) => str_contains($text, '已新增 2 条') && str_contains($text, '跳过重复 2 条'));
        $this->assertDatabaseCount('finance_records', 3);
        $this->assertSame($originalPayload, $original->fresh()->payload);
        $record = FinanceRecord::where('reference', 'NEW-PAYPAL-1')->sole();
        $this->assertNotSame($original->id, $record->id);
        $row = app(\App\Portals\Finance\FinanceSettlementService::class)->rows('paypal', ['reference' => $record->reference])->sole();
        foreach (['owner' => '新增使用人', 'team' => '新增团队', 'domain' => 'new.example.test', 'subscription' => '订阅', 'upsell' => '否', 'erp_order' => 'NEW-ERP', 'note' => '新记录备注', 'paypal_12' => 'NEW-RELATED'] as $key => $value) {
            $this->assertSame($value, $row[$key], $key);
        }
        $this->assertSame('21', $row['gross']);
        // Reimporting the entire file is a successful no-op rather than an error.
        $this->post('/finance/reports/paypal-settlement/import', ['import_mode' => 'create', 'file' => $this->upload($book)])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($text) => str_contains($text, '已新增 0 条') && str_contains($text, '跳过重复 4 条'));
        $this->assertDatabaseCount('finance_records', 3);
        $locked = $base; $locked[2] = 'LOCKED-NEW-PAYPAL';
        $lockedBook = new Spreadsheet;
        $lockedBook->getActiveSheet()->fromArray([$headers, $locked], null, 'A1');
        DB::table('finance_period_locks')->updateOrInsert(['month' => '2026-11'], ['report_snapshot' => '[]']);
        $this->post('/finance/reports/paypal-settlement/import', ['import_mode' => 'create', 'file' => $this->upload($lockedBook)])
            ->assertSessionHasErrors('file');
        $this->assertDatabaseCount('finance_records', 3);
        $this->assertDatabaseMissing('finance_records', ['reference' => 'LOCKED-NEW-PAYPAL']);
    }

    public function test_paypal_editor_updates_all_visible_business_fields_and_rejects_duplicate_transaction_ids(): void
    {
        $account = $this->financePayPalAccount('编辑原账户');
        $target = $this->financePayPalAccount('编辑新账户');
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow(), 3 => [...$this->recordRow(), '交易 ID' => 'EXISTING-ID']], $account->activity_account, 'UTC', 1);
        $record = FinanceRecord::where('reference', '0000123')->sole();
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $current = $service->rows('paypal', ['reference' => $record->reference])->sole();
        $values = array_intersect_key($current, array_diff_key($service->columns('paypal'), ['id' => true]));
        $values = [...$values, 'id' => $record->id, 'account' => $target->account_name, 'reference' => 'EDITED-ID',
            'paypal_0' => '2026-11-07', 'paypal_1' => '02:00:00', 'paypal_2' => 'UTC', 'paypal_3' => '编辑姓名', 'paypal_4' => 'T0006', 'paypal_5' => 'S',
            'paypal_6' => 'EUR', 'paypal_7' => '30', 'paypal_8' => '-1', 'paypal_9' => '29', 'paypal_10' => 'edited@example.test',
            'paypal_12' => 'EDITED-RELATED', 'paypal_13' => 'EDITED-INVOICE', 'paypal_14' => 'Credit',
            'month' => '2026-11', 'date' => '2026-11-07 10:00:00', 'timezone' => 'Asia/Shanghai',
            'subscription' => '订阅', 'upsell' => '否', 'converted_usd' => '34.8', 'usd_rate' => '1.2', 'settlement' => '36', 'fee' => '1.2',
            'domain' => 'edited.example.test', 'owner' => '编辑使用人', 'team' => '编辑团队', 'erp_order' => 'EDITED-ERP', 'note' => '编辑备注'];
        $page = $this->get('/finance/reports/paypal-settlement')->assertOk();
        foreach (array_diff_key($service->columns('paypal'), ['id' => true, 'team' => true, 'operation_region' => true]) as $field => $label) {
            $page->assertSee('id="edit-'.$field.'"', false);
        }
        $page->assertDontSee('id="edit-team"', false);
        $page->assertDontSee('原始交易与计算结果（只读）');
        DB::table('finance_payment_transactions')->insert(['payment_account_id' => $account->id, 'finance_record_id' => $record->id, 'transaction_id' => $record->reference, 'event_code' => 'T0006', 'raw_payload' => '{}']);
        $listFilters = ['from' => '2026-11-01', 'timezone' => 'UTC', 'per_page' => 20, 'page' => 2];
        $this->post(route('finance.settlement.save', ['report' => 'paypal-settlement', ...$listFilters]), $values)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('finance.report', ['report' => 'paypal-settlement', ...$listFilters]));
        $updated = $record->fresh();
        $this->assertSame('EDITED-ID', $updated->reference);
        $this->assertSame($target->activity_account, $updated->account);
        $this->assertSame('2026-11-07 02:00:00', $updated->occurred_at->format('Y-m-d H:i:s'));
        $row = $service->rows('paypal', ['reference' => 'EDITED-ID'])->sole();
        $this->assertSame('10:00:00', $row['paypal_1']);
        $this->assertSame('Asia/Shanghai', $row['paypal_2']);
        foreach (array_diff_key($values, ['id' => true, 'paypal_1' => true, 'paypal_2' => true, 'paypal_4' => true]) as $field => $value) {
            $this->assertEquals($value ?? '', $row[$field] ?? '', $field);
        }
        $this->assertDatabaseHas('finance_payment_transactions', ['finance_record_id' => $record->id, 'payment_account_id' => $target->id, 'transaction_id' => 'EDITED-ID']);
        $payload = $updated->payload;
        $this->post('/finance/reports/paypal-settlement/save', ['id' => $record->id, 'reference' => 'EXISTING-ID', 'paypal_7' => '999'])->assertSessionHasErrors('reference');
        $this->assertSame($payload, $record->fresh()->payload);
        $this->assertSame('EDITED-ID', $record->fresh()->reference);
        $this->post('/finance/reports/paypal-settlement/save', ['id' => $record->id, 'date' => '2026-02-30 01:00:00'])->assertSessionHasErrors('date');
        // Non-unique business text may repeat and explicit blanks must stay blank.
        $this->post('/finance/reports/paypal-settlement/save', ['id' => $record->id, 'owner' => '', 'domain' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', $service->rows('paypal', ['reference' => 'EDITED-ID'])->sole()['owner']);
        DB::table('finance_period_locks')->updateOrInsert(['month' => '2026-12'], ['report_snapshot' => '[]']);
        $this->post('/finance/reports/paypal-settlement/save', ['id' => $record->id, 'date' => '2026-12-01 10:00:00'])->assertSessionHasErrors('month');
        $this->assertSame('2026-11-07 02:00:00', $record->fresh()->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_credit_editor_updates_transaction_fields_and_calculated_amounts(): void
    {
        $account = $this->financePayPalAccount('信用卡编辑', ['account_type' => 'credit_card']);
        $payload = [...array_fill_keys(FinanceCatalog::dataset('xuzong2-details')['headers'], ''), '平台订单号' => 'CARD-EDIT', '商户订单号' => 'MERCHANT-EDIT',
            '创建时间' => '2026-11-06 02:00:00', '交易币种' => 'USD', '交易金额' => '10', '支付状态' => 'SUCCESS'];
        $this->workbooks->importRecords('xuzong2-details', [2 => $payload, 3 => [...$payload, '平台订单号' => 'CARD-DUPLICATE']], $account->activity_account, 'UTC', 1);
        $record = FinanceRecord::where('reference', 'CARD-EDIT')->sole();
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $page = $this->get('/finance/reports/credit-settlement')->assertOk();
        foreach (array_diff_key($service->columns('credit_card'), ['id' => true]) as $field => $label) {
            $page->assertSee('id="edit-'.$field.'"', false);
        }
        $this->post('/finance/reports/credit-settlement/save', ['id' => $record->id, 'reference' => 'CARD-DUPLICATE', 'gross' => '999'])->assertSessionHasErrors('reference');
        $this->assertSame('10', $record->fresh()->payload['交易金额']);
        $values = ['id' => $record->id, 'reference' => 'CARD-NEW-ID', 'date' => '2026-11-08 10:00:00', 'timezone' => 'Asia/Shanghai',
            'currency' => 'EUR', 'gross' => '25', 'status' => '成功', 'settlement' => '28', 'source_settlement_usd' => '27', 'currency_warning' => '已人工核对',
            'owner' => '财务使用人', 'team' => '财务团队', 'usd_rate' => '1.1'];
        $this->post('/finance/reports/credit-settlement/save', $values)->assertSessionHasNoErrors();
        $row = $service->rows('credit_card', ['reference' => 'CARD-NEW-ID'])->sole();
        foreach (array_diff_key($values, ['id' => true, 'status' => true]) as $field => $value) {
            $this->assertEquals($value, $row[$field], $field);
        }
        $this->assertSame('SUCCESS', $record->fresh()->status);
        $this->assertSame('已完成', $row['status']);
        $export = $this->readDownload($this->get('/finance/reports/credit-settlement?reference=CARD-NEW-ID&download=1')->assertOk()->streamedContent())->toArray();
        $this->assertEquals(28, $export[1][array_search('结算金额（美元）', $export[0], true)]);
    }

    public function test_credit_settlement_channel_switch_uses_the_source_columns_for_display_and_export(): void
    {
        $account = $this->financePayPalAccount('叶总支付', ['account_type' => 'credit_card']);
        $payload = ['平台订单号' => 'YE-ORDER', '创建时间' => '2026-11-06 02:00:00',
            '交易币种' => 'USD', '交易金额' => '10', '支付状态' => 'SUCCESS',
            '订单退款金额' => '2', '退款金额' => '3', '结算-美元' => '9', '结算金额' => '8', '结算币种' => 'USD'];
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('bill_number');
            $table->decimal('paid_amount_usd');
        });
        DB::table('logistics_orders')->insert(['bill_number' => 'YE-ORDER', 'paid_amount_usd' => 7]);
        $this->workbooks->importRecords('xuzong2-details', [
            2 => $payload,
            3 => [...$payload, '平台订单号' => 'WP-ORDER', '网关接入号' => '123', '预仲裁金额' => '4'],
        ], $account->activity_account, 'UTC', 1);

        $report = '/finance/reports/credit-settlement';
        $ye = $this->get($report.'?card_channel=ye')->assertOk();
        $ye->assertSeeInOrder(['>网站名称 / 账单描述符</th>', '>交易 ID</th>', '>退款状态</th>', '>订单退款金额</th>',
            '>单笔费用</th>', '>账单退款金额</th>', '>应结算</th>', '>备注</th>'], false)
            ->assertSee('YE-ORDER')->assertDontSee('WP-ORDER')->assertDontSee('>预仲裁金额</th>', false);
        $worldpay = $this->get($report.'?card_channel=worldpay')->assertOk();
        $worldpay->assertSeeInOrder(['>网关接入号</th>', '>交易 ID</th>', '>结单汇率</th>', '>预仲裁金额</th>',
            '>RDR申诉成功</th>', '>商品名称-简称</th>'], false)->assertSee('WP-ORDER')->assertDontSee('YE-ORDER');

        (require database_path('migrations/2026_11_11_000001_split_finance_credit_settlement_menu.php'))->up();
        $this->get('/finance/reports/ye-settlement')->assertOk()
            ->assertSee('叶总支付明细')->assertSee('worldpy明细')->assertDontSee('信用卡结算明细')
            ->assertSee('YE-ORDER')->assertDontSee('WP-ORDER');
        $this->get('/finance/reports/worldpay-settlement?card_channel=ye')->assertOk()
            ->assertSee('WP-ORDER')->assertDontSee('YE-ORDER');

        $yeExport = $this->readDownload($this->get('/finance/reports/ye-settlement?download=1')->assertOk()->streamedContent())->toArray();
        $this->assertCount(53, $yeExport[0]);
        $this->assertSame('订单退款金额', $yeExport[0][18]);
        $this->assertSame('账单退款金额', $yeExport[0][29]);
        $this->assertEquals(2, $yeExport[1][18]);
        $this->assertEquals(3, $yeExport[1][29]);
        $this->assertEquals(2, array_combine($yeExport[0], $yeExport[1])['与店铺差额（美元）']);
        $this->assertCount(2, $yeExport);
        $worldpayExport = $this->readDownload($this->get('/finance/reports/worldpay-settlement?download=1')->assertOk()->streamedContent())->toArray();
        $this->assertCount(45, $worldpayExport[0]);
        $this->assertSame('网关接入号', $worldpayExport[0][0]);
        $this->assertSame('预仲裁金额', $worldpayExport[0][31]);
        $this->assertEquals(4, $worldpayExport[1][31]);
        $this->assertCount(2, $worldpayExport);

        $template = $this->readDownload($this->get($report.'?card_channel=ye&download=1&export_template=1')
            ->assertOk()->streamedContent());
        $this->assertSame([array_slice($yeExport[0], 0, count($template->toArray()[0]))], $template->toArray());
        $this->postJson($report.'/export-template/preview?card_channel=ye', [
            'file' => $this->upload($template->getParent()), 'header_row' => 1,
        ])->assertOk()->assertJsonPath('fields.18', 'order_refund')->assertJsonPath('fields.29', 'refund')
            ->assertJsonPath('duplicates', []);
    }

    public function test_conflux_credit_usd_rates_follow_beijing_trade_date_in_list_and_export(): void
    {
        Http::fake(['data-api.ecb.europa.eu/*' => Http::response(
            "CURRENCY,TIME_PERIOD,OBS_VALUE\nUSD,2026-11-05,1.20\nGBP,2026-11-05,0.96\n"
        )]);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-10 12:00:00', 'Asia/Shanghai'));
        $account = $this->financePayPalAccount('郑州单包', ['account_type' => 'credit_card', 'card_platform' => 'ye']);
        $order = ['创建时间' => '2026-11-04 17:00:00', '交易币种' => 'GBP', '交易金额' => '10.05',
            '支付状态' => 'SUCCESS', '_card_channel' => 'ye'];
        $this->workbooks->importRecords('xuzong2-details', [
            2 => [...$order, '平台订单号' => 'FX-DAY'],
            3 => [...$order, '平台订单号' => 'FX-RANGE', '创建时间' => '2026-11-05 17:00:00'],
            4 => [...$order, '平台订单号' => 'FX-MONTH', '创建时间' => '2026-11-06 17:00:00'],
            5 => [...$order, '平台订单号' => 'FX-USD', '交易币种' => 'USD'],
            6 => [...$order, '平台订单号' => 'FX-EUR', '交易币种' => 'EUR'],
        ], $account->activity_account, 'UTC', 1);

        $report = '/finance/reports/credit-settlement?card_channel=ye&timezone=UTC';
        $initial = $this->get($report)->assertOk()->assertSee('汇率未填写')
            ->viewData('rows')->getCollection()->keyBy('reference');
        $this->assertSame('GBP', $initial['FX-DAY']['card_layout']['currency']);
        $this->assertSame('10.05', $initial['FX-DAY']['card_layout']['gross']);
        $this->assertSame('EUR', $initial['FX-EUR']['card_layout']['currency']);
        $this->assertSame('12.56', $initial['FX-DAY']['card_layout']['source_settlement_usd']);
        $this->assertSame('汇率未填写', $initial['FX-RANGE']['card_layout']['source_settlement_usd']);
        $settings = '/finance/settings/accounting';
        foreach ([
            ['usd_scope' => 'month', 'usd_month' => '2026-11', 'usd_rate' => '1.2'],
            ['usd_scope' => 'range', 'usd_start' => '2026-11-04', 'usd_end' => '2026-11-06', 'usd_rate' => '1.3'],
            ['usd_scope' => 'day', 'usd_start' => '2026-11-05', 'usd_rate' => '1.5'],
        ] as $rate) {
            $this->post($settings.'/credit-usd-rate', [...$rate, 'usd_currency' => 'GBP'])
                ->assertRedirect($settings)->assertSessionHasNoErrors();
        }
        $this->get($settings)->assertOk()->assertSee('信用卡美元折算汇率')->assertSee('2026-11-05');
        $rows = $this->get($report)->assertOk()->viewData('rows')->getCollection()->keyBy('reference');
        $this->assertSame('2026-11-05', $rows['FX-DAY']['card_layout']['calendar_date']);
        $expected = ['FX-DAY' => '15.08', 'FX-RANGE' => '13.07', 'FX-MONTH' => '12.06', 'FX-USD' => '10.05', 'FX-EUR' => '12.06'];
        foreach ($expected as $reference => $amount) {
            $this->assertEquals($amount, $rows[$reference]['card_layout']['source_settlement_usd']);
        }
        $export = $this->readDownload($this->get($report.'&download=1')->assertOk()->streamedContent())->toArray(formatData: false);
        $referenceColumn = array_search('交易 ID', $export[0], true);
        $usdColumn = array_search('结算-美元', $export[0], true);
        $exported = array_column(array_slice($export, 1), $usdColumn, $referenceColumn);
        foreach ($expected as $reference => $amount) {
            $this->assertSame(is_numeric($amount) ? (float) $amount : $amount, $exported[$reference]);
        }
        Http::assertSentCount(1);
    }

    public function test_credit_transaction_status_filters_the_list_and_export(): void
    {
        $account = $this->financePayPalAccount('郑州单包', ['account_type' => 'credit_card', 'card_platform' => 'ye']);
        $statuses = ['ORDER-PAID' => '成功', 'ORDER-FAILED' => 'FAILED', 'ORDER-DECLINED' => '失败', 'ORDER-PENDING' => '处理中'];
        $rows = [];
        foreach ($statuses as $reference => $status) {
            $rows[] = ['_card_channel' => 'ye', '平台订单号' => $reference, '支付状态' => $status,
                '创建时间' => '2026-11-01 02:00:00', '交易币种' => 'USD', '交易金额' => '10'];
        }
        $this->workbooks->importRecords('xuzong2-details', $rows, $account->activity_account, 'UTC', 1);

        $url = '/finance/reports/credit-settlement?card_channel=ye&card_transaction_status=';
        $this->get($url.'failed')->assertOk()->assertSee('状态')->assertSee('ORDER-FAILED')->assertSee('ORDER-DECLINED')
            ->assertDontSee('ORDER-PAID')->assertDontSee('ORDER-PENDING');
        $this->get($url.'success')->assertOk()->assertSee('ORDER-PAID')->assertDontSee('ORDER-FAILED');
        $this->get($url.'pending')->assertOk()->assertSee('ORDER-PENDING')->assertDontSee('ORDER-DECLINED');

        $export = $this->get($url.'failed&download=1')->assertOk();
        $data = $this->readDownload($export->streamedContent())->toArray();
        $referenceColumn = array_search('交易 ID', array_shift($data), true);
        $this->assertEqualsCanonicalizing(['ORDER-FAILED', 'ORDER-DECLINED'], array_column($data, $referenceColumn));
    }

    public function test_credit_settlement_account_filter_uses_workbench_platform_configuration(): void
    {
        (require database_path('migrations/2026_11_11_000001_split_finance_credit_settlement_menu.php'))->up();
        $this->get('/finance?balance_tab=credit-card&add_credit_merchant=ye')->assertOk()
            ->assertSee('data-new-card-platform="ye"', false);
        $this->post('/finance/payment-accounts', [
            'account_name' => 'Account-A', 'account_type' => 'credit_card', 'card_platform' => 'ye',
            'main_currency' => 'USD', 'status' => 'enabled', 'from_credit_settlement' => '1', 'card_merchant_key' => 'Account-A',
        ])->assertSessionHasNoErrors()->assertRedirect(route('finance.report', [
            'report' => 'ye-settlement', 'card_channel' => 'ye', 'account' => 'Account-A',
        ]));
        $first = FinancePaymentAccount::query()->where('account_name', 'Account-A')->sole();
        $second = $this->financePayPalAccount('Account-B', ['account_type' => 'credit_card', 'card_platform' => 'ye']);
        $other = $this->financePayPalAccount('Account-C', ['account_type' => 'credit_card', 'card_platform' => 'worldpay']);
        $report = '/finance/reports/credit-settlement';

        $this->get($report.'?card_channel=ye')->assertOk()
            ->assertSee('ConfluxAPI')
            ->assertViewHas('filterAccounts', fn ($accounts) => $accounts->pluck('id')->all() === [$first->id, $second->id]);
        $this->get($report.'?card_channel=worldpay')->assertOk()
            ->assertViewHas('filterAccounts', fn ($accounts) => $accounts->pluck('id')->all() === [$other->id]);
        $this->get('/finance?balance_tab=credit-card')->assertOk()->assertSee('ConfluxAPI')
            ->assertSee('功能未开发')
            ->assertSee('data-add-payment-account="credit_card"', false)
            ->assertDontSee('/finance/reports/ye-settlement?account='.$second->activity_account, false)
            ->assertDontSee('id="payment-balance-add"', false);

        foreach ([$first, $second, $other] as $account) {
            $this->workbooks->importRecords('xuzong2-details', [2 => [
                '平台订单号' => 'ORDER-'.$account->id, '创建时间' => '2026-11-06 02:00:00',
                '交易币种' => 'USD', '交易金额' => '10', '支付状态' => 'SUCCESS',
            ]], $account->activity_account, 'UTC', 1);
        }
        $this->get($report.'?card_channel=ye&account='.$second->activity_account)->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->getCollection()->pluck('reference')->all() === ['ORDER-'.$second->id]);
    }

    public function test_conflux_filtered_queries_paginate_across_record_batches(): void
    {
        (require database_path('migrations/2026_11_11_000001_split_finance_credit_settlement_menu.php'))->up();
        $orders = [];
        for ($index = 1; $index <= 512; $index++) {
            $orders[] = [
                '_card_channel' => 'ye', '平台订单号' => 'BATCH-'.$index,
                '创建时间' => '2026-11-06 02:00:00', '交易币种' => 'USD',
                '交易金额' => '10', '支付状态' => 'SUCCESS',
            ];
        }
        $this->workbooks->importRecords('xuzong2-details', $orders, 'Batch shop', 'UTC', 1);

        // Every record is waiting for a statement, including the records in the second batch.
        $this->get('/finance/reports/ye-settlement?card_match=waiting&page=51')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 512
                && $rows->getCollection()->pluck('reference')->all() === array_map(
                    fn ($index) => 'BATCH-'.$index, range(12, 3)
                ));
    }

    public function test_credit_account_group_can_own_multiple_merchants_and_scope_settlement(): void
    {
        foreach (['郑州单包' => '叶总', '西安单包' => '叶总', '另一单包' => '其他主体'] as $name => $group) {
            $this->post('/finance/payment-accounts', [
                'account_name' => $name, 'account_type' => 'credit_card', 'card_platform' => 'ye',
                'card_group_name' => $group, 'main_currency' => 'USD', 'status' => 'enabled',
            ])->assertSessionHasNoErrors();
        }
        $zhengzhou = FinancePaymentAccount::query()->where('account_name', '郑州单包')->sole();
        $xian = FinancePaymentAccount::query()->where('account_name', '西安单包')->sole();
        $other = FinancePaymentAccount::query()->where('account_name', '另一单包')->sole();
        $this->assertNotNull($zhengzhou->card_group_id);
        $this->assertEquals($zhengzhou->card_group_id, $xian->card_group_id);
        $this->assertNotEquals($zhengzhou->card_group_id, $other->card_group_id);
        $this->get('/finance?balance_tab=credit-card')->assertOk()
            ->assertSee('data-credit-group="叶总"', false)->assertSee('添加子账户');

        foreach ([$zhengzhou, $xian, $other] as $account) {
            $this->workbooks->importRecords('xuzong2-details', [2 => [
                '_card_channel' => 'ye', '平台订单号' => 'GROUP-ORDER-'.$account->id, '创建时间' => '2026-11-06 02:00:00',
                '交易币种' => 'USD', '交易金额' => '10', '支付状态' => '成功',
            ]], $account->activity_account, 'Asia/Shanghai', 1);
        }
        $report = '/finance/reports/credit-settlement?'.http_build_query([
            'card_group' => $zhengzhou->card_group_id, 'card_channel' => 'ye',
        ]);
        $this->get($report)->assertOk()
            ->assertViewHas('filterAccounts', fn ($accounts) => $accounts->pluck('id')->all() === [$zhengzhou->id, $xian->id])
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2)
            ->assertDontSee('GROUP-ORDER-'.$other->id);
    }

    public function test_conflux_report_checks_merchant_and_matches_ledger_to_later_orders(): void
    {
        $account = $this->financePayPalAccount('郑州单包', [
            'account_type' => 'credit_card', 'card_platform' => 'ye', 'card_merchant_key' => 'ZZdanbao',
        ]);
        $other = $this->financePayPalAccount('西安单包', [
            'account_type' => 'credit_card', 'card_platform' => 'ye', 'card_merchant_key' => 'XAdanbao',
        ]);
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('结算统计-ZZdanbao')->fromArray([
            ['结算日期', '商户号', '结算金额'], ['2026-11-09', 'merchant-1', 25], ['合计', null, 25],
        ]);
        foreach ([
            '成功交易' => [['流水订单号', '结算货币', '结算金额'], ['ORDER-1', 'USD', 30]],
            'Shopline生态代收' => [['流水订单号', '结算货币', '结算金额'], ['ORDER-1', 'USD', 30]],
            'YY生态代收' => [['流水订单号', '结算货币', '结算金额'], ['ORDER-1', 'USD', 100]],
            'Shoplazza生态代收' => [['流水订单号', '结算货币', '结算金额'], ['ORDER-1', 'USD', 50]],
            '退款' => [['支付订单号', '退款订单号', '结算币种', '结算金额'], ['ORDER-1', 'REFUND-1', 'USD', 5]],
            'RDR退款' => [['流水订单号', '拒付预警时间', '结算币种', '结算金额'], ['ORDER-1', '2026-11-08', 'USD', 2]],
        ] as $name => $sheetRows) {
            $book->createSheet()->setTitle($name.'-ZZdanbao')->fromArray($sheetRows);
        }
        $url = '/finance/reports/credit-settlement';
        $data = ['card_channel' => 'ye', 'import_kind' => 'conflux_report'];
        $this->post($url.'/import', [...$data, 'account' => $other->activity_account, 'file' => $this->upload($book)])
            ->assertSessionHasErrors('account');
        $this->post($url.'/import', [...$data, 'account' => $account->activity_account, 'file' => $this->upload($book)])
            ->assertSessionHasNoErrors()->assertSessionHas('success', '新增 7 条账单流水，1 笔原交易待匹配。');
        $this->post($url.'/import', [...$data, 'account' => $account->activity_account, 'file' => $this->upload($book)])
            ->assertSessionHasNoErrors()->assertSessionHas('success', '新增 0 条账单流水，1 笔原交易待匹配。');
        $this->assertDatabaseCount('finance_credit_settlement_entries', 7);

        $orders = new Spreadsheet;
        $orderSheet = $orders->getActiveSheet()->setTitle('订单');
        $orderSheet->fromArray(array_slice(array_values(\App\Portals\Finance\FinanceCreditSettlementLayout::columns('ye')), 0, 21));
        foreach ([2 => 'ORDER-1', 3 => 'ORDER-WAITING'] as $index => $reference) {
            $orderSheet->setCellValue('D'.$index, $reference);
            $orderSheet->setCellValue('B'.$index, '2026-11-06 02:00:00');
            $orderSheet->setCellValue('M'.$index, 'USD');
            $orderSheet->setCellValue('N'.$index, 30);
            $orderSheet->setCellValue('O'.$index, '成功');
        }
        foreach ([$account, $other] as $merchant) {
            $this->post($url.'/import', [
                'card_channel' => 'ye', 'account' => $merchant->activity_account, 'timezone' => 'Asia/Shanghai',
                'file' => $this->upload($orders),
            ])->assertSessionHasNoErrors();
        }
        $scope = ['card_channel' => 'ye', 'account' => $account->activity_account];
        $this->get($url.'?'.http_build_query([...$scope, 'card_match' => 'waiting']))->assertOk()
            ->assertSee('待匹配账单')->assertViewHas('rows', fn ($rows) => $rows->getCollection()->sole()['reference'] === 'ORDER-WAITING');
        $this->get($url.'?'.http_build_query([...$scope, 'card_match' => 'matched']))->assertOk()
            ->assertSee('已匹配账单')->assertSee('2026-11-09')
            ->assertViewHas('rows', fn ($rows) => $rows->getCollection()->sole()['reference'] === 'ORDER-1');
        $export = $this->get($url.'?'.http_build_query([...$scope, 'card_match' => 'matched', 'download' => 1]))->assertOk();
        $dataRows = $this->readDownload($export->streamedContent())->toArray();
        $this->assertCount(2, $dataRows);
        $this->assertCount(53, $dataRows[0]);
        $this->assertSame(\App\Portals\Finance\FinanceSettlementService::SHOP_DIFFERENCE_LABEL, $dataRows[0][49]);
        $this->assertSame('ORDER-1', $dataRows[1][3]);
        $this->assertEquals(30, $dataRows[1][25]);
        $this->assertEquals(5, $dataRows[1][29]);
        $this->assertEquals(2, $dataRows[1][35]);
        $page = $this->get($url.'?card_channel=ye&card_exceptions=1')->assertOk()
            ->assertSee('退款与 RDR 同时存在')->assertSee('2026-11-09');
        $exceptionExport = $this->get($url.'?card_channel=ye&card_exceptions=1&download=1')->assertOk();
        $exceptionSheet = $this->readDownload($exceptionExport->streamedContent());
        $this->assertSame('异常汇总', $exceptionSheet->getTitle());
        $exceptionRows = $exceptionSheet->toArray();
        $exceptionDateColumn = array_search('新增异常账单日期', $exceptionRows[0], true);
        $this->assertNotFalse($exceptionDateColumn);
        $this->assertSame('2026-11-09', $exceptionRows[1][$exceptionDateColumn]);
        $row = $page->viewData('rows')->getCollection()->sole()['card_layout'];
        $this->assertEquals(30, $row['settlement']);
        $this->assertEquals(5, $row['refund']);
        $this->assertEquals(2, $row['rdr_refund']);
        $this->assertSame('0.400', $row['nbc_fee']);
        $this->assertSame('0.240', $row['store_fee']);
        $this->get($url.'?'.http_build_query(['card_channel' => 'ye', 'account' => $other->activity_account, 'card_exceptions' => 1]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    public function test_conflux_batch_creates_accounts_and_rejects_invalid_merchant_identifiers(): void
    {
        $account = $this->financePayPalAccount('郑州单包', [
            'account_type' => 'credit_card', 'card_platform' => 'ye', 'card_merchant_key' => 'ZZdanbao',
        ]);
        $orders = new Spreadsheet;
        $orderSheet = $orders->getActiveSheet()->setTitle('订单');
        $order = ['Kraveloa INC-ZZdanbao2-wallet', '2026-11-06 02:00:00', null, 'BATCH-ORDER', null, null, null, null, null, null, null, null, 'USD', 30.05, '成功'];
        $orderSheet->fromArray([
            array_slice(array_values(\App\Portals\Finance\FinanceCreditSettlementLayout::columns('ye')), 0, 21),
            $order,
        ]);
        $orderSheet->fromArray($order, null, 'A10002');
        $statement = new Spreadsheet;
        $statement->getActiveSheet()->setTitle('结算统计-XA danbao')->fromArray([
            ['结算日期', '交易日期', '商户号', '账单描述符', '结算金额', '成功网关费'],
            ['2026-11-09', '2026-11-06', 'merchant-1', 'store-wallet', 25.19, -0.35],
        ]);
        $sales = $statement->createSheet()->setTitle('成功交易-XA danbao')->fromArray([
            ['流水订单号', '结算货币', '结算金额', '商户号', '账单描述符', '交易时间'],
            ['BATCH-ORDER', 'USD', 30.05, 'merchant-1', 'store-wallet', '2026-11-06 02:00:00'],
        ]);
        $url = '/finance/reports/credit-settlement';
        $data = ['card_channel' => 'ye', 'timezone' => 'Asia/Shanghai'];
        $octetStreamUpload = function (Spreadsheet $book, string $filename = 'finance.xlsx'): UploadedFile {
            $file = $this->upload($book);

            return new class($file->getPathname(), $filename, 'application/octet-stream', null, true) extends UploadedFile {
                public function getMimeType(): ?string
                {
                    return 'application/octet-stream';
                }
            };
        };
        $submit = fn () => $this->post($url.'/import', [...$data, 'settlement_type' => '正常结算', 'files' => [$octetStreamUpload($statement), $octetStreamUpload($orders)]]);

        $this->post($url.'/import', [...$data, 'files' => [UploadedFile::fake()->createWithContent('invalid.xlsx', 'not a workbook')]])
            ->assertSessionHasErrors('files.0');

        $submit()->assertSessionHasErrors('files.0');
        $this->assertDatabaseMissing('finance_records', ['reference' => 'BATCH-ORDER']);
        $this->assertDatabaseCount('finance_credit_settlement_entries', 0);

        $statement->getActiveSheet()->setTitle('结算统计-ZZdanbao');
        $sales->setTitle('成功交易-ZZdanbao');
        $submit()->assertSessionHasNoErrors()
            ->assertSessionHas('success', '新增订单 1 笔，跳过重复订单 1 笔。识别结算报表 1 份，新增账单流水 2 条，跳过重复报表 0 份。');
        $matchedUrl = $url.'?'.http_build_query([...$data, 'card_match' => 'matched']);
        $matched = $this->get($matchedUrl)->assertOk()->viewData('rows')->getCollection()->sole()['card_layout'];
        $this->assertSame('正常结算', $matched['ledger_settlement_type']);
        $this->assertSame('30.05000000', $matched['settlement']);
        $this->assertSame('3.01000000', $matched['hold']);
        $this->assertSame('1.50000000', $matched['fee']);
        $this->assertSame('0.35000000', $matched['per_order_fee']);
        $this->get($matchedUrl.'&card_wallet=1&card_exceptions=1')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->getCollection()->sole()['reference'] === 'BATCH-ORDER');
        $this->assertSame('25.19000000', $matched['payable']);
        $this->assertNull($matched['nbc_fee']);
        $this->assertNull($matched['store_fee']);
        $export = $this->readDownload($this->get($matchedUrl.'&download=1')->assertOk()->streamedContent())->toArray();
        $this->assertEquals(25.19, $export[1][array_search('应结算', $export[0], true)]);
        $submit()->assertSessionHasNoErrors()
            ->assertSessionHas('success', '新增订单 0 笔，跳过重复订单 2 笔。识别结算报表 1 份，新增账单流水 0 条，跳过重复报表 1 份。');
        $this->post($url.'/import', [...$data, 'settlement_type' => '正常结算', 'files' => [$octetStreamUpload($orders)]],
            ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('message', '新增订单 0 笔，跳过重复订单 2 笔。')
            ->assertJsonStructure(['redirect']);

        $otherAccount = $this->financePayPalAccount('西安单包', [
            'account_type' => 'credit_card', 'card_platform' => 'ye', 'card_merchant_key' => 'XAdanbao',
        ]);
        $otherOrders = new Spreadsheet;
        $otherOrders->getActiveSheet()->setTitle('订单')->fromArray([
            array_slice(array_values(\App\Portals\Finance\FinanceCreditSettlementLayout::columns('ye')), 0, 21),
            array_replace($order, [0 => 'Store-XAdanbao', 3 => 'OTHER-BATCH-ORDER', 12 => 'GBP']),
        ]);
        $statement->getActiveSheet()->setCellValue('D2', 'another-store');
        $sales->setCellValue('A2', 'ANOTHER-STATEMENT-ORDER')->setCellValue('E2', 'another-store');
        $this->post($url.'/import', [...$data, 'settlement_type' => '停结算', 'files' => [$octetStreamUpload($statement), $octetStreamUpload($otherOrders)]])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('finance_records', [
            'reference' => 'OTHER-BATCH-ORDER', 'account' => $otherAccount->activity_account, 'currency' => 'GBP',
        ]);
        $this->assertDatabaseHas('finance_credit_settlement_entries', [
            'reference' => 'ANOTHER-STATEMENT-ORDER', 'payment_account_id' => $account->id, 'settlement_date' => '2026-11-09',
        ]);

        $statement->getActiveSheet()->setTitle('结算统计-kuaiy');
        $sales->setTitle('成功交易-kuaiy')->setCellValue('A2', 'NEW-SHOP-ORDER')->setCellValue('B2', 'GBP');
        $otherOrders->getActiveSheet()->setCellValue('A2', 'Vepagrav Limited-kuaiy')->setCellValue('D2', 'NEW-SHOP-ORDER');
        $this->post($url.'/import', [...$data, 'settlement_type' => '正常结算', 'files' => [$octetStreamUpload($otherOrders), $octetStreamUpload($statement)]])
            ->assertSessionHasNoErrors();
        $new = FinancePaymentAccount::query()->where('card_merchant_key', 'kuaiy')->sole();
        $this->assertSame('GBP', $new->main_currency);
        $this->assertDatabaseHas('finance_records', ['reference' => 'NEW-SHOP-ORDER', 'account' => $new->activity_account]);
        $this->assertDatabaseHas('finance_credit_settlement_entries', ['reference' => 'NEW-SHOP-ORDER', 'payment_account_id' => $new->id]);

        $otherOrders->getActiveSheet()->setCellValue('A2', 'Vepagrav Limited-orderonly')->setCellValue('D2', 'ORDER-ONLY');
        $this->post($url.'/import', [...$data, 'files' => [$octetStreamUpload($otherOrders)]])->assertSessionHasNoErrors();
        $orderOnly = FinancePaymentAccount::query()->where('card_merchant_key', 'orderonly')->sole();
        $this->assertDatabaseHas('finance_records', ['reference' => 'ORDER-ONLY', 'account' => $orderOnly->activity_account, 'currency' => 'GBP']);

        $statement->getActiveSheet()->setTitle('结算统计-statementonly');
        $sales->setTitle('成功交易-statementonly')->setCellValue('A2', 'STATEMENT-ONLY')->setCellValue('G1', '卡种')->setCellValue('G2', 'Visa');
        $this->post($url.'/import', [...$data, 'settlement_type' => '停结算', 'files' => [$octetStreamUpload($statement)]])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, '仍有待匹配交易'));
        $statementOnly = FinancePaymentAccount::query()->where('card_merchant_key', 'statementonly')->sole();
        $this->assertDatabaseHas('finance_credit_settlement_entries', ['reference' => 'STATEMENT-ONLY', 'payment_account_id' => $statementOnly->id]);
        $stopUrl = $url.'?'.http_build_query(['card_channel' => 'ye', 'account' => 'statementonly', 'ledger_settlement_type' => '停结算']);
        $this->get($stopUrl)->assertOk()->assertSee('未匹配订单')->assertSee('STATEMENT-ONLY')->assertSee('Visa')->assertViewHas('rows', fn ($rows) => $rows->total() === 1);

        $otherOrders->getActiveSheet()->setCellValue('A2', 'Store-statementonly')->setCellValue('D2', 'STATEMENT-ONLY');
        $this->post($url.'/import', [...$data, 'files' => [$octetStreamUpload($otherOrders)]])->assertSessionHasNoErrors();
        $matched = $this->get($stopUrl)
            ->assertOk()->viewData('rows')->getCollection()->sole();
        $this->assertSame('停结算', $matched['card_layout']['ledger_settlement_type']);

    }

    public function test_credit_channel_workbooks_can_be_imported_manually_without_overwriting_transactions(): void
    {
        $report = '/finance/reports/credit-settlement';
        $reference = '12345678901234567890';
        foreach (['ye' => [4, 2, 13, 14, 15], 'worldpay' => [2, 9, 4, 5, 10]] as $channel => [$ref, $date, $currency, $amount, $status]) {
            $account = $this->financePayPalAccount('信用卡导入-'.$channel, ['account_type' => 'credit_card', 'card_platform' => $channel]);
            $sheet = $this->readDownload($this->get($report.'?template=1&card_channel='.$channel)->assertOk()->streamedContent());
            $sheet->setTitle($channel === 'ye' ? '叶总订单明细' : 'worldpay单包');
            $sheet->getParent()->createSheet(0)->setTitle('说明');
            $sheet->getParent()->setActiveSheetIndex(0);
            $sheet->setCellValueExplicit([$ref, 2], $reference, DataType::TYPE_STRING);
            $sheet->setCellValue([$date, 2], 46332.5);
            $sheet->setCellValue([$currency, 2], 'USD');
            $sheet->setCellValue([$amount, 2], 10);
            $sheet->setCellValue([$status, 2], '成功');
            if ($channel === 'ye') {
                $sheet->setCellValue('S2', 2);
                $sheet->setCellValue('AD2', 3);
                $sheet->setCellValue('U2', 'https://example.com/payment');
            } else {
                $sheet->setCellValue('AE2', 4);
            }
            $data = ['card_channel' => $channel, 'account' => $account->activity_account, 'timezone' => 'Asia/Shanghai'];
            $this->post($report.'/import', [...$data, 'file' => $this->upload($sheet->getParent())])
                ->assertSessionHasNoErrors()->assertSessionHas('success', '已新增 1 条信用卡明细，跳过重复 0 条。');
            $this->post($report.'/import', [...$data, 'file' => $this->upload($sheet->getParent())])
                ->assertSessionHasNoErrors()->assertSessionHas('success', '已新增 0 条信用卡明细，跳过重复 1 条。');

            $export = $this->readDownload($this->get($report.'?download=1&card_channel='.$channel)->assertOk()->streamedContent())->toArray();
            $this->assertCount(2, $export);
            $this->assertSame($reference, $export[1][$ref - 1]);
            if ($channel === 'ye') {
                $this->assertEquals(2, $export[1][18]);
                $this->assertEquals(3, $export[1][29]);
                $this->assertSame('example.com', $export[1][42]);
            } else {
                $this->assertEquals(4, array_combine($export[0], $export[1])['预仲裁金额']);
            }
        }
        $this->assertSame(2, FinanceRecord::query()->where('reference', $reference)->count());
    }

    public function test_worldpay_official_csv_merges_order_and_bill_in_either_import_order(): void
    {
        (require database_path('migrations/2026_11_11_000001_split_finance_credit_settlement_menu.php'))->up();
        (require database_path('migrations/2026_11_12_000007_create_finance_worldpay_releases.php'))->up();
        $report = '/finance/reports/worldpay-settlement';
        $this->get($report)->assertOk()->assertSee('name="gateway"', false)
            ->assertSee('name="files[]"', false)->assertDontSee('id="credit-import-timezone"', false)
            ->assertDontSee('id="credit-manual-account"', false)->assertDontSee('id="settlement-account"', false);
        $this->post($report.'/import')->assertSessionHasErrors(['files' => '没有收到 Worldpay 文件，请重新选择文件上传。']);
        $oversize = new \Illuminate\Http\UploadedFile('', 'worldpay.csv', error: UPLOAD_ERR_INI_SIZE, test: true);
        $this->post($report.'/import', ['files' => [$oversize]])->assertSessionHasErrors([
            'files.0' => 'Worldpay 文件上传失败，请检查文件大小是否超过服务器的上传限制。',
        ]);
        session()->forget('errors');
        $date = '2026-11-07 15:03:27';
        $general = "网关接入号,流水订单号,商户订单号,币种,交易金额,结单币种,结单金额,结单汇率,交易时间,交易状态,支付方式,HPP支付方式,卡种,交易网址,卡号,邮箱,收货人国家,划款状态\n";
        $posted = "网关接入号,流水订单号,商户订单号,交易时间,记账时间,结算批次号,结算类型,交易币种,交易金额,结单币种,成功结单金额,交易手续费,单笔手续费,保证金扣取金额,应结算正交易金额,来源网址\n";
        $event = "网关接入号,流水订单号,商户订单号,交易时间,记账时间,结算批次号,结算类型,结单币种,异常结单金额,处理费,应结算异常结单金额,Case ID\n";
        $import = function (string ...$csvs) use ($report): void {
            $this->post($report.'/import', ['files' => array_map(
                fn ($csv) => \Illuminate\Http\UploadedFile::fake()->createWithContent('worldpay.csv', $csv), $csvs)])
                ->assertSessionHasNoErrors()->assertRedirect();
        };
        foreach (['wp-1', 'wp-2'] as $reference) {
            $order = $general."25,$reference,order-$reference,USD,45.89,USD,45.89,1,$date,成功,HPP,,Visa,example.com,,,US,未入账\n";
            $bill = $posted."25,$reference,order-$reference,$date,$date,R161,成功,USD,45.89,USD,45.89,2.07,0.30,4.59,38.93,example.com\n";
            $refund = $event."25,$reference,order-$reference,$date,$date,R161,退款,USD,5,0.30,-5.30,case-$reference\n";
            $import(...($reference === 'wp-1' ? [$order, $bill, $refund] : [$refund, $bill, $order]));
            $import(...array_fill(0, 20, $refund));
        }
        $import($general."25,wp-failed,order-failed,USD,45.89,USD,45.89,1,$date,失败,HPP,,Visa,example.com,,,US,未入账\n");
        $import($posted."25,wp-failed,order-failed,$date,$date,R161,失败,USD,0,USD,0,0,0.30,0,-0.30,example.com\n");
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('bill_number');
            $table->string('source_shop_domain');
            $table->string('assigned_owner_name');
            $table->string('group_names');
        });
        DB::table('logistics_orders')->insert(['bill_number' => 'wp-failed', 'source_shop_domain' => 'matched.example',
            'assigned_owner_name' => '订单运营', 'group_names' => '厦门团队']);
        $this->assertSame(3, FinanceRecord::query()->where('payload->_card_channel', 'worldpay')->count());
        $this->get($report.'?worldpay_exception=yes')->assertOk()->assertSee('wp-1')->assertSee('wp-2')->assertDontSee('wp-failed');
        $this->get($report.'?worldpay_exception=no')->assertOk()->assertSee('wp-failed')->assertDontSee('wp-1')->assertDontSee('wp-2');
        $query = http_build_query(['month' => '2026-11', 'download' => 1,
            'export_columns' => ['reference', 'gross', 'settlement', 'hold', 'fee', 'refund', 'refund_fee', 'payable', 'worldpay_exception']]);
        $sheet = $this->readDownload($this->get($report.'?'.$query)->assertOk()->streamedContent());
        $rows = $sheet->toArray();
        $allocation = $sheet->getParent()->getSheetByName('失败单分摊');
        $this->assertNotNull($allocation);
        $this->assertEquals(['2026-11', '未归属', 2, 1, 2, '100.00%', 1, 0.30], $allocation->toArray()[1]);
        $xls = $this->readDownload($this->get($report.'?'.$query.'&format=xls')->assertOk()->streamedContent());
        $this->assertEquals($allocation->toArray(null, true, false),
            $xls->getParent()->getSheetByName('失败单分摊')->toArray(null, true, false));
        $this->assertCount(4, $rows);
        foreach (array_slice($rows, 1) as $row) {
            $this->assertEquals($row[0] === 'wp-failed' ? [45.89, 0, 0, 0, null, null, -0.30, '否']
                : [45.89, 45.89, 4.59, 2.07, 5, 0.30, 33.63, '是'], array_slice($row, 1));
        }
        $attributionQuery = http_build_query(['month' => '2026-11', 'gateway' => '25', 'team' => '厦门团队', 'download' => 1,
            'export_columns' => ['reference', 'transaction_url', 'domain', 'owner', 'team', 'per_order_fee']]);
        $attribution = $this->readDownload($this->get($report.'?'.$attributionQuery)->assertOk()->streamedContent())->toArray();
        $this->assertCount(2, $attribution);
        $this->assertEquals(['wp-failed', 'example.com', 'matched.example', '订单运营', '厦门团队', 0.30], $attribution[1]);
        $this->get($report.'?gateway=999')->assertOk()->assertDontSee('wp-failed');
        Schema::table('logistics_orders', fn (Blueprint $table) => $table->string('tongtu_product_name')->nullable());
        $orderId = DB::table('logistics_orders')->insertGetId(['bill_number' => 'wp-1', 'source_shop_domain' => 'matched.example',
            'assigned_owner_name' => '订单运营', 'group_names' => '厦门团队', 'tongtu_product_name' => "Men's thermal underwear set"]);
        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->id();
            $table->string('domain');
            $table->string('user_name');
            $table->string('team');
            $table->string('site_builder');
        });
        DB::table('assistant_domain_stores')->insert(['domain' => 'matched.example', 'user_name' => '订单运营',
            'team' => '厦门团队', 'site_builder' => 'xshoppy']);
        Schema::create('fashion_logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->string('source_domain');
            $table->string('transaction_id');
            $table->string('currency');
            $table->decimal('total_amount');
            $table->dateTime('order_created_at_utc')->nullable();
            $table->dateTime('order_created_at');
        });
        DB::table('fashion_logistics_orders')->insert(['assistant_domain_store_id' => 1,
            'source_domain' => 'source.example', 'transaction_id' => 'wp-2', 'currency' => 'USD', 'total_amount' => 45,
            'order_created_at_utc' => null, 'order_created_at' => '2026-11-07 15:03:27']);
        (require database_path('migrations/2026_10_30_000003_create_assistant_domain_store_owner_histories.php'))->up();
        DB::table('assistant_domain_store_owner_histories')->insert([
            ['assistant_domain_store_id' => 1, 'owner_name_snapshot' => '历史使用人', 'event' => 'assigned',
                'effective_from' => '2026-11-01 00:00:00', 'effective_to' => '2026-11-07 08:00:00'],
            ['assistant_domain_store_id' => 1, 'owner_name_snapshot' => '后续使用人', 'event' => 'transferred',
                'effective_from' => '2026-11-07 08:00:00', 'effective_to' => null],
        ]);
        Schema::create('fashion_logistics_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fashion_logistics_order_id');
            $table->string('product_name');
            $table->decimal('total');
        });
        DB::table('fashion_logistics_order_items')->insert(['fashion_logistics_order_id' => 1,
            'product_name' => "Women's Casual Contrast Web Sports Suit", 'total' => 36]);
        $fashionQuery = http_build_query(['domain' => 'matched.example', 'reference' => 'wp-2', 'download' => 1,
            'export_columns' => ['reference', 'transaction_url', 'domain', 'owner', 'team', 'operation_region', 'product_name', 'shop_difference_usd']]);
        $fashionExport = $this->readDownload($this->get($report.'?'.$fashionQuery)->assertOk()->streamedContent())->toArray();
        $this->assertEquals(['wp-2', 'example.com', 'matched.example', '历史使用人', '厦门团队', '厦门', '撞色运动套装', 9.89], $fashionExport[1]);
        Schema::create('logistics_tongtu_sku_mappings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('product_guide_cost_cny');
            $table->decimal('goods_weight_gram');
        });
        Schema::create('logistics_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('logistics_order_id');
            $table->unsignedBigInteger('tongtu_sku_mapping_id');
            $table->decimal('quantity');
        });
        DB::table('logistics_tongtu_sku_mappings')->insert(['id' => 1, 'product_guide_cost_cny' => 12, 'goods_weight_gram' => 250]);
        DB::table('logistics_order_items')->insert(['logistics_order_id' => $orderId, 'tongtu_sku_mapping_id' => 1, 'quantity' => 2]);
        $orderQuery = http_build_query(['reference' => 'wp-1', 'download' => 1,
            'export_columns' => ['reference', 'logistics', 'store_platform', 'product_name', 'payable']]);
        $orderExport = $this->readDownload($this->get($report.'?'.$orderQuery)->assertOk()->streamedContent())->toArray();
        $this->assertEquals(['wp-1', 60, '索引', '保暖内衣套装', 33.63], $orderExport[1]);
        $release = "商户号,结单币种,成功结单金额,保证金扣取日期,保证金扣取批次号,保证金返还日期,保证金返还批次号,保证金返还金额\n"
            .'888999,USD,"80,709.64",2026-05-12,R135,2026-11-10,R161,"8,076.25"';
        $import($release);
        $import($release);
        $this->assertDatabaseCount('finance_worldpay_releases', 1);
        $this->get($report)->assertOk()->assertSee('data-settlement-column="release"', false)
            ->assertDontSee('保证金返还批次');
    }

    public function test_settlement_editor_saves_all_supplement_text_fields(): void
    {
        $this->workbooks->importRecords('paypal-orders', [2 => [
            ...$this->recordRow(), '收件人電郵地址' => 'recipient@example.test', '收據 ID' => 'RECEIPT-123',
            '選項 1 名稱' => '颜色', '選項 1 的值' => '蓝色', '新增日期' => '2026-09-09',
        ]], 'A', 'UTC', 1);
        $record = FinanceRecord::sole();
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $page = $this->get('/finance/reports/paypal-settlement')->assertOk();
        foreach (array_diff_key($service->textFields('paypal'), ['team' => true]) as $field => $label) {
            $page->assertSee('id="edit-'.$field.'"', false);
        }
        $page->assertSee('id="edit-reference"', false)->assertSee('删除所选');
        $values = ['id' => $record->id, 'owner' => '手动使用人', 'team' => '手动团队', 'paypal_12' => 'MANUAL-TXN',
            'domain' => 'manual.example.test', 'subscription' => '订阅', 'upsell' => '否', 'erp_order' => 'MANUAL-ERP', 'note' => '手动备注'];
        $this->post('/finance/reports/paypal-settlement/save', $values)->assertSessionHasNoErrors();
        $row = $service->rows('paypal', [])->sole();
        foreach (array_diff_key($values, ['id' => true]) as $field => $value) {
            $this->assertSame($value, $row[$field], $field);
        }
        $this->post('/finance/reports/paypal-settlement/save', ['id' => $record->id, 'owner' => str_repeat('a', 192)])->assertSessionHasErrors('owner');
        $this->assertSame('手动使用人', $service->rows('paypal', [])->sole()['owner']);
        $this->get('/finance/reports/paypal-settlement/records/'.$record->id)->assertOk()
            ->assertSeeText(['0000123', 'MANUAL-TXN', '10.00', '财务记录', '查看全部流水字段（43 项）',
                '收件人电邮地址', 'recipient@example.test', '收据 ID', 'RECEIPT-123', '选项 1 名称', '颜色', '蓝色', '2026-09-09'])
            ->assertDontSeeText(['收件人電郵地址', '收據 ID', '選項 1 名稱']);
    }

    public function test_settlement_delete_removes_reports_but_preserves_orders_and_checks_locks_and_permissions(): void
    {
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow(),
            3 => [...$this->recordRow(), '交易 ID' => 'DELETE-SECOND']], 'A', 'UTC', 1);
        $ids = FinanceRecord::pluck('id')->all();
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $this->user->manage = false;
        $this->delete('/finance/reports/paypal-settlement/records', ['ids' => $ids])->assertForbidden();
        $this->user->manage = true;
        $this->delete('/finance/reports/credit-settlement/records', ['ids' => $ids])->assertSessionHasErrors('ids');
        $this->assertCount(2, $service->rows('paypal', []));
        $month = FinanceRecord::first()->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m');
        $this->workbooks->database()->table('finance_period_locks')->insert(['month' => $month, 'report_snapshot' => '[]']);
        $this->delete('/finance/reports/paypal-settlement/records', ['ids' => $ids])->assertSessionHasErrors('ids');
        $this->assertCount(2, $service->rows('paypal', []));
        $this->workbooks->database()->table('finance_period_locks')->where('month', $month)->delete();
        $this->delete('/finance/reports/paypal-settlement/records', ['ids' => $ids])->assertRedirect(route('finance.report', 'paypal-settlement'))->assertSessionHasNoErrors();
        $this->assertCount(0, $service->rows('paypal', []));
        $this->get('/finance/reports/paypal-settlement')->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->assertDatabaseCount('finance_records', 2);
        $this->assertSame(2, $this->workbooks->database()->table('finance_settlement_supplements')->whereNotNull('payload->_deleted_at')->count());
        $this->delete('/finance/reports/paypal-settlement/records', ['ids' => [$ids[0]]])->assertSessionHasErrors('ids');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['记录ID', '备注'], [(string) $ids[0], '重新导入']], null, 'A1');
        $this->post('/finance/reports/paypal-settlement/import', ['file' => $this->upload($book)])
            ->assertSessionHasErrors('file');
    }

    public function test_paypal_import_cannot_update_existing_records_even_with_legacy_mode(): void
    {
        $account = $this->financePayPalAccount('导入只新增');
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], $account->activity_account, 'UTC', 1);
        $record = FinanceRecord::sole();
        $original = $record->payload;
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['记录ID', '支付账户', '交易 ID', '备注'], [(string) $record->id, $account->account_name, $record->reference, '不能覆盖']], null, 'A1');
        foreach ([[], ['import_mode' => 'supplement']] as $input) {
            $this->post('/finance/reports/paypal-settlement/import', [...$input, 'file' => $this->upload($book)])
                ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($text) => str_contains($text, '已新增 0 条') && str_contains($text, '跳过重复 1 条'));
        }
        $this->assertSame($original, $record->fresh()->payload);
        $this->assertDatabaseCount('finance_settlement_supplements', 0);
        $this->assertDatabaseCount('finance_records', 1);
        $this->get('/finance/reports/paypal-settlement')->assertDontSee('id="paypal-import-mode"', false)->assertDontSee('补充已有记录');
    }

    public function test_paypal_tongtu_cost_import_updates_only_the_primary_payment(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('tongtu_order_number');
            $table->string('bill_number');
        });
        Schema::create('logistics_order_payment_references', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('logistics_order_id');
            $table->string('reference_number');
            $table->string('reference_type');
        });
        DB::table('logistics_orders')->insert(['id' => 1, 'tongtu_order_number' => 'erp-1001', 'bill_number' => 'PRIMARY']);
        DB::table('logistics_order_payment_references')->insert([
            ['logistics_order_id' => 1, 'reference_number' => 'PRIMARY', 'reference_type' => 'primary'],
            ['logistics_order_id' => 1, 'reference_number' => 'UPSELL', 'reference_type' => 'upsell'],
        ]);
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '交易 ID' => 'PRIMARY'],
            3 => [...$this->recordRow(), '交易 ID' => 'UPSELL'],
        ], 'PayPal costs', 'UTC', 1);

        $url = '/finance/reports/paypal-settlement';
        $headers = ['通途订单', '物流-元', '采购成本-元'];
        $template = $this->readDownload($this->get($url.'?template=1&import_kind=tongtu_costs')->assertOk()->streamedContent());
        $this->assertSame([$headers], $template->toArray());
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([$headers, ['erp-1001', '21.40', '15.00'], ['missing', '8.00', '']], null, 'A1');
        $this->post($url.'/import', ['import_kind' => 'tongtu_costs', 'file' => $this->upload($book)])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('finance_settlement_supplements', 0);
        $book->getActiveSheet()->removeRow(3);
        $book->getActiveSheet()->fromArray([['erp-1001', '21.4', '16.00']], null, 'A3');
        $this->post($url.'/import', ['import_kind' => 'tongtu_costs', 'file' => $this->upload($book)])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('finance_settlement_supplements', 0);
        $book->getActiveSheet()->setCellValue('C3', '15');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post($url.'/import', ['import_kind' => 'tongtu_costs', 'file' => $this->upload($book)])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('finance_settlement_supplements', 1);
        $this->assertDatabaseCount('finance_records', 2);

        $sheet = $this->readDownload($this->get($url.'?'.http_build_query([
            'download' => 1, 'export_columns' => ['reference', 'erp_order', 'logistics', 'purchase'],
        ]))->assertOk()->streamedContent())->toArray();
        $exported = collect(array_slice($sheet, 1))->keyBy(0);
        $this->assertEquals(['PRIMARY', 'erp-1001', 21.4, 15], $exported['PRIMARY']);
        $this->assertSame(['UPSELL', 'erp-1001', '', ''], $exported['UPSELL']);
    }

    public function test_paypal_detail_columns_match_workbook_and_export_can_be_reimported(): void
    {
        $account = $this->financePayPalAccount('A');
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], $account->activity_account, 'UTC', 1);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $expected = array_map(FinanceCatalog::simplified(...), array_values(FinanceCatalog::source()['headers'])[0]);
        $expected = array_values(array_filter($expected, fn ($header) => $header !== '应结算'));
        $expected[array_search('团队', $expected, true)] = '运营所属部门';
        array_splice($expected, array_search('运营所属部门', $expected, true) + 1, 0, ['地区']);
        array_splice($expected, array_search('退款金额', $expected, true), 0, ['冻结退款', '补差价退款']);
        $actual = array_values($service->columns('paypal'));
        $this->assertSame(['记录ID', '支付账户', ...$expected], $actual);
        $template = $this->readDownload($this->get('/finance/reports/paypal-settlement?template=1')->assertOk()->streamedContent());
        $this->assertSame(array_values($service->columns('paypal')), $template->toArray()[0]);
        $sheet = $this->readDownload($this->get('/finance/reports/paypal-settlement?download=1')->assertOk()->streamedContent());
        $headers = $sheet->toArray()[0];
        $page = $this->get('/finance/reports/paypal-settlement')->assertOk()->assertSee('按当前列表导出')
            ->assertSee('data-new-display-fields="frozen_refund refund_top_up operation_region shop_difference_usd"', false);
        $this->assertSame(array_values($page->viewData('columns')), $headers);
        $this->assertSame([
            '转换美元', '汇率', '收款金额-美元', '手续费', '冻结金额', '争议冻结金额', '冻结退款', '补差价退款',
            '退款金额', '退款费用', '争议退款', '争议费', '返还手续费', '返还争议冻结', '返还冻结', '应结算（USD）',
        ], array_slice($headers, array_search('转换美元', $headers, true), 16));
        $this->assertSame('应结算（USD）', $page->viewData('columns')['payable']);
        $this->assertSame(['Shopify订单号', '交易 ID', '支付账户', '当前订单状态',
            '未解除冻结（USD）', '核对说明', '偏好设定 Txn ID', '关联号类型', '记录ID', '日期'],
            array_slice($headers, 0, 10));
        $this->assertNotContains('商品大类', $headers);
        $this->assertSame('0000123', $sheet->getCell('B2')->getValue());
        $this->assertEquals(FinanceRecord::sole()->id, $sheet->getCell('I2')->getValue());
        $this->assertSame('2026-09-09', $sheet->getCell('J2')->getValue());
        $utcSheet = $this->readDownload($this->get('/finance/reports/paypal-settlement?download=1&timezone=UTC')->assertOk()->streamedContent());
        $this->assertSame('2026-09-08', $utcSheet->getCell('J2')->getValue());
        $this->assertSame(1, $sheet->getParent()->getSheetCount());
        $legacyContent = $this->get('/finance/reports/paypal-settlement?download=1&format=xls')->assertOk()->streamedContent();
        $legacy = $this->readDownload($legacyContent);
        $this->assertSame($headers, $legacy->toArray()[0]);
        $this->assertSame($sheet->calculateWorksheetDimension(), $sheet->getAutoFilter()->getRange());
        // The XLS reader does not expose AutoFilter. Check the BIFF record written for all columns.
        $this->assertStringContainsString(pack('vvv', 0x009D, 2, count($headers)), $legacyContent);
        foreach ([$sheet, $legacy] as $export) {
            $this->assertEmpty($export->getFreezePane());
            $statusColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search('当前订单状态', $headers, true) + 1);
            $this->assertGreaterThanOrEqual(44, $export->getColumnDimension($statusColumn)->getWidth());
            $this->assertGreaterThanOrEqual(38, $export->getColumnDimension('B')->getWidth());
            $this->assertFalse($export->getStyle('A1')->getFont()->getBold());
            $this->assertSame('none', $export->getStyle('A1')->getFill()->getFillType());
            $this->assertSame('none', $export->getStyle('A1')->getBorders()->getBottom()->getBorderStyle());
        }
        $refundColumn = array_search('退款金额', $headers, true) + 1;
        $sheet->setCellValue([$refundColumn, 2], '2.50');
        $this->post('/finance/reports/paypal-settlement/import', ['file' => $this->upload($sheet->getParent())])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($service->rows('paypal', [])->first()['refund']);
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['支付账户', '交易 ID', '退款金额'], ['A', '0000123', '3.50']]);
        $this->post('/finance/reports/paypal-settlement/import', ['file' => $this->upload($book)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($service->rows('paypal', [])->first()['refund']);
        $this->assertDatabaseCount('finance_records', 1);
    }

    public function test_paypal_settlement_keeps_payments_separate_and_exports_event_exchange_rates(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_id');
            $table->string('shopify_order_number')->nullable();
            $table->string('bill_number')->nullable();
        });
        DB::table('logistics_orders')->insert(['transaction_id' => 'PAY-CAD', 'bill_number' => 'BILL-CAD']);
        $account = $this->financePayPalAccount('PayPal结算测试');
        DB::table('finance_transaction_types')->updateOrInsert(['raw_type' => 'T0006'], ['category' => '结账付款']);
        $sync = app(\App\Portals\Finance\FinancePayPalSyncService::class);
        $add = function ($id, $code, $day, $currency, $amount, $status = 'S', $parent = '', $fee = null, $invoice = '', $referenceType = 'TXN', $paymentAccount = null, $updatedAt = null) use ($account, $sync): void {
            $paymentAccount ??= $account;
            $info = [
                'transaction_id' => $id, 'transaction_event_code' => $code,
                'transaction_initiation_date' => $day.'T12:00:00Z', 'transaction_status' => $status,
                'transaction_amount' => ['value' => $amount, 'currency_code' => $currency], 'invoice_id' => $invoice,
                'paypal_reference_id' => $parent, 'paypal_reference_id_type' => $referenceType,
            ];
            if ($updatedAt !== null) {
                $info['transaction_updated_date'] = $updatedAt;
            }
            if ($fee !== null) {
                $info['fee_amount'] = ['value' => $fee, 'currency_code' => $currency];
            }
            if ($id === 'PAY-CAD') {
                $info['transaction_updated_date'] = '2026-09-18T12:05:00Z';
                $info['transaction_note'] = '核对样本附注';
                $info['ending_balance'] = ['value' => '100.20', 'currency_code' => 'CAD'];
            }
            $raw = ['transaction_info' => $info];
            if ($id === 'PAY-CAD') {
                $raw['shipping_info']['address'] = ['line1' => '42 Sample Street', 'country_code' => 'CA'];
                $raw['cart_info']['item_details'] = [['item_name' => '样本商品', 'item_code' => 'ITEM-123', 'item_quantity' => '2']];
            }
            $entry = $sync->transaction($paymentAccount, $raw, 1);
            $record = FinanceRecord::create($entry['record']);
            DB::table('finance_payment_transactions')->insert([
                'payment_account_id' => $paymentAccount->id, 'finance_record_id' => $record->id,
                'transaction_id' => $id, 'event_code' => $code, 'raw_payload' => json_encode($entry['raw']),
            ]);
        };
        $add('PAY-CAD', 'T0006', '2026-09-18', 'CAD', '59.25', parent: 'ORIGINAL-TXN', fee: '-2.91');
        $add('PAY-USD', 'T0006', '2026-09-19', 'USD', '10', parent: 'APPROVAL-ID', invoice: 'BILL-CAD', referenceType: 'PAP');
        $add('PAY-OTHER', 'T0006', '2026-09-20', 'CAD', '15');
        $add('FX-PAY-CAD', 'T0200', '2026-09-18', 'CAD', '-56.34', parent: 'PAY-CAD');
        $add('FX-PAY-USD', 'T0200', '2026-09-18', 'USD', '38.99', parent: 'PAY-CAD');
        $add('DISPUTE', 'T1110', '2026-10-06', 'CAD', '-56.34', 'P', 'PAY-CAD');
        $add('DISPUTE', 'T1111', '2026-10-06', 'CAD', '56.34', 'D', 'PAY-CAD');
        $add('FX-HOLD-USD', 'T0200', '2026-10-06', 'USD', '-39.67', parent: 'DISPUTE');
        $add('FX-HOLD-CAD', 'T0200', '2026-10-06', 'CAD', '56.34', parent: 'DISPUTE');
        $add('FX-RELEASE-CAD', 'T0200', '2026-10-15', 'CAD', '-56.34', parent: 'DISPUTE');
        $add('FX-RELEASE-USD', 'T0200', '2026-10-15', 'USD', '39.67', parent: 'DISPUTE');
        $add('REFUND', 'T1201', '2026-10-15', 'CAD', '-59.25', parent: 'PAY-CAD');
        $add('FX-REFUND-USD', 'T0200', '2026-10-15', 'USD', '-41.58', parent: 'REFUND');
        $add('FX-REFUND-CAD', 'T0200', '2026-10-15', 'CAD', '59.25', parent: 'REFUND');
        $add('DISPUTE-FEE', 'T0114', '2026-10-16', 'CAD', '-10', parent: 'PAY-CAD');
        $add('FX-FEE-USD', 'T0200', '2026-10-16', 'USD', '-7.26', parent: 'PAY-CAD');
        $add('FX-FEE-CAD', 'T0200', '2026-10-16', 'CAD', '10', parent: 'PAY-CAD');
        $add('UNLINKED-REFUND', 'T1201', '2026-10-17', 'USD', '-10', parent: 'MISSING-PAYMENT');
        $add('RELATED-OTHER-EVENT', 'T0800', '2026-10-18', 'USD', '20', parent: 'PAY-CAD');
        $add('UNRELATED-OTHER-EVENT', 'T0800', '2026-10-18', 'USD', '20', parent: 'MISSING-PAYMENT');
        $add('WITHDRAWAL', 'T0403', '2026-10-18', 'USD', '-20', parent: 'PAY-CAD');
        $add('FX-WITHDRAWAL-CAD', 'T0200', '2026-10-18', 'CAD', '-28', parent: 'WITHDRAWAL');
        $add('FX-WITHDRAWAL-USD', 'T0200', '2026-10-18', 'USD', '20', parent: 'WITHDRAWAL');
        $add('FX-NO-REFERENCE', 'T0200', '2026-10-18', 'USD', '18');

        $url = '/finance/reports/paypal-settlement?from=2026-09-01&to=2026-09-30';
        $page = $this->get($url)->assertOk()->assertSee('data-view-settlement')->assertSee('data-edit-settlement')
            ->assertDontSee('FX-RELEASE-USD')->assertDontSee('金额依据')->assertDontSee('amount_source');
        $rows = $page->viewData('rows');
        $this->assertSame(3, $rows->total());
        $usd = $rows->getCollection()->firstWhere('reference', 'PAY-USD');
        $this->assertSame('10.00000000', $usd['payable']);
        $this->assertSame('APPROVAL-ID', $usd['paypal_12']);
        $this->assertSame('PAP', $usd['paypal_reference_type']);
        $this->assertCount(1, $usd['payments']);
        $this->assertSame('BILL-CAD', $usd['paypal_13']);
        $order = $rows->getCollection()->firstWhere('reference', 'PAY-CAD');
        $this->assertSame('', $order['shopify_order_number']);
        $this->assertSame('ORIGINAL-TXN', $order['paypal_12']);
        $this->assertSame('TXN', $order['paypal_reference_type']);
        $this->assertArrayNotHasKey('events', $order);
        $this->assertCount(1, $order['payments']);
        $this->assertSame('已付款 · 全额退款 · 冻结已解除', $order['order_status']);
        $this->assertSame('39.67000000', $order['dispute_hold']);
        $this->assertSame('41.58000000', $order['refund']);
        $this->assertSame('0', $order['frozen_refund']);
        $this->assertSame('0', $order['refund_top_up']);
        $this->assertSame('-9.85000000', $order['payable']);
        $other = $rows->getCollection()->firstWhere('reference', 'PAY-OTHER');
        $this->assertNull($other['payable']);
        $this->assertSame('', $other['paypal_12']);
        $this->assertSame('', $other['paypal_reference_type']);

        $detailUrl = '/finance/reports/paypal-settlement/records/'.$order['id'].'?timezone=Asia/Shanghai';
        $detail = $this->get($detailUrl)->assertOk()
            ->assertSee('FX-RELEASE-USD')->assertSee('REFUND')->assertDontSee('APPROVAL-ID')
            ->assertSee('PAY-CAD')
            ->assertSeeText(['原交易号', '关联交易号', '关联号类型', '原币', '原币金额', '原币手续费', '美元金额', '美元汇率', '美元来源', '-2.91'])
            ->assertSeeText(['原币净额', '56.34', '美元净额', '账单号', 'Asia/Shanghai', 'T0800', '仅供核对'])
            ->assertSeeText(['查看全部流水字段（43 项）', '2026-09-18 20:05:00', '核对样本附注', 'CAD 100.20',
                '物品标题', '样本商品', 'ITEM-123', '42 Sample Street'])
            ->assertSee('RELATED-OTHER-EVENT')->assertDontSee('UNRELATED-OTHER-EVENT')
            ->assertDontSee('WITHDRAWAL')->assertDontSee('FX-NO-REFERENCE')
            ->assertSee('-9.85')->assertDontSee('PAY-OTHER');
        $this->assertNotContains('PAY-USD', array_column($detail->viewData('row')['events'], 'reference'));
        foreach ($detail->viewData('row')['events'] as $event) {
            $this->assertCount(43, array_merge(...array_values($event['fields'])));
            $this->assertSame('—', $event['fields']['联系人与配送']['收件人电邮地址']);
        }
        $this->get(str_replace('Asia/Shanghai', '-04:00', $detailUrl))->assertOk()
            ->assertSeeText(['2026-09-18 08:00:00', '2026-09-18 08:05:00']);
        $this->user->manage = false;
        $this->get($url)->assertOk()->assertSee('data-view-settlement=')->assertDontSee('type="button" data-edit-settlement', false);
        $this->user->manage = true;
        $unlinkedId = DB::table('finance_payment_transactions')->where('transaction_id', 'UNLINKED-REFUND')->value('finance_record_id');
        $this->get('/finance/reports/paypal-settlement/records/'.$unlinkedId)->assertOk()
            ->assertSee('UNLINKED-REFUND')->assertSee('待关联交易')->assertSee('待核对');
        $this->get('/finance/reports/paypal-settlement/records/999999')->assertNotFound();
        $allUrl = '/finance/reports/paypal-settlement';
        $this->get($allUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 5);
        $allExport = $this->readDownload($this->get($allUrl.'?download=1')->assertOk()->streamedContent())->toArray();
        $this->assertEqualsCanonicalizing(['PAY-CAD', 'PAY-USD', 'PAY-OTHER', 'UNLINKED-REFUND', 'FX-NO-REFERENCE'],
            array_column(array_slice($allExport, 1), array_search('交易 ID', $allExport[0], true)));
        $headers = array_flip($allExport[0]);
        $unlinked = collect(array_slice($allExport, 1))->first(fn ($row) => $row[$headers['交易 ID']] === 'UNLINKED-REFUND');
        $this->assertSame('未匹配', $unlinked[$headers['订阅类型']]);
        $this->assertSame('未匹配', $unlinked[$headers['是否加购']]);
        $withdrawalExchangeId = DB::table('finance_payment_transactions')->where('transaction_id', 'FX-WITHDRAWAL-USD')->value('finance_record_id');
        $this->get($allUrl.'/records/'.$withdrawalExchangeId)->assertNotFound();
        $this->assertDatabaseHas('finance_payment_transactions', ['transaction_id' => 'WITHDRAWAL']);
        $this->assertDatabaseHas('finance_payment_transactions', ['transaction_id' => 'FX-WITHDRAWAL-USD']);

        $this->get($url.'&reference=REFUND')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $sheet = $this->readDownload($this->get($url.'&download=1&reference=REFUND')->assertOk()->streamedContent())->toArray();
        $this->assertCount(2, $sheet);
        $this->assertNotContains('金额依据', $sheet[0]);
        $this->assertSame(-9.85, (float) $sheet[1][array_search('应结算（USD）', $sheet[0], true)]);
        $this->assertSame($order['order_status'], $sheet[1][array_search('当前订单状态', $sheet[0], true)]);
        $this->assertSame($order['paypal_12'], $sheet[1][array_search('偏好设定 Txn ID', $sheet[0], true)]);
        $this->assertSame($order['paypal_reference_type'], $sheet[1][array_search('关联号类型', $sheet[0], true)]);

        $release = DB::table('finance_payment_transactions')->where('transaction_id', 'FX-RELEASE-USD');
        $payload = json_decode($release->value('raw_payload'), true);
        $payload['transaction_info']['transaction_amount']['value'] = '40.00';
        $release->update(['raw_payload' => json_encode($payload)]);
        $after = $this->get($url.'&reference=REFUND')->assertOk()->viewData('rows')->first();
        $this->assertSame('39.67000000', $after['dispute_hold']);
        $this->assertSame('40.00000000', $after['dispute_release']);
        $this->assertSame('0', $after['open_hold']);
        $this->assertSame('-9.52000000', $after['payable']);
        $this->get($detailUrl)->assertOk()->assertViewHas('row', fn ($row) => $row['payable'] === $after['payable']);

        $add('PAY-DISPUTE', 'T0006', '2026-09-24', 'USD', '53.07', fee: '-2.64', invoice: 'BILL-DISPUTE');
        $add('USD-DISPUTE', 'T1110', '2026-09-24', 'USD', '-50.43', 'P', 'PAY-DISPUTE');
        $add('USD-DISPUTE', 'T1111', '2026-09-25', 'USD', '50.43', 'D', 'PAY-DISPUTE');
        $add('USD-REFUND', 'T1107', '2026-09-25', 'USD', '-53.07', invoice: 'BILL-DISPUTE');
        $disputeUrl = $url.'&reference=USD-REFUND';
        $dispute = $this->get($disputeUrl)->assertOk()->viewData('rows')->first();
        $this->assertSame('PAY-DISPUTE', $dispute['reference']);
        $this->assertSame('50.43000000', $dispute['dispute_release']);
        $this->assertSame('0', $dispute['open_hold']);
        $this->assertSame('-2.64000000', $dispute['payable']);
        $this->assertSame('已付款 · 全额退款 · 冻结已解除', $dispute['order_status']);
        $this->get('/finance/reports/paypal-settlement/records/'.$dispute['id'])->assertOk()
            ->assertSee('同交易号争议冻结返还')->assertSee('USD-REFUND');
        $disputeExport = $this->readDownload($this->get($disputeUrl.'&download=1')->assertOk()->streamedContent())->toArray();
        $this->assertSame(-2.64, (float) $disputeExport[1][array_search('应结算（USD）', $disputeExport[0], true)]);

        $add('SECOND-USD-DISPUTE', 'T1110', '2026-09-26', 'USD', '-50.43', 'P', 'PAY-DISPUTE');
        $this->get($disputeUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['open_hold'] === '50.43000000'
            && $rows->first()['payable'] === '-53.07000000'
            && str_contains($rows->first()['order_status'], '仍有冻结'));

        $usdRelease = DB::table('finance_payment_transactions')->where('transaction_id', 'USD-DISPUTE')->where('event_code', 'T1111');
        $payload = json_decode($usdRelease->value('raw_payload'), true);
        $payload['transaction_info']['transaction_amount']['value'] = '50.42';
        $usdRelease->update(['raw_payload' => json_encode($payload)]);
        $this->get($disputeUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === null
            && str_contains($rows->first()['review'], '争议返还待核对'));

        $add('SAME-INVOICE', 'T0006', '2026-09-24', 'USD', '53.07', invoice: 'BILL-DISPUTE');
        $this->get($url.'&reference=PAY-DISPUTE')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === null
                && str_contains($rows->first()['review'], '存在无法唯一归属的资金流水'));

        $add('PAY-CAD-REFUNDED', 'T0006', '2026-09-20', 'CAD', '56.85', fee: '-2.80');
        $add('PAYMENT-FX-CAD', 'T0200', '2026-09-20', 'CAD', '-54.05', parent: 'PAY-CAD-REFUNDED');
        $add('PAYMENT-FX-USD', 'T0200', '2026-09-20', 'USD', '37.40', parent: 'PAY-CAD-REFUNDED');
        $add('HOLD-FOR-REFUND', 'T1110', '2026-10-04', 'CAD', '-54.05', 'P', 'PAY-CAD-REFUNDED');
        $add('HOLD-FX-CAD', 'T0200', '2026-10-04', 'CAD', '54.05', parent: 'HOLD-FOR-REFUND');
        $add('HOLD-FX-USD', 'T0200', '2026-10-04', 'USD', '-38.06', parent: 'HOLD-FOR-REFUND');
        $add('HOLD-FOR-REFUND', 'T1111', '2026-10-04', 'CAD', '54.05', 'D', 'PAY-CAD-REFUNDED',
            updatedAt: '2026-10-09T12:00:00Z');
        $add('REFUND-USING-HOLD', 'T1107', '2026-10-09', 'CAD', '-56.85', parent: 'PAY-CAD-REFUNDED');
        $add('REFUND-TOPUP-CAD', 'T0200', '2026-10-09', 'CAD', '2.80', parent: 'REFUND-USING-HOLD');
        $add('REFUND-TOPUP-USD', 'T0200', '2026-10-09', 'USD', '-1.97', parent: 'REFUND-USING-HOLD');
        $fundedUrl = $url.'&reference=PAY-CAD-REFUNDED';
        $funded = $this->get($fundedUrl)->assertOk()
            ->assertSeeText(['冻结退款已列 38.06 USD', '补差价退款已列 1.97 USD'])->viewData('rows')->first();
        $this->assertSame('0', $funded['refund']);
        $this->assertSame('0', $funded['refund_fee']);
        $this->assertSame('38.06000000', $funded['frozen_refund']);
        $this->assertSame('1.97000000', $funded['refund_top_up']);
        $this->assertSame('38.06000000', $funded['dispute_release']);
        $this->assertSame('0', $funded['open_hold']);
        $this->assertSame('-2.63000000', $funded['payable']);
        $this->assertSame('full', $funded['refund_state']);
        $this->get('/finance/reports/paypal-settlement/records/'.$funded['id'])->assertOk()
            ->assertSeeText(['解冻资金用于退款', '冻结退款（USD）', '补差价退款（USD）']);
        $fundedExport = $this->readDownload($this->get($fundedUrl.'&download=1')->assertOk()->streamedContent())->toArray();
        $this->assertSame(38.06, (float) $fundedExport[1][array_search('冻结退款', $fundedExport[0], true)]);
        $this->assertSame(1.97, (float) $fundedExport[1][array_search('补差价退款', $fundedExport[0], true)]);
        $this->assertSame('冻结退款已列 38.06 USD', $fundedExport[1][array_search('退款金额', $fundedExport[0], true)]);
        $this->assertSame('补差价退款已列 1.97 USD', $fundedExport[1][array_search('退款费用', $fundedExport[0], true)]);
        $this->assertSame(38.06, (float) $fundedExport[1][array_search('返还争议冻结', $fundedExport[0], true)]);
        $this->assertSame(-2.63, (float) $fundedExport[1][array_search('应结算（USD）', $fundedExport[0], true)]);

        $fundedRecord = FinanceRecord::findOrFail($funded['id']);
        $settlements = app(\App\Portals\Finance\FinanceSettlementService::class);
        $settlements->save($fundedRecord, ['refund' => '0', 'refund_fee' => '0'], 1);
        $this->get($fundedUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === '-2.63000000'
            && $rows->first()['frozen_refund'] === '38.06000000' && $rows->first()['refund_top_up'] === '1.97000000');
        $settlements->save($fundedRecord, ['refund' => null, 'refund_fee' => null], 1);
        $settlements->save($fundedRecord, ['refund_fee' => '1.97'], 1);
        $this->get($fundedUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['refund_fee'] === '1.97'
            && $rows->first()['refund_top_up'] === '0' && $rows->first()['payable'] === '-2.63000000');
        $settlements->save($fundedRecord, ['refund_fee' => null], 1);

        $release = DB::table('finance_payment_transactions')->where('transaction_id', 'HOLD-FOR-REFUND')->where('event_code', 'T1111');
        $releasePayload = json_decode($release->value('raw_payload'), true);
        unset($releasePayload['transaction_info']['transaction_updated_date']);
        $releasePayload['transaction_info']['transaction_initiation_date'] = '2026-10-09T12:00:00Z';
        $release->update(['raw_payload' => json_encode($releasePayload)]);
        FinanceRecord::findOrFail($release->value('finance_record_id'))->update(['occurred_at' => '2026-10-09 12:00:00']);
        $this->get($fundedUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === '-2.63000000'
            && $rows->first()['frozen_refund'] === '38.06000000' && $rows->first()['refund_top_up'] === '1.97000000');

        $refundTopup = DB::table('finance_payment_transactions')->where('transaction_id', 'REFUND-TOPUP-CAD');
        $payload = json_decode($refundTopup->value('raw_payload'), true);
        $payload['transaction_info']['transaction_amount']['value'] = '2.79';
        $refundTopup->update(['raw_payload' => json_encode($payload)]);
        $this->get($fundedUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === null
            && $rows->first()['frozen_refund'] === '0' && $rows->first()['refund_top_up'] === '0');

        $saveUrl = '/finance/reports/paypal-settlement/save';
        $this->get($fundedUrl)->assertOk()->assertSee('name="frozen_refund"', false)->assertSee('name="refund_top_up"', false);
        $this->post($saveUrl, ['id' => $funded['id'], 'refund_top_up' => '-1.97'])->assertSessionHasErrors('refund_top_up');
        $this->post($saveUrl, ['id' => $funded['id'], 'refund_top_up' => '1.97'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($fundedUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === '-2.63000000'
            && $rows->first()['frozen_refund'] === '38.06000000'
            && str_contains($rows->first()['review'], '已人工补录补差价退款'));
        $this->post($saveUrl, ['id' => $funded['id'], 'refund_fee' => '1.97'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($fundedUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === null
            && str_contains($rows->first()['review'], '待核对是否重复'));
        $this->post($saveUrl, ['id' => $funded['id'], 'refund_fee' => ''])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($saveUrl, ['id' => $funded['id'], 'refund_top_up' => ''])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($fundedUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === null);

        $otherAccount = $this->financePayPalAccount('另一 PayPal 账户');
        $add('PAY-CAD', 'T0006', '2026-09-18', 'CAD', '59.25', paymentAccount: $otherAccount);
        $this->get($detailUrl)->assertOk()->assertViewHas('row', fn ($row) => $row['payable'] === null);

        $add('PAY-TO-HKD', 'T0006', '2026-09-28', 'CAD', '28.80', fee: '-1.57');
        $add('FX-HKD-SOURCE', 'T0200', '2026-09-28', 'CAD', '-27.23', parent: 'PAY-TO-HKD');
        $add('FX-HKD-TARGET', 'T0200', '2026-09-28', 'HKD', '147.80', parent: 'PAY-TO-HKD');
        $hkdUrl = $url.'&reference=PAY-TO-HKD';
        $hkdRow = $this->get($hkdUrl)->assertOk()->viewData('rows')->first();
        $this->assertStringContainsString('收款实际换入 147.80 HKD，缺少美元折算依据', $hkdRow['review']);
        $this->assertNull($hkdRow['payable']);
        $settlements->save(FinanceRecord::findOrFail($hkdRow['id']), ['usd_rate' => '0.70'], 1);
        $this->get($hkdUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === '19.06100000'
            && ! str_contains($rows->first()['review'], '缺少美元折算依据'));
        $settlements->save(FinanceRecord::findOrFail($hkdRow['id']), ['usd_rate' => null], 1);

        $add('OTHER-HKD-REFERENCE', 'T0006', '2026-09-28', 'USD', '20.00', paymentAccount: $otherAccount);
        $add('OTHER-HKD-USD', 'T0200', '2026-09-28', 'USD', '-20.00', parent: 'OTHER-HKD-REFERENCE', paymentAccount: $otherAccount);
        $add('OTHER-HKD-TARGET', 'T0200', '2026-09-28', 'HKD', '150.00', parent: 'OTHER-HKD-REFERENCE', paymentAccount: $otherAccount);
        $this->get($hkdUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === null);

        $add('HKD-REFERENCE', 'T0006', '2026-09-29', 'USD', '21.00', fee: '-1.00');
        $add('HKD-REFERENCE-USD', 'T0200', '2026-09-29', 'USD', '-20.00', parent: 'HKD-REFERENCE');
        $add('HKD-REFERENCE-HKD', 'T0200', '2026-09-29', 'HKD', '150.00', parent: 'HKD-REFERENCE');
        $this->get($hkdUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === '19.70666666'
            && str_contains($rows->first()['review'], '同账户 PayPal 参考汇率折算美元（交易 HKD-REFERENCE）'));
        DB::table('finance_monthly_exchange_rates')->insert([
            ['month' => '2026-09', 'currency' => 'HKD', 'cny_rate' => '0.90'],
            ['month' => '2026-09', 'currency' => 'USD', 'cny_rate' => '7.20'],
        ]);
        $this->get($hkdUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === '18.47500000'
            && str_contains($rows->first()['review'], '按 2026-09 财务月汇率折算美元'));

        $add('RESERVE-WITHOUT-ORDER', 'T2103', '2026-09-29', 'USD', '-40000.00');
        $add('RESERVE-RELEASE-WITHOUT-ORDER', 'T2104', '2026-09-29', 'USD', '40000.00');
        foreach (['RESERVE-WITHOUT-ORDER', 'RESERVE-RELEASE-WITHOUT-ORDER'] as $reference) {
            $reserveUrl = $url.'&reference='.$reference;
            $this->get($reserveUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1
                && $rows->first()['review'] === '预备金流水无原付款关联号，未计入订单应结算');
            foreach (['xlsx', 'xls'] as $format) {
                $export = $this->readDownload($this->get($reserveUrl.'&download=1&format='.$format)->assertOk()->streamedContent())->toArray();
                $this->assertCount(2, $export);
                $headers = array_flip($export[0]);
                $this->assertSame($reference, $export[1][$headers['交易 ID']]);
                $this->assertTrue(blank($export[1][$headers['核对说明']]));
            }
            $id = FinanceRecord::where('reference', $reference)->sole()->id;
            $this->get('/finance/reports/paypal-settlement/records/'.$id)->assertOk();
        }

        $add('PAY-RESERVE', 'T0006', '2026-09-29', 'USD', '20.00');
        $add('RESERVE-FOR-ORDER', 'T2103', '2026-09-29', 'USD', '-5.00', parent: 'PAY-RESERVE');
        $add('RESERVE-RELEASE-FOR-ORDER', 'T2104', '2026-09-30', 'USD', '3.00', parent: 'PAY-RESERVE');
        $this->get($url.'&reference=PAY-RESERVE')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->first()['payable'] === '18.00000000'
                && $rows->first()['open_hold'] === '2.00000000');
    }

    public function test_settlement_custom_export_order_applies_to_selected_rows_and_blank_templates(): void
    {
        $this->workbooks->importRecords('paypal-orders', [
            2 => $this->recordRow(),
            3 => [...$this->recordRow(), '交易 ID' => 'SECOND-TRANSACTION'],
        ], 'A', 'UTC', 1);
        $url = '/finance/reports/paypal-settlement';
        $selectedId = FinanceRecord::where('reference', '0000123')->sole()->id;
        $page = $this->get($url)->assertOk()->assertSee('列与导出设置')->assertSee('下载空白模板')->assertDontSee('比较方式')
            ->assertSee('type="hidden" data-condition-operator value="equals"', false)
            ->assertDontSee('name="product_name_mode"', false);
        $parameters = ['download' => 1, 'export_columns' => ['order_status', 'reference', 'paypal_18']];
        $selection = [\App\Portals\Finance\FinanceExportSelection::key(['id' => $selectedId])];
        $defaultSelected = $this->readDownload($this->get($url.'?'.http_build_query(['download' => 1, 'selected_rows' => $selection]))
            ->assertOk()->streamedContent())->toArray();
        $this->assertSame(array_values(array_intersect_key($page->viewData('exportColumns'), $page->viewData('columns'))), $defaultSelected[0]);
        $this->assertCount(2, $defaultSelected);
        $this->assertSame('0000123', $defaultSelected[1][1]);
        $filtered = $this->readDownload($this->get($url.'?'.http_build_query($parameters))->assertOk()->streamedContent())->toArray();
        $selected = $this->readDownload($this->get($url.'?'.http_build_query([...$parameters, 'selected_rows' => $selection]))
            ->assertOk()->streamedContent())->toArray();
        $this->assertSame(['当前订单状态', '交易 ID', '商品大类'], $filtered[0]);
        $this->assertCount(3, $filtered);
        $this->assertCount(2, $selected);
        $this->assertSame($filtered[0], $selected[0]);
        $this->assertSame('0000123', $selected[1][1]);
        $template = $this->readDownload($this->get($url.'?'.http_build_query([...$parameters, 'export_template' => 1]))
            ->assertOk()->streamedContent())->toArray();
        $this->assertSame([['当前订单状态', '交易 ID', '商品大类']], $template);
        $withoutProduct = $this->readDownload($this->get($url.'?'.http_build_query([...$parameters, 'export_columns' => ['reference']]))
            ->assertOk()->streamedContent())->toArray();
        $this->assertSame(['交易 ID'], $withoutProduct[0]);
        foreach ([['reference', 'reference'], ['unknown_field']] as $invalid) {
            $this->getJson($url.'?'.http_build_query([...$parameters, 'export_columns' => $invalid]))->assertUnprocessable();
        }
    }

    public function test_settlement_imports_only_template_headers_in_their_original_order(): void
    {
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->fromArray([
            ['财务对账专用模板'],
            ['應結算', '交易號', '物品標題', '自定义字段', '交易 ID', '當前訂單狀態'],
            ['=1+1', '不应导入的交易', '不应导入的商品'],
        ]);
        $url = '/finance/reports/paypal-settlement/export-template/preview';
        $this->user->manage = false;
        $result = $this->post($url, ['file' => $this->upload($workbook), 'header_row' => 2], ['Accept' => 'application/json'])
            ->assertOk()->assertExactJson([
                'fields' => ['payable', 'reference', 'paypal_18', 'order_status'],
                'unmatched' => ['自定义字段'],
                'duplicates' => ['交易 ID'],
            ])->json();
        $csv = UploadedFile::fake()->createWithContent('columns.csv', "\xEF\xBB\xBF支付账户,交易 ID,商品名称\r\n");
        $this->post($url, ['file' => $csv, 'header_row' => 1], ['Accept' => 'application/json'])->assertOk()
            ->assertExactJson(['fields' => ['account', 'reference', 'paypal_18'], 'unmatched' => [], 'duplicates' => []]);
        $this->assertDatabaseCount('finance_records', 0);
        $query = http_build_query(['download' => 1, 'export_template' => 1, 'export_columns' => $result['fields']]);
        $sheet = $this->readDownload($this->get('/finance/reports/paypal-settlement?'.$query)->assertOk()->streamedContent());
        $this->assertSame([['应结算（USD）', '交易 ID', '商品大类', '当前订单状态']], $sheet->toArray());
    }

    public function test_both_supplied_supplement_templates_match_by_transaction_and_order_numbers(): void
    {
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], 'A', 'UTC', 1);
        $headers = FinanceCatalog::dataset('xuzong2-details')['headers'];
        $credit = [...array_fill_keys($headers, ''), '商户订单号' => 'MERCHANT-1', '平台订单号' => 'PLATFORM-1',
            '创建时间' => '2026-11-02 12:00:00', '交易币种' => 'USD', '交易金额' => '10', '支付状态' => 'SUCCESS'];
        $this->workbooks->importRecords('xuzong2-details', [2 => $credit], 'B', 'UTC', 1);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $book = new Spreadsheet;
        $paypal = $book->getActiveSheet()->setTitle('贝宝导入模版');
        $paypalHeaders = array_values($service->importFields('paypal'));
        $paypal->fromArray($paypalHeaders, null, 'A1');
        $paypalRow = array_fill_keys($paypalHeaders, '');
        $paypalRow['交易 ID'] = '0000123'; $paypalRow['退款金额'] = '2.5';
        $paypal->fromArray([array_values($paypalRow)], null, 'A2');
        $card = $book->createSheet()->setTitle('徐总支付导入模版');
        $cardHeaders = array_values($service->importFields('credit_card'));
        $this->assertCount(24, $paypalHeaders); $this->assertCount(25, $cardHeaders);
        $card->fromArray($cardHeaders, null, 'A1');
        $cardRow = array_fill_keys($cardHeaders, '');
        $cardRow['商户订单号'] = 'MERCHANT-1'; $cardRow['平台订单号'] = 'PLATFORM-1'; $cardRow['结算金额'] = '9.5'; $cardRow['交易手续费'] = '0.5';
        $card->fromArray([array_values($cardRow)], null, 'A2');
        foreach (['credit-settlement'] as $report) {
            $this->post('/finance/reports/'.$report.'/import', ['file' => $this->upload($book)])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertNull($service->rows('paypal', [])->first()['refund']);
        $this->assertSame('10.00000000', $service->rows('credit_card', [])->first()['settlement']);
        $this->assertNull($service->rows('credit_card', [])->first()['fee']);
        $this->assertDatabaseCount('finance_records', 2);
    }

    public function test_credit_full_field_import_keeps_business_fields_costs_and_skips_duplicates_without_updating(): void
    {
        $account = $this->financePayPalAccount('信用卡全字段账户', ['account_type' => 'credit_card']);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $headers = array_values(array_unique(['支付账户', ...FinanceCatalog::dataset('xuzong2-details')['headers'], '汇率', '手续费']));
        $base = array_fill_keys($headers, '模拟值');
        foreach ($service->importFields('credit_card') as $field => $label) {
            if (isset($service->moneyFields('credit_card')[$field]) || $field === 'settlement') {
                $base[$label] = $field === 'usd_rate' ? '1' : '0.5';
            }
        }
        $base = [...$base, '支付账户' => $account->account_name, '商户订单号' => 'CARD-FULL-MERCHANT', '平台订单号' => 'CARD-FULL-TXN',
            '创建时间' => '2026-11-06 12:00:00', '完成时间' => '2026-11-06 12:01:00', '年月' => '2026-11',
            '时间点' => '2026-11-06 12:00:00', '导出时区' => 'Asia/Shanghai', '交易币种' => 'USD', '交易金额' => '50',
            '订单币种' => 'USD', '订单金额' => '50', '支付状态' => 'SUCCESS', '订阅类型' => '订阅', '是否加购' => '否',
            '域名' => 'card-full.example.test', '使用人' => '信用卡使用人', '团队' => '信用卡团队', '通途订单' => 'CARD-FULL-ERP', '备注' => '信用卡全字段',
            '交易手续费' => '', '手续费' => '1.25'];
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([$headers, array_values($base), array_values($base)], null, 'A1');
        $request = ['account' => $account->activity_account, 'import_month' => '2026-11'];
        $this->post('/finance/reports/credit-settlement/import', [...$request, 'file' => $this->upload($book)])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($text) => str_contains($text, '已新增 1 条') && str_contains($text, '跳过重复 1 条'));
        $row = $service->rows('credit_card', [])->sole();
        foreach ($service->textFields('credit_card') as $field => $label) {
            $this->assertSame($base[$label], $row[$field], $field);
        }
        foreach ($service->importFields('credit_card') as $field => $label) {
            if (isset($service->moneyFields('credit_card')[$field]) || $field === 'settlement') {
                $this->assertEquals($field === 'fee' ? 1.25 : (float) $base[$label], (float) $row[$field], $field);
            }
        }
        $record = FinanceRecord::sole();
        $this->assertSame('模拟值', $record->payload['消费者邮箱']);
        $originalPayload = $record->payload;
        $originalSupplement = DB::table('finance_settlement_supplements')->value('payload');
        $base['使用人'] = '不能覆盖'; $base['交易金额'] = '999'; $base['交易手续费'] = '999';
        $fresh = $base; $fresh['平台订单号'] = 'CARD-FULL-NEW'; $fresh['商户订单号'] = 'CARD-FULL-NEW-MERCHANT';
        $book->getActiveSheet()->fromArray([array_values($base), array_values($fresh)], null, 'A2');
        $this->post('/finance/reports/credit-settlement/import', [...$request, 'file' => $this->upload($book)])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($text) => str_contains($text, '已新增 1 条') && str_contains($text, '跳过重复 1 条'));
        $this->assertSame($originalPayload, $record->fresh()->payload);
        $this->assertSame($originalSupplement, DB::table('finance_settlement_supplements')->where('finance_record_id', $record->id)->value('payload'));
        $this->assertDatabaseCount('finance_records', 2);
        $page = $this->get('/finance/reports/credit-settlement')->assertOk();
        foreach (['owner', 'team', 'subscription', 'upsell', 'erp_order', 'note', 'domain'] as $field) {
            $page->assertSee('id="edit-'.$field.'"', false);
        }
    }

    public function test_credit_import_allows_shared_platform_number_with_different_merchant_orders(): void
    {
        $account = $this->financePayPalAccount('组合匹配账户', ['account_type' => 'credit_card']);
        $headers = FinanceCatalog::dataset('xuzong2-details')['headers'];
        $base = [...array_fill_keys($headers, ''), '平台订单号' => 'SAME-PLATFORM', '创建时间' => '2026-11-02 12:00:00',
            '交易币种' => 'USD', '交易金额' => '10', '支付状态' => 'SUCCESS'];
        $input = [2 => [...$base, '商户订单号' => 'MERCHANT-A'], 3 => [...$base, '商户订单号' => 'MERCHANT-B']];
        $this->workbooks->importRecords('xuzong2-details', $input, $account->activity_account, 'UTC', 1);
        $this->workbooks->importRecords('xuzong2-details', $input, $account->activity_account, 'UTC', 1);
        $this->assertDatabaseCount('finance_records', 2);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $fields = array_values($service->importFields('credit_card'));
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('徐总支付导入模版');
        $sheet->fromArray($fields, null, 'A1');
        foreach (['MERCHANT-A' => '1.25', 'MERCHANT-B' => '2.50'] as $merchant => $fee) {
            $row = array_fill_keys($fields, '');
            $row['平台订单号'] = 'SAME-PLATFORM'; $row['商户订单号'] = $merchant; $row['交易手续费'] = $fee;
            $sheet->fromArray([array_values($row)], null, $merchant === 'MERCHANT-A' ? 'A2' : 'A3');
        }
        $this->post('/finance/reports/credit-settlement/import?'.http_build_query(['account' => $account->activity_account]), ['file' => $this->upload($book)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $rows = $service->rows('credit_card', []);
        foreach (['MERCHANT-A' => '1.25', 'MERCHANT-B' => '2.5'] as $merchant => $fee) {
            $record = FinanceRecord::where('payload->商户订单号', $merchant)->sole();
            $this->assertNull($rows->firstWhere('id', $record->id)['fee']);
        }
        $this->assertDatabaseCount('finance_records', 2);
    }

    public function test_credit_settlement_accepts_raw_template_and_keeps_failed_rows_and_export_timezone(): void
    {
        $account = $this->financePayPalAccount('模板账户', ['account_type' => 'credit_card']);
        $headers = array_slice(\App\Portals\Finance\FinanceCatalog::dataset('xuzong2-details')['headers'], 0, 16);
        $headers = [...$headers, '时间点', '导出时区', '结算金额', '应结算'];
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($headers, null, 'A1');
        foreach (['SUCCESS', 'FAILED'] as $index => $status) {
            $row = array_fill_keys($headers, '');
            $row = [...$row, '平台订单号' => 'TEMPLATE-'.$index, '商户订单号' => 'ORDER-'.$index,
                '交易币种' => 'HKD', '交易金额' => '80', '支付状态' => $status,
                '创建时间' => '2026-11-01 08:30:00', '时间点' => '2026-10-31 16:30:00', '导出时区' => '-8',
                '结算金额' => $index === 0 ? '10' : '', '应结算' => '=1+2'];
            $book->getActiveSheet()->fromArray([array_values($row)], null, 'A'.($index + 2));
        }
        foreach ([1, 2] as $pass) {
            $this->post('/finance/reports/credit-settlement/import', ['file' => $this->upload($book),
                'account' => $account->activity_account])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('finance_records', 2);
        $rows = app(\App\Portals\Finance\FinanceSettlementService::class)->rows('credit_card', [])->keyBy('reference');
        $success = $rows['TEMPLATE-0'];
        $this->assertSame('2026-10', $success['month']);
        $this->assertSame('-08:00', $success['timezone']);
        $converted = app(\App\Portals\Finance\FinanceSettlementService::class)->rows('credit_card', ['timezone' => 'Asia/Shanghai'])->keyBy('reference')['TEMPLATE-0'];
        $this->assertSame('2026-11-01 08:30:00', $converted['date']);
        $this->assertSame('Asia/Shanghai', $converted['timezone']);
        $this->assertEquals(80, $success['source_settlement_usd']);
        $this->assertEquals(10, $success['settlement']);
        $this->assertStringContainsString('HKD', $success['currency_warning']);
        $this->assertNull($rows['TEMPLATE-1']['source_settlement_usd']);
        $this->assertNull($rows['TEMPLATE-1']['settlement']);
    }

    public function test_paypal_new_import_reads_conversion_and_rate_columns(): void
    {
        $account = $this->financePayPalAccount('换汇账户');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['支付账户', '交易 ID', '日期', '时间', '时区', '类型', '状态', '货币', '总额', '费用', '净额', '转换美元', '汇率'],
            [$account->account_name, 'NEW-HKD', '2026-11-06', '04:00:00', 'UTC', 'PayPal Checkout付款', 'S', 'HKD', '80', '-8', '72', '9', '0.125'],
        ], null, 'A1');
        $this->post('/finance/reports/paypal-settlement/import', ['file' => $this->upload($book)])->assertSessionHasNoErrors();
        $row = app(\App\Portals\Finance\FinanceSettlementService::class)->rows('paypal', [])->sole();
        $this->assertEquals(9, $row['converted_usd']);
        $this->assertEquals(0.125, $row['usd_rate']);
        $this->assertEquals(10, $row['settlement']);
        $this->assertDatabaseCount('finance_records', 1);
    }

    public function test_actual_credit_source_file_can_be_imported_twice_without_duplicate_records(): void
    {
        $path = getenv('FINANCE_CREDIT_IMPORT_CHECK_FILE');
        if (! $path || ! is_file($path)) {
            $this->markTestSkipped('Run with an explicit local statement to verify its import.');
        }
        $account = $this->financePayPalAccount('文件验证账户', ['account_type' => 'credit_card']);
        $count = null;
        foreach ([1, 2] as $pass) {
            $file = new UploadedFile($path, 'statement.xlsx', null, null, true);
            $this->post('/finance/reports/credit-settlement/import', ['file' => $file,
                'account' => $account->activity_account])
                ->assertRedirect()->assertSessionHasNoErrors();
            $actual = FinanceRecord::count();
            $this->assertGreaterThan(0, $actual);
            if ($count !== null) {
                $this->assertSame($count, $actual);
            }
            $count = $actual;
        }
        fwrite(STDERR, 'Verified statement: '.$count.' distinct records; repeat import is idempotent.'.PHP_EOL);
    }

    public function test_manual_credit_template_derives_month_from_order_time(): void
    {
        $account = $this->financePayPalAccount('手动账户', ['account_type' => 'credit_card']);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $headers = [...array_values($service->importFields('credit_card')), '交易时间', '交易币种', '支付状态', '年月'];
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('徐总支付导入模版');
        $sheet->fromArray($headers, null, 'A1');
        foreach (['A' => '9.5', 'B' => '19.5'] as $merchant => $amount) {
            $row = array_fill_keys($headers, '');
            $row['商户订单号'] = $merchant; $row['平台订单号'] = 'SHARED'; $row['结算金额'] = $amount;
            $row['交易币种'] = 'USD'; $row['支付状态'] = 'SUCCESS'; $row['年月'] = '2026-12';
            $sheet->fromArray([array_values($row)], null, $merchant === 'A' ? 'A2' : 'A3');
        }
        $this->post('/finance/reports/credit-settlement/import', ['file' => $this->upload($book),
            'account' => $account->activity_account, 'import_month' => '2026-12'])
            ->assertSessionHasErrors(['file' => '第2行缺少订单时间，请填写交易时间或创建时间后再导入。']);
        $this->assertDatabaseCount('finance_records', 0);
        $dateColumn = array_search('交易时间', $headers, true) + 1;
        $sheet->setCellValue([$dateColumn, 2], '2026-11-04 12:00:00');
        $sheet->setCellValue([$dateColumn, 3], '2026-11-05 12:00:00');
        foreach ([1, 2] as $attempt) {
            $this->post('/finance/reports/credit-settlement/import', ['file' => $this->upload($book),
                'account' => $account->activity_account])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseCount('finance_records', 2);
        }
        $rows = $service->rows('credit_card', []);
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($row) => $row['date'] !== '' && $row['month'] === '2026-11'));
        $this->assertEqualsCanonicalizing(['9.5', '19.5'], $rows->pluck('settlement')->all());
        $url = '/finance/reports/summary?view=transactions&channel=credit_card';
        $this->get($url)->assertOk()->assertViewHas('creditMonths', ['2026-11'])
            ->assertViewHas('items', fn ($items) => count($items) === 1 && $items[0]['settlement'] === '29.00000000');
        $this->get($url.'&month=2026-12')->assertOk()->assertViewHas('items', [])->assertSee('2026-11');
        $this->get($url.'&suggestion_field=settlement&suggestion_q=19')->assertOk()->assertJsonPath('0.value', '19.5');
        $filtered = $url.'&'.http_build_query(['conditions' => [['field' => 'settlement', 'operator' => 'equals', 'value' => '19.5']]]);
        $this->get($filtered)->assertOk()->assertViewHas('items', fn ($items) => count($items) === 1 && $items[0]['settlement'] === '19.50000000');
        $sheet = $this->readDownload($this->get($filtered.'&download=1')->assertOk()->streamedContent());
        $this->assertEquals(19.5, $sheet->getCell('H2')->getValue());
        $overview = '/finance/reports/summary?view=settlement&channel=credit_card&layer=project';
        $this->get($overview)->assertOk()->assertViewHas('creditMonths', ['2026-11'])
            ->assertViewHas('groups', fn ($groups) => count($groups) === 1
                && $groups[0]['channel'] === 'credit_card' && $groups[0]['settlement'] === '29.00000000');
        $this->get($overview.'&month=2026-12')->assertOk()->assertViewHas('groups', [])->assertSee('2026-11');
    }

    public function test_loss_report_fills_missing_costs_from_a_matched_logistics_order_only_once(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_id');
        });
        Schema::create('logistics_tongtu_sku_mappings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('product_guide_cost_cny');
            $table->decimal('goods_weight_gram');
        });
        Schema::create('logistics_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('logistics_order_id');
            $table->unsignedBigInteger('tongtu_sku_mapping_id');
            $table->decimal('quantity');
        });
        $row = [...$this->recordRow(), '日期' => '2026-10-01'];
        $this->workbooks->importRecords('paypal-orders', [2 => $row], 'A', 'UTC', 1);
        $this->workbooks->importRecords('paypal-orders', [2 => $row], 'B', 'UTC', 1);
        DB::table('logistics_orders')->insert(['id' => 1, 'transaction_id' => FinanceRecord::first()->reference]);
        DB::table('logistics_tongtu_sku_mappings')->insert(['id' => 1, 'product_guide_cost_cny' => 12, 'goods_weight_gram' => 250]);
        DB::table('logistics_order_items')->insert(['logistics_order_id' => 1, 'tongtu_sku_mapping_id' => 1, 'quantity' => 2]);
        DB::table('finance_monthly_exchange_rates')->insertOrIgnore(['month' => '2026-10', 'currency' => 'USD', 'cny_rate' => '6.7628']);

        $this->get('/finance/reports/losses?month=2026-10&channel=combined')->assertOk()
            ->assertViewHas('groups', fn ($groups) => count($groups) === 1
                && $groups[0]['purchase'] === '24.00000000' && $groups[0]['logistics'] === '60.00000000');
    }

    public function test_finance_export_selection_and_imported_upsell_labels(): void
    {
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '日期' => '2026-11-02', '交易 ID' => 'ADD-ON', '是否加购' => '已加购', '订阅类型' => '订阅'],
            3 => [...$this->recordRow(), '日期' => '2026-11-02', '交易 ID' => 'NORMAL', '是否加购' => '未加购', '订阅类型' => '订阅'],
        ], 'A', 'UTC', 1);
        $headers = FinanceCatalog::dataset('xuzong2-details')['headers'];
        $this->workbooks->importRecords('xuzong2-details', [2 => [...array_fill_keys($headers, ''), '平台订单号' => 'CARD-ADD', '创建时间' => '2026-11-02 12:00:00', '交易币种' => 'USD', '交易金额' => '35', '支付状态' => 'SUCCESS', '是否加购' => '已加购']], 'B', 'UTC', 1);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $paypalRows = $service->rows('paypal', ['from' => '2026-11-01', 'to' => '2026-11-30', 'logistics_costs' => true]);
        $this->assertSame('加购', $paypalRows->firstWhere('reference', 'ADD-ON')['subscription']);
        $this->assertSame('订阅', $paypalRows->firstWhere('reference', 'NORMAL')['subscription']);
        $url = '/finance/reports/summary?view=transactions&month=2026-11&channel=paypal';
        $response = $this->get($url)->assertOk()->assertSee('data-export-row', false)->assertSee('data-export-all', false);
        $item = collect($response->viewData('items'))->firstWhere('subscription', '加购');
        $selection = http_build_query(['selected_rows' => [\App\Portals\Finance\FinanceExportSelection::key($item)]]);
        $sheet = $this->readDownload($this->get($url.'&download=1&'.$selection)->assertOk()->streamedContent());
        $this->assertCount(2, $sheet->toArray());
        $this->assertSame('加购', $sheet->toArray()[1][3]);
        $lossUrl = '/finance/reports/losses?month_from=2026-11&month_to=2026-11';
        $loss = $this->get($lossUrl)->assertOk();
        foreach (['Shopify', 'PayPal', '信用卡'] as $scope) {
            $this->assertNotNull(collect($loss->viewData('groups'))->first(fn ($group) => $group['scope'] === $scope && $group['category'] === '加购'));
        }
        $panel = collect($loss->viewData('groups'))->first(fn ($group) => $group['scope'] === 'Shopify' && $group['category'] === '加购');
        $metricKey = hash('sha256', \App\Portals\Finance\FinanceExportSelection::key($panel).'|success_count');
        $sheet = $this->readDownload($this->get($lossUrl.'&download=1&'.http_build_query(['selected_rows' => [$metricKey]]))->assertOk()->streamedContent());
        $this->assertSame('Shopify / 加购', $sheet->toArray()[1][0]);
        $this->assertSame('成功订单数', $sheet->toArray()[2][0]);
        $this->assertLessThanOrEqual(4, count($sheet->toArray()));
        $detailKey = \App\Portals\Finance\FinanceExportSelection::key($paypalRows->firstWhere('reference', 'ADD-ON'));
        $detail = $this->readDownload($this->get('/finance/reports/paypal-settlement?from=2026-11-01&to=2026-11-30&download=1&'.http_build_query(['selected_rows' => [$detailKey]]))->assertOk()->streamedContent());
        $this->assertCount(2, $detail->toArray());
        $summaryUrl = '/finance/reports/summary?month=2026-11&layer=subscription';
        $summaryResponse = $this->get($summaryUrl)->assertOk();
        $group = collect($summaryResponse->viewData('groups'))->firstWhere('name', '加购');
        $summarySheet = $this->readDownload($this->get($summaryUrl.'&download=1&'.http_build_query(['selected_rows' => [\App\Portals\Finance\FinanceExportSelection::key($group)]]))->assertOk()->streamedContent());
        $this->assertCount(2, $summarySheet->toArray());
        $this->assertSame('加购', $summarySheet->toArray()[1][0]);
        $this->get($url.'&download=1&'.http_build_query(['selected_rows' => [str_repeat('a', 64)]]))->assertSessionHasErrors('selected_rows');
        $type = $this->workbooks->typeSettings()->first();
        $typeSheet = $this->readDownload($this->get('/finance/settings/types/export?'.http_build_query(['selected_rows' => [\App\Portals\Finance\FinanceExportSelection::key(['raw_type' => $type->raw_type])]]))->assertOk()->streamedContent());
        $this->assertCount(2, $typeSheet->toArray());
        $this->assertSame($type->raw_type, $typeSheet->toArray()[1][0]);

    }

    public function test_transaction_summary_paginates_groups_preserves_filters_and_exports_all_groups(): void
    {
        $rows = [];
        for ($index = 1; $index <= 25; $index++) {
            $rows[$index + 1] = [...$this->recordRow(), '日期' => '2026-11-02', '交易 ID' => 'PAGE-'.$index, '订阅类型' => '订阅复购'.$index.'次'];
        }
        $this->workbooks->importRecords('paypal-orders', $rows, 'A', 'UTC', 1);
        $url = '/finance/reports/summary?view=transactions&month=2026-11&channel=paypal&currency=USD&per_page=10';
        $first = $this->get($url)->assertOk()->assertSee('aria-label="分页"', false);
        $this->assertCount(10, $first->viewData('items'));
        $this->assertSame(25, $first->viewData('paginator')->total());
        $nextUrl = $first->viewData('paginator')->nextPageUrl();
        parse_str(parse_url($nextUrl, PHP_URL_QUERY), $query);
        $this->assertSame('transactions', $query['view']);
        $this->assertSame('USD', $query['currency']);
        $this->assertSame('2026-11', $query['month']);
        $second = $this->get($nextUrl)->assertOk();
        $this->assertCount(10, $second->viewData('items'));
        $this->assertEmpty(array_intersect(array_column($first->viewData('items'), 'subscription'), array_column($second->viewData('items'), 'subscription')));
        $last = $this->get($url.'&page=3')->assertOk();
        $this->assertCount(5, $last->viewData('items'));
        parse_str(parse_url($last->viewData('tabUrls')['combined'], PHP_URL_QUERY), $tabQuery);
        $this->assertArrayNotHasKey('page', $tabQuery);
        $larger = $this->get(str_replace('per_page=10', 'per_page=20', $url))->assertOk();
        $this->assertCount(20, $larger->viewData('items'));
        $filtered = $this->get($url.'&subscription='.urlencode('订阅复购1次'))->assertOk();
        $this->assertSame(1, $filtered->viewData('paginator')->total());
        $sheet = $this->readDownload($this->get($url.'&page=3&download=1')->assertOk()->streamedContent());
        $this->assertCount(26, $sheet->toArray());
        $this->get($url.'&page=0')->assertSessionHasErrors('page');
    }

    public function test_settlement_uses_explicit_account_ownership_after_legacy_name_changes(): void
    {
        $account = $this->financePayPalAccount('Current account', ['status' => 'disabled']);
        foreach (['Old short name', 'Old full name'] as $index => $name) {
            $this->workbooks->importRecords('paypal-orders', [
                2 => [...$this->recordRow(), '日期' => '2026-11-02', '交易 ID' => 'ALIAS-'.$index],
            ], $name, 'UTC', 1);
            $record = FinanceRecord::query()->where('account', $name)->sole();
            DB::table('finance_payment_transactions')->insert([
                'payment_account_id' => $account->id, 'finance_record_id' => $record->id,
                'transaction_id' => $record->reference, 'event_code' => 'T0006', 'raw_payload' => '{}',
            ]);
        }
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $rows = $service->rows('paypal', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertCount(2, $rows);
        $this->assertSame(['Current account'], $rows->pluck('account')->unique()->values()->all());
        $this->assertSame(['Current account'], $rows->pluck('account_key')->unique()->values()->all());
        $response = $this->get('/finance/reports/summary?view=settlement&month=2026-11&channel=paypal&layer=account')->assertOk();
        $this->assertCount(1, $response->viewData('groups'));
        $this->assertSame(2, $response->viewData('groups')[0]['success_count']);
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?export_scope=filtered&export_format=xlsx')->assertOk()->streamedContent());
        $exported = $sheet->toArray();
        $column = array_search(__('finance.fields.paypal_account'), $exported[0], true);
        $this->assertNotFalse($column);
        $this->assertSame('Current account', $exported[1][$column]);
        $this->assertSame('Current account', $exported[2][$column]);
        $this->artisan('finance:audit-account-history')->assertSuccessful();
    }

    public function test_reports_exclude_deleted_account_history_but_keep_disabled_and_reused_accounts(): void
    {
        $deleted = $this->financePayPalAccount('Deleted legacy');
        $disabled = $this->financePayPalAccount('Disabled history', ['status' => 'disabled']);
        foreach ([$deleted, $disabled] as $account) {
            $this->workbooks->importRecords('paypal-orders', [
                2 => [...$this->recordRow(), '日期' => '2026-11-02', '交易 ID' => 'LEGACY-'.$account->id],
            ], $account->activity_account, 'UTC', 1);
        }
        $deleted->delete(); // Reproduce the old soft-delete-only behavior.
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $filters = ['from' => '2026-11-01', 'to' => '2026-11-30'];
        $this->assertSame(['Disabled history'], $service->rows('paypal', $filters)->pluck('account')->all());
        $url = '/finance/reports/summary?view=settlement&month=2026-11&channel=paypal&layer=account';
        $response = $this->get($url)->assertOk();
        $this->assertSame(['Disabled history'], collect($response->viewData('groups'))->pluck('name')->all());
        $sheet = $this->readDownload($this->get($url.'&download=1')->assertOk()->streamedContent());
        $this->assertStringNotContainsString('Deleted legacy', json_encode($sheet->toArray()));
        $export = $this->get('/finance/records/paypal-orders/export?export_scope=filtered&export_format=csv')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Deleted legacy', $export);
        $this->assertStringContainsString('Disabled history', $export);
        $this->assertDatabaseCount('finance_records', 2);

        // Explicitly linked deleted history must stay excluded after the name is reused.
        $old = FinanceRecord::query()->where('account', $deleted->activity_account)->sole();
        DB::table('finance_payment_transactions')->insert([
            'payment_account_id' => $deleted->id, 'finance_record_id' => $old->id,
            'transaction_id' => $old->reference, 'event_code' => 'T0006', 'raw_payload' => '{}',
        ]);
        $replacement = $this->financePayPalAccount('Deleted legacy');
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '日期' => '2026-11-02', '交易 ID' => 'REPLACEMENT'],
        ], $replacement->activity_account, 'UTC', 1);
        $rows = $service->rows('paypal', $filters);
        $this->assertCount(2, $rows);
        $this->assertFalse($rows->contains('reference', $old->reference));
        $this->assertTrue($rows->contains('reference', 'REPLACEMENT'));
    }

    public function test_settlement_channel_labels_and_grouping_match_selected_filters(): void
    {
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '日期' => '2026-11-02', '交易 ID' => 'CHANNEL-TEST'],
        ], 'Account A', 'UTC', 1);
        $base = '/finance/reports/summary?view=settlement&month=2026-11';
        $all = $this->get($base.'&layer=project')->assertOk();
        $this->assertSame('Shopify', $all->viewData('groups')[0]['name']);
        $this->assertSame('combined', $all->viewData('groups')[0]['channel']);
        $combined = $this->get($base.'&channel=combined&layer=account')->assertOk();
        $this->assertSame('Account A', $combined->viewData('groups')[0]['name']);
        $this->assertSame('account', $combined->viewData('filters')['layer']);
        $combined->assertDontSee('value="combined"', false);
        foreach (['paypal', 'credit_card'] as $channel) {
            $project = $this->get($base.'&channel='.$channel.'&layer=project')->assertOk();
            $this->assertSame('project', $project->viewData('filters')['layer']);
            if ($channel === 'paypal') {
                $this->assertSame('Shopify', $project->viewData('groups')[0]['name']);
                $this->assertSame('paypal', $project->viewData('groups')[0]['channel']);
            }
        }
        $this->get($base.'&channel=credit_card&layer=account')->assertOk()->assertViewHas('groups', []);
        $sheet = $this->readDownload($this->get($base.'&layer=project&download=1')->assertOk()->streamedContent());
        $this->assertSame('Shopify', $sheet->toArray()[1][0]);
    }

    public function test_settlement_project_filter_preserves_channel_and_month_in_results_and_export(): void
    {
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '日期' => '2026-11-02', '交易 ID' => 'NOV-PAYPAL'],
            3 => [...$this->recordRow(), '日期' => '2026-10-02', '交易 ID' => 'OCT-PAYPAL'],
            4 => [...$this->recordRow(), '日期' => '2026-12-02', '交易 ID' => 'DEC-PAYPAL'],
        ], 'A', 'UTC', 1);
        $headers = FinanceCatalog::dataset('xuzong2-details')['headers'];
        $this->workbooks->importRecords('xuzong2-details', [2 => [...array_fill_keys($headers, ''),
            '平台订单号' => 'NOV-CARD', '创建时间' => '2026-11-02 12:00:00', '交易币种' => 'USD', '交易金额' => '35', '支付状态' => 'SUCCESS']], 'B', 'UTC', 1);
        $url = '/finance/reports/summary?view=settlement&month=2026-11&channel=paypal&layer=project';
        $response = $this->get($url)->assertOk();
        $group = $response->viewData('groups')[0];
        $this->assertCount(1, $response->viewData('groups'));
        $this->assertSame('Shopify', $group['name']);
        $this->assertSame('paypal', $group['channel']);
        $this->assertSame(1, $group['success_count']);
        $this->assertSame('10.00000000', $group['settlement']);
        $sheet = $this->readDownload($this->get($url.'&download=1')->assertOk()->streamedContent());
        $this->assertEquals(['Shopify', 'PayPal', 1, 0, 10], $sheet->toArray()[1]);
        $combined = $this->get('/finance/reports/summary?view=settlement&month=2026-11&channel=combined&layer=project')->assertOk();
        $this->assertSame(2, $combined->viewData('groups')[0]['success_count']);
        $this->assertSame('45.00000000', $combined->viewData('groups')[0]['settlement']);
    }

    public function test_loss_workbook_sum_ignores_blank_costs_and_filters_subscription_groups(): void
    {
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '日期' => '2026-10-01', '費用' => '-0.5', '订阅类型' => '订阅'],
            3 => [...$this->recordRow(), '日期' => '2026-10-02', '交易 ID' => 'SECOND', '費用' => '-0.2', '订阅类型' => '无订阅'],
        ], 'A', 'UTC', 1);
        DB::table('finance_monthly_exchange_rates')->insertOrIgnore(['month' => '2026-10', 'currency' => 'USD', 'cny_rate' => '6.7628']);
        $this->get('/finance/reports/losses?month=2026-10&layer=project')->assertOk()
            ->assertViewHas('groups', fn ($groups) => count($groups) === 1 && $groups[0]['success_count'] === 2
                && $groups[0]['refund'] === '0' && $groups[0]['ratios']['fee'] === '3.50000000'
                && $groups[0]['total_ratio'] === '3.50000000');
        $this->get('/finance/reports/losses?'.http_build_query(['month' => '2026-10', 'subscription' => '订阅', 'layer' => 'subscription']))
            ->assertOk()->assertViewHas('groups', fn ($groups) => count($groups) === 1 && $groups[0]['name'] === '订阅'
                && $groups[0]['success_count'] === 1 && $groups[0]['ratios']['fee'] === '5.00000000');
        $this->assertDatabaseHas('finance_monthly_exchange_rates', ['month' => '2026-10', 'currency' => 'USD', 'cny_rate' => '6.7628']);
    }

    public function test_manual_credit_classification_uses_unique_logistics_order_metadata(): void
    {
        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->id(); $table->string('domain'); $table->string('user_name'); $table->text('team');
        });
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id(); $table->integer('assistant_domain_store_id'); $table->string('transaction_id');
            $table->string('source_shop_domain'); $table->string('subscription_type'); $table->string('is_add_on'); $table->string('tongtu_order_number');
        });
        DB::table('assistant_domain_stores')->insert(['id' => 1, 'domain' => 'formal.com', 'user_name' => '运营A', 'team' => '["厦门总部"]']);
        $order = ['assistant_domain_store_id' => 1, 'transaction_id' => 'MERCHANT-REF', 'source_shop_domain' => 'internal.myshopify.com',
            'subscription_type' => '订阅首次购买', 'is_add_on' => '否', 'tongtu_order_number' => 'ERP-TEST'];
        DB::table('logistics_orders')->insert($order);
        $headers = FinanceCatalog::dataset('xuzong2-details')['headers'];
        $this->workbooks->importRecords('xuzong2-details', [2 => [...array_fill_keys($headers, ''), '商户订单号' => 'MERCHANT-REF',
            '平台订单号' => 'PLATFORM', '创建时间' => '2026-10-02 12:00:00', '交易币种' => 'USD', '交易金额' => '10', '支付状态' => 'SUCCESS']], 'B', 'UTC', 1);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $row = $service->rows('credit_card', [])->first();
        $this->assertSame('formal.com', $row['domain']);
        $this->assertSame('订阅', $row['subscription']);
        $this->assertSame('运营A', $row['owner']);
        $this->assertSame('厦门总部', $row['team']);
        DB::table('logistics_orders')->update(['is_add_on' => '已加购']);
        $this->assertSame('加购', $service->rows('credit_card', [])->first()['subscription']);

        DB::table('logistics_orders')->insert($order);
        $this->assertSame('', $service->rows('credit_card', [])->first()['domain']);
    }

    public function test_report_queries_and_reset_preserve_the_selected_tab(): void
    {
        $account = $this->financePayPalAccount('tab账户');
        $settlement = $this->get('/finance/reports/summary?'.http_build_query([
            'view' => 'settlement', 'month' => '2026-10', 'channel' => 'paypal', 'account' => $account->activity_account,
        ]))->assertOk();
        $tabs = $settlement->viewData('reportTabUrls');
        $this->assertStringContainsString('payment_account_id='.$account->id, $tabs['transactions']);
        $switched = $this->get($tabs['transactions'])->assertOk()->assertViewIs('portals.finance.report');
        $this->assertSame('2026-10', $switched->viewData('filters')['month']);
        $this->assertSame('paypal', $switched->viewData('filters')['channel']);
        $this->get($switched->viewData('reportTabUrls')['settlement'])->assertOk()
            ->assertViewIs('portals.finance.settlement-overview')->assertViewHas('filters', fn ($filters) => $filters['account'] === $account->activity_account);
        $transaction = $this->get('/finance/reports/summary?view=transactions&month=2026-10')->assertOk()
            ->assertViewIs('portals.finance.report')->assertSee('name="view" value="transactions"', false);
        $document = new \DOMDocument;
        @$document->loadHTML($transaction->getContent());
        $xpath = new \DOMXPath($document);
        $reset = $xpath->query('//a[normalize-space(text())="重置"]')->item(0)->getAttribute('href');
        $this->assertStringContainsString('view=transactions', $reset);
        $this->get($reset)->assertOk()->assertViewIs('portals.finance.report');
        $this->get('/finance/reports/summary?view=transactions&month=2026-09&channel=paypal')->assertOk()->assertViewIs('portals.finance.report');
        $this->get('/finance/reports/summary?view=settlement&month=2026-10')->assertOk()
            ->assertViewIs('portals.finance.settlement-overview')->assertSee('name="view" value="settlement"', false);
        $this->get('/finance/reports/losses?month=2026-10')->assertOk()->assertViewHas('report', 'losses')
            ->assertDontSee('aria-label="汇总明细视图"', false);
    }

    public function test_transaction_summary_uses_each_channel_template_for_display_and_export(): void
    {
        foreach (['paypal' => 21, 'credit_card' => 23, 'combined' => 24] as $channel => $count) {
            $url = '/finance/reports/summary?view=transactions&month=2026-10&channel='.$channel;
            $response = $this->get($url)->assertOk()->assertViewHas('tab', $channel);
            $columns = $response->viewData('columns');
            $this->assertCount($count, $columns);
            $this->assertSame('手续费', $columns['fee']);
            $this->assertSame('货币', $columns['currency']);
            $this->assertSame($channel !== 'credit_card', isset($columns['dispute_hold']));
            $sheet = $this->readDownload($this->get($url.'&download=1')->assertOk()->streamedContent());
            $this->assertSame(array_values($columns), $sheet->toArray()[0]);
        }

        $this->get('/finance/reports/summary?channel=combined')->assertOk()
            ->assertViewHas('filters', fn ($filters) => $filters['layer'] === 'project');
        $this->get('/finance/reports/losses?channel=combined')->assertOk()
            ->assertViewHas('filters', fn ($filters) => $filters['layer'] === 'project_classification');
    }

    public function test_paypal_new_import_rolls_back_invalid_new_rows(): void
    {
        $account = $this->financePayPalAccount('事务验证');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['支付账户', '交易 ID', '日期', '时间', '时区', '类型', '状态', '货币', '总额', '费用', '净额', '备注'],
            [$account->account_name, 'ATOMIC-VALID', '2026-11-06', '04:00:00', 'UTC', 'T0006', 'S', 'USD', '21', '-1', '20', '有效'],
            [$account->account_name, 'ATOMIC-INVALID', 'bad-date', '04:00:00', 'UTC', 'T0006', 'S', 'USD', '21', '-1', '20', '无效'],
        ], null, 'A1');
        $this->post('/finance/reports/paypal-settlement/import', ['file' => $this->upload($book)])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('finance_records', 0);
        $this->assertDatabaseCount('finance_settlement_supplements', 0);
    }

    public function test_credit_settlement_keeps_failed_attempt_fees_and_excludes_failed_sales(): void
    {
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $headers = FinanceCatalog::dataset('xuzong2-details')['headers'];
        $row = [...array_fill_keys($headers, ''), '平台订单号' => 'CARD-SUCCESS', '创建时间' => '2026-11-02 12:00:00', '交易币种' => 'USD', '交易金额' => '10', '支付状态' => 'SUCCESS'];
        $this->workbooks->importRecords('xuzong2-details', [2 => $row, 3 => [...$row, '平台订单号' => 'CARD-FAILED', '支付状态' => 'FAIL']], '信用卡账户', 'UTC', 1);
        foreach (FinanceRecord::all() as $record) {
            $values = array_fill_keys(array_keys($service->moneyFields('credit_card')), '0');
            $service->save($record, [...$values, 'usd_rate' => '1', 'per_order_fee' => '0.3'], 1);
        }
        $rows = $service->rows('credit_card', []);
        $this->assertCount(2, $rows);
        $failed = $rows->firstWhere('reference', 'CARD-FAILED');
        $this->assertNull($failed['settlement']);
        $this->assertSame('0.3', $failed['per_order_fee']);
        $businessRows = $rows->values()->map(function ($row, $index) {
            $row['team'] = $row['success'] ? '刷单' : '正常团队';
            if ($row['success']) { $row['channel'] = 'paypal'; }
            return $row;
        });
        $businessGroups = collect($service->totals($businessRows, 'business'))->keyBy('name');
        $this->assertSame(1, $businessGroups['PayPal / 刷单']['success_count']);
        $this->assertSame(1, $businessGroups['Shopify']['success_count']);
        $this->assertSame(1, $businessGroups['Shopify / 刷单']['success_count']);
        $this->assertFalse($businessGroups->contains(fn ($group) => $group['scope'] === '刷单'));
        $this->assertSame(1, $businessGroups['Shopify']['failed_count']);
        $monthly = $this->get('/finance/reports/losses?month=2026-11')->assertOk();
        $this->assertSame(['2026-09', '2026-10', '2026-11'], array_keys($monthly->viewData('monthlyLossGroups')));
        $monthly->assertSee('loss-month-panel', false)->assertSee('2026-09');
        $range = $this->get('/finance/reports/losses?month_from=2026-07&month_to=2026-11')->assertOk();
        $this->assertSame(['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], array_keys($range->viewData('monthlyLossGroups')));
        $range->assertSee('name="month_from"', false)->assertSee('name="month_to"', false);
        $singleMonth = $this->get('/finance/reports/losses?month_from=2026-11&month_to=2026-11')->assertOk();
        $this->assertSame(['2026-11'], array_keys($singleMonth->viewData('monthlyLossGroups')));
        $this->get('/finance/reports/losses?month_from=2026-12&month_to=2026-11')->assertSessionHasErrors('month_to');
        $rangeSheet = $this->readDownload($this->get('/finance/reports/losses?month_from=2026-09&month_to=2026-11&download=1')->assertOk()->streamedContent());
        $this->assertSame(['项目 / 指标', '2026-09', '2026-10', '2026-11'], $rangeSheet->toArray()[0]);

        $legacy = $this->get('/finance/reports/losses?month=2026-11&layer=classification&loss_scope=Shopify&loss_category=总计')->assertOk();
        $this->assertSame('business', $legacy->viewData('filters')['layer']);
        $legacyDocument = new \DOMDocument;
        @$legacyDocument->loadHTML($legacy->getContent());
        $legacyXpath = new \DOMXPath($legacyDocument);
        $visibleTables = $legacyXpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " loss-month-panel ")]/div/table');
        $this->assertSame(1, $visibleTables->length);
        $this->assertSame('Shopify', $legacyXpath->query('.//th', $visibleTables->item(0))->item(1)->textContent);
        $this->assertSame(1, collect($legacy->viewData('groups'))->firstWhere('name', 'Shopify')['success_count']);
        $channelView = $this->get('/finance/reports/losses?month=2026-11&layer=channel&loss_scope=Shopify')->assertOk();
        $channelDocument = new \DOMDocument;
        @$channelDocument->loadHTML($channelView->getContent());
        $channelXpath = new \DOMXPath($channelDocument);
        $this->assertSame(0, $channelXpath->query('//nav[@aria-label="损耗报表项目"]/a[normalize-space(.)="Shopify"]')->length);
        $this->assertSame(1, $channelXpath->query('//nav[@aria-label="损耗报表项目"]/a[@aria-current="page" and normalize-space(.)="信用卡"]')->length);

        $classifiedRows = $businessRows->map(fn ($row) => [...$row, 'team' => '正常团队', 'subscription' => $row['success'] ? '订阅复购2次' : '']);
        $classified = collect($service->totals($classifiedRows, 'business'));
        $this->assertTrue($classified->contains(fn ($group) => $group['scope'] === 'Shopify' && $group['category'] === '订阅复购2次'));
        $this->assertFalse($classified->contains(fn ($group) => $group['category'] === '未分类'));
        $switched = $this->get('/finance/reports/losses?month=2026-11&loss_scope=信用卡&loss_category=总计')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($switched->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " loss-month-panel ")]')->length);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="损耗报表项目"]/a[@aria-current="page" and normalize-space(.)="信用卡"]')->length);
        $payload = json_decode($xpath->query('//script[@data-loss-panels]')->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($payload);
        $categoryOptions = json_decode($xpath->query('//script[@data-loss-categories]')->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotContains('刷单', $categoryOptions['信用卡']);
        $this->assertNotContains('未分类', $categoryOptions['Shopify']);
        $this->assertContains('订阅复购1次', $categoryOptions['信用卡']);
        $this->assertContains('订阅复购2次', $categoryOptions['信用卡']);

        foreach ($payload as $cachedPanel) {
            $this->assertSame(1, $xpath->query('//template[@id="'.$cachedPanel['template'].'"]')->length);
        }
        $switched->assertSee('finance-loss-switcher.js', false);

        $this->assertStringContainsString('month=2026-11', $xpath->query('//nav[@aria-label="损耗报表项目"]/a')->item(0)->getAttribute('href'));

        $total = $service->totals($rows, 'channel')[0];
        $this->assertSame('10.00000000', $total['settlement']);
        $this->assertSame('0.60000000', $total['per_order_fee']);
        $this->assertArrayNotHasKey('receivable', $total);
        $this->get('/finance/reports/credit-settlement')->assertOk();
        $this->get('/finance/reports/summary')->assertOk()->assertSee('结算汇总');
        $this->get('/finance/reports/losses?month=2026-11&download=1')->assertOk();
        $orders = [...array_fill_keys(FinanceCatalog::dataset('xuzong2-orders')['headers'], ''),
            '交易单号' => 'CARD-SUCCESS', '创建时间' => '2026-11-02 12:00:00', '交易币种' => 'USD', '交易金额' => '10', '支付状态' => 'SUCCESS'];
        $this->workbooks->importRecords('xuzong2-orders', [2 => $orders, 3 => [...$orders, '支付状态' => 'FAIL']], '信用卡账户', 'UTC', 1);
        $this->assertCount(3, $service->rows('credit_card', []));
    }

    public function test_credit_statement_import_is_idempotent_and_closed_month_rolls_back(): void
    {
        $account = $this->financePayPalAccount('信用卡账单账户', ['account_type' => 'credit_card']);
        $headers = FinanceCatalog::dataset('xuzong2-details')['headers'];
        $row = [...array_fill_keys($headers, ''), '平台订单号' => 'STATEMENT-1', '创建时间' => '2026-11-02 12:00:00', '交易币种' => 'USD', '交易金额' => '10', '支付状态' => 'FAIL', '单笔费用' => '0.3'];
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([$headers, array_values($row)], null, 'A1');
        $data = ['import_kind' => 'source', 'source' => 'xuzong2-details', 'account' => $account->activity_account, 'timezone' => 'UTC'];
        for ($i = 0; $i < 2; $i++) {
            $this->post('/finance/reports/credit-settlement/import', [...$data, 'file' => $this->upload($book)])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('finance_records', 1);
        $db = $this->workbooks->database();
        $db->table('finance_period_locks')->where('month', '2026-11')->update(['report_snapshot' => '[]']);
        $row['单笔费用'] = '9';
        $book->getActiveSheet()->fromArray([array_values($row)], null, 'A2');
        $this->post('/finance/reports/credit-settlement/import', [...$data, 'file' => $this->upload($book)])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($text) => str_contains($text, '跳过重复 1 条'));
        $this->assertSame('0.3', FinanceRecord::sole()->payload['单笔费用']);
    }

    public function test_installation_is_idempotent_and_preserves_administrator_changes(): void
    {
        DB::table('finance_domain_groups')->insert(['domain' => 'shopyayashi.com', 'owner' => 'Changed', 'team' => 'Test']);
        DB::table('portal_menus')->where('code', 'finance_orders')->update(['sort' => 777]);
        (require database_path('migrations/2026_11_03_000002_install_finance_portal.php'))->up();
        $this->assertDatabaseCount('portals', 1);
        $this->assertDatabaseCount('portal_menus', 18);
        $this->assertDatabaseCount('finance_transaction_types', 22);
        $this->assertDatabaseCount('finance_domain_groups', 1);
        $this->assertDatabaseCount('finance_records', 0);
        $this->assertDatabaseHas('finance_domain_groups', ['domain' => 'shopyayashi.com', 'owner' => 'Changed']);
        $this->assertDatabaseHas('portal_menus', ['code' => 'finance_orders', 'sort' => 777]);
    }

    public function test_all_finance_pages_render_with_source_headers_and_honest_report_status(): void
    {
        foreach (['/finance', '/finance/settings/types', '/finance/settings/domains', '/finance/reports/summary', '/finance/reports/losses'] as $path) {
            $this->get($path)->assertOk();
        }
        foreach (FinanceCatalog::datasets() as $key => $definition) {
            $this->get('/finance/records/'.$key)->assertOk()->assertSee($definition['headers'][0]);
        }
        $this->get('/finance/reports/losses')->assertSee('统计口径');
    }

    public function test_main_domain_entry_urls_and_domain_boundaries(): void
    {
        config(['portals.main_domain' => 'www.kky.com', 'portals.root_domain' => 'kky.com', 'portals.scheme' => 'http']);
        $domains = app(PortalDomainService::class);
        $this->assertSame('caiwu', $domains->portalForRequest(Request::create('http://www.kky.com/finance'))->code);
        $this->assertSame('caiwu', $domains->portalForRequest(Request::create('http://www.kky.com/finance/settings/types'))->code);
        $this->assertSame('caiwu', $domains->portalForRequest(Request::create('http://caiwu.kky.com/'))->code);
        $this->assertNull($domains->portalForRequest(Request::create('http://www.kky.com/finance-other')));
        $this->assertNull($domains->portalForRequest(Request::create('http://www.kky.com/admin')));
        $this->assertNull($domains->portalForRequest(Request::create('http://unknown.example/finance')));
        $this->assertSame('http://caiwu.kky.com/', $domains->portalUrl('caiwu'));
        $this->assertSame('http://caiwu.kky.com/finance/settings/types?q=PayPal', $domains->portalUrl('caiwu', '/finance/settings/types?q=PayPal'));
        $this->assertSame('http://caiwu.kky.com/finance/management/menus', $domains->portalUrl('caiwu', '/management/menus'));
        $this->assertSame('http://ppay.kky.com/account-risk', $domains->portalUrl('ppay', '/account-risk'));
    }

    public function test_finance_subdomain_redirects_keep_queries_and_do_not_redirect_writes(): void
    {
        config(['portals.main_domain' => 'www.kky.com', 'portals.root_domain' => 'kky.com', 'portals.scheme' => 'http']);
        $this->get('http://www.kky.com/finance?balance_tab=credit-card&paypal_currency=CNY')
            ->assertRedirect('http://caiwu.kky.com/?balance_tab=credit-card&paypal_currency=CNY');
        $this->get('http://www.kky.com/finance/records/paypal-orders?account=Example')
            ->assertRedirect('http://caiwu.kky.com/finance/records/paypal-orders?account=Example');
        $this->post('http://www.kky.com/finance/payment-accounts', [])->assertSessionHasErrors(['account_name'], null, 'paymentAccount');
        $this->get('http://caiwu.kky.com/finance')->assertOk()->assertViewIs('portals.finance.home');
        DB::table('portal_menus')->where('code', 'finance_home')->update(['is_enabled' => false]);
        $this->app->forgetInstance(PortalEntryController::class);
        Admin::guard()->setUser($this->user);
        $this->get('http://caiwu.kky.com/')->assertForbidden();
    }

    public function test_credit_order_page_reads_live_logistics_orders_for_filters_and_exports(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            foreach (['collection_account', 'transaction_id', 'payment_gateway_order_id', 'bill_number', 'shopify_order_number',
                'source_shop_domain', 'store_name', 'paid_amount_usd', 'payment_status', 'payment_method', 'payment_gateway',
                'tongtu_order_number', 'subscription_type', 'is_add_on', 'order_created_at', 'paid_at', 'shopify_payload', 'buyer_email'] as $field) {
                $table->text($field)->nullable();
            }
        });
        $id = DB::table('logistics_orders')->insertGetId([
            'collection_account' => 'SHANGYUN-v2', 'transaction_id' => 'LIVE-CARD-1', 'payment_gateway' => 'FintrixV2',
            'payment_gateway_order_id' => '8851033722824594636885',
            'shopify_order_number' => '#1001', 'store_name' => '物流店铺', 'source_shop_domain' => 'example.com',
            'paid_amount_usd' => '12.50', 'payment_status' => 'paid', 'payment_method' => 'card', 'buyer_email' => 'buyer@example.com',
            'order_created_at' => '2026-11-03 17:00:00', 'paid_at' => '2026-11-03 17:01:00',
            'shopify_payload' => json_encode(['tags' => ['CreditCardByTest'], 'currency' => 'EUR', 'total_price' => '11.00']),
        ]);
        DB::table('logistics_orders')->insert(['transaction_id' => 'PAYPAL-EXCLUDED', 'payment_gateway' => 'PayPal', 'shopify_payload' => '{"tags":["PayPal"]}']);
        $query = ['account' => 'SHANGYUN-v2', 'from' => '2026-11-04', 'to' => '2026-11-04', 'timezone' => 'Asia/Shanghai'];
        $this->get('/finance/records/xuzong2-orders?'.http_build_query($query))->assertOk()
            ->assertSee('LIVE-CARD-1')->assertSee('物流店铺')->assertDontSee('PAYPAL-EXCLUDED')
            ->assertViewHas('records', fn ($rows) => $rows->total() === 1 && $rows->first()->payload['店铺币种'] === 'EUR')
            ->assertViewHas('accounts', fn ($accounts) => $accounts->contains('SHANGYUN-v2'));
        $this->get('/finance/records/xuzong2-orders?'.http_build_query([...$query, 'timezone' => 'UTC']))->assertOk()
            ->assertViewHas('records', fn ($rows) => $rows->total() === 0);
        $this->get('/finance/records/xuzong2-orders?'.http_build_query([
            ...$query, 'suggestion_field' => 'reference', 'suggestion_q' => 'LIVE',
        ]))->assertOk()->assertExactJson([['value' => 'LIVE-CARD-1', 'label' => 'LIVE-CARD-1']]);
        $this->get('/finance/records/xuzong2-orders')->assertOk()
            ->assertDontSee('id="finance-select-page"', false)->assertDontSee('data-export-record', false)
            ->assertDontSee('<select class="form-control" data-filter-operator', false);
        $filtered = [...$query, 'conditions' => [['field' => 'column_7', 'operator' => 'gte', 'value' => '12']]];
        $this->get('/finance/records/xuzong2-orders?'.http_build_query($filtered))->assertOk()
            ->assertViewHas('records', fn ($rows) => $rows->total() === 1);
        DB::table('logistics_orders')->where('id', $id)->update(['paid_amount_usd' => '15.00']);
        DB::table('logistics_orders')->where('id', $id)->update(['shopify_payload' => json_encode([
            'tags' => ['CreditCardByTest'], 'currentTotalPriceSet' => ['shopMoney' => ['currencyCode' => 'USD', 'amount' => '15.00']],
            'customAttributes' => [['key' => 'yx_order_id', 'value' => 'MERCHANT-ORDER-1']],
            'transactions' => [
                ['status' => 'FAILURE', 'kind' => 'SALE', 'amountSet' => ['shopMoney' => ['currencyCode' => 'USD', 'amount' => '99.00']]],
                ['status' => 'SUCCESS', 'kind' => 'AUTHORIZATION', 'amountSet' => ['shopMoney' => ['currencyCode' => 'USD', 'amount' => '98.00']]],
                ['status' => 'SUCCESS', 'kind' => 'CAPTURE', 'amountSet' => ['shopMoney' => ['currencyCode' => 'USD', 'amount' => '14.75']]],
            ],
        ])]);
        $this->get('/finance/records/xuzong2-orders')->assertOk()->assertViewHas('records', fn ($rows) =>
            $rows->first()->payload['店铺币种'] === 'USD' && $rows->first()->payload['店铺订单金额'] === '15.00');
        $sheet = $this->readDownload($this->get('/finance/records/xuzong2-orders/export?'.http_build_query([
            ...$query, 'export_scope' => 'records', 'selected_ids' => [$id],
        ]))->assertOk()->streamedContent());
        $this->assertSame('LIVE-CARD-1', $sheet->getCell('D2')->getValue());
        $this->assertEquals(15, $sheet->getCell('H2')->getValue());
        DB::table('logistics_orders')->where('id', $id)->update([
            'tongtu_order_number' => 'ERP-CARD', 'subscription_type' => '订阅', 'is_add_on' => '1',
            'bill_number' => 'BILL-CARD',
        ]);
        $pageExport = $this->readDownload($this->get('/finance/records/xuzong2-orders/export?'.http_build_query([
            ...$query, 'export_scope' => 'records', 'selected_ids' => [$id], 'export_layout' => 'page',
        ]))->assertOk()->streamedContent())->toArray();
        $this->assertSame(\App\Portals\Finance\FinancePayPalOrderExport::pageHeaders('xuzong2-orders'), $pageExport[0]);
        $this->assertNotContains('余额', $pageExport[0]);
        $this->assertCount(count($pageExport[0]), $pageExport[1]);
        foreach (['交易号' => 'LIVE-CARD-1', 'Shopify订单号' => '#1001', '通途订单号' => 'ERP-CARD',
            '账单号' => 'BILL-CARD', '店铺名称' => '物流店铺', '店铺域名' => 'example.com',
            '订阅类型' => '订阅', '加购' => '加购', '收款账号' => 'SHANGYUN-v2',
            '入库时间' => '', '下单时间' => '2026-11-04 01:00:00', '付款时间' => '2026-11-04 01:01:00'] as $header => $expected) {
            $this->assertSame($expected, $pageExport[1][array_search($header, $pageExport[0], true)], $header);
        }
        $this->assertEquals(15, (float) preg_replace('/[^0-9.]/', '', (string) $pageExport[1][array_search('实付金额', $pageExport[0], true)]));
        $this->get('/finance/records/xuzong2-orders')->assertOk()->assertSee('name="export_layout" value="page"', false);

        $migration = require database_path('migrations/2026_11_10_000003_add_xuzong_credit_orders_report_menu.php');
        $migration->up();
        $migration->up();
        $report = '/finance/reports/xuzong-credit';
        $this->assertDatabaseHas('portal_menus', [
            'code' => 'finance_xuzong-credit', 'title' => '徐总信用卡', 'path' => $report,
            'parent_id' => DB::table('portal_menus')->where('code', 'finance_reports')->value('id'),
        ]);
        $this->get($report.'?'.http_build_query($query))->assertOk()->assertSee('徐总信用卡')
            ->assertViewHas('records', fn ($rows) => $rows->total() === 0);
        $this->get($report.'?order_detail='.$id)->assertNotFound();
        DB::table('logistics_orders')->where('id', $id)->update(['bill_number' => 'TXN26011000001']);

        $csv = "商户名称,商户订单号,交易 ID,货币,总额,平台返回code,状态,是否 3D,创建时间\n"
            .",MANUAL-ORDER,TXN26011000001,USD,18.25,,SUCCESS,YES,2026-11-03 17:00:00\n"
            .",MANUAL-FAILED,TXN-UNLINKED-FAIL,USD,9.50,50005,FAILED,NO,2026-11-03 17:01:00\n"
            ."SHANGYUN,MANUAL-LEGACY,LEGACY-GATEWAY,USD,3.00,,SUCCESS,NO,2026-11-03 17:01:00\n";
        $uploadOrders = fn (array $headers = []) => $this->from($report)->post($report.'/import', [
            'file' => UploadedFile::fake()->createWithContent('fintrix.csv', $csv),
        ], $headers);
        $uploadOrders()->assertRedirect($report)->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, '关联到系统订单 1 条'));
        $uploadOrders(['Accept' => 'application/json'])->assertOk()->assertExactJson(['total' => 3, 'matched' => 1, 'saved' => 0]);
        $importedOrder = FinanceRecord::where('dataset', \App\Portals\Finance\FinanceLogisticsCreditOrders::IMPORTED_DATASET)
            ->where('reference', 'TXN26011000001')->sole();
        $this->assertSame('UTC', $importedOrder->source_timezone);
        $this->assertSame('2026-11-03 17:00:00', $importedOrder->occurred_at->utc()->toDateTimeString());
        $this->assertSame(['SHANGYUN', 'SHANGYUN-v2'], FinanceRecord::where('dataset',
            \App\Portals\Finance\FinanceLogisticsCreditOrders::IMPORTED_DATASET)->distinct()->orderBy('account')->pluck('account')->all());
        $this->get($report.'?reference=8851033722824594636885')->assertOk()
            ->assertViewHas('xuzongRows', fn ($rows) => $rows->count() === 1 && $rows->first()['交易流水号'] === 'TXN26011000001'
                && $rows->first()['交易金额'] === '18.25' && $rows->first()['域名'] === 'example.com');
        $this->get($report.'?reference=TXN-UNLINKED-FAIL')->assertOk()
            ->assertSee('平台返回结果')->assertSee('卡号无效')->assertDontSee('平台返回code')->assertDontSee('平台返回message')
            ->assertViewHas('xuzongRows', fn ($rows) => $rows->count() === 1 && $rows->first()['支付状态'] === '失败'
                && $rows->first()['备注'] === '失败；系统无匹配订单' && $rows->first()['平台订单号'] === '');
        $this->get($report.'/export?export_scope=records&selected_ids[]='.$id)
            ->assertRedirect()->assertSessionHasErrors('selected_ids');
        $importedSheet = $this->readDownload($this->get($report.'/export?reference=8851033722824594636885&export_scope=filtered')
            ->assertOk()->streamedContent());
        $this->assertSame('徐总支付2明细表', $importedSheet->getTitle());
        $this->assertSame('A1:AZ2', $importedSheet->getAutoFilter()->getRange());
        $this->assertSame('MANUAL-ORDER', $importedSheet->getCell('B2')->getValue());
        $this->assertEquals(18.25, $importedSheet->getCell('H2')->getValue());
        $this->assertSame('成功', $importedSheet->getCell('I2')->getValue());
        $this->assertSame('ERP-CARD', $importedSheet->getCell('AW2')->getValue());
        $resultExport = $this->readDownload($this->get($report.'/export?reference=TXN-UNLINKED-FAIL&export_scope=filtered&report_columns[]=53')
            ->assertOk()->streamedContent());
        $this->assertSame([['平台返回结果'], ['卡号无效']], $resultExport->toArray());
        $template = $this->readDownload($this->get($report.'/export?export_scope=filtered&export_template=1&report_columns[]=53&report_columns[]=52')
            ->assertOk()->streamedContent());
        $this->assertSame([['平台返回结果', '原始支付状态']], $template->toArray());
        $this->postJson($report.'/export-template/preview', ['header_row' => 1, 'file' => $this->upload($template->getParent())])
            ->assertOk()->assertJsonPath('fields', [53, 52]);
        $refundCsv = "退款单号,平台订单号,商户订单号,退款金额,退款币种,退款处理费,退款完成时间,状态\n"
            ."REFUND-1,TXN-UNLINKED-FAIL,MANUAL-FAILED,2.50,USD,1.00,2026-11-04 01:10:17,SUCCESS\n"
            ."REFUND-2,TXN-UNLINKED-FAIL,MANUAL-FAILED,7.00,USD,1.00,,REJECTED\n";
        $uploadRefunds = fn () => $this->post($report.'/import', [
            'file' => UploadedFile::fake()->createWithContent('refunds.csv', $refundCsv),
        ], ['Accept' => 'application/json']);
        $uploadRefunds()->assertOk()->assertExactJson(['total' => 2, 'matched' => 1, 'saved' => 1]);
        $uploadRefunds()->assertOk()->assertExactJson(['total' => 2, 'matched' => 1, 'saved' => 0]);
        $this->get($report.'?reference=TXN-UNLINKED-FAIL')->assertOk()
            ->assertViewHas('xuzongRows', fn ($rows) => $rows->sole()['退款金额'] === '2.50000000');
        $statement = "平台订单号,商户订单号,结算币种,适用汇率,折算后结算贡献,结算时间,订单时间,交易本金,退款本金,拒付本金,拒付处理费,保证金扣除,保证金返还,拒付返还,交易处理费,交易手续费,退款处理费,争议处理费,Ethoca,RDR,CDRN\n"
            ."TXN-UNLINKED-FAIL,MANUAL-FAILED,USD,1.000000,-1.00,2026-11-04 01:10:17,-,0,0,0,0,0,0,0,-1,0,0,0,0,0,0\n";
        $uploadStatement = fn () => $this->post($report.'/import', [
            'file' => UploadedFile::fake()->createWithContent('statement.csv', $statement),
        ], ['Accept' => 'application/json']);
        $uploadStatement()->assertOk()->assertExactJson(['total' => 1, 'matched' => 1, 'saved' => 1]);
        $uploadStatement()->assertOk()->assertExactJson(['total' => 1, 'matched' => 1, 'saved' => 0]);
        $this->get($report.'?reference=TXN-UNLINKED-FAIL')->assertOk()
            ->assertViewHas('xuzongRows', fn ($rows) => $rows->sole()['应结算（USD）'] === '-1.00');
        $original = explode("\n", trim($statement));
        $later = "TXN-UNLINKED-FAIL,MANUAL-FAILED,USD,1.000000,-2.50,2026-11-05 01:10:17,-,0,-2.50,0,0,0,0,0,0,0,0,0,0,0,0";
        $overlapping = implode("\n", [...$original, $later])."\n";
        $append = fn () => $this->post($report.'/import', [
            'file' => UploadedFile::fake()->createWithContent('overlapping.csv', $overlapping),
        ], ['Accept' => 'application/json']);
        $append()->assertOk()->assertExactJson(['total' => 2, 'matched' => 1, 'saved' => 1]);
        $append()->assertOk()->assertExactJson(['total' => 2, 'matched' => 1, 'saved' => 0]);
        $this->get($report.'?reference=TXN-UNLINKED-FAIL')->assertOk()
            ->assertViewHas('xuzongRows', fn ($rows) => $rows->sole()['应结算（USD）'] === '-3.50');
        $workbook = $this->readDownload($this->get($report.'/export?reference=TXN-UNLINKED-FAIL&export_scope=filtered')
            ->assertOk()->streamedContent())->getParent();
        $statementSheet = $workbook->getSheetByName('结算变动明细');
        $this->assertNotNull($statementSheet);
        $this->assertSame([['2026-11-04 01:10:17'], ['2026-11-05 01:10:17']], $statementSheet->rangeToArray('D2:D3'));
        $this->assertSame([[-1], [-2.5]], $statementSheet->rangeToArray('U2:U3', formatData: false));
        $statementOrder = FinanceRecord::where('reference', 'TXN-UNLINKED-FAIL')->sole();
        $statementPath = $report.'/statement/'.$statementOrder->id;
        $this->get($report)->assertOk()->assertSee('待处理结算变动')->assertSee($statementPath);
        $this->get($statementPath)->assertOk()->assertSee('TXN-UNLINKED-FAIL')->assertSee('2026-11-05 01:10:17');
        $this->get($statementPath, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('保存修改')->assertDontSee('返回徐总信用卡');
        $this->assertNotEmpty($statementOrder->refresh()->payload['_xuzong_statement_import']['notice']);
        $time = '2026-11-06 01:10:17';
        $this->post($statementPath, ['action' => 'add', 'time' => $time, 'amounts' => ['应结算' => '1.50']])
            ->assertRedirect($statementPath)->assertSessionHasNoErrors();
        $this->assertSame('-2.00', $statementOrder->refresh()->payload['应结算']);
        $eventKey = array_search($time, array_map(fn ($event) => $event['time'], $statementOrder->payload['_xuzong_statement_import']['events']), true);
        $this->post($statementPath, ['action' => 'update', 'event' => $eventKey, 'time' => $time, 'amounts' => ['应结算' => '2.00']])
            ->assertRedirect($statementPath)->assertSessionHasNoErrors();
        $this->assertSame('-1.50', $statementOrder->refresh()->payload['应结算']);
        $eventKey = array_search($time, array_map(fn ($event) => $event['time'], $statementOrder->payload['_xuzong_statement_import']['events']), true);
        $this->post($statementPath, ['action' => 'delete', 'event' => $eventKey])->assertRedirect($statementPath)->assertSessionHasNoErrors();
        $this->assertSame('-3.50', $statementOrder->refresh()->payload['应结算']);
        $notice = $statementOrder->payload['_xuzong_statement_import']['notice'];
        $this->postJson($statementPath, ['action' => 'edit_order', 'order_fields' => [
            '商户名称' => '修正商户', '平台订单号' => 'CORRECTED-ID',
            '创建时间' => '2026-11-03 17:01:00', '交易币种' => 'USD', '交易金额' => '9.51', '支付状态' => '失败',
        ], 'report_fields' => ['汇率' => '1.25', '订阅类型' => '订阅复购1次', '备注' => '财务复核']])->assertOk()->assertJsonPath('handled', false);
        $this->get($report.'?reference=TXN-UNLINKED-FAIL')->assertOk()
            ->assertViewHas('xuzongRows', fn ($rows) => $rows->sole()['商户名称'] === '修正商户'
                && $rows->sole()['平台订单号'] === 'CORRECTED-ID' && $rows->sole()['交易金额'] === '9.51'
                && $rows->sole()['汇率'] === '1.25');
        $this->assertSame($notice, $statementOrder->refresh()->payload['_xuzong_statement_import']['notice']);
        $this->get($report.'?'.http_build_query(['reference' => 'CORRECTED-ID', 'order_fields' => ['subscription_type' => '订阅复购1次']]))
            ->assertOk()->assertViewHas('xuzongRows', fn ($rows) => $rows->count() === 1
                && $rows->sole()['备注'] === '失败；财务复核；系统无匹配订单');
        $this->post($statementPath, ['action' => 'handled', 'notice' => $notice])->assertRedirect($report);
        $append()->assertOk()->assertExactJson(['total' => 2, 'matched' => 1, 'saved' => 0]);
        $this->get($report)->assertOk()->assertDontSee('待处理结算变动');
        $this->get($report.'?reference=LEGACY-GATEWAY&order_fields[status]='.urlencode('成功'))->assertOk()
            ->assertViewHas('xuzongRows', fn ($rows) => $rows->count() === 1 && $rows->first()['支付状态'] === 'SUCCESS'
                && $rows->first()['状态'] === '成功');
        $lateOrder = (array) DB::table('logistics_orders')->where('id', $id)->first();
        unset($lateOrder['id']);
        $lateOrder['transaction_id'] = 'MANUAL-FAILED';
        $lateOrder['bill_number'] = 'TXN-UNLINKED-FAIL';
        $lateOrder['payment_gateway_order_id'] = 'LATE-PLATFORM-ID';
        $lateOrder['source_shop_domain'] = 'late.example.com';
        $lateOrder['tongtu_order_number'] = 'LATE-TONGTU';
        $lateId = DB::table('logistics_orders')->insertGetId($lateOrder);
        $this->get($report.'?reference=TXN-UNLINKED-FAIL')->assertOk()
            ->assertViewHas('xuzongRows', fn ($rows) => $rows->sole()['域名'] === 'late.example.com'
                && $rows->sole()['备注'] === '失败；财务复核' && $rows->sole()['原始支付状态'] === 'FAILED');
        $lateExport = $this->readDownload($this->get($report.'/export?reference=TXN-UNLINKED-FAIL&export_scope=filtered')
            ->assertOk()->streamedContent())->toArray();
        $this->assertSame('late.example.com', $lateExport[1][array_search('域名', $lateExport[0], true)]);
        $this->assertSame('FAILED', $lateExport[1][array_search('原始支付状态', $lateExport[0], true)]);
        DB::table('logistics_orders')->where('id', $lateId)->delete();
        FinanceRecord::where('dataset', \App\Portals\Finance\FinanceLogisticsCreditOrders::IMPORTED_DATASET)->delete();

        $migration->down();
        $this->get($report)->assertForbidden();

        $this->assertDatabaseCount('finance_records', 0);
        DB::table('logistics_orders')->where('id', $id)->delete();
        $this->get('/finance/records/xuzong2-orders')->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 0);
        DB::table('logistics_orders')->insert([
            'transaction_id' => 'OTHER-GATEWAY', 'payment_gateway' => 'FintrixV2', 'collection_account' => '其他渠道',
            'order_created_at' => '2026-11-03 17:00:00', 'shopify_payload' => '{}',
        ]);
        $this->get('/finance/records/xuzong2-orders')->assertOk()->assertSee('更多筛选')->assertSee('paypal-order-check-all', false)
            ->assertViewHas('records', fn ($rows) => $rows->total() === 1 && $rows->first()->reference === 'OTHER-GATEWAY');
    }

    public function test_shopify_failed_credit_payment_is_excluded_from_imported_finance_report(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            foreach (['collection_account', 'transaction_id', 'payment_gateway_order_id', 'bill_number', 'shopify_order_number',
                'source_shop_domain', 'store_name', 'paid_amount_usd', 'payment_status', 'payment_method', 'payment_gateway',
                'tongtu_order_number', 'subscription_type', 'is_add_on', 'order_created_at', 'paid_at', 'shopify_payload',
                'shopify_order_id', 'buyer_email'] as $field) {
                $table->text($field)->nullable();
            }
        });
        $connection = new \App\Models\LogisticsShopifyConnection([
            'shop_domain' => 'test.myshopify.com', 'status' => 'token_ready', 'access_token' => 'test-token',
            'token_expires_at' => now()->addHour(), 'webhook_secret' => 'test-secret',
        ]);
        $connection->syncOriginal()->setRelation('store', new \App\Models\AssistantDomainStore([
            'shop_login_url' => 'test.myshopify.com', 'shopify_client_id' => 'test-id', 'shopify_client_secret' => 'test-secret',
        ]));
        $order = [
            'legacyResourceId' => '123', 'name' => '#101', 'createdAt' => '2026-11-03T17:00:00Z',
            'displayFinancialStatus' => 'PENDING',
            'customAttributes' => [
                ['key' => 'yx_payment_gateway', 'value' => 'FintrixV2'],
                ['key' => 'yx_payment_alias', 'value' => 'SHANGYUN-v2'],
                ['key' => 'yx_payment_gateway_order_id', 'value' => 'CARD-FAIL-1'],
            ],
            'transactions' => [['kind' => 'AUTHORIZATION', 'status' => 'FAILURE', 'gateway' => 'FintrixV2']],
        ];
        Http::fake(fn () => Http::response(['data' => ['order' => $order]]));
        $sync = app(\App\Portals\Logistics\ShopifyOrderSyncService::class);
        $this->assertNull($sync->syncWebhookOrder($connection, ['id' => '123'], 'orders/create'));
        $this->assertNull($sync->syncWebhookOrder($connection, [
            'order_id' => '123', 'kind' => 'authorization', 'status' => 'failure', 'gateway' => 'FintrixV2',
        ], 'order_transactions/create'));
        $this->assertDatabaseCount('logistics_orders', 0);
        $failed = FinanceRecord::query()->where('dataset', 'xuzong-credit-failed-orders')->sole();
        $key = -$failed->id;

        (require database_path('migrations/2026_11_10_000003_add_xuzong_credit_orders_report_menu.php'))->up();
        $url = '/finance/reports/xuzong-credit';
        $filters = http_build_query(['order_fields' => ['status' => '失败', 'shopify_order_number' => '101']]);
        $this->get($url.'?'.$filters)->assertOk()
            ->assertViewHas('records', fn ($rows) => $rows->total() === 0);
        $this->get($url.'?order_detail='.$key)->assertNotFound();
        $this->get('/finance/records/xuzong2-orders')->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 0);

        DB::table('logistics_orders')->insert([
            'shopify_order_id' => '123', 'source_shop_domain' => 'test.myshopify.com', 'payment_status' => '已付款',
            'payment_gateway' => 'FintrixV2', 'paid_at' => '2026-11-03 17:01:00', 'shopify_payload' => '{}',
        ]);
        $this->get($url.'?'.$filters)->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 0);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('徐总支付2明细表');
        $headers = [...FinanceCatalog::dataset('xuzong2-details')['headers'], '交易流水号'];
        $bill = ['商户名称' => 'SHANGYUN-v2', '商户订单号' => 'CARD-BILL-ONLY', '支付状态' => 'DECLINED',
            '创建时间' => '2026-11-04 17:00:00', '交易流水号' => 'TXN26110400001', '单笔费用' => '0.35'];
        $sheet->fromArray([$headers, array_map(fn ($header) => $bill[$header] ?? '', $headers)]);
        $this->from($url)->post($url.'/import', [
            'file' => $this->upload($book),
        ])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame('SHANGYUN-v2', FinanceRecord::where('dataset', 'xuzong-credit-source-2')->sole()->account);
        $only = $url.'?account=SHANGYUN-v2&order_fields[status]='.urlencode('失败');
        $this->get($only)->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 0);
    }

    public function test_order_pages_disable_imports_and_templates_while_details_keep_them(): void
    {
        foreach (['paypal-orders', 'xuzong2-orders'] as $dataset) {
            $this->get('/finance/records/'.$dataset)->assertOk()
                ->assertDontSee('id="import-file"', false)
                ->assertDontSee('/finance/records/'.$dataset.'/import', false)
                ->assertDontSee('template=1', false);
            $this->post('/finance/records/'.$dataset.'/import')->assertNotFound();
            $this->get('/finance/records/'.$dataset.'/export?template=1')->assertNotFound();
        }
        $this->assertDatabaseCount('finance_records', 0);
        foreach (['paypal-details', 'xuzong2-details', 'xuzong1-details'] as $dataset) {
            $this->get('/finance/records/'.$dataset)->assertOk()
                ->assertSee('id="import-file"', false)
                ->assertSee('/finance/records/'.$dataset.'/import', false)
                ->assertSee('template=1', false);
            $this->get('/finance/records/'.$dataset.'/export?template=1')->assertOk();
        }
    }

    public function test_main_domain_entry_uses_real_login_and_finance_permissions_and_navigation(): void
    {
        config(['portals.main_domain' => 'www.kky.com', 'portals.root_domain' => 'kky.com', 'portals.scheme' => 'http']);
        $this->app->forgetInstance(PortalEntryController::class);
        $this->get('http://www.kky.com/finance/settings/types')->assertRedirect('http://caiwu.kky.com/finance/settings/types');
        $this->get('http://caiwu.kky.com/finance/settings/types')->assertOk()->assertViewIs('portal.notice')->assertViewHas('noticeType', 'login');
        $request = Request::create('http://www.kky.com/admin/auth/login');
        $request->setLaravelSession($this->app['session']->driver());
        $this->assertSame('http://caiwu.kky.com/finance/settings/types', app(PortalReturnUrlService::class)->pull($request));
        Admin::guard()->setUser($this->user);
        $this->get('http://www.kky.com/finance')->assertRedirect('http://caiwu.kky.com/');
        $this->get('http://caiwu.kky.com/')->assertOk()->assertViewIs('portals.finance.home')
            ->assertSee('href="http://caiwu.kky.com/finance"', false)
            ->assertSee('href="http://caiwu.kky.com/finance/management/menus"', false);
        $menuPage = $this->get('http://caiwu.kky.com/finance/management/menus')->assertOk()
            ->assertSee(json_encode('/finance/management/menus/order'), false);
        $document = new \DOMDocument();
        @$document->loadHTML($menuPage->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//li[contains(concat(" ", normalize-space(@class), " "), " menu-open ")]/ul/li/a[@href="http://caiwu.kky.com/finance/management/menus"]')->length);
        $this->user->view = false;
        $this->get('http://caiwu.kky.com/finance/settings/types')->assertForbidden();
    }

    public function test_reimport_updates_data_and_preserves_distinct_currency_and_failed_attempts(): void
    {
        $row = $this->recordRow();
        $this->workbooks->importRecords('paypal-orders', [2 => $row], 'A', '-08:00', 1);
        $row['總額'] = '99.10';
        $this->workbooks->importRecords('paypal-orders', [2 => $row], 'A', '-08:00', 1);
        $this->assertDatabaseCount('finance_records', 1);
        $record = FinanceRecord::first();
        $this->assertSame('0000123', $record->reference);
        $this->assertSame('99.10', $record->payload['總額']);
        $this->assertSame('2026-09-09 07:59:59', $record->occurred_at->format('Y-m-d H:i:s'));
        $row['貨幣'] = 'EUR';
        $this->workbooks->importRecords('paypal-orders', [2 => $row], 'A', '-08:00', 1);
        $row['狀態'] = 'Fail';
        $this->workbooks->importRecords('paypal-orders', [2 => $row], 'A', '-08:00', 1);
        $this->assertDatabaseCount('finance_records', 3);
    }

    public function test_invalid_record_batch_is_atomic_and_unknown_types_are_registered(): void
    {
        $valid = $this->recordRow();
        $valid['類型'] = 'New transaction type';
        $invalid = $valid;
        $invalid['日期'] = '2026-02-30';
        try {
            $this->workbooks->importRecords('paypal-orders', [2 => $valid, 3 => $invalid], 'A', 'UTC', 1);
            $this->fail('Invalid batch accepted');
        } catch (ValidationException $e) {
            $this->assertDatabaseCount('finance_records', 0);
            $this->assertDatabaseMissing('finance_transaction_types', ['raw_type' => 'New transaction type']);
        }
        $this->workbooks->importRecords('paypal-orders', [2 => $valid], 'A', 'UTC', 1);
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'New transaction type', 'category' => null]);
    }

    public function test_timezone_filter_has_exclusive_next_day_boundary_and_export_matches(): void
    {
        $first = $this->recordRow();
        $second = $first;
        $second['交易 ID'] = 'boundary';
        $second['日期'] = '2026-09-09';
        $second['時間'] = '00:00:00';
        $this->workbooks->importRecords('paypal-orders', [2 => $first, 3 => $second], 'A', '-04:00', 1);
        $query = http_build_query(['from' => '2026-09-08', 'to' => '2026-09-08', 'timezone' => '-04:00']);
        $this->get('/finance/records/paypal-orders?'.$query)->assertOk()
            ->assertViewHas('records', fn ($rows) => $rows->total() === 1)
            ->assertSee('value="-04:00" selected', false)->assertSeeText(['UTC-04:00', '美国东部时间', 'UTC+05:45']);
        $record = FinanceRecord::where('reference', '0000123')->sole();
        $this->get('/finance/records/paypal-orders?order_detail='.$record->id.'&timezone=-04:00')
            ->assertOk()->assertSeeText('UTC-04:00');
        foreach (['paypal-settlement', 'credit-settlement'] as $report) {
            $this->get('/finance/reports/'.$report.'?'.$query)->assertOk()
                ->assertSee('value="-04:00" selected', false)->assertSeeText('UTC+05:45');
        }
        $this->get('/finance/records/paypal-orders?to=2026-09-08')->assertOk();
        $response = $this->get('/finance/records/paypal-orders/export?'.$query.'&'.http_build_query(['export_scope' => 'accounts', 'selected_accounts' => ['A']]))->assertOk();
        $sheet = $this->readDownload($response->streamedContent());
        $this->assertSame(2, $sheet->getHighestDataRow());
        $this->assertSame('0000123', $sheet->getCell('L2')->getValue());
        $this->assertSame('结账付款', $sheet->getCell('AQ2')->getValue());
        $pageExport = $this->get('/finance/records/paypal-orders/export?'.$query.'&export_scope=filtered&export_layout=page')->assertOk();
        [$headers, $values] = $this->readDownload($pageExport->streamedContent())->toArray();
        $this->assertSame('2026-09-08 23:59:59', $values[array_search('交易时间', $headers, true)]);
        $this->assertSame('UTC-04:00', $values[array_search('查看时区', $headers, true)]);
    }

    public function test_paypal_report_scope_category_and_extra_filters_are_applied_to_query_and_export(): void
    {
        $payment = [...$this->recordRow(), '餘額影響' => 'Credit', '寄件人電郵地址' => 'buyer@example.com', '帳單號碼' => 'INV-1'];
        $refund = [...$payment, '交易 ID' => 'refund-id', '類型' => '付款退款', '餘額影響' => 'Debit'];
        $memo = [...$payment, '交易 ID' => 'memo-id', '類型' => 'New memo type', '餘額影響' => 'Memo'];
        $this->workbooks->importRecords('paypal-orders', [2 => $payment, 3 => $refund, 4 => $memo], '尚云', '-07:00', 1);
        $this->workbooks->importRecords('paypal-orders', [2 => $payment], '立鉑', '-07:00', 1);
        $filters = [
            'account' => '尚云', 'from' => '2026-09-08', 'to' => '2026-09-08', 'timezone' => '-07:00',
            'transaction_scope' => 'balance_affecting',
            'conditions' => [
                ['field' => 'category', 'operator' => 'equals', 'value' => '结账付款'],
                ['field' => 'sender_email', 'operator' => 'contains', 'value' => '@example.com'],
                ['field' => 'invoice_number', 'operator' => 'not_empty'],
            ],
        ];
        $this->get('/finance/records/paypal-orders?'.http_build_query($filters))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1 && $rows->first()->reference === '0000123');
        $response = $this->get('/finance/records/paypal-orders/export?'.http_build_query([...$filters, 'export_scope' => 'accounts', 'selected_accounts' => ['尚云']]))->assertOk();
        $sheet = $this->readDownload($response->streamedContent());
        $this->assertSame(2, $sheet->getHighestDataRow());
        $this->assertSame('PayPal Checkout付款', $sheet->getCell('E2')->getValue());
        $this->assertSame('结账付款', $sheet->getCell('AQ2')->getValue());
        DB::table('finance_transaction_types')->where('raw_type', 'PayPal Checkout付款')->update(['category' => '收款']);
        $this->get('/finance/records/paypal-orders?'.http_build_query($filters))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 0);
        $filters['conditions'][0]['value'] = '收款';
        $this->get('/finance/records/paypal-orders?'.http_build_query($filters))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1);
        $this->get('/finance/records/paypal-orders?'.http_build_query(['account' => '尚云', 'transaction_scope' => 'non_balance_affecting']))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1 && $rows->first()->reference === 'memo-id');
        $this->get('/finance/records/paypal-orders?'.http_build_query(['conditions' => [['field' => 'category', 'operator' => 'empty']]]))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1 && $rows->first()->reference === 'memo-id');
        $this->get('/finance/records/paypal-orders?'.http_build_query(['account' => '尚云', 'conditions' => [['field' => 'raw_type', 'operator' => 'not_equals', 'value' => '付款退款']]]))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 2);
        $this->getJson('/finance/records/paypal-orders?'.http_build_query(['conditions' => [['field' => 'payload->untrusted', 'value' => 'X']]]))->assertUnprocessable();
    }

    public function test_paypal_report_supports_csv_and_tsv_exports_with_classification_and_safe_literal_values(): void
    {
        $row = [...$this->recordRow(), '姓名' => '=HYPERLINK("unsafe")', '費用' => '-0.30', '類型' => 'New export type', '餘額影響' => 'Credit'];
        $this->workbooks->importRecords('paypal-orders', [2 => $row], '尚云', '-07:00', 1);
        foreach (['csv' => ',', 'tsv' => "\t"] as $format => $delimiter) {
            $response = $this->get('/finance/records/paypal-orders/export?'.http_build_query(['export_format' => $format, 'transaction_scope' => 'balance_affecting', 'export_scope' => 'accounts', 'selected_accounts' => ['尚云']]))->assertOk();
            $content = $response->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, substr($content, 3));
            rewind($stream);
            $headers = fgetcsv($stream, 0, $delimiter, '"', '');
            $values = fgetcsv($stream, 0, $delimiter, '"', '');
            $this->assertSame('交易分类', $headers[42]);
            $this->assertSame('0000123', $values[11]);
            $this->assertSame('待分类', $values[42]);
            $this->assertSame('New export type', $values[4]);
            $this->assertSame('-0.30', $values[8]);
            $this->assertSame("'=HYPERLINK(\"unsafe\")", $values[3]);
            $this->assertFalse(fgetcsv($stream, 0, $delimiter, '"', ''));
            fclose($stream);
        }
    }

    public function test_activity_report_uses_selected_columns_and_keeps_scope_date_and_selection_filters(): void
    {
        $payment = [...$this->recordRow(), '餘額影響' => 'Credit'];
        $memo = [...$payment, '交易 ID' => 'memo', '餘額影響' => 'Memo'];
        $this->workbooks->importRecords('paypal-orders', [2 => $payment, 3 => $memo], '尚云', '-07:00', 1);
        $account = $this->financePayPalAccount('尚云');
        $this->get('/finance')->assertOk()
            ->assertDontSee('id="finance-activity-report"', false)
            ->assertDontSee('自定义报告字段')->assertDontSee('下载路径')
            ->assertViewHas('paymentAccountActivities', fn ($activities) => $activities['finance-'.$account->id]['record_count'] === 2);
        $this->get('/finance/records/paypal-orders')->assertOk()->assertDontSee('id="finance-activity-report"', false)
            ->assertDontSee('id="finance-transaction-sync"', false);
        $query = [
            'export_scope' => 'accounts', 'selected_accounts' => ['尚云'],
            'transaction_scope' => 'balance_affecting', 'from' => '2026-09-08', 'to' => '2026-09-08', 'timezone' => '-07:00',
            'report_fields_customized' => '1', 'report_columns' => [11, 41, 42],
        ];
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query($query))->assertOk()->streamedContent());
        $this->assertSame(2, $sheet->getHighestDataRow());
        $this->assertSame('C', $sheet->getHighestDataColumn());
        $this->assertSame(['交易 ID', 'PayPal账户', '交易分类'], $sheet->rangeToArray('A1:C1')[0]);
        $this->assertSame(['0000123', '尚云', '结账付款'], $sheet->rangeToArray('A2:C2')[0]);
        $withoutDates = \Illuminate\Support\Arr::except($query, ['from', 'to']);
        $withoutDates['export_scope'] = 'filtered';
        $noDateSheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query($withoutDates))->assertOk()->streamedContent());
        $this->assertSame(['0000123', '尚云', '结账付款'], $noDateSheet->rangeToArray('A2:C2')[0]);
        $this->get('/finance/records/paypal-orders/export?'.http_build_query([...$withoutDates, 'export_format' => 'csv']))->assertOk();
        foreach ([[], [999], [11, 11]] as $columns) {
            $this->getJson('/finance/records/paypal-orders/export?'.http_build_query([...$query, 'report_columns' => $columns]))->assertUnprocessable();
        }
        $this->get('/finance/records/xuzong2-orders')->assertOk()->assertDontSee('id="finance-activity-report"', false);
        $this->user->manage = false;
        $this->get('/finance')->assertOk()->assertDontSee('id="finance-account-activity"', false)
            ->assertDontSee('id="finance-activity-sync"', false);
    }

    public function test_activity_report_categories_follow_paypal_type_settings_and_export_real_excel_formats(): void
    {
        $payment = [...$this->recordRow(), '餘額影響' => 'Credit', '姓名' => '=SUM(1,2)'];
        $secondType = [...$payment, '交易 ID' => 'same-category', '類型' => 'Second checkout type', '餘額影響' => 'Memo'];
        $unclassified = [...$payment, '交易 ID' => 'unclassified', '類型' => 'Unmapped type'];
        $tomorrow = [...$payment, '交易 ID' => 'tomorrow', '日期' => '2026-09-09', '時間' => '00:00:00'];
        $this->workbooks->importRecords('paypal-orders', [2 => $payment, 3 => $secondType, 4 => $unclassified, 5 => $tomorrow], '尚云', '-07:00', 1);
        $this->workbooks->importRecords('paypal-orders', [2 => $payment], '其他账户', '-07:00', 1);
        $this->post('/finance/settings/types', ['raw_type' => 'Second checkout type', 'category' => '结账付款'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/finance')->assertOk()
            ->assertViewHas('activityCategories', fn ($categories) => $categories->contains('结账付款') && $categories->contains('待分类') && $categories->count() === $categories->unique()->count());

        $query = [
            'export_scope' => 'accounts', 'selected_accounts' => ['尚云'], 'account' => '尚云',
            'type_category' => '结账付款', 'from' => '2026-09-08', 'to' => '2026-09-08', 'timezone' => '-07:00',
            'report_fields_customized' => '1', 'report_columns' => [11, 3, 41, 42],
        ];
        foreach (['xls', 'xlsx'] as $format) {
            $response = $this->get('/finance/records/paypal-orders/export?'.http_build_query([...$query, 'export_format' => $format]))->assertOk();
            $this->assertStringContainsString('.'.$format, $response->headers->get('Content-Disposition'));
            $response->assertHeader('Content-Type', $format === 'xls' ? 'application/vnd.ms-excel' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $content = $response->streamedContent();
            $this->assertStringStartsWith($format === 'xls' ? hex2bin('D0CF11E0A1B11AE1') : 'PK', $content);
            $sheet = $this->readDownload($content);
            $this->assertSame(3, $sheet->getHighestDataRow());
            $this->assertSame(['0000123', '=SUM(1,2)', '尚云', '结账付款'], $sheet->rangeToArray('A2:D2', null, false)[0]);
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
            $this->assertSame('same-category', $sheet->getCell('A3')->getValue());
        }

        $pending = [...$query, 'type_category' => '待分类'];
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query($pending))->assertOk()->streamedContent());
        $this->assertSame(2, $sheet->getHighestDataRow());
        $this->assertSame(['unclassified', '=SUM(1,2)', '尚云', '待分类'], $sheet->rangeToArray('A2:D2', null, false)[0]);
        $this->post('/finance/settings/types', ['raw_type' => 'Unmapped type', 'category' => '新分类'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/finance')->assertOk()->assertViewHas('activityCategories', fn ($categories) => $categories->contains('新分类'));
        $this->getJson('/finance/records/paypal-orders/export?'.http_build_query($pending))->assertUnprocessable();
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query([...$query, 'type_category' => '新分类']))->assertOk()->streamedContent());
        $this->assertSame('新分类', $sheet->getCell('D2')->getValue());
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query([...$query, 'type_category' => '']))->assertOk()->streamedContent());
        $this->assertSame(4, $sheet->getHighestDataRow());

        foreach (['pdf'] as $format) {
            $this->getJson('/finance/records/paypal-orders/export?'.http_build_query([...$query, 'export_format' => $format]))->assertUnprocessable()->assertJsonValidationErrors('export_format');
        }
        foreach ([['from' => 'invalid'], ['to' => 'invalid']] as $invalid) {
            $this->getJson('/finance/records/paypal-orders/export?'.http_build_query([...$query, ...$invalid]))->assertUnprocessable();
        }
    }

    public function test_custom_conditions_can_choose_original_report_fields_and_reject_unknown_fields(): void
    {
        $first = [...$this->recordRow(), '姓名' => 'Alice', '總額' => '0010.00'];
        $second = [...$first, '交易 ID' => 'other', '姓名' => 'Bob'];
        $this->workbooks->importRecords('paypal-orders', [2 => $first, 3 => $second], '尚云', 'UTC', 1);
        $filters = ['conditions' => [
            ['field' => 'column_3', 'operator' => 'contains', 'value' => 'Ali'],
            ['field' => 'column_7', 'operator' => 'equals', 'value' => '0010.00'],
        ]];
        $this->get('/finance/records/paypal-orders?'.http_build_query($filters))->assertOk()
            ->assertViewHas('records', fn ($rows) => $rows->total() === 1 && $rows->first()->reference === '0000123')
            ->assertViewHas('exportAccounts', fn ($rows) => $rows->count() === 1 && (int) $rows->first()->total === 1)
            ->assertViewHas('filterFields', fn ($fields) => isset($fields['column_3'], $fields['column_14']));
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query([
            ...$filters, 'export_scope' => 'accounts', 'selected_accounts' => ['尚云'],
        ]))->assertOk()->streamedContent());
        $this->assertSame(2, $sheet->getHighestDataRow());
        $this->assertSame('Alice', $sheet->getCell('D2')->getValue());
        $this->getJson('/finance/records/paypal-orders?'.http_build_query(['conditions' => [['field' => 'column_999', 'value' => 'X']]]))->assertUnprocessable();
    }

    public function test_export_requires_selection_and_limits_records_to_the_dataset_and_active_filters(): void
    {
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], '尚云', 'UTC', 1);
        $this->workbooks->importRecords('paypal-orders', [2 => [...$this->recordRow(), '交易 ID' => 'libo']], '立鉑', 'UTC', 1);
        $details = [...array_fill_keys(FinanceCatalog::dataset('paypal-details')['headers'], ''), ...$this->recordRow()];
        $this->workbooks->importRecords('paypal-details', [2 => $details], '尚云', 'UTC', 1);
        $id = FinanceRecord::where('dataset', 'paypal-orders')->where('account', '尚云')->value('id');
        $otherDatasetId = FinanceRecord::where('dataset', 'paypal-details')->value('id');

        $this->getJson('/finance/records/paypal-orders/export')->assertUnprocessable()->assertJsonValidationErrors('export_scope');
        foreach (['accounts' => 'selected_accounts', 'records' => 'selected_ids'] as $scope => $key) {
            $this->getJson('/finance/records/paypal-orders/export?export_scope='.$scope)->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query([
            'export_scope' => 'records', 'selected_ids' => [$id, $otherDatasetId], 'selected_accounts' => ['立鉑'],
        ]))->assertOk()->streamedContent());
        $this->assertSame(2, $sheet->getHighestDataRow());
        $this->assertSame('尚云', $sheet->getCell('AP2')->getValue());
        $this->getJson('/finance/records/paypal-orders/export?'.http_build_query([
            'export_scope' => 'records', 'selected_ids' => [$otherDatasetId],
        ]))->assertUnprocessable()->assertJsonValidationErrors('selected_ids');
        $this->getJson('/finance/records/paypal-orders/export?'.http_build_query([
            'account' => '立鉑', 'export_scope' => 'accounts', 'selected_accounts' => ['尚云'],
        ]))->assertUnprocessable()->assertJsonValidationErrors('selected_accounts');
        $this->get('/finance/records/paypal-details/export?template=1')->assertOk();
    }

    public function test_account_export_contains_all_filtered_pages_and_only_selected_accounts(): void
    {
        $rows = [];
        for ($index = 1; $index <= 31; $index++) {
            $rows[$index + 1] = [...$this->recordRow(), '交易 ID' => 'page-'.$index];
        }
        $this->workbooks->importRecords('paypal-orders', $rows, '尚云', 'UTC', 1);
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], '立鉑', 'UTC', 1);
        $this->get('/finance/records/paypal-orders')->assertOk()
            ->assertViewHas('records', fn ($rows) => $rows->count() === 10 && $rows->perPage() === 10 && $rows->total() === 32)
            ->assertViewHas('exportAccounts', fn ($rows) => (int) $rows->firstWhere('account', '尚云')->total === 31);
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query([
            'page' => 2, 'export_scope' => 'accounts', 'selected_accounts' => ['尚云'],
        ]))->assertOk()->streamedContent());
        $this->assertSame(32, $sheet->getHighestDataRow());
        for ($index = 2; $index <= 32; $index++) {
            $this->assertSame('尚云', $sheet->getCell('AP'.$index)->getValue());
        }
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query([
            'export_scope' => 'accounts', 'selected_accounts' => ['尚云', '立鉑'],
        ]))->assertOk()->streamedContent());
        $this->assertSame(33, $sheet->getHighestDataRow());
    }

    public function test_paypal_activity_delimited_import_accepts_report_timezone_and_registers_new_types(): void
    {
        foreach (['csv' => ',', 'tsv' => "\t"] as $format => $delimiter) {
            $path = tempnam(sys_get_temp_dir(), 'paypal-activity-');
            $this->temporaryFiles[] = $path;
            $stream = fopen($path, 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            $headers = FinanceCatalog::dataset('paypal-details')['headers'];
            fputcsv($stream, $headers, $delimiter, '"', '');
            $row = [...array_fill_keys($headers, ''), ...$this->recordRow(), '類型' => 'New CSV type', '餘額影響' => 'Credit'];
            fputcsv($stream, array_values($row), $delimiter, '"', '');
            fclose($stream);
            $file = new UploadedFile($path, 'activity.'.$format, 'text/plain', null, true);
            $this->post('/finance/records/paypal-details/import', ['file' => $file, 'account' => '尚云', 'source_timezone' => '-07:00'])->assertRedirect();
        }
        $this->assertDatabaseCount('finance_records', 1);
        $this->assertDatabaseHas('finance_records', ['dataset' => 'paypal-details', 'reference' => '0000123', 'occurred_at' => '2026-09-09 06:59:59']);
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'New CSV type', 'category' => null]);
    }

    public function test_domain_groups_are_read_only_and_follow_the_shared_store_source(): void
    {
        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->id();
            $table->string('domain');
            $table->string('user_name')->nullable();
            $table->text('team')->nullable();
        });
        $id = DB::table('assistant_domain_stores')->insertGetId([
            'domain' => 'shopyayashi.com', 'user_name' => '同步使用人',
            'team' => json_encode(['厦门', '深圳'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->get('/finance/settings/domains')->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->owner === '同步使用人')
            ->assertSee('厦门、深圳')->assertDontSee('data-new-setting', false)
            ->assertDontSee('data-open-import', false)->assertDontSee('下载模板')
            ->assertDontSee('>导出</a>', false)->assertDontSee('finance-setting-form', false)
            ->assertDontSee('<th>操作</th>', false);
        DB::table('assistant_domain_stores')->where('id', $id)->update(['user_name' => '更新使用人', 'team' => '["郑州"]']);
        $this->get('/finance/settings/domains?'.http_build_query(['q' => '更新使用人']))->assertOk()
            ->assertSee('更新使用人')->assertSee('郑州')->assertDontSee('同步使用人');
        $this->post('/finance/settings/domains', ['domain' => 'example.com', 'owner' => '非法修改', 'team' => 'X'])->assertForbidden();
        $this->post('/finance/settings/domains/import')->assertForbidden();
        $this->get('/finance/settings/domains/export')->assertForbidden();
        $this->get('/finance/settings/domains/export?template=1')->assertForbidden();
        DB::table('assistant_domain_stores')->where('id', $id)->delete();
        $this->get('/finance/settings/domains')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    public function test_manual_settings_and_batch_import_validate_before_writing(): void
    {
        $this->workbooks->importSettings('domains', [2 => ['domain' => 'EXAMPLE.COM', 'owner' => 'Owner', 'team' => 'Team']]);
        $this->assertDatabaseHas('finance_domain_groups', ['domain' => 'example.com', 'owner' => 'Owner']);
        $this->workbooks->importSettings('domains', [2 => ['domain' => 'example.com', 'owner' => 'New owner', 'team' => 'Team']]);
        $this->assertSame(1, DB::table('finance_domain_groups')->where('domain', 'example.com')->count());
        try {
            $this->workbooks->importSettings('domains', [2 => ['a' => 'example.com', 'b' => 'Overwritten', 'c' => 'Team'], 3 => ['a' => 'https://invalid', 'b' => 'X', 'c' => 'Team']]);
            $this->fail('Invalid settings accepted');
        } catch (ValidationException $e) {
            $this->assertDatabaseHas('finance_domain_groups', ['domain' => 'example.com', 'owner' => 'New owner']);
        }
    }

    public function test_import_selects_named_sheet_rejects_formula_and_preserves_text_ids(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Other');
        $sheet = $book->createSheet()->setTitle('贝宝类型分类');
        $sheet->fromArray([['類型', '分类'], ['New type', 'New category']]);
        $file = $this->upload($book);
        $rows = $this->workbooks->read($file, ['類型', '分类'], '贝宝类型分类');
        $this->assertSame('New type', $rows[2]['類型']);
        $this->post('/finance/settings/types/import', ['file' => $file])->assertRedirect();
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'New type', 'category' => 'New category']);
        $sheet->setCellValue('B2', '=1+1');
        $file = $this->upload($book);
        $this->expectException(ValidationException::class);
        $this->workbooks->read($file, ['類型', '分类'], '贝宝类型分类');
    }

    public function test_uploaded_record_and_live_domain_mapping_are_visible_and_exported(): void
    {
        $headers = FinanceCatalog::dataset('paypal-details')['headers'];
        $row = [...array_fill_keys($headers, ''), ...$this->recordRow(), '域名' => 'shopyayashi.com', '使用人' => 'Old'];
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray($headers);
        foreach (array_values($row) as $index => $value) {
            $sheet->setCellValueExplicit([$index + 1, 2], $value, DataType::TYPE_STRING);
        }
        $this->post('/finance/records/paypal-details/import', ['file' => $this->upload($book), 'account' => 'A', 'source_timezone' => '-08:00'])->assertRedirect();
        $this->assertDatabaseCount('finance_records', 1);
        DB::table('finance_domain_groups')->insert(['domain' => 'shopyayashi.com', 'owner' => 'Latest owner', 'team' => 'Test']);
        $this->get('/finance/records/paypal-details')->assertOk()->assertSee('Latest owner');
        $response = $this->get('/finance/records/paypal-details/export?'.http_build_query(['export_scope' => 'accounts', 'selected_accounts' => ['A']]))->assertOk();
        $export = $this->readDownload($response->streamedContent());
        $this->assertSame('Latest owner', $export->getCell('AL2')->getValue());
    }

    public function test_view_permission_cannot_write_and_other_portals_or_disabled_menus_cannot_enter(): void
    {
        $this->user->manage = false;
        $this->get('/finance/settings/types')->assertOk()->assertDontSee('id="finance-setting-form"', false);
        $this->post('/finance/settings/types', ['raw_type' => 'X', 'category' => 'Y'])->assertForbidden();
        $this->post('/finance/records/paypal-details/import')->assertForbidden();
        $this->user->view = false;
        $this->get('/finance')->assertForbidden();
        $this->user->view = true;
        $this->portalCode = 'zhuli';
        $this->get('/finance/settings/types')->assertNotFound();
        $this->portalCode = 'caiwu';
        DB::table('portal_menus')->where('code', 'finance_types')->update(['is_enabled' => false]);
        $this->get('/finance/settings/types/export')->assertForbidden();
    }

    public function test_workbench_replaces_old_content_and_keeps_balance_filtering_and_old_links(): void
    {
        $this->paymentBalance(['account' => 'A', 'withdrawable' => '10', 'pending' => '20', 'reserve' => '30']);
        $this->paymentBalance(['account' => 'B', 'withdrawable' => '100', 'pending' => '200', 'reserve' => '300']);
        $this->get('/finance?balance_account=A')->assertOk()->assertViewIs('portals.finance.home')
            ->assertSee('财务工作台')->assertSee('PayPal余额')->assertSee('信用卡余额')
            ->assertDontSee('id="payment-balance-form"', false)
            ->assertSee('action="'.route('finance.home').'"', false)
            ->assertDontSee('已导入账务记录')->assertDontSee('待分类类型')
            ->assertDontSee('报表准备')->assertDontSee('实时余额')
            ->assertViewHas('paymentBalance', fn ($balance) => $balance['account'] === 'A' && $balance['rows'][0]['total'] === '60.0000');
        $this->get('/finance/balances?balance_account=A')->assertRedirect(route('finance.home', ['balance_account' => 'A']));
        DB::table('portal_menus')->where('code', 'finance_home')->update(['is_enabled' => false]);
        $this->get('/finance')->assertForbidden();
        $this->get('/finance/balances')->assertForbidden();
        $this->post('/finance/balances/xuzong2')->assertForbidden();
    }

    public function test_workbench_loads_sync_history_in_pages_only_for_managers_with_menu_access(): void
    {
        $this->get('/finance/sync-logs')->assertOk()->assertSee('暂无同步记录');
        $account = $this->financePayPalAccount('同步历史账户');
        foreach (range(1, 23) as $count) {
            DB::table('finance_sync_logs')->insert([
                'payment_account_id' => $account->id, 'operation' => 'transaction_search',
                'status' => 'success', 'page_count' => 1, 'record_count' => $count,
                'created_at' => '2026-11-09 01:00:00',
            ]);
        }

        $this->get('/finance')->assertOk()->assertSee('data-open-sync-logs', false)
            ->assertDontSee('2026-11-09 09:00:00');
        $this->get('/finance/sync-logs')->assertOk()
            ->assertSee('2026-11-09 09:00:00')->assertSee('共 23 条')
            ->assertSeeInOrder(['1 / 23', '1 / 14'])->assertDontSee('1 / 13')
            ->assertSee('page=2');
        $this->get('/finance/sync-logs?page=3')->assertOk()
            ->assertSeeInOrder(['1 / 3', '1 / 2', '1 / 1'])->assertDontSee('1 / 4')
            ->assertSee('page=2')->assertDontSee('page=4');

        $this->user->manage = false;
        $this->get('/finance')->assertOk()->assertDontSee('data-open-sync-logs', false);
        $this->get('/finance/sync-logs')->assertForbidden();
        $this->user->manage = true;
        DB::table('portal_menus')->where('code', 'finance_home')->update(['is_enabled' => false]);
        $this->get('/finance/sync-logs')->assertForbidden();
    }

    public function test_balance_menu_migration_hides_existing_entry_and_preserves_balances(): void
    {
        $portalId = Portal::query()->where('code', 'caiwu')->value('id');
        DB::table('portal_menus')->insert([
            'portal_id' => $portalId, 'parent_id' => 0, 'code' => 'finance_balances',
            'title_key' => 'finance.menus.balances', 'path' => '/finance/balances', 'icon' => 'fa-bank',
            'sort' => 20, 'is_enabled' => true,
        ]);
        $this->paymentBalance(['account' => 'Saved balance']);
        $migration = require database_path('migrations/2026_11_03_000004_move_finance_balances_to_workbench.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('portal_menus', ['code' => 'finance_balances', 'is_enabled' => false]);
        $this->assertDatabaseHas('portal_menus', ['code' => 'finance_home', 'is_enabled' => true]);
        $this->assertDatabaseHas('finance_payment_balances', ['account' => 'Saved balance']);
        $this->get('/finance/balances')->assertRedirect(route('finance.home'));
    }

    public function test_channel_rename_updates_saved_menu_titles_without_changing_routes_or_sorting(): void
    {
        DB::table('portal_menus')->where('code', 'finance_paypal-orders')->update(['title' => '贝宝订单数据', 'sort' => 777]);
        DB::table('portal_menus')->where('code', 'finance_xuzong2-orders')->update(['title' => '徐总支付2订单数据']);
        DB::table('portal_menus')->where('code', 'finance_domains')->update(['title' => '自定义分组']);
        $migration = require database_path('migrations/2026_11_03_000005_rename_finance_payment_channels.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('portal_menus', ['code' => 'finance_paypal-orders', 'title' => 'PayPal订单数据', 'sort' => 777, 'path' => '/finance/records/paypal-orders']);
        $this->assertDatabaseHas('portal_menus', ['code' => 'finance_xuzong2-orders', 'title' => '信用卡2订单数据']);
        $this->assertDatabaseHas('portal_menus', ['code' => 'finance_domains', 'title' => '自定义分组']);
        $this->get('/finance')->assertOk()->assertSee('PayPal余额')->assertSee('信用卡余额')
            ->assertSee('PayPal订单数据')->assertSee('信用卡2订单数据')->assertSee('信用卡1明细表')
            ->assertDontSee('贝宝')->assertDontSee('徐总支付');
    }

    public function test_renamed_workbook_sheets_are_preferred_and_legacy_sheets_still_import(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Other');
        $book->createSheet()->setTitle('贝宝类型分类')->fromArray([['類型', '分类'], ['Legacy type', 'Legacy category']]);
        $file = $this->upload($book);
        $this->post('/finance/settings/types/import', ['file' => $file])->assertRedirect();
        $this->assertDatabaseHas('finance_transaction_types', ['raw_type' => 'Legacy type', 'category' => 'Legacy category']);
        $book->createSheet()->setTitle('PayPal类型分类')->fromArray([['類型', '分类'], ['Current type', 'Current category']]);
        $rows = $this->workbooks->read($this->upload($book), ['類型', '分类'], 'PayPal类型分类');
        $this->assertSame('Current type', $rows[2]['類型']);
    }

    public function test_paypal_conversion_uses_manual_cross_rates_and_preserves_nulls_zeros_and_negative_values(): void
    {
        $exchange = app(FinanceExchangeRateService::class);
        $amounts = collect([
            'USD' => ['available' => '100.10', 'withheld' => '5.20'],
            'EUR' => ['available' => '20', 'withheld' => '-4'],
            'JPY' => ['available' => '0', 'withheld' => '0'],
        ]);
        $rates = collect(['USD' => '1', 'EUR' => '0.8', 'CNY' => '7.2']);
        $usd = $exchange->convert($amounts, 'USD', $rates);
        $this->assertSame('125.1000000000000000', $usd['available']);
        $this->assertSame('0.2000000000000000', $usd['withheld']);
        $this->assertSame('125.3000000000000000', $usd['total']);
        $this->assertCount(0, $usd['missing_rates']);
        $this->assertSame('902.1600000000000000', $exchange->convert($amounts, 'CNY', $rates)['total']);
        $this->assertSame('100.2400000000000000', $exchange->convert($amounts, 'EUR', $rates)['total']);
        $missing = $exchange->convert($amounts, 'HKD', $rates);
        $this->assertNull($missing['total']);
        $this->assertSame(['HKD'], $missing['missing_rates']->all());
        $amounts['EUR'] = ['available' => '20', 'withheld' => null];
        $incomplete = $exchange->convert($amounts, 'USD', $rates);
        $this->assertSame('125.1000000000000000', $incomplete['available']);
        $this->assertNull($incomplete['withheld']);
        $this->assertNull($incomplete['total']);
        $native = $exchange->convert(collect(['EUR' => ['available' => '20', 'withheld' => '5']]), 'EUR', collect(['USD' => '1']));
        $this->assertSame('25.0000000000000000', $native['total']);
        $this->assertNull($exchange->convert(collect(), 'USD', $rates)['total']);
    }

    public function test_workbench_has_one_summary_and_native_currency_rows_and_keeps_filters(): void
    {
        $account = $this->financePayPalAccount('尚云');
        $key = 'finance-'.$account->id;
        $this->financeBalanceSnapshot($account, ['USD' => ['100', '20'], 'EUR' => ['40', '8']]);
        $this->post('/finance/exchange-rates', ['rates' => [
            ['currency' => 'EUR', 'units_per_usd' => '0.8'], ['currency' => 'CNY', 'units_per_usd' => '7.2'],
        ], 'paypal_currency' => 'CNY', 'balance_account' => 'A'])->assertRedirect(route('finance.home', ['paypal_currency' => 'CNY', 'balance_account' => 'A']));
        $response = $this->get('/finance?paypal_currency=EUR&balance_account=A')->assertOk()->assertDontSee('@else');
        $balance = $response->viewData('paypalBalance');
        $this->assertSame('48.0000000000000000', $balance['summary']['total']);
        $this->assertSame('48.0000000000000000', $balance['cards'][$key]['display']['total']);
        $this->assertSame('48.00000000', $balance['totals']['EUR']['total']);
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@data-paypal-summary="EUR"]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-paypal-account="'.$key.'"]//div[@class="paypal-account-values"]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="paypal_currency"]/option[@selected and @value="EUR"]')->length);
        $this->assertSame(['USD', 'EUR'], array_map(fn ($node) => $node->getAttribute('value'), iterator_to_array($xpath->query('//select[@name="paypal_currency"]/option'))));
        $this->assertSame(0, $xpath->query('//*[@data-target="#paypal-exchange-rates" or @id="paypal-exchange-rates"]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="paypal_currency"]/../input[@name="balance_account" and @value="A"]')->length);
        $this->get('/finance')->assertOk()->assertViewHas('paypalBalance', fn ($balance) => $balance['summary']['currency'] === 'USD' && $balance['summary']['total'] === '120.0000000000000000');
        $this->get('/finance?paypal_currency=CNY')->assertOk()
            ->assertViewHas('paypalBalance', fn ($balance) => $balance['summary']['currency'] === 'USD' && $balance['summary']['total'] === '120.0000000000000000');
        $this->user->manage = false;
        $this->get('/finance?paypal_currency=CNY')->assertOk()->assertDontSee('id="paypal-exchange-rates-form"', false);
    }

    public function test_balance_cards_show_returned_native_currency_instead_of_global_usd_selection(): void
    {
        $usd=$this->financePayPalAccount('USD native');
        $hkd=$this->financePayPalAccount('HKD native');
        $this->financeBalanceSnapshot($usd,['USD'=>['100','0']]);
        $this->financeBalanceSnapshot($hkd,['USD'=>['0','0'],'HKD'=>['1234.56','10']]);
        $balance=app(FinancePayPalBalanceService::class)->dashboard('USD');
        $this->assertSame('USD',$balance['cards']['finance-'.$usd->id]['display_rows'][0]['currency']);
        $this->assertSame('HKD',$balance['cards']['finance-'.$hkd->id]['display_rows'][0]['currency']);
        $this->assertSame('1234.5600000000000000',$balance['cards']['finance-'.$hkd->id]['display_rows'][0]['available']);
        $this->assertSame('100.0000000000000000',$balance['summary']['total']);
        $view=$this->get('/finance?paypal_currency=USD')->assertOk();
        $doc=new \DOMDocument();
        @$doc->loadHTML($view->getContent());
        $xpath=new \DOMXPath($doc);
        $card=$xpath->query('//*[@data-paypal-account="finance-'.$hkd->id.'"]')->item(0);
        $this->assertNotNull($card);
        $this->assertStringContainsString('HKD',$card->textContent);
        $this->assertStringContainsString('1,234.56',$card->textContent);
        $this->assertStringNotContainsString('USD',$card->textContent);
    }

    public function test_currency_categories_use_actual_balances_and_keep_accounts_without_the_selected_currency(): void
    {
        $usd = $this->financePayPalAccount('美元账户');
        $aud = $this->financePayPalAccount('澳元账户');
        $closed = $this->financePayPalAccount('已关闭英镑账户', ['status' => 'disabled']);
        $this->financePayPalAccount('未连接账户');
        $this->financeBalanceSnapshot($usd, ['USD' => ['100', '20'], 'EUR' => ['40', '8']]);
        $this->financeBalanceSnapshot($aud, ['AUD' => ['2', '1', '4']]);
        $this->financeBalanceSnapshot($closed, ['GBP' => ['999', '0']]);
        DB::enableQueryLog();
        $balance = app(FinancePayPalBalanceService::class)->dashboard('AUD');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(['USD', 'AUD', 'EUR', 'GBP'], $balance['availableCurrencies']->all());
        $this->assertSame('4.0000000000000000', $balance['summary']['total']);
        $this->assertCount(4, $balance['cards']);
        $this->assertSame('4.0000000000000000', $balance['cards']['finance-'.$aud->id]['display']['total']);
        $this->assertNull($balance['cards']['finance-'.$usd->id]['display']['total']);
        $this->assertFalse($balance['cards']['finance-'.$usd->id]['display']['has_balance']);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('finance_exchange_rates', $query['query']);
        }
        $this->get('/finance?paypal_currency=AUD')->assertOk()->assertSee('暂无 AUD 余额')
            ->assertDontSee('编辑汇率')->assertDontSee('尚未设置汇率');
        $gbp = app(FinancePayPalBalanceService::class)->dashboard('GBP');
        $this->assertNull($gbp['summary']['total']);
        $this->assertSame('999.0000000000000000', $gbp['cards']['finance-'.$closed->id]['display']['total']);
        $fallback = app(FinancePayPalBalanceService::class)->dashboard('JPY');
        $this->assertSame('USD', $fallback['summary']['currency']);
        $this->assertSame('120.0000000000000000', $fallback['summary']['total']);
        Http::assertNothingSent();
    }

    public function test_refresh_replaces_currency_categories_and_updates_all_cards_when_the_selected_currency_disappears(): void
    {
        $eur = $this->financePayPalAccount('欧元账户');
        $account = $this->financePayPalAccount('更新币种账户');
        $account->update(['relay_certificate_sha1' => str_replace(':', '', $account->relay_certificate_sha1)]);
        $this->financeBalanceSnapshot($eur, ['EUR' => ['5', '0']]);
        $this->financeBalanceSnapshot($account, ['USD' => ['10', '0']]);
        Http::fake(fn ($request) => $this->signedRelayResponse($request, $account, [
            'as_of_time' => '2026-11-04T02:00:00Z', 'balances' => [[
                'currency' => 'EUR', 'available_balance' => ['value' => '7'], 'withheld_balance' => ['value' => '0'], 'total_balance' => ['value' => '7'],
            ]],
        ]));
        $response = $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh', ['paypal_currency' => 'USD'])
            ->assertOk()->assertJsonPath('selected_currency', 'EUR');
        $this->assertStringContainsString('5.00 EUR', $response->json('card_balances_html.finance-'.$eur->id));
        $this->assertStringContainsString('7.00 EUR', $response->json('balance_html'));
        $this->assertStringContainsString('data-paypal-total="total">12.00', $response->json('summary_html'));
        $balance = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertSame(['EUR'], $balance['availableCurrencies']->all());
        $this->assertSame('12.0000000000000000', $balance['summary']['total']);
    }

    private function newPaymentAccountData(array $overrides = []): array
    {
        return [...[
            'account_name' => '新增美国PayPal', 'account_type' => 'paypal', 'main_currency' => 'USD',
            'status' => 'enabled', 'remark' => '财务账户备注',
            'relay_url' => 'https://vps.example.test/relay/v1/paypal/read',
            'relay_certificate_sha1' => implode(':', array_fill(0, 20, 'AB')),
            'relay_hmac_key_id' => 'finance-test-key', 'relay_hmac_secret' => base64_encode('test-only-hmac-secret-never-echo-this'),
            'relay_server_remark' => '测试VPS', 'paypal_currency' => 'USD',
        ], ...$overrides];
    }

    public function test_add_paypal_account_encrypts_credentials_and_displays_card_without_requesting_paypal(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $this->get('/finance')->assertOk()->assertSee('data-add-payment-account="paypal"', false)->assertSee('data-add-payment-account="credit_card"', false);
        $data = $this->newPaymentAccountData();
        $this->post('/finance/payment-accounts', $data)->assertRedirect(route('finance.home', ['paypal_currency' => 'USD', 'balance_tab' => 'paypal']));
        $account = FinancePaymentAccount::query()->sole();
        $this->assertStringStartsWith('FA-', $account->account_code);
        $this->assertSame(str_repeat('AB', 20), $account->relay_certificate_sha1);
        $this->assertSame($data['relay_hmac_secret'], $account->relay_hmac_secret);
        $storedSecret = DB::table('finance_payment_accounts')->value('relay_hmac_secret');
        $this->assertNotSame($data['relay_hmac_secret'], $storedSecret);
        $this->assertSame($data['relay_hmac_secret'], \Illuminate\Support\Facades\Crypt::decryptString($storedSecret));
        $this->assertArrayNotHasKey('relay_hmac_secret', $account->toArray());
        $this->get('/finance')->assertOk()->assertSee('新增美国PayPal')->assertSee($account->account_code)
            ->assertDontSee($data['relay_hmac_secret'])->assertDontSee($storedSecret)
            ->assertViewHas('paypalBalance', fn ($balance) => $balance['cards']['finance-'.$account->id]['display']['total'] === null);
        $this->post('/finance/paypal-accounts/finance-'.$account->id, [
            'profile_key' => 'finance-'.$account->id, 'display_name' => '改名后的账户', 'remark' => '新备注',
        ])->assertRedirect();
        $this->assertSame('改名后的账户', $account->fresh()->account_name);
        $this->assertSame('新增美国PayPal', $account->fresh()->activity_account);
        $this->assertSame($storedSecret, $account->fresh()->getRawOriginal('relay_hmac_secret'));
        $this->get('/finance')->assertOk()->assertSee('改名后的账户');
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_add_credit_card_requires_only_common_fields_and_ignores_relay_credentials(): void
    {
        $data = $this->newPaymentAccountData(['account_type' => 'credit_card', 'account_name' => '公司信用卡', 'main_currency' => 'cny', 'status' => 'disabled', 'relay_url' => 'not-a-url']);
        $this->post('/finance/payment-accounts', $data)->assertRedirect(route('finance.home', ['paypal_currency' => 'USD', 'balance_tab' => 'credit-card']));
        $account = FinancePaymentAccount::query()->sole();
        $this->assertSame('CNY', $account->main_currency);
        foreach (['relay_url', 'relay_certificate_sha1', 'relay_hmac_key_id', 'relay_hmac_secret', 'relay_server_remark'] as $field) {
            $this->assertNull($account->$field);
        }
        $this->get('/finance?balance_tab=credit-card')->assertOk()->assertSee('公司信用卡')->assertSee('功能未开发')
            ->assertViewHas('creditAccounts', fn ($accounts) => $accounts->count() === 1 && $accounts->first()->status === 'disabled');
        $this->assertCount(0, app(FinancePayPalBalanceService::class)->dashboard()['cards']);
    }

    public function test_account_creation_validates_relay_fields_and_never_flashes_secret_on_error(): void
    {
        foreach ([
            ['relay_url' => 'http://vps.example.test/relay/v1/paypal/read'],
            ['relay_url' => 'https://vps.example.test/other'],
            ['relay_url' => 'https://vps.example.test/relay/v1/paypal/read?url=x'],
            ['relay_url' => 'https://user:pass@vps.example.test/relay/v1/paypal/read'],
            ['relay_url' => ['invalid']], ['relay_certificate_sha1' => 'BAD'],
            ['relay_hmac_secret' => ''], ['relay_hmac_key_id' => "bad\r\nheader"],
            ['relay_hmac_secret' => 'plain-text-secret-must-not-be-used-for-hmac'],
            ['relay_hmac_secret' => base64_encode('too-short')],
            ['relay_hmac_secret' => ['invalid']],
        ] as $invalid) {
            $this->from('/finance')->post('/finance/payment-accounts', $this->newPaymentAccountData($invalid))
                ->assertSessionHasErrors(array_keys($invalid), null, 'paymentAccount');
            $this->assertArrayNotHasKey('relay_hmac_secret', session()->getOldInput());
        }
        $this->get('/finance')->assertOk()->assertDontSee('test-only-hmac-secret-never-echo-this');
        $this->assertDatabaseCount('finance_payment_accounts', 0);
    }

    public function test_account_creation_requires_manage_permission_and_rejects_duplicate_names(): void
    {
        $data = $this->newPaymentAccountData();
        $this->post('/finance/payment-accounts', $data)->assertRedirect();
        $this->post('/finance/payment-accounts', $data)->assertSessionHasErrors(['account_name'], null, 'paymentAccount');
        $this->user->manage = false;
        $this->get('/finance')->assertOk()->assertDontSee('data-add-payment-account=', false)->assertDontSee('id="finance-payment-account-form"', false);
        $this->post('/finance/payment-accounts', [...$data, 'account_name' => '不能添加'])->assertForbidden();
        $this->assertDatabaseCount('finance_payment_accounts', 1);
        $this->user->manage = true;
        DB::table('portal_menus')->where('code', 'finance_home')->update(['is_enabled' => false]);
        $this->post('/finance/payment-accounts', [...$data, 'account_name' => '不能添加'])->assertForbidden();
    }

    public function test_deleted_account_names_can_be_reused_when_creating_and_renaming_accounts(): void
    {
        $data = $this->newPaymentAccountData(['account_name' => '123', 'account_type' => 'credit_card']);
        $this->post('/finance/payment-accounts', $data)->assertRedirect();
        $deleted = FinancePaymentAccount::query()->sole();
        $this->delete('/finance/payment-accounts/finance-'.$deleted->id)->assertRedirect();
        $this->assertSoftDeleted('finance_payment_accounts', ['id' => $deleted->id]);
        $this->post('/finance/payment-accounts', $data)->assertRedirect();
        $replacement = FinancePaymentAccount::query()->sole();
        $this->assertNotSame($deleted->id, $replacement->id);
        $this->assertSame('123', $deleted->fresh()->account_name);
        $this->delete('/finance/payment-accounts/finance-'.$replacement->id)->assertRedirect();

        $account = $this->financePayPalAccount('主账户');
        $secret = $account->getRawOriginal('relay_hmac_secret');
        $this->financeBalanceSnapshot($account, ['USD' => ['100', '10']]);
        $key = 'finance-'.$account->id;
        $edit = $this->newPaymentAccountData(['account_name' => '123', 'relay_hmac_secret' => '']);
        $this->post('/finance/payment-accounts/'.$key, $edit)->assertRedirect();
        $this->post('/finance/payment-accounts/'.$key, $edit)->assertRedirect();
        $this->assertSame('123', $account->fresh()->account_name);
        $this->assertSame('主账户', $account->fresh()->activity_account);
        $this->assertSame($secret, $account->fresh()->getRawOriginal('relay_hmac_secret'));
        $this->assertSame('110.0000000000000000', app(FinancePayPalBalanceService::class)->dashboard()['cards'][$key]['display']['total']);
        $this->post('/finance/paypal-accounts/'.$key, ['profile_key' => $key, 'display_name' => '主账户新名称'])->assertRedirect();
        $other = $this->financePayPalAccount('备用账户');
        $otherKey = 'finance-'.$other->id;
        $this->post('/finance/paypal-accounts/'.$otherKey, ['profile_key' => $otherKey, 'display_name' => '123'])->assertRedirect();
        $this->assertSame('123', $other->fresh()->account_name);
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData(['account_name' => '123']))->assertSessionHasErrors(['account_name'], null, 'paymentAccount');
        $this->post('/finance/payment-accounts/'.$key, $edit)->assertSessionHasErrors(['account_name'], null, 'paymentAccount');
        $this->post('/finance/paypal-accounts/'.$key, ['profile_key' => $key, 'display_name' => '123'])->assertSessionHasErrors(['display_name'], null, 'paypalAccount');
        $this->assertSame('主账户新名称', $account->fresh()->account_name);
        $this->assertSame(2, FinancePaymentAccount::onlyTrashed()->where('account_name', '123')->count());
        $migration = require database_path('migrations/2026_11_04_000005_release_deleted_finance_account_names.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('finance_payment_accounts', 4);
        Http::assertNothingSent();
    }

    public function test_database_enforces_unique_live_names_while_preserving_deleted_names(): void
    {
        $deleted = $this->financePayPalAccount('可复用名称');
        $deleted->delete();
        $active = $this->financePayPalAccount('可复用名称', ['status' => 'disabled']);
        $this->assertSame('可复用名称', $deleted->fresh()->account_name);
        $this->assertNull(DB::table('finance_payment_accounts')->where('id', $deleted->id)->value('active_account_name'));
        $this->assertSame('可复用名称', DB::table('finance_payment_accounts')->where('id', $active->id)->value('active_account_name'));
        $this->assertArrayNotHasKey('active_account_name', $active->fresh()->toArray());
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->financePayPalAccount('可复用名称');
    }

    public function test_page_xlsx_export_includes_all_fourteen_thousand_rows_across_batches(): void
    {
        $this->financePayPalAccount('Bulk export');
        $payload=$this->recordRow();
        for($start=1;$start<=14000;$start+=500) {
            $records=[];
            for($id=$start;$id<min($start+500,14001);$id++) {
                $records[]=['id'=>$id,'dataset'=>'paypal-orders','account'=>'Bulk export','reference'=>'BULK-'.$id,
                    'occurred_at'=>'2026-11-06 00:00:00','source_timezone'=>'UTC','currency'=>'USD','status'=>'S','raw_type'=>'T0006',
                    'fingerprint'=>hash('sha256','bulk-export-'.$id),'payload'=>json_encode([...$payload,'交易 ID'=>'BULK-'.$id],JSON_UNESCAPED_UNICODE),
                    'created_at'=>now(),'updated_at'=>now()];
            }
            DB::table('finance_records')->insert($records);
        }
        $binary=$this->get('/finance/records/paypal-orders/export?export_scope=filtered&export_layout=page&export_format=xlsx')->assertOk()->streamedContent();
        $file=tempnam(sys_get_temp_dir(),'finance-bulk-');
        $this->temporaryFiles[]=$file;
        file_put_contents($file,$binary);
        unset($binary);
        $zip=new \ZipArchive();
        $this->assertTrue($zip->open($file)===true);
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $zip->close();
        $reader=new \XMLReader();
        $this->assertTrue($reader->open('zip://'.$file.'#xl/worksheets/sheet1.xml'));
        $rows=0;$expected=1;
        while($reader->read()) {
            if($reader->nodeType!==\XMLReader::ELEMENT || $reader->localName!=='row')continue;
            $rows++;
            if($rows===1)continue;
            $xml=$reader->readOuterXml();
            preg_match('/<c[^>]*>.*?<t[^>]*>([0-9]+)<\/t>/s',$xml,$match);
            $this->assertSame((string)$expected++,$match[1]??null);
        }
        $reader->close();
        $this->assertSame(14001,$rows);
    }

    public function test_product_export_splits_fields_and_keeps_unmatched_paypal_items(): void
    {
        $foodCover = '200PCS Disposable Stretch Food Covers for Bowls & Plates';
        $this->workbooks->importRecords('paypal-orders', [2 => [
            ...$this->recordRow(), '帳單號碼' => 'UNMATCHED-PAYPAL-BILL',
            '物品标题' => $foodCover."\n60ml / Buy 2", '数量' => "1\n2",
        ]], 'Unmatched products', 'UTC', 1);
        $this->get('/finance/records/paypal-orders')->assertOk()->assertSee('一次性弹力碗盘保鲜罩')->assertSee($foodCover)->assertSee('60ml / 买2件')
            ->assertDontSee('name="product_name_mode"', false);
        $this->get('/finance/records/paypal-orders?order_detail='.FinanceRecord::sole()->id)->assertOk()
            ->assertSee('一次性弹力碗盘保鲜罩')->assertSee($foodCover)->assertSee('UNMATCHED-PAYPAL-BILL');
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?export_scope=filtered&export_layout=page')->assertOk()->streamedContent())->toArray();
        $this->assertNotContains('出单产品明细', $sheet[0]);
        $this->assertSame("家居厨具\n未分类", $sheet[1][array_search('商品大类', $sheet[0], true)]);
        $this->assertSame("1\n2", $sheet[1][array_search('数量', $sheet[0], true)]);
        $settlement = $this->readDownload($this->get('/finance/reports/paypal-settlement?download=1&export_columns[]=paypal_18')
            ->assertOk()->streamedContent());
        $this->assertSame('商品大类', $settlement->getCell('A1')->getValue());
        $this->assertSame("家居厨具\n未分类", $settlement->getCell('A2')->getValue());
        $this->assertContains('PCL', $sheet[0]);
        $this->assertContains('原始SKU', $sheet[0]);
        $this->assertContains('包裹重量（克）', $sheet[0]);
        $items = collect([
            (object) ['tongtu_product_code' => 'PCL-A', 'shopify_sku' => 'SKU-A', 'quantity' => 1,
                'sale_weight_gram' => 12, 'tongtu_package_weight_gram' => 18, 'tongtu_product_name' => 'Product A'],
            (object) ['tongtu_product_code' => 'PCL-B', 'tongtu_sku' => 'ERP-B', 'quantity' => 2,
                'tongtu_goods_weight_gram' => 20, 'product_title' => 'Product B'],
        ]);
        $columns = \App\Portals\Finance\FinancePayPalOrderExport::productColumns($items);
        $this->assertSame(["PCL-A\nPCL-B", "SKU-A\n", "\nERP-B", "1\n2", "12\n20", "18\n", "未分类\n未分类"], $columns);
        $fallback = \App\Portals\Finance\FinancePayPalOrderExport::productItems([], "A\nB", '3');
        $this->assertSame([null, null], $fallback->pluck('quantity')->all());
    }

    public function test_page_order_export_and_settlement_export_include_linked_business_fields(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            foreach (['transaction_id', 'tongtu_order_number', 'source_shop_domain', 'subscription_type',
                'is_add_on', 'bill_number', 'buyer_name', 'buyer_email', 'tracking_number', 'store_name'] as $column) {
                $table->string($column)->nullable();
            }
        });
        $this->workbooks->importRecords('paypal-orders', [2 => [...$this->recordRow(), '余额' => '-125.50']], 'Page export account', 'UTC', 1);
        $record = FinanceRecord::sole();
        DB::table('finance_domain_groups')->insert(['domain' => 'linked.example.test', 'owner' => 'Linked owner', 'team' => 'Linked team']);
        DB::table('logistics_orders')->insert(['id' => 1, 'transaction_id' => $record->reference,
            'tongtu_order_number' => 'ERP-LINKED', 'source_shop_domain' => 'linked.example.test',
            'subscription_type' => '订阅', 'is_add_on' => '1', 'bill_number' => 'LINKED-BILL',
            'buyer_name' => 'Linked buyer', 'buyer_email' => 'linked@example.test',
            'tracking_number' => 'TRACK-LINKED', 'store_name' => 'Linked store']);
        $response = $this->get('/finance/records/paypal-orders')->assertOk();
        $response->assertSee('ERP-LINKED')->assertSee('Linked buyer')->assertSee('列与导出设置')
            ->assertSee('data-label="余额"', false);
        $sheet = $this->readDownload($this->get('/finance/records/paypal-orders/export?export_scope=filtered&export_layout=page')->assertOk()->streamedContent())->toArray();
        $this->assertSame(\App\Portals\Finance\FinancePayPalOrderExport::pageHeaders('paypal-orders'), $sheet[0]);
        foreach (['通途订单号' => 'ERP-LINKED', '账单号' => 'LINKED-BILL', '买家名称' => 'Linked buyer',
            'Email' => 'linked@example.test', '使用人' => 'Linked owner', '所属团队' => 'Linked team',
            '订阅类型' => '订阅', '加购' => '加购', '物流单号' => 'TRACK-LINKED'] as $header => $expected) {
            $this->assertSame($expected, $sheet[1][array_search($header, $sheet[0], true)], $header);
        }
        $balanceIndex = array_search('余额', $sheet[0], true);
        $referenceIndex = array_search('交易号', $sheet[0], true);
        $query = http_build_query([
            'export_scope' => 'records', 'selected_ids' => [$record->id], 'export_layout' => 'page',
            'report_columns' => [$balanceIndex, $referenceIndex],
        ]);
        $custom = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.$query)->assertOk()->streamedContent());
        $this->assertSame(['余额', '交易号'], $custom->rangeToArray('A1:B1')[0]);
        $this->assertSame(-125.5, $custom->getCell('A2')->getValue());
        $this->assertSame($record->reference, $custom->getCell('B2')->getValue());

        $template = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query([
            'export_layout' => 'page', 'export_scope' => 'filtered', 'export_template' => 1,
            'report_columns' => [$balanceIndex, $referenceIndex],
        ]))
            ->assertOk()->streamedContent());
        $this->assertSame([['余额', '交易号']], $template->toArray());
        $this->postJson('/finance/records/paypal-orders/export-template/preview', [
            'file' => $this->upload($template->getParent()), 'header_row' => 1,
        ])->assertOk()->assertJsonPath('fields', [$balanceIndex, $referenceIndex]);
        $this->postJson('/finance/records/paypal-orders/export-template/preview', [
            'file' => UploadedFile::fake()->createWithContent('template.tsv', "余额\t交易号\n"), 'header_row' => 1,
        ])->assertOk()->assertJsonPath('fields', [$balanceIndex, $referenceIndex]);
        $settlement = $this->readDownload($this->get('/finance/reports/paypal-settlement?download=1')->assertOk()->streamedContent())->toArray();
        foreach (['通途订单' => 'ERP-LINKED', '域名' => 'linked.example.test', '使用人' => 'Linked owner',
            '运营所属部门' => '', '订阅类型' => '未匹配', '是否加购' => '已加购'] as $header => $expected) {
            $this->assertSame($expected, $settlement[1][array_search($header, $settlement[0], true)], $header);
        }
        $this->getJson('/finance/records/paypal-orders/export?export_scope=filtered&export_layout=page&report_columns[]=999')->assertUnprocessable();
    }

    public function test_paypal_settlement_classifies_each_payment_and_exports_the_same_labels(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('bill_number');
            $table->string('subscription_type');
            $table->string('is_add_on');
        });
        Schema::create('logistics_order_payment_references', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('logistics_order_id');
            $table->string('reference_number');
            $table->string('reference_type');
        });
        DB::table('logistics_orders')->insert([
            'id' => 1, 'bill_number' => 'PRIMARY-PAYMENT',
            'subscription_type' => '订阅复购1次', 'is_add_on' => '已加购',
        ]);
        DB::table('logistics_order_payment_references')->insert([
            ['logistics_order_id' => 1, 'reference_number' => 'PRIMARY-PAYMENT', 'reference_type' => 'primary'],
            ['logistics_order_id' => 1, 'reference_number' => 'UPSELL-PAYMENT', 'reference_type' => 'upsell'],
        ]);
        $this->workbooks->importRecords('paypal-orders', [
            2 => [...$this->recordRow(), '交易 ID' => 'PRIMARY-PAYMENT'],
            3 => [...$this->recordRow(), '交易 ID' => 'UPSELL-PAYMENT'],
            4 => [...$this->recordRow(), '交易 ID' => 'UNKNOWN-PAYMENT'],
            5 => [...$this->recordRow(), '交易 ID' => 'INVOICE-ONLY', '帳單號碼' => 'PRIMARY-PAYMENT'],
            6 => [...$this->recordRow(), '交易 ID' => 'REVIEWED-PAYMENT', '订阅类型' => '订阅'],
        ], 'Payment classifications', 'UTC', 1);
        $reviewed = FinanceRecord::query()->where('reference', 'REVIEWED-PAYMENT')->sole();
        app(\App\Portals\Finance\FinanceSettlementEditor::class)->save($reviewed, ['upsell' => '已加购'], 'paypal', 1);

        $url = '/finance/reports/paypal-settlement';
        $page = $this->get($url)->assertOk()->viewData('rows')->getCollection()->keyBy('reference');
        $exportQuery = [
            'download' => 1, 'export_columns' => ['reference', 'subscription', 'upsell'],
        ];
        $sheet = $this->readDownload($this->get($url.'?'.http_build_query($exportQuery))->assertOk()->streamedContent())->toArray();
        $this->assertSame(['交易 ID', '订阅类型', '是否加购'], array_shift($sheet));
        $exported = collect($sheet)->keyBy(0);
        foreach ([
            'PRIMARY-PAYMENT' => ['订阅复购1次', '已加购'],
            'UPSELL-PAYMENT' => ['加购', '已加购'],
            'UNKNOWN-PAYMENT' => ['未匹配', '未匹配'],
            'INVOICE-ONLY' => ['未匹配', '已加购'],
            'REVIEWED-PAYMENT' => ['加购', '已加购'],
        ] as $reference => $labels) {
            $this->assertSame($labels, [$page[$reference]['subscription'], $page[$reference]['upsell']]);
            $this->assertSame([$reference, ...$labels], $exported[$reference]);
        }

        DB::table('logistics_order_payment_references')->insert([
            'logistics_order_id' => 1, 'reference_number' => 'UNKNOWN-PAYMENT', 'reference_type' => 'upsell',
        ]);
        $updated = $this->get($url.'?reference=UNKNOWN-PAYMENT')->assertOk()->viewData('rows')->getCollection()->sole();
        $this->assertSame(['加购', '已加购'], [$updated['subscription'], $updated['upsell']]);
        $this->assertStringNotContainsString('未匹配物流订单', $updated['review']);
        $updatedExport = $this->readDownload($this->get($url.'?'.http_build_query([
            ...$exportQuery, 'reference' => 'UNKNOWN-PAYMENT',
        ]))->assertOk()->streamedContent())->toArray();
        $this->assertSame(['UNKNOWN-PAYMENT', '加购', '已加购'], $updatedExport[1]);

    }

    public function test_api_paypal_order_filters_and_metadata_match_without_legacy_accounts(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id(); $table->string('transaction_id'); $table->string('tongtu_order_number');
            $table->string('source_shop_domain');
        });
        Schema::create('logistics_order_payment_references', function (Blueprint $table): void {
            $table->id(); $table->integer('logistics_order_id'); $table->string('reference_number');
        });
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], 'API account', 'UTC', 1);
        $record = FinanceRecord::sole();
        $record->update(['domain' => 'saved.example.test']);
        DB::table('logistics_orders')->insert(['id' => 1, 'transaction_id' => $record->reference, 'tongtu_order_number' => 'ERP-API', 'source_shop_domain' => 'api.example.test']);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $this->assertSame('ERP-API', $service->logisticsMetadata(collect([$record]), 'paypal')[$record->id]['erp_order']);
        $url = '/finance/records/paypal-orders?'.http_build_query(['order_fields' => ['tongtu_order_number' => 'ERP-API']]);
        $this->get($url)->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1);
        $domainUrl = '/finance/records/paypal-orders?order_fields%5Bdomain%5D=';
        $this->get($domainUrl.'api.example.test')->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1);
        $this->get($domainUrl.'saved.example.test')->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 0);
        $exportUrl = '/finance/records/paypal-orders/export?'.http_build_query([
            'order_fields' => ['domain' => 'api.example.test'], 'export_scope' => 'filtered', 'export_format' => 'xlsx',
        ]);
        $exported = $this->readDownload($this->get($exportUrl)->assertOk()->streamedContent())->toArray();
        $this->assertCount(2, $exported);
        $this->assertSame('ERP-API', $exported[1][array_search('通途订单号', $exported[0], true)]);
        $this->assertSame('api.example.test', $exported[1][array_search('店铺域名', $exported[0], true)]);
        DB::table('logistics_orders')->where('id', 1)->update(['transaction_id' => 'unrelated']);
        DB::table('logistics_order_payment_references')->insert(['logistics_order_id' => 1, 'reference_number' => $record->reference]);
        $this->get($url)->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1);
        $payload = $record->payload;
        $payload['帳單號碼'] = $record->reference;
        $record->update(['reference' => 'API-different-transaction', 'payload' => $payload]);
        $this->get($url)->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1);
        $this->assertSame('ERP-API', $service->logisticsMetadata(collect([$record->fresh()]), 'paypal')[$record->id]['erp_order']);
        DB::table('logistics_orders')->insert(['id' => 2, 'transaction_id' => $payload['帳單號碼'], 'tongtu_order_number' => 'OTHER', 'source_shop_domain' => 'other.example.test']);
        $this->assertSame([], $service->logisticsMetadata(collect([$record]), 'paypal'));
        $this->get($url)->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 0);
        $this->get($domainUrl.'api.example.test')->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 0);
        $this->get($domainUrl.'saved.example.test')->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1);
        $this->artisan('finance:audit-data')->assertExitCode(0);
        $this->assertDatabaseCount('finance_records', 1);
        $this->assertDatabaseCount('logistics_orders', 2);
    }

    public function test_paypal_matches_domain_from_the_same_account_and_transaction_order(): void
    {
        Schema::table('finance_payment_accounts', fn (Blueprint $table) => $table->unsignedBigInteger('system_paypal_account_id')->nullable());
        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->id(); $table->string('domain'); $table->string('user_name'); $table->text('team');
        });
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id(); $table->integer('assistant_domain_store_id'); $table->string('source_shop_domain');
            $table->string('subscription_type'); $table->string('is_add_on'); $table->string('tongtu_order_number');
        });
        Schema::create('logistics_paypal_transactions', function (Blueprint $table): void {
            $table->id(); $table->integer('logistics_paypal_account_id'); $table->string('payment_transaction_id');
            $table->string('event_code'); $table->integer('logistics_order_id');
        });
        $account = $this->financePayPalAccount('匹配账户');
        $account->forceFill(['system_paypal_account_id' => 7])->save();
        $this->workbooks->importRecords('paypal-orders', [2 => $this->recordRow()], $account->activity_account, 'UTC', 1);
        $record = FinanceRecord::sole();
        DB::table('assistant_domain_stores')->insert(['id' => 1, 'domain' => 'official.com', 'user_name' => '匹配使用人', 'team' => '["厦门团队"]']);
        DB::table('logistics_orders')->insert(['id' => 1, 'assistant_domain_store_id' => 1, 'source_shop_domain' => 'internal.myshopify.com', 'subscription_type' => '订阅', 'is_add_on' => '否', 'tongtu_order_number' => 'ERP-1']);
        $transaction = ['logistics_paypal_account_id' => 8, 'payment_transaction_id' => $record->reference, 'event_code' => $record->raw_type, 'logistics_order_id' => 1];
        DB::table('logistics_paypal_transactions')->insert($transaction);
        $service = app(\App\Portals\Finance\FinanceSystemPayPalService::class);
        $this->assertSame(0, $service->matchOrders());
        DB::table('logistics_paypal_transactions')->update(['logistics_paypal_account_id' => 7]);
        $this->assertSame(1, $service->matchOrders());
        $this->assertSame(0, $service->matchOrders());
        $this->assertSame('official.com', $record->fresh()->domain);
        $rows = app(\App\Portals\Finance\FinanceSettlementService::class)->rows('paypal', []);
        $this->assertSame('匹配使用人', $rows->first()['owner']);
        $this->assertSame('厦门团队', $rows->first()['team']);
        $this->assertSame('ERP-1', $rows->first()['erp_order']);
    }

    public function test_system_paypal_balances_are_never_used_even_with_legacy_linking(): void
    {
        $this->createPayPalBalanceTables();
        DB::table('logistics_paypal_accounts')->insert(['id' => 1, 'account_name' => '尚云', 'is_enabled' => true]);
        DB::table('logistics_paypal_accounts')->insert(['id' => 2, 'account_name' => 'Current environment account', 'is_enabled' => false]);
        DB::table('logistics_paypal_balance_snapshots')->insert([
            'logistics_paypal_account_id' => 1, 'currency_code' => 'USD', 'available_balance' => '100',
            'withheld_balance' => '10', 'as_of_time' => '2026-11-03 01:00:00',
        ]);
        $response = $this->get('/finance')->assertOk()->assertSee(__('finance.paypal.no_accounts'));
        $this->assertSame([], $response->viewData('paymentAccountEditors'));
        $this->assertCount(0, $response->viewData('paypalBalance')['cards']);
        $this->assertNull($response->viewData('paypalBalance')['summary']['total']);
        $this->get('/finance/records/paypal-orders')->assertOk()->assertViewHas('accounts', fn ($accounts) => $accounts->isEmpty());
        $this->post('/finance/payment-accounts/shangyun', $this->newPaymentAccountData())->assertNotFound();
        $this->post('/finance/paypal-accounts/shangyun', ['profile_key' => 'shangyun', 'display_name' => '改名'])->assertNotFound();
        $this->assertDatabaseCount('finance_payment_accounts', 0);

        // A logistics name is available independently in finance; its balance is not inherited.
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData(['account_name' => '尚云']))->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $account->update(['legacy_card_key' => 'shangyun']);
        $balance = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertCount(1, $balance['cards']);
        $this->assertNull($balance['cards']['finance-'.$account->id]['display']['total']);
        $this->assertNull($balance['summary']['total']);
        $this->assertSame(['尚云'], $balance['missing']->all());
        (require database_path('migrations/2026_11_04_000015_connect_existing_system_paypal.php'))->up();
        $this->assertNull($account->fresh()->system_paypal_account_id);
        $this->assertDatabaseCount('finance_payment_accounts', 1);
        $account->forceFill(['system_paypal_account_id' => 1])->save();
        $balance = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertNull($balance['summary']['available']);
        $this->assertNull($balance['summary']['withheld']);
        $this->assertNull($balance['summary']['total']);
        $this->assertSame('disconnected', $balance['cards']['finance-'.$account->id]['connection_status']);
        $this->assertDatabaseCount('finance_balance_snapshots', 0);
        Schema::create('logistics_paypal_transactions', function (Blueprint $table): void {
            $table->id(); $table->integer('logistics_paypal_account_id'); $table->text('raw_payload');
        });
        DB::table('logistics_paypal_transactions')->insert(['logistics_paypal_account_id' => 1, 'raw_payload' => '{}']);
        app(\App\Portals\Finance\FinanceSystemPayPalService::class)->sync();
        $this->get('/finance')->assertOk();
        $this->get('/finance/records/paypal-orders')->assertOk();
        $this->assertDatabaseCount('finance_records', 0);
        $this->assertDatabaseHas('logistics_paypal_accounts', ['id' => 1, 'account_name' => '尚云']);
        $this->assertDatabaseHas('logistics_paypal_balance_snapshots', ['logistics_paypal_account_id' => 1, 'available_balance' => '100']);
        Http::assertNothingSent();
    }

    public function test_legacy_linked_account_uses_only_relay_balances_even_when_system_balance_is_newer(): void
    {
        $this->createPayPalBalanceTables();
        Schema::table('finance_payment_accounts', fn (Blueprint $table) => $table->unsignedBigInteger('system_paypal_account_id')->nullable());
        DB::table('logistics_paypal_accounts')->insert(['id' => 1, 'account_name' => '刷新来源', 'is_enabled' => true]);
        DB::table('logistics_paypal_balance_snapshots')->insert([
            'logistics_paypal_account_id' => 1, 'currency_code' => 'USD', 'available_balance' => '100',
            'withheld_balance' => '10', 'total_balance' => '110', 'as_of_time' => '2026-11-02 01:00:00',
        ]);
        $account = $this->financePayPalAccount('刷新来源', ['relay_certificate_sha1' => str_repeat('AB', 20)]);
        $account->forceFill(['system_paypal_account_id' => 1])->save();
        $service = app(FinancePayPalBalanceService::class);
        $this->assertTrue($service->dashboard()['cards']['finance-'.$account->id]['relay_configured']);
        Http::fake(function ($request) use ($account) {
            $payload = $request['operation'] === 'balances' ? [
                'as_of_time' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                'balances' => [['currency' => 'USD', 'available_balance' => ['value' => '200'],
                    'withheld_balance' => ['value' => '10'], 'total_balance' => ['value' => '210']]],
            ] : ['page' => 1, 'total_pages' => 0, 'total_items' => 0, 'transaction_details' => []];

            return $this->signedRelayResponse($request, $account, $payload);
        });
        $response = $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh');
        $this->assertSame(200, $response->status(), (string) $response->json('error'));
        $response->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('refresh_allowed', true)->assertJsonPath('orders_queued', true);
        $card = $service->dashboard()['cards']['finance-'.$account->id];
        $this->assertSame('200.0000000000000000', $card['display']['available']);
        $this->assertSame('relay', $card['currencies']->first()['source']);
        DB::table('logistics_paypal_balance_snapshots')->update(['as_of_time' => now('UTC')->addDay()->format('Y-m-d H:i:s'), 'available_balance' => '999']);
        $this->assertSame('200.0000000000000000', $service->dashboard()['cards']['finance-'.$account->id]['display']['available']);
        Http::assertSent(fn ($request) => $request['operation'] === 'balances');
        Http::assertNotSent(fn ($request) => $request['operation'] === 'transaction_search');
    }

    public function test_account_editor_shares_add_form_and_prefills_saved_settings_without_secret(): void
    {
        $data = $this->newPaymentAccountData(['main_currency' => 'EUR', 'status' => 'disabled']);
        $this->post('/finance/payment-accounts', $data)->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $response = $this->get('/finance')->assertOk()->assertSee('portal-admin-shell finance-system', false)
            ->assertSee('/assets/erp/finance-theme.css', false)->assertSee('data-edit-payment-account="finance-'.$account->id.'"', false)
            ->assertDontSee('id="paypal-account-editor"', false)->assertDontSee($data['relay_hmac_secret']);
        $editor = $response->viewData('paymentAccountEditors')['finance-'.$account->id];
        foreach (['account_name', 'main_currency', 'status', 'remark', 'relay_url', 'relay_certificate_sha1', 'relay_hmac_key_id', 'relay_server_remark'] as $field) {
            $expected = $field === 'relay_certificate_sha1' ? str_replace(':', '', $data[$field]) : $data[$field];
            $this->assertSame($expected, $editor[$field]);
        }
        $this->assertTrue($editor['has_secret']);
        $this->assertTrue($editor['relay_configured']);
        $this->assertFalse($editor['type_locked']);
        $this->assertArrayNotHasKey('relay_hmac_secret', $editor);
        $this->assertSame(route('finance.payment-accounts.update', 'finance-'.$account->id), $editor['url']);
    }

    public function test_full_account_update_preserves_blank_secret_and_can_reset_it(): void
    {
        $data = $this->newPaymentAccountData();
        $this->post('/finance/payment-accounts', $data)->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $encrypted = $account->getRawOriginal('relay_hmac_secret');
        $code = $account->account_code;
        $changes = [...$data, 'account_name' => '更新后的账户', 'main_currency' => 'HKD', 'status' => 'disabled', 'relay_url' => 'https://new-vps.example.test/relay/v1/paypal/read', 'relay_hmac_secret' => ''];
        $url = '/finance/payment-accounts/finance-'.$account->id;
        $this->post($url, $changes)->assertRedirect();
        $account->refresh();
        $this->assertSame('HKD', $account->main_currency);
        $this->assertSame('disabled', $account->status);
        $this->assertSame($changes['relay_url'], $account->relay_url);
        $this->assertSame($encrypted, $account->getRawOriginal('relay_hmac_secret'));
        $this->assertSame($code, $account->account_code);
        $this->assertSame($data['account_name'], $account->activity_account);
        $newSecret = base64_encode('new-test-only-hmac-secret-long-enough');
        $this->post($url, [...$changes, 'relay_hmac_secret' => $newSecret])->assertRedirect();
        $this->assertSame($newSecret, $account->fresh()->relay_hmac_secret);
        $this->assertNotSame($encrypted, $account->fresh()->getRawOriginal('relay_hmac_secret'));
        $this->get('/finance')->assertOk()->assertDontSee('new-test-only-hmac-secret');
    }

    public function test_existing_finance_account_keeps_its_data_without_using_legacy_card_identity(): void
    {
        $account = $this->financePayPalAccount('尚云', ['legacy_card_key' => 'shangyun']);
        $this->financeBalanceSnapshot($account, ['USD' => ['100', '10']]);
        $key = 'finance-'.$account->id;
        $data = $this->newPaymentAccountData(['account_name' => '尚云新名称']);
        $this->post('/finance/payment-accounts/'.$key, $data)->assertRedirect();
        $this->assertSame('尚云', $account->fresh()->activity_account);
        $balance = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertSame([$key], $balance['cards']->keys()->all());
        $this->assertSame('尚云新名称', $balance['cards'][$key]['name']);
        $this->assertSame('110.0000000000000000', $balance['summary']['total']);
        $editor = $this->get('/finance')->assertOk()->viewData('paymentAccountEditors')[$key];
        $this->assertSame($data['relay_url'], $editor['relay_url']);
        $this->assertSame(str_replace(':', '', $data['relay_certificate_sha1']), $editor['relay_certificate_sha1']);
        $this->assertSame($data['relay_hmac_key_id'], $editor['relay_hmac_key_id']);
        $this->assertTrue($editor['relay_configured']);
        $this->assertTrue($editor['has_secret']);
        $this->assertTrue($editor['type_locked']);
        $this->post('/finance/payment-accounts/'.$key, [...$data, 'relay_hmac_secret' => '', 'status' => 'disabled'])->assertRedirect();
        $this->assertDatabaseCount('finance_payment_accounts', 1);
        $balance = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertSame('110.0000000000000000', $balance['cards'][$key]['display']['total']);
        $this->assertNull($balance['summary']['total']);
        $this->post('/finance/payment-accounts/'.$key, [...$data, 'account_type' => 'credit_card'])->assertSessionHasErrors(['account_type'], null, 'paymentAccount');
        $this->assertSame('paypal', $account->fresh()->account_type);
    }

    public function test_credit_account_editor_updates_common_fields_and_type_switching_validates_relay_settings(): void
    {
        $data = $this->newPaymentAccountData(['account_type' => 'credit_card', 'account_name' => '信用卡账户']);
        $this->post('/finance/payment-accounts', $data)->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $key = 'finance-'.$account->id;
        $this->get('/finance?balance_tab=credit-card')->assertOk()->assertDontSee('data-edit-payment-account="'.$key.'"', false)
            ->assertViewHas('paymentAccountEditors', fn ($editors) => $editors[$key]['account_type'] === 'credit_card');
        $this->post('/finance/payment-accounts/'.$key, [...$data, 'main_currency' => 'CNY', 'status' => 'disabled'])->assertRedirect();
        $this->assertSame('CNY', $account->fresh()->main_currency);
        $this->post('/finance/payment-accounts/'.$key, ['account_name' => '信用卡账户', 'account_type' => 'paypal', 'main_currency' => 'USD', 'status' => 'enabled'])->assertSessionHasErrors(['relay_url', 'relay_hmac_secret'], null, 'paymentAccount');
        $this->assertSame('credit_card', $account->fresh()->account_type);
        $this->post('/finance/payment-accounts/'.$key, [...$data, 'account_type' => 'paypal'])->assertRedirect();
        $this->assertSame('paypal', $account->fresh()->account_type);
        $this->post('/finance/payment-accounts/'.$key, $data)->assertRedirect();
        $this->assertSame('credit_card', $account->fresh()->account_type);
        $this->assertNull($account->fresh()->relay_hmac_secret);
        $this->assertNull($account->fresh()->relay_url);
    }

    public function test_full_account_edit_checks_permissions_and_does_not_flash_reset_secrets(): void
    {
        $data = $this->newPaymentAccountData();
        $this->post('/finance/payment-accounts', $data)->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $url = '/finance/payment-accounts/finance-'.$account->id;
        $this->post($url, [...$data, 'account_name' => '', 'account_editor_key' => 'finance-'.$account->id, 'relay_hmac_secret' => 'reset-secret-must-not-flash'])->assertSessionHasErrors(['account_name'], null, 'paymentAccount');
        $this->assertArrayNotHasKey('relay_hmac_secret', session()->getOldInput());
        $this->get('/finance')->assertOk()->assertDontSee('reset-secret-must-not-flash');
        $this->post('/finance/payment-accounts/finance-999999', $data)->assertNotFound();
        $this->user->manage = false;
        $this->post($url, $data)->assertForbidden();
        $this->get('/finance')->assertOk()->assertViewHas('paymentAccountEditors', []);
        $this->user->manage = true;
        DB::table('portal_menus')->where('code', 'finance_home')->update(['is_enabled' => false]);
        $this->post($url, $data)->assertForbidden();
    }

    public function test_paypal_profile_edit_keeps_finance_balance_identity_and_activity_links(): void
    {
        $account = $this->financePayPalAccount('尚云');
        $this->financeBalanceSnapshot($account, ['USD' => ['100', '10']]);
        $key = 'finance-'.$account->id;
        $data = ['profile_key' => $key, 'display_name' => '美国主账户', 'remark' => '财务备注 <b>原样显示</b>', 'paypal_currency' => 'USD'];
        $this->post('/finance/paypal-accounts/'.$key, $data)->assertRedirect(route('finance.home', ['paypal_currency' => 'USD', 'balance_tab' => 'paypal']));
        $this->assertDatabaseHas('finance_payment_accounts', ['id' => $account->id, 'account_name' => '美国主账户', 'activity_account' => '尚云', 'updated_by' => 1]);
        $response = $this->get('/finance')->assertOk()->assertSee('美国主账户')->assertSee('财务备注 &lt;b&gt;原样显示&lt;/b&gt;', false)
            ->assertSee(route('finance.records', ['dataset' => 'paypal-orders', 'account' => '尚云']))
            ->assertViewHas('paypalBalance', fn ($balance) => $balance['cards'][$key]['display']['total'] === '110.0000000000000000');
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@data-edit-payment-account]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-paypal-account]//a[contains(@href,"/finance/records/paypal-orders?account=")]')->length);
        $this->post('/finance/paypal-accounts/'.$key, [...$data, 'remark' => '更新备注'])->assertRedirect();
        $this->assertDatabaseCount('finance_paypal_account_profiles', 0);
        $this->assertDatabaseCount('finance_balance_snapshots', 1);
    }

    public function test_paypal_profile_changes_require_permissions_and_valid_unique_names(): void
    {
        $account = $this->financePayPalAccount('尚云');
        $this->financePayPalAccount('立鉑');
        $key = 'finance-'.$account->id;
        $url = '/finance/paypal-accounts/'.$key;
        $data = ['profile_key' => $key, 'display_name' => '美国账户', 'remark' => '备注'];
        $this->post($url, [...$data, 'display_name' => '立鉑'])->assertSessionHasErrors(['display_name'], null, 'paypalAccount');
        $this->post($url, [...$data, 'display_name' => ''])->assertSessionHasErrors(['display_name'], null, 'paypalAccount');
        $this->post($url, [...$data, 'remark' => str_repeat('a', 1001)])->assertSessionHasErrors(['remark'], null, 'paypalAccount');
        $this->assertSame('尚云', $account->fresh()->account_name);
        $this->post('/finance/paypal-accounts/unknown', $data)->assertNotFound();
        $this->user->manage = false;
        $this->post($url, $data)->assertForbidden();
        $this->get('/finance')->assertOk()->assertDontSee('data-edit-payment-account=', false)->assertSee('查看尚云的活动');
        $this->user->manage = true;
        DB::table('portal_menus')->where('code', 'finance_home')->update(['is_enabled' => false]);
        $this->post($url, $data)->assertForbidden();
    }

    public function test_channel_tabs_keep_both_overviews_visible_and_credit_overview_includes_all_accounts(): void
    {
        $this->paymentBalance(['account' => 'A', 'withdrawable' => '10', 'pending' => '20', 'reserve' => '30']);
        $this->paymentBalance(['account' => 'B', 'withdrawable' => '100', 'pending' => '200', 'reserve' => '300']);
        $this->get('/finance')->assertOk()->assertViewHas('activeBalanceTab', 'paypal');
        $response = $this->get('/finance?balance_tab=credit-card&balance_account=A')->assertOk()
            ->assertViewHas('activeBalanceTab', 'credit-card')
            ->assertViewHas('paymentBalance', fn ($balance) => $balance['summary']['total'] === '60.000000000000')
            ->assertViewHas('paymentOverview', fn ($balance) => $balance['summary']['total'] === '660.000000000000');
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(3, $xpath->query('//*[@role="tab"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="finance-tab-credit-card" and @aria-selected="true"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="finance-panel-paypal" and @hidden]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="finance-panel-credit-card" and not(@hidden)]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="finance-panel-conflux" and @hidden]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-paypal-summary and not(ancestor::*[@role="tabpanel"])]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-credit-summary and not(ancestor::*[@role="tabpanel"])]')->length);
        $this->assertSame(0, $xpath->query('//*[@role="tabpanel"]//*[@data-paypal-summary or @data-credit-summary]')->length);
        $this->post('/finance/exchange-rates', [
            'rates' => [['currency' => 'CNY', 'units_per_usd' => '7.2']],
            'balance_tab' => 'credit-card', 'paypal_currency' => 'CNY', 'balance_account' => 'A',
        ])->assertRedirect(route('finance.home', ['paypal_currency' => 'CNY', 'balance_account' => 'A', 'balance_tab' => 'credit-card']));
    }

    public function test_credit_validation_keeps_its_tab_selected(): void
    {
        $this->from('/finance?balance_tab=credit-card')->post('/finance/balances/xuzong2', [
            'balance_tab' => 'credit-card', 'account' => 'A', 'currency' => 'USD',
        ])->assertSessionHasErrors(['withdrawable', 'pending', 'reserve', 'as_of']);
        $this->get('/finance')->assertOk()->assertViewHas('activeBalanceTab', 'credit-card');
    }

    public function test_exchange_rate_updates_are_validated_atomically_and_require_manage_permission(): void
    {
        $this->post('/finance/exchange-rates', ['rates' => [['currency' => 'CNY', 'units_per_usd' => '7.2']]])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('finance_exchange_rates', ['currency' => 'CNY', 'units_per_usd' => '7.2', 'updated_by' => 1]);
        foreach (['0', '-1', '9999999999999', '0.00000000001', 'garbage'] as $invalid) {
            $this->post('/finance/exchange-rates', ['rates' => [
                ['currency' => 'CNY', 'units_per_usd' => '8'], ['currency' => 'EUR', 'units_per_usd' => $invalid],
            ]])->assertSessionHasErrors(['rates.1.units_per_usd'], null, 'exchangeRates');
            $this->assertDatabaseHas('finance_exchange_rates', ['currency' => 'CNY', 'units_per_usd' => '7.2']);
        }
        $this->post('/finance/exchange-rates', ['rates' => [['currency' => 'USD', 'units_per_usd' => '2']]])->assertSessionHasErrors(['rates'], null, 'exchangeRates');
        $this->post('/finance/exchange-rates', ['rates' => [['currency' => 'EUR', 'units_per_usd' => '0.8'], ['currency' => 'EUR', 'units_per_usd' => '0.9']]])->assertSessionHasErrors(['rates.0.currency'], null, 'exchangeRates');
        $this->user->manage = false;
        $this->post('/finance/exchange-rates', ['rates' => [['currency' => 'CNY', 'units_per_usd' => '9']]])->assertForbidden();
        $this->assertDatabaseHas('finance_exchange_rates', ['currency' => 'CNY', 'units_per_usd' => '7.2']);
        $this->user->manage = true;
        $this->post('/finance/exchange-rates', ['rates' => [['currency' => 'CNY', 'units_per_usd' => '']]])->assertRedirect();
        $this->assertDatabaseMissing('finance_exchange_rates', ['currency' => 'CNY']);
        DB::table('portal_menus')->where('code', 'finance_home')->update(['is_enabled' => false]);
        $this->post('/finance/exchange-rates', ['rates' => [['currency' => 'CNY', 'units_per_usd' => '7']]])->assertForbidden();
    }

    public function test_balance_snapshots_use_latest_finance_response_and_separate_currencies_and_missing_accounts(): void
    {
        $account = $this->financePayPalAccount('A');
        $this->financePayPalAccount('Missing');
        $this->financeBalanceSnapshot($account, ['USD' => ['10', null], 'EUR' => ['20', '5']], '2026-11-03 01:00:00');
        // A later insert with an older balance time must not replace the latest snapshot.
        $this->financeBalanceSnapshot($account, ['USD' => ['999', '999'], 'GBP' => ['999', '999']], '2026-11-01 01:00:00');
        $this->get('/finance')->assertOk()->assertSee('Missing')->assertSee('暂无余额快照')
            ->assertViewHas('totals', fn ($totals) => $totals['USD']['available'] === '10.00000000'
                && $totals['EUR']['total'] === '25.00000000' && $totals['USD']['withheld'] === null && ! $totals->has('GBP'));
    }

    public function test_only_configured_finance_accounts_are_visible_before_api_data_arrives(): void
    {
        $dashboard = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertCount(0, $dashboard['cards']);
        $this->assertCount(0, $dashboard['missing']);
        $this->assertCount(0, $dashboard['totals']);
        $response = $this->get('/finance')->assertOk()->assertSee('可用结余合计')->assertSee('尚未可用合计')->assertSee('余额总计')
            ->assertSee(__('finance.paypal.no_accounts'))->assertSee('接入余额数据后自动计算合计');
        $this->assertSame([], $response->viewData('paymentAccountEditors'));
        $account = $this->financePayPalAccount('独立财务账户');
        $dashboard = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertSame(['finance-'.$account->id], $dashboard['cards']->keys()->all());
        $this->assertSame(['独立财务账户'], $dashboard['missing']->all());
        $this->assertNull($dashboard['summary']['total']);
        $this->get('/finance')->assertOk()->assertSee('独立财务账户')->assertSee('暂无余额快照')
            ->assertViewHas('paymentAccountEditors', fn ($editors) => ! $editors['finance-'.$account->id]['type_locked']);
        $this->get('/finance/records/paypal-orders')->assertOk()
            ->assertViewHas('accounts', fn ($accounts) => $accounts->all() === ['独立财务账户']);
    }

    public function test_finance_accounts_sum_currencies_without_name_aliases_or_inferred_zero_balances(): void
    {
        foreach (['SHANGYUN', '尚云', '立铂', '启盛', '晟达', '维驰'] as $index => $name) {
            $account = $this->financePayPalAccount($name);
            $balances = ['USD' => ['10.10', $index === 0 ? '4.20' : '0']];
            if ($index === 0) {
                $balances['EUR'] = ['20', '5'];
            }
            $this->financeBalanceSnapshot($account, $balances);
        }
        $dashboard = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertCount(6, $dashboard['cards']);
        $this->assertCount(0, $dashboard['missing']);
        $this->assertFalse($dashboard['incomplete']);
        $this->assertSame('60.60000000', $dashboard['totals']['USD']['available']);
        $this->assertSame('4.20000000', $dashboard['totals']['USD']['withheld']);
        $this->assertSame('64.80000000', $dashboard['totals']['USD']['total']);
        $this->assertSame('25.00000000', $dashboard['totals']['EUR']['total']);
        $this->get('/finance')->assertOk()->assertDontSee('EUR 未设置汇率')
            ->assertViewHas('paypalBalance', fn ($balance) => $balance['summary']['total'] === '64.8000000000000000');
        $this->financeBalanceSnapshot($account, ['USD' => ['10.10', null]], '2026-11-04 01:00:00');
        $missingHold = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertNull($missingHold['totals']['USD']['withheld']);
        $this->assertNull($missingHold['totals']['USD']['total']);
        $this->assertTrue($missingHold['incomplete']);
    }

    public function test_card_actions_and_connection_states_follow_the_latest_balance_refresh(): void
    {
        $fresh = $this->financePayPalAccount('尚未连接');
        $connected = $this->financePayPalAccount('已连接账户');
        $error = $this->financePayPalAccount('异常账户');
        $closed = $this->financePayPalAccount('关闭账户', ['status' => 'disabled']);
        $this->financeBalanceSnapshot($connected, ['USD' => ['100', '10']]);
        $this->financeBalanceSnapshot($error, ['USD' => ['20', '0']]);
        foreach ([[$error, 'balances', 'success'], [$error, 'balances', 'failed'], [$error, 'balances', 'running'],
            [$connected, 'transaction_search', 'failed'], [$closed, 'balances', 'success']] as [$account, $operation, $status]) {
            DB::table('finance_sync_logs')->insert([
                'payment_account_id' => $account->id, 'operation' => $operation, 'status' => $status,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $response = $this->get('/finance')->assertOk();
        $cards = $response->viewData('paypalBalance')['cards'];
        $this->assertSame(array_map(fn ($account) => 'finance-'.$account->id, [$connected, $closed, $error, $fresh]), $cards->keys()->all());
        $this->assertSame('disconnected', $cards['finance-'.$fresh->id]['connection_status']);
        $this->assertSame('connected', $cards['finance-'.$connected->id]['connection_status']);
        $this->assertSame('error', $cards['finance-'.$error->id]['connection_status']);
        $this->assertSame('closed', $cards['finance-'.$closed->id]['connection_status']);
        $this->assertSame('20.0000000000000000', $cards['finance-'.$error->id]['display']['total']);
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        foreach ([$fresh, $connected, $error, $closed] as $account) {
            $card = '//*[@data-paypal-account="finance-'.$account->id.'"]';
            $this->assertSame(1, $xpath->query($card.'//div[@class="paypal-account-header"]//form[@data-refresh-account]')->length);
            $this->assertSame(1, $xpath->query($card.'//div[@class="paypal-account-actions"]//form[@data-delete-account]')->length);
            $this->assertSame(0, $xpath->query($card.'//a[contains(text(),"余额变动")]')->length);
            $this->assertSame(0, $xpath->query($card.'//div[@class="finance-account-maintenance"]')->length);
        }
        $this->assertSame(1, $xpath->query('//*[@data-paypal-account="finance-'.$fresh->id.'"]//*[@data-connection-status and @data-state="disconnected" and not(@hidden)]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-paypal-account="finance-'.$closed->id.'"]//form[@data-refresh-account]//button[@disabled]')->length);
        $this->get('/finance')->assertOk()
            ->assertDontSee('id="finance-account-activity"', false)
            ->assertDontSee('finance-account-activity.js', false)
            ->assertSee(route('finance.records', ['dataset' => 'paypal-orders', 'account' => $connected->activity_account]))
            ->assertViewHas('paymentAccountActivities', fn ($activities) => $activities['finance-'.$connected->id]['account'] === $connected->activity_account
                && $activities['finance-'.$connected->id]['sync_allowed']);
        $this->user->manage = false;
        $response = $this->get('/finance')->assertOk()->assertSee('连接异常')->assertSee('已关闭');
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//form[@data-refresh-account or @data-delete-account]')->length);
        Http::assertNothingSent();
    }

    public function test_installation_does_not_seed_private_finance_business_data(): void
    {
        $this->assertDatabaseCount('finance_domain_groups', 0);
        $this->assertDatabaseCount('finance_payment_accounts', 0);
        $this->assertDatabaseCount('finance_records', 0);
        $this->assertDatabaseCount('finance_monthly_exchange_rates', 0);
        $accounts = require database_path('migrations/2026_11_04_000004_restore_finance_paypal_account_cards.php');
        $rates = require database_path('migrations/2026_11_04_000016_import_supplied_finance_monthly_rates.php');
        $accounts->up();
        $rates->up();
        $this->assertDatabaseCount('finance_payment_accounts', 0);
        $this->assertDatabaseCount('finance_monthly_exchange_rates', 0);
        $existing = $this->financePayPalAccount('User account');
        DB::table('finance_monthly_exchange_rates')->insert(['month' => '2026-10', 'currency' => 'USD', 'cny_rate' => '7']);
        $accounts->up();
        $rates->up();
        $this->assertDatabaseCount('finance_payment_accounts', 1);
        $this->assertSame('User account', $existing->fresh()->account_name);
        $this->assertDatabaseHas('finance_monthly_exchange_rates', ['cny_rate' => '7']);
        Http::assertNothingSent();
    }

    public function test_balance_card_displays_fetch_time_separately_from_balance_data_time(): void
    {
        $account = $this->financePayPalAccount('双时间测试');
        $this->financeBalanceSnapshot($account, ['USD' => ['100', '10']], '2026-11-05 01:00:00');
        DB::table('finance_balance_snapshots')->where('payment_account_id', $account->id)
            ->update(['created_at' => '2026-11-05 02:30:45']);
        $card = app(FinancePayPalBalanceService::class)->dashboard()['cards']['finance-'.$account->id];
        $this->assertSame('2026-11-05 02:30:45', $card['fetched_at']->format('Y-m-d H:i:s'));
        $this->get('/finance')->assertOk()
            ->assertSee('接口刷新时间')->assertSee('2026-11-05 10:30:45')
            ->assertSee('余额数据时间')->assertSee('2026-11-05 09:00');
    }

    public function test_quick_disable_requires_permission_and_preserves_account_data(): void
    {
        foreach (['paypal', 'credit_card'] as $type) {
            $account = $this->financePayPalAccount('快捷停用-'.$type, ['account_type' => $type]);
            $this->financeBalanceSnapshot($account, ['USD' => ['10', '0']]);
            $url = '/finance/payment-accounts/finance-'.$account->id.'/disable';
            $this->user->manage = false;
            $this->postJson($url)->assertForbidden();
            $this->assertSame('enabled', $account->fresh()->status);
            $this->user->manage = true;
            $this->postJson($url)->assertOk()->assertJsonPath('ok', true);
            $this->assertSame('disabled', $account->fresh()->status);
            $this->assertFalse($account->fresh()->trashed());
            $this->assertDatabaseHas('finance_balance_snapshots', ['payment_account_id' => $account->id]);
            $this->assertSame($account->account_name, $account->fresh()->account_name);
        }
    }

    public function test_paypal_and_credit_names_are_unique_within_each_type(): void
    {
        $paypal = $this->financePayPalAccount('同名账户');
        $creditData = $this->newPaymentAccountData(['account_name' => '同名账户', 'account_type' => 'credit_card']);
        $this->post('/finance/payment-accounts', $creditData)->assertSessionHasNoErrors();
        $credit = FinancePaymentAccount::query()->where('account_type', 'credit_card')->sole();
        $this->assertSame($paypal->account_name, $credit->account_name);
        $this->post('/finance/payment-accounts', $creditData)->assertSessionHasErrors(['account_name'], null, 'paymentAccount');
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData(['account_name' => '同名账户']))
            ->assertSessionHasErrors(['account_name'], null, 'paymentAccount');
        $this->delete('/finance/payment-accounts/finance-'.$credit->id)->assertRedirect();
        $this->post('/finance/payment-accounts', $creditData)->assertSessionHasNoErrors();
        $this->assertFalse($paypal->fresh()->trashed());
    }

    private function financePayPalAccount(string $name, array $overrides = []): FinancePaymentAccount
    {
        $data = $this->newPaymentAccountData(['account_name' => $name]);
        unset($data['paypal_currency']);

        return FinancePaymentAccount::query()->create([
            ...$data, 'activity_account' => $name, 'account_code' => 'FA-TEST-'.(FinancePaymentAccount::withTrashed()->count() + 1),
            ...$overrides,
        ]);
    }

    private function financeBalanceSnapshot(FinancePaymentAccount $account, array $amounts, string $asOf = '2026-11-03 01:00:00'): void
    {
        $balances = [];
        foreach ($amounts as $currency => $values) {
            [$available, $withheld] = $values;
            $total = array_key_exists(2, $values) ? $values[2]
                : ($available !== null && $withheld !== null ? bcadd($available, $withheld, 8) : null);
            $balance = ['currency' => $currency];
            foreach (['available_balance' => $available, 'withheld_balance' => $withheld, 'total_balance' => $total] as $field => $value) {
                if ($value !== null) {
                    $balance[$field] = ['currency_code' => $currency, 'value' => $value];
                }
            }
            $balances[] = $balance;
        }
        DB::table('finance_balance_snapshots')->insert([
            'payment_account_id' => $account->id, 'sync_log_id' => 1, 'as_of' => $asOf,
            'payload' => json_encode(['balances' => $balances], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createPayPalBalanceTables(): void
    {
        Schema::create('logistics_paypal_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_name');
            $table->boolean('is_enabled');
        });
        Schema::create('logistics_paypal_balance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->integer('logistics_paypal_account_id');
            $table->string('currency_code');
            $table->decimal('available_balance', 18, 8)->nullable();
            $table->decimal('withheld_balance', 18, 8)->nullable();
            $table->decimal('total_balance', 18, 8)->nullable();
            $table->dateTime('as_of_time');
        });
    }

    public function test_payment_balance_converts_all_currencies_and_keeps_native_totals_and_account_scope(): void
    {
        $this->paymentBalance(['account' => 'A', 'currency' => 'USD', 'withdrawable' => '10', 'pending' => '20', 'reserve' => '30']);
        $this->paymentBalance(['account' => 'A', 'currency' => 'EUR', 'withdrawable' => '2', 'pending' => '3', 'reserve' => '4', 'usd_rate' => '1.25']);
        $this->paymentBalance(['account' => 'B', 'currency' => 'USD', 'withdrawable' => '-1', 'pending' => '0', 'reserve' => '0']);
        $service = app(FinancePaymentBalanceService::class);
        $dashboard = $service->dashboard();
        $this->assertSame('USD', $dashboard['rows'][0]['currency']);
        $this->assertSame('59.0000', $dashboard['rows'][0]['total']);
        $this->assertSame('9.0000', $dashboard['rows'][1]['total']);
        $this->assertSame('70.25', $service->format($dashboard['summary']['total']));
        $this->assertSame('71.25', $service->format($service->dashboard('A')['summary']['total']));
        $this->assertSame('999,999,999,999.13', $service->format('999999999999.125'));
        $this->assertSame('-12.35', $service->format('-12.345'));
        $this->assertSame('0.00', $service->format('0'));
        $this->assertSame('—', $service->format(null));
    }

    public function test_missing_exchange_rate_never_becomes_a_partial_or_zero_converted_balance(): void
    {
        $service = app(FinancePaymentBalanceService::class);
        $this->assertNull($service->dashboard()['summary']['total']);
        $this->paymentBalance(['currency' => 'USD']);
        $this->paymentBalance(['currency' => 'EUR', 'usd_rate' => null]);
        $dashboard = $service->dashboard();
        $this->assertNull($dashboard['summary']['withdrawable']);
        $this->assertNull($dashboard['summary']['total']);
        $this->assertSame(['EUR'], $dashboard['missingRates']->all());
        $this->assertSame('6.0000', $dashboard['rows'][1]['total']);
    }

    public function test_balance_update_is_permission_checked_and_recalculates_in_shanghai_timezone(): void
    {
        $data = ['account' => 'A', 'currency' => 'usd', 'withdrawable' => '10.12', 'pending' => '20.34', 'reserve' => '30.56', 'usd_rate' => '2', 'as_of' => now('Asia/Shanghai')->subMinute()->format('Y-m-d\TH:i')];
        $this->post('/finance/balances/xuzong2', $data)->assertRedirect(route('finance.home', ['balance_account' => 'A', 'balance_tab' => 'credit-card']));
        $this->assertDatabaseCount('finance_payment_balances', 1);
        $entry = FinancePaymentBalance::first();
        $this->assertSame('USD', $entry->currency);
        $this->assertSame('1.00000000', $entry->usd_rate);
        $this->assertSame($data['as_of'], $entry->as_of->setTimezone('Asia/Shanghai')->format('Y-m-d\TH:i'));
        $this->post('/finance/balances/xuzong2', [...$data, 'pending' => '25.34'])->assertRedirect();
        $this->assertDatabaseCount('finance_payment_balances', 1);
        $this->assertSame('66.02', app(FinancePaymentBalanceService::class)->format(app(FinancePaymentBalanceService::class)->dashboard()['summary']['total']));
        $this->user->manage = false;
        $this->post('/finance/balances/xuzong2', [...$data, 'pending' => '999'])->assertForbidden();
        $this->assertSame('25.3400', FinancePaymentBalance::first()->pending);
        $this->user->manage = true;
        $this->post('/finance/balances/xuzong2', [...$data, 'as_of' => now('Asia/Shanghai')->addDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('as_of');
        $this->post('/finance/balances/xuzong2', [...$data, 'pending' => '100000000000000.0000'])->assertSessionHasErrors('pending');
        $this->post('/finance/balances/xuzong2', [...$data, 'currency' => 'EUR', 'usd_rate' => '0'])->assertSessionHasErrors('usd_rate');
        $this->assertDatabaseCount('finance_payment_balances', 1);
    }

    public function test_relay_balance_refresh_signs_requests_and_uses_pinned_tls_and_verified_api_total(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $capturedOptions = [];
        Http::fake(function ($request, $options) use ($account, &$capturedOptions) {
            $capturedOptions = $options;
            $this->assertSame('balances', json_decode($request->body(), true)['operation']);
            $this->assertStringContainsString('"query":{}', $request->body());
            $timestamp = $request->header('X-Relay-Timestamp')[0];
            $nonce = $request->header('X-Relay-Nonce')[0];
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
            $expected = hash_hmac('sha256', "POST\n/relay/v1/paypal/read\n$timestamp\n$nonce\n".hash('sha256', $request->body()), base64_decode($account->relay_hmac_secret, true));
            $this->assertSame($expected, $request->header('X-Relay-Signature')[0]);

            return $this->signedRelayResponse($request, $account, ['as_of_time' => '2026-11-03T01:00:00Z', 'balances' => [[
                'currency' => 'USD', 'available_balance' => ['value' => '10', 'currency_code' => 'USD'],
                'withheld_balance' => ['value' => '2', 'currency_code' => 'USD'], 'total_balance' => ['value' => '13', 'currency_code' => 'USD'],
            ]]]);
        });
        $response = $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh')->assertOk()->assertJson(['ok' => true])
            ->assertJsonPath('connection_status', 'connected')->assertJsonPath('refresh_allowed', true);
        $this->assertStringContainsString('13.00 USD', $response->json('balance_html'));
        $this->assertStringContainsString('data-paypal-total="total">13.00', $response->json('summary_html'));
        $this->assertStringNotContainsString($account->relay_hmac_secret, $response->getContent());
        $this->assertStringNotContainsString($account->getRawOriginal('relay_hmac_secret'), $response->getContent());
        $this->assertTrue($capturedOptions['verify']);
        $this->assertFalse($capturedOptions['allow_redirects']);
        $this->assertSame(['sha1' => strtolower($account->relay_certificate_sha1)], $capturedOptions['stream_context']['ssl']['peer_fingerprint']);
        $this->assertTrue($capturedOptions['stream_context']['ssl']['verify_peer']);
        $this->assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, $capturedOptions['crypto_method']);
        $this->assertDatabaseCount('finance_balance_snapshots', 1);
        $this->assertDatabaseHas('finance_sync_logs', ['status' => 'success', 'page_count' => 1]);
        $dashboard = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertSame('13.0000000000000000', $dashboard['summary']['total']);
    }

    public function test_bad_relay_signatures_keep_last_balance_and_do_not_save_raw_or_secrets(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        Http::fake(fn () => Http::response('{"balances":[]}', 200, ['X-Relay-Response-Timestamp' => (string) now()->timestamp, 'X-Relay-Response-Signature' => str_repeat('0', 64)]));
        $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh')->assertStatus(422)->assertJson(['ok' => false])
            ->assertJsonPath('connection_status', 'error');
        $this->assertDatabaseCount('finance_balance_snapshots', 0);
        $this->assertDatabaseCount('finance_sync_pages', 0);
        $this->assertDatabaseHas('finance_sync_logs', ['status' => 'failed', 'error_code' => 'signature']);
        $this->assertStringNotContainsString($account->relay_hmac_secret, json_encode(DB::table('finance_sync_logs')->get()));
        $account->update(['status' => 'disabled']);
        Http::fake();
        $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh')->assertStatus(422);
        Http::assertNothingSent();
        $this->user->manage = false;
        $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh')->assertForbidden();
    }

    public function test_unsigned_relay_rejections_report_the_http_status_without_saving_data(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $sequence = Http::fakeSequence();
        foreach ([401, 403, 200] as $status) {
            $sequence->push(['ok' => false, 'error' => 'forbidden', 'untrusted' => 'must-not-be-saved'], $status);
        }
        foreach ([401 => 'relay_unauthorized', 403 => 'relay_forbidden', 200 => 'response_unsigned'] as $status => $reason) {
            $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh')
                ->assertStatus(422)->assertJson(['ok' => false])
                ->assertJsonPath('error', __('finance.sync.errors.'.$reason));
            $this->assertDatabaseHas('finance_sync_logs', ['status' => 'failed', 'error_code' => $reason]);
        }
        $this->assertDatabaseCount('finance_balance_snapshots', 0);
        $this->assertDatabaseCount('finance_sync_pages', 0);
        $this->assertStringNotContainsString('must-not-be-saved', json_encode(DB::table('finance_sync_logs')->get()));
    }

    public function test_relay_transaction_pagination_keeps_raw_data_and_updates_same_identity(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        Http::fake(function ($request) use ($account) {
            $query = json_decode($request->body(), true)['query'];
            $this->assertSame('all', $query['fields']);
            $this->assertSame('Y', $query['balance_affecting_records_only']);
            $page = (int) $query['page'];

            return $this->signedRelayResponse($request, $account, ['page' => $page, 'total_pages' => 2, 'total_items' => 2, 'transaction_details' => [[
                'transaction_info' => ['transaction_id' => 'TX-'.$page, 'transaction_event_code' => 'T0006',
                    'transaction_initiation_date' => '2026-11-01T01:00:00Z', 'transaction_amount' => ['value' => '10', 'currency_code' => 'USD'],
                    'fee_amount' => ['value' => '-1', 'currency_code' => 'USD'], 'transaction_status' => 'S'],
                'payer_info' => ['email_address' => 'test@example.test'],
            ]]]);
        });
        $url = '/finance/payment-accounts/finance-'.$account->id.'/transactions';
        $data = ['from' => '2026-11-01', 'to' => '2026-11-01', 'timezone' => 'UTC'];
        $this->postJson($url, $data)->assertOk()->assertJsonPath('ok', true)->assertJsonPath('record_count', 2)
            ->assertJsonStructure(['last_updated_at']);
        $download = $this->postJson($url, [...$data, 'export_after_sync' => 1])->assertOk()
            ->assertHeader('X-Finance-Record-Count', '2');
        $this->assertSame(3, $this->readDownload($download->streamedContent())->getHighestDataRow());
        Http::assertSentCount(4);
        $this->assertDatabaseCount('finance_payment_transactions', 2);
        $this->assertDatabaseCount('finance_records', 2);
        $this->assertDatabaseCount('finance_sync_pages', 4);
        $this->assertDatabaseHas('finance_sync_logs', ['status' => 'success', 'page_count' => 2, 'record_count' => 2]);
        $record = FinanceRecord::query()->first();
        $this->assertSame('9.00000000', $record->payload[FinanceCatalog::dataset('paypal-orders')['headers'][9]]);
        $transaction = DB::table('finance_payment_transactions')->first();
        $this->get('/finance/transactions/'.$transaction->id.'/raw')->assertOk()->assertJsonPath('payer_info.email_address', 'test@example.test');
        $this->financeBalanceSnapshot($account, ['USD' => ['100', '10']]);
        $this->user->manage = false;
        $this->delete('/finance/payment-accounts/finance-'.$account->id)->assertForbidden();
        $this->assertFalse($account->fresh()->trashed());
        $this->user->manage = true;
        $other = $this->financePayPalAccount('保留账户');
        $otherRecord = $record->replicate();
        $otherRecord->account = $other->activity_account;
        $otherRecord->fingerprint = hash('sha256', 'other-account-record');
        $otherRecord->save();
        DB::table('finance_settlement_supplements')->insert([
            ['finance_record_id' => $record->id, 'payload' => '{}'],
            ['finance_record_id' => $otherRecord->id, 'payload' => '{}'],
        ]);
        DB::table('finance_period_locks')->insert(['month' => '2026-10', 'report_snapshot' => json_encode([
            ['account_id' => $account->id, 'amount' => '10'], ['account_id' => $other->id, 'amount' => '20'],
        ])]);
        $this->deleteJson('/finance/payment-accounts/finance-'.$account->id)->assertOk()->assertJsonPath('ok', true)->assertJsonPath('redirect', route('finance.home'));
        $this->assertSoftDeleted('finance_payment_accounts', ['id' => $account->id]);
        $this->assertDatabaseCount('finance_payment_transactions', 0);
        $this->assertDatabaseCount('finance_records', 1);
        $this->assertDatabaseHas('finance_records', ['id' => $otherRecord->id]);
        $this->assertDatabaseCount('finance_settlement_supplements', 1);
        $this->assertDatabaseHas('finance_settlement_supplements', ['finance_record_id' => $otherRecord->id]);
        $this->assertDatabaseCount('finance_balance_snapshots', 0);
        $this->assertDatabaseCount('finance_sync_pages', 0);
        $this->assertDatabaseCount('finance_sync_logs', 0);
        $this->assertSame([['account_id' => $other->id, 'amount' => '20']], json_decode(DB::table('finance_period_locks')->where('month', '2026-10')->value('report_snapshot'), true));
        $balance = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertCount(1, $balance['cards']);
        $this->assertNull($balance['summary']['total']);
        $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh')->assertNotFound();
    }

    public function test_credit_account_deletion_clears_finance_data_but_preserves_external_orders(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_id');
        });
        $account = $this->financePayPalAccount('信用卡删除测试', ['account_type' => 'credit_card']);
        $record = FinanceRecord::query()->create([
            'dataset' => 'xuzong2-details', 'account' => $account->activity_account, 'reference' => 'CREDIT-DELETE',
            'occurred_at' => now(), 'source_timezone' => 'Asia/Shanghai', 'currency' => 'USD',
            'fingerprint' => hash('sha256', 'credit-delete'), 'payload' => [],
        ]);
        DB::table('finance_payment_balances')->insert([
            'channel' => 'xuzong2', 'account' => $account->activity_account, 'currency' => 'USD',
            'withdrawable' => 10, 'pending' => 0, 'reserve' => 0, 'as_of' => now(),
        ]);
        DB::table('finance_settlement_supplements')->insert(['finance_record_id' => $record->id, 'payload' => '{}']);
        DB::table('logistics_orders')->insert(['transaction_id' => 'CREDIT-DELETE']);
        $this->deleteJson('/finance/payment-accounts/finance-'.$account->id)->assertOk();
        $this->assertDatabaseMissing('finance_records', ['id' => $record->id]);
        $this->assertDatabaseCount('finance_payment_balances', 0);
        $this->assertDatabaseCount('finance_settlement_supplements', 0);
        $this->assertDatabaseHas('logistics_orders', ['transaction_id' => 'CREDIT-DELETE']);
    }

    public function test_relay_rejects_legacy_or_short_secrets_before_sending_credentials(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        Http::fake();
        foreach (['plain-text-secret-must-not-be-used-for-hmac', base64_encode('too-short')] as $secret) {
            $account->relay_hmac_secret = $secret;
            try {
                app(\App\Portals\Finance\FinanceRelayClient::class)->read($account, 'balances');
                $this->fail('Invalid Base64 credentials must be rejected before HTTP');
            } catch (\App\Portals\Finance\FinanceRelayException $e) {
                $this->assertSame('secret_encoding', $e->reason);
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_balance_snapshots', 0);
    }

    public function test_relay_rejects_stale_responses_and_write_operations_before_saving(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        Http::fake();
        try {
            app(\App\Portals\Finance\FinanceRelayClient::class)->read($account, 'refund');
            $this->fail('Write operation must be rejected');
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            $this->assertSame('operation', $e->reason);
        }
        Http::assertNothingSent();
        Http::fake(fn ($request) => $this->signedRelayResponse($request, $account, ['as_of_time' => '2026-11-01T00:00:00Z', 'balances' => []], 200, now()->subMinutes(6)->timestamp));
        $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh')->assertStatus(422);
        $this->assertDatabaseCount('finance_balance_snapshots', 0);
        $this->delete('/finance/payment-accounts/finance-'.$account->id)->assertRedirect();
        $this->assertSoftDeleted('finance_payment_accounts', ['id' => $account->id]);
    }

    public function test_monthly_accounting_rates_classification_and_locked_reports_are_immutable(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $rule = ['name' => '结账付款', 'source_field' => 'transaction_event_code', 'match_type' => 'prefix', 'match_value' => 'T000',
            'category' => '销售收入', 'direction' => 'income', 'loss_subject' => '', 'priority' => 10, 'is_enabled' => 1];
        $this->post('/finance/settings/accounting/rule', $rule)->assertRedirect();
        $this->post('/finance/settings/accounting/rate', ['month' => '2026-10', 'currency' => 'USD', 'cny_rate' => '7.25', 'source' => '财务确认'])->assertRedirect();
        Http::fake(fn ($request) => $this->signedRelayResponse($request, $account, ['page' => 1, 'total_pages' => 1, 'total_items' => 1, 'transaction_details' => [[
            'transaction_info' => ['transaction_id' => 'LOCK-1', 'transaction_event_code' => 'T0006', 'transaction_initiation_date' => '2026-10-03T01:00:00Z',
                'transaction_amount' => ['value' => '10', 'currency_code' => 'USD'], 'fee_amount' => ['value' => '-1', 'currency_code' => 'USD'], 'transaction_status' => 'S'],
        ]]]));
        $url = '/finance/payment-accounts/finance-'.$account->id.'/transactions';
        $dates = ['from' => '2026-10-03', 'to' => '2026-10-03', 'timezone' => 'UTC'];
        $this->post($url, $dates)->assertRedirect();
        $this->assertDatabaseHas('finance_payment_transactions', ['category' => '销售收入', 'direction' => 'income', 'accounting_month' => '2026-10', 'cny_amount' => '72.50000000']);
        $accounting = app(\App\Portals\Finance\FinanceAccountingService::class);
        $this->assertSame('72.50000000', $accounting->report('2026-10')[0]['cny_amount']);
        $this->get('/finance/settings/accounting')->assertOk()->assertSee('7.25');
        $this->get('/finance/records/paypal-orders')->assertOk()->assertSee('销售收入')->assertSee('72.5');
        $this->get('/finance/records/paypal-orders?'.http_build_query(['conditions' => [['field' => 'category', 'operator' => 'equals', 'value' => '销售收入']]]))->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1);
        $export = $this->get('/finance/records/paypal-orders/export?'.http_build_query(['export_scope' => 'filtered', 'export_format' => 'xlsx']))->assertOk();
        $sheet = $this->readDownload($export->streamedContent());
        $this->assertSame('销售收入', $sheet->getCell('AQ2')->getValue());
        $this->assertSame('折合人民币', $sheet->getCell($sheet->getHighestColumn().'1')->getValue());
        $this->get('/finance/reports/summary?view=transactions&month=2026-10')->assertOk()->assertSee('应结算')->assertSee('Shopify');
        $this->get('/finance/reports/summary?view=transactions&month=2026-10&download=1')->assertOk();
        $this->post('/finance/settings/accounting/lock', ['month' => '2026-10'])->assertRedirect()->assertSessionHasNoErrors();
        $frozen = $accounting->report('2026-10');
        $account->update(['account_name' => '改名不改变已结账报表']);
        $this->post('/finance/settings/accounting/rule', [...$rule, 'id' => DB::table('finance_transaction_type_mappings')->value('id'), 'category' => '修改分类'])->assertRedirect();
        $this->post('/finance/settings/accounting/rate', ['month' => '2026-10', 'currency' => 'USD', 'cny_rate' => '8'])->assertSessionHasErrors('month');
        $this->post('/finance/settings/accounting/reapply', ['month' => '2026-10'])->assertSessionHasErrors('month');
        $this->post($url, $dates)->assertRedirect();
        $this->assertDatabaseHas('finance_sync_logs', ['status' => 'failed', 'error_code' => 'period_locked']);
        $this->assertSame($frozen, $accounting->report('2026-10'));
        $this->post('/finance/settings/types', ['raw_type' => 'T0006', 'category' => '配置中心最新分类'])->assertRedirect()->assertSessionHasNoErrors();
        $activityQuery = [
            'export_scope' => 'accounts', 'selected_accounts' => [$account->activity_account],
            'type_category' => '配置中心最新分类', ...$dates, 'report_fields_customized' => '1', 'report_columns' => [42],
        ];
        $activity = $this->readDownload($this->get('/finance/records/paypal-orders/export?'.http_build_query($activityQuery))->assertOk()->streamedContent());
        $this->assertSame('配置中心最新分类', $activity->getCell('A2')->getValue());
        $this->assertSame($frozen, $accounting->report('2026-10'));
        $this->assertDatabaseHas('finance_payment_transactions', ['category' => '销售收入', 'cny_amount' => '72.50000000']);
        $this->assertDatabaseCount('finance_payment_transactions', 1);
        $this->assertDatabaseCount('finance_sync_pages', 1);
    }

    public function test_missing_monthly_rates_and_unclassified_transactions_prevent_closing(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        Http::fake(fn ($request) => $this->signedRelayResponse($request, $account, ['page' => 1, 'total_pages' => 1, 'total_items' => 1, 'transaction_details' => [[
            'transaction_info' => ['transaction_id' => 'UNCLASSIFIED-1', 'transaction_event_code' => 'T9999', 'transaction_initiation_date' => '2026-10-31T23:00:00Z',
                'transaction_amount' => ['value' => '10', 'currency_code' => 'EUR'], 'transaction_status' => 'S'],
        ]]]));
        $this->post('/finance/payment-accounts/finance-'.$account->id.'/transactions', ['from' => '2026-10-31', 'to' => '2026-11-01', 'timezone' => 'UTC'])->assertRedirect();
        $this->assertDatabaseHas('finance_payment_transactions', ['accounting_month' => '2026-11', 'monthly_rate' => null, 'cny_amount' => null, 'category' => null]);
        $this->get('/finance/reports/summary?view=transactions&month=2026-11')->assertOk()->assertViewHas('items', []);
        $this->assertSame([], app(\App\Portals\Finance\FinanceAccountingService::class)->report('2026-11', null, false));
        $this->post('/finance/settings/accounting/lock', ['month' => '2026-11'])->assertSessionHasErrors('month');
        $this->travelTo(\Carbon\Carbon::parse('2026-12-02T00:00:00Z'));
        $this->post('/finance/settings/accounting/lock', ['month' => '2026-11'])->assertSessionHasErrors('month');
        $this->assertNull(DB::table('finance_period_locks')->where('month', '2026-11')->value('report_snapshot'));
        $this->travelBack();
        $this->user->manage = false;
        $this->get('/finance/settings/accounting')->assertForbidden();
        $this->post('/finance/settings/accounting/rate', ['month' => '2026-11', 'currency' => 'USD', 'cny_rate' => '8'])->assertForbidden();
    }

    public function test_relay_signature_failure_keeps_successful_snapshot_and_other_accounts_can_refresh(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $fail = false;
        Http::fake(function ($request) use ($account, &$fail) {
            if ($fail) {
                return Http::response('{"balances":[]}', 200, ['X-Relay-Response-Timestamp' => (string) now()->timestamp, 'X-Relay-Response-Signature' => str_repeat('0', 64)]);
            }
            return $this->signedRelayResponse($request, $account, ['as_of_time' => '2026-11-03T01:00:00Z', 'balances' => [[
                'currency' => 'USD', 'available_balance' => ['value' => '10'], 'withheld_balance' => ['value' => '2'], 'total_balance' => ['value' => '12'],
            ]]]);
        });
        $service = app(\App\Portals\Finance\FinancePayPalSyncService::class);
        $this->assertTrue($service->balances($account, 1)['ok']);
        $this->assertSame('connected', app(FinancePayPalBalanceService::class)->dashboard()['cards']['finance-'.$account->id]['connection_status']);
        $fail = true;
        $failedResponse = $this->postJson('/finance/payment-accounts/finance-'.$account->id.'/refresh')->assertStatus(422)
            ->assertJsonPath('ok', false)->assertJsonPath('connection_status', 'error');
        $this->assertStringContainsString('12.00 USD', $failedResponse->json('balance_html'));
        $this->assertStringContainsString('data-paypal-total="total">12.00', $failedResponse->json('summary_html'));
        $this->assertSame('error', app(FinancePayPalBalanceService::class)->dashboard()['cards']['finance-'.$account->id]['connection_status']);
        $this->assertDatabaseCount('finance_balance_snapshots', 1);
        $this->assertSame('12.0000000000000000', app(FinancePayPalBalanceService::class)->dashboard()['summary']['total']);
        $fail = false;
        $this->post('/finance/payment-accounts/finance-'.$account->id.'/refresh')->assertRedirect()
            ->assertSessionHas('balanceRefreshResults.'.$account->id.'.ok', true);
        $html = $this->get('/finance')->assertOk()->getContent();
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@data-paypal-account="finance-'.$account->id.'"]//*[@data-refresh-message and @data-result="success" and not(@hidden)]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="finance-refresh-results" or @id="finance-refresh-progress"]')->length);
        $this->assertSame('connected', app(FinancePayPalBalanceService::class)->dashboard()['cards']['finance-'.$account->id]['connection_status']);
    }

    public function test_unverified_locked_history_is_not_reported_as_complete(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-06T08:00:00Z'));
        $this->post('/finance/payment-accounts',$this->newPaymentAccountData())->assertRedirect();
        $account=FinancePaymentAccount::query()->sole();
        DB::table('finance_period_locks')->insert(['month'=>'2026-05','report_snapshot'=>'[]']);
        DB::table('finance_period_locks')->insert(['month'=>'2026-10','report_snapshot'=>'[]']);
        DB::table('finance_sync_logs')->insert(['payment_account_id'=>$account->id,'operation'=>'transaction_search',
            'status'=>'success','range_from'=>'2026-09-30 16:00:00','range_to'=>'2026-10-31 15:59:59',
            'page_count'=>0,'record_count'=>0,'actor_id'=>1,'created_at'=>now(),'updated_at'=>now()]);
        Http::fake(fn($request)=>$this->signedRelayResponse($request,$account,['page'=>1,'total_pages'=>0,'total_items'=>0]));
        $sync=app(\App\Portals\Finance\FinancePayPalSyncService::class);
        $result=$sync->refreshOrders($account,1);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('2026-05',$result['error']);
        $this->assertStringNotContainsString('2026-10',$result['error']);
        DB::table('finance_period_locks')->where('month','2026-05')->delete();
        $result=$sync->refreshOrders($account,1);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['history_complete']);
        $this->travelBack();
    }

    public function test_existing_account_backfills_six_months_before_five_hour_incremental_sync(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-06T08:00:00Z'));
        $this->post('/finance/payment-accounts',$this->newPaymentAccountData())->assertRedirect();
        $account=FinancePaymentAccount::query()->sole();
        DB::table('finance_sync_logs')->insert(['payment_account_id'=>$account->id,'operation'=>'transaction_search',
            'status'=>'success','range_from'=>'2026-11-01 00:00:00','range_to'=>'2026-11-06 07:00:00',
            'page_count'=>1,'record_count'=>4,'actor_id'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $windows=[];
        Http::fake(function($request)use($account,&$windows){
            $windows[]=$request['query'];
            return $this->signedRelayResponse($request,$account,['page'=>1,'total_pages'=>0,'total_items'=>0]);
        });
        $sync=app(\App\Portals\Finance\FinancePayPalSyncService::class);
        $result=$sync->refreshOrders($account,1);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['history_complete']);
        $this->assertSame('2026-05-06T08:00:00Z',$windows[0]['start_date']);
        $this->assertSame('2026-11-06T08:00:00Z',end($windows)['end_date']);
        $windows=[];
        $this->assertTrue($sync->refreshOrders($account,1)['ok']);
        $this->assertSame('2026-11-06T03:00:00Z',$windows[0]['start_date']);
        $this->travelBack();
    }

    public function test_hourly_paypal_order_sync_uses_five_hour_overlap_and_updates_unique_transactions(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-06T08:00:00Z'));
        $this->post('/finance/payment-accounts',$this->newPaymentAccountData())->assertRedirect();
        $account=FinancePaymentAccount::query()->sole();
        DB::table('finance_sync_logs')->insert(['payment_account_id'=>$account->id,'operation'=>'transaction_search',
            'status'=>'success','range_from'=>'2026-05-06 08:00:00','range_to'=>'2026-11-06 07:00:00',
            'page_count'=>0,'record_count'=>0,'actor_id'=>0,'created_at'=>now(),'updated_at'=>now()]);
        $requests=[];
        $amount='10';
        Http::fake(function($request) use($account,&$requests,&$amount) {
            $requests[]=$request['query'];
            return $this->signedRelayResponse($request,$account,['page'=>1,'total_pages'=>1,'total_items'=>1,
                'transaction_details'=>[['transaction_info'=>['transaction_id'=>'HOURLY-UNIQUE',
                    'transaction_event_code'=>'T0006','transaction_initiation_date'=>'2026-11-06T04:00:00Z',
                    'transaction_amount'=>['value'=>$amount,'currency_code'=>'USD']]]]]);
        });
        $service=app(\App\Portals\Finance\FinancePayPalSyncService::class);
        $this->assertTrue($service->refreshOrders($account,0)['ok']);
        $this->assertSame('2026-11-06T03:00:00Z',$requests[0]['start_date']);
        $this->assertSame('2026-11-06T08:00:00Z',$requests[0]['end_date']);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-06T09:00:00Z'));
        $amount='12';
        $this->assertTrue($service->refreshOrders($account,0)['ok']);
        $this->assertSame('2026-11-06T04:00:00Z',$requests[1]['start_date']);
        $this->assertDatabaseCount('finance_records',1);
        $this->assertDatabaseCount('finance_payment_transactions',1);
        $headers=FinanceCatalog::dataset('paypal-orders')['headers'];
        $this->assertSame('12',FinanceRecord::query()->sole()->payload[$headers[7]]);
        $this->travelBack();
    }

    public function test_hourly_balance_refresh_continues_other_accounts_and_retries_failed_accounts_next_run(): void
    {
        $relay = ['relay_certificate_sha1' => str_repeat('ab', 20)];
        $first = $this->financePayPalAccount('Retry hourly', $relay);
        $second = $this->financePayPalAccount('Other hourly', [...$relay, 'relay_hmac_key_id' => 'second-hourly-key']);
        $this->financePayPalAccount('Disabled hourly', ['status' => 'disabled']);
        $this->financePayPalAccount('Unconfigured hourly', ['relay_url' => null]);
        $this->financePayPalAccount('Card hourly', ['account_type' => 'credit_card']);
        $fail = true;
        Http::fake(function ($request) use ($first, $second, &$fail) {
            $account = $request->header('X-Relay-Key')[0] === $first->relay_hmac_key_id ? $first : $second;
            if ($account->is($first) && $fail) {
                return $this->signedRelayResponse($request, $account, ['error' => 'temporary'], 502);
            }

            return $this->signedRelayResponse($request, $account, ['as_of_time' => '2026-11-09T05:00:00Z', 'balances' => []]);
        });
        $this->artisan('finance:paypal-balances:refresh')->assertFailed();
        $this->assertDatabaseHas('finance_sync_logs', ['payment_account_id' => $first->id, 'operation' => 'balances', 'status' => 'failed', 'actor_id' => 0]);
        $this->assertDatabaseHas('finance_balance_snapshots', ['payment_account_id' => $second->id]);

        $fail = false;
        $this->artisan('finance:paypal-balances:refresh')->assertSuccessful();
        $this->assertDatabaseHas('finance_balance_snapshots', ['payment_account_id' => $first->id]);
        Http::assertSentCount(4);
    }

    public function test_hourly_scheduler_queues_configured_accounts_once_without_requesting_balances(): void
    {
        Http::fake();
        $account=$this->financePayPalAccount('Hourly queue');
        $this->financePayPalAccount('Disabled hourly',['status'=>'disabled']);
        $this->financePayPalAccount('Unconfigured hourly',['relay_url'=>null]);
        $this->financePayPalAccount('Card hourly',['account_type'=>'credit_card']);
        $this->artisan('finance:paypal-orders:enqueue')->assertSuccessful();
        $this->artisan('finance:paypal-orders:enqueue')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncFinancePayPalOrders::class,1);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncFinancePayPalOrders::class,fn($job)=>$job->accountId===$account->id && $job->actorId===0);
        Http::assertNothingSent();
    }

    public function test_order_refresh_fills_earlier_gaps_despite_later_success_and_has_contiguous_windows(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-06T08:00:00Z'));
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        foreach ([['failed','2026-07-01 00:00:00','2026-07-31 23:59:59'],
            ['success','2026-05-06 08:00:00','2026-07-14 23:59:59'],
            ['success','2026-11-01 00:00:00','2026-11-05 08:00:00']] as [$status,$from,$to]) {
            DB::table('finance_sync_logs')->insert(['payment_account_id'=>$account->id,'operation'=>'transaction_search',
                'status'=>$status,'range_from'=>$from,'range_to'=>$to,'page_count'=>0,'record_count'=>0,'actor_id'=>1,
                'created_at'=>now(),'updated_at'=>now()]);
        }
        $windows = [];
        Http::fake(function ($request) use ($account, &$windows) {
            $windows[] = $request['query'];
            return $this->signedRelayResponse($request,$account,['page'=>1,'total_pages'=>0,'total_items'=>0]);
        });
        $service = app(\App\Portals\Finance\FinancePayPalSyncService::class);
        $this->assertTrue($service->refreshOrders($account,1)['ok']);
        $this->assertSame('2026-07-15T00:00:00Z',$windows[0]['start_date']);
        foreach ($windows as $index=>$window) {
            $start=\Carbon\CarbonImmutable::parse($window['start_date']);
            $end=\Carbon\CarbonImmutable::parse($window['end_date']);
            $this->assertLessThanOrEqual(7*86400, $start->diffInSeconds($end)+1);
            if ($index) $this->assertSame(\Carbon\CarbonImmutable::parse($windows[$index-1]['end_date'])->addSecond()->format('Y-m-d\TH:i:s\Z'),$window['start_date']);
        }
        $this->assertSame('2026-11-06T08:00:00Z',end($windows)['end_date']);
        $windows=[];
        $this->assertTrue($service->refreshOrders($account,1)['ok']);
        $this->assertSame('2026-11-06T03:00:00Z',$windows[0]['start_date']);
        $this->travelBack();
    }

    public function test_duplicate_transaction_across_pages_does_not_falsely_complete_sync(): void
    {
        $this->post('/finance/payment-accounts',$this->newPaymentAccountData())->assertRedirect();
        $account=FinancePaymentAccount::query()->sole();
        Http::fake(fn ($request)=>$this->signedRelayResponse($request,$account,[
            'page'=>(int)$request['query']['page'],'total_pages'=>2,'total_items'=>2,'transaction_details'=>[[
                'transaction_info'=>['transaction_id'=>'DUPLICATED-PAGE','transaction_event_code'=>'T0006',
                    'transaction_initiation_date'=>'2026-11-01T01:00:00Z','transaction_amount'=>['value'=>'10','currency_code'=>'USD']],
            ]],
        ]));
        $result=app(\App\Portals\Finance\FinancePayPalSyncService::class)->transactions($account,
            \Carbon\CarbonImmutable::parse('2026-11-01T00:00:00Z'),\Carbon\CarbonImmutable::parse('2026-11-01T23:59:59Z'),1);
        $this->assertFalse($result['ok']);
        Http::assertSentCount(6);
        $this->assertDatabaseCount('finance_payment_transactions',1);
        $this->assertDatabaseHas('finance_sync_logs',['status'=>'failed','error_code'=>'pagination']);
    }

    public function test_transaction_sync_fetches_every_time_segment_and_every_short_page(): void
    {
        config(['finance.relay.segment_days' => 1]);
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $requests = [];
        Http::fake(function ($request) use ($account, &$requests) {
            $query = $request['query'];
            $page = (int) $query['page'];
            $this->assertSame('500', $query['page_size']);
            $this->assertSame(substr($query['start_date'], 0, 10).'T23:59:59Z', $query['end_date']);
            $requests[] = [$query['start_date'], $page];
            // Simulate a relay returning only 100 rows even when 500 were requested.
            $count = $page === 3 ? 5 : 100;
            $rows = [];
            for ($index = 0; $index < $count; $index++) {
                $rows[] = ['transaction_info' => [
                    'transaction_id' => $query['start_date'].'-'.$page.'-'.$index, 'transaction_event_code' => 'T0006',
                    'transaction_initiation_date' => $query['start_date'],
                    'transaction_amount' => ['value' => '10', 'currency_code' => 'USD'],
                ]];
            }
            return $this->signedRelayResponse($request, $account, ['page' => $page, 'total_pages' => 3,
                'total_items' => 205, 'transaction_details' => $rows]);
        });
        $result = app(\App\Portals\Finance\FinancePayPalSyncService::class)->transactions($account,
            \Carbon\CarbonImmutable::parse('2026-11-01T00:00:00Z'), \Carbon\CarbonImmutable::parse('2026-11-03T23:59:59Z'), 1);
        $this->assertTrue($result['ok']);
        $this->assertSame(615, $result['count']);
        $this->assertSame([1,2,3,1,2,3,1,2,3], array_column($requests, 1));
        $this->assertSame(['2026-11-01T00:00:00Z','2026-11-02T00:00:00Z','2026-11-03T00:00:00Z'], array_values(array_unique(array_column($requests, 0))));
        $this->assertDatabaseCount('finance_payment_transactions', 615);
        $this->assertDatabaseHas('finance_sync_logs', ['status' => 'success', 'page_count' => 9, 'record_count' => 615]);
    }

    public function test_relay_accepts_omitted_transactions_only_for_explicitly_empty_results(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $total = 0;
        Http::fake(function ($request) use ($account, &$total) {
            return $this->signedRelayResponse($request, $account, [
                'page' => 1, 'total_pages' => $total, 'total_items' => $total,
            ]);
        });
        foreach ([0 => true, 1 => false] as $total => $expected) {
            $result = app(\App\Portals\Finance\FinancePayPalSyncService::class)->transactions($account,
                \Carbon\CarbonImmutable::parse('2026-11-01T00:00:00Z'), \Carbon\CarbonImmutable::parse('2026-11-01T23:59:59Z'), 1);
            $this->assertSame($expected, $result['ok'], json_encode($result));
            $this->assertSame(0, $result['count']);
        }
        $this->assertDatabaseCount('finance_records', 0);
    }

    public function test_relay_retries_changed_totals_without_duplicate_transactions(): void
    {
        $this->assertPaginationRetry('changed', true);
    }

    public function test_relay_retries_incomplete_transaction_counts(): void
    {
        $this->assertPaginationRetry('short', true);
    }

    public function test_relay_stops_after_three_inconsistent_attempts(): void
    {
        $this->assertPaginationRetry('changed', false);
    }

    private function assertPaginationRetry(string $failure, bool $recover): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $attempt = 0;
        $pages = [];
        Http::fake(function ($request) use ($account, $failure, $recover, &$attempt, &$pages) {
            $query = json_decode($request->body(), true)['query'];
            $this->assertSame('500', $query['page_size']);
            $page = (int) $query['page'];
            $pages[] = $page;
            if ($page === 1) {
                $attempt++;
            }
            $invalid = $page === 2 && (!$recover || $attempt === 1);
            $ids = $page === 1 ? range(1, 101) : [102];
            $rows = array_map(static fn ($id) => ['transaction_info' => [
                'transaction_id' => 'RETRY-'.$id, 'transaction_event_code' => 'T0006',
                'transaction_initiation_date' => '2026-11-01T01:00:00Z',
                'transaction_amount' => ['value' => '10', 'currency_code' => 'USD'],
            ]], $ids);

            return $this->signedRelayResponse($request, $account, [
                'page' => $page, 'total_pages' => 2,
                'total_items' => $invalid && $failure === 'changed' ? 103 : 102,
                'transaction_details' => $invalid && $failure === 'short' ? [] : $rows,
            ]);
        });
        $result = app(\App\Portals\Finance\FinancePayPalSyncService::class)->transactions(
            $account, \Carbon\CarbonImmutable::parse('2026-11-01T00:00:00Z'),
            \Carbon\CarbonImmutable::parse('2026-11-01T23:59:59Z'), 1,
        );
        $this->assertSame($recover, $result['ok']);
        $this->assertSame($recover ? [1, 2, 1, 2] : [1, 2, 1, 2, 1, 2], $pages);
        $this->assertSame($recover ? 102 : 101, $result['count']);
        $this->assertDatabaseCount('finance_payment_transactions', $recover ? 102 : 101);
        $this->assertDatabaseCount('finance_records', $recover ? 102 : 101);
        $this->assertDatabaseHas('finance_sync_logs', [
            'status' => $recover ? 'success' : 'failed',
            'error_code' => $recover ? null : 'pagination',
            'page_count' => $recover ? 2 : 1, 'record_count' => $recover ? 102 : 101,
        ]);
    }

    public function test_failed_second_page_keeps_only_the_verified_page_and_marks_incomplete_sync(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        Http::fake(function ($request) use ($account) {
            $page = (int) json_decode($request->body(), true)['query']['page'];
            if ($page === 2) {
                return Http::response('{"transaction_details":[]}', 200);
            }
            return $this->signedRelayResponse($request, $account, ['page' => 1, 'total_pages' => 2, 'total_items' => 2, 'transaction_details' => [[
                'transaction_info' => ['transaction_id' => 'PAGE-1', 'transaction_event_code' => 'T0006', 'transaction_initiation_date' => '2026-11-01T01:00:00Z', 'transaction_amount' => ['value' => '10', 'currency_code' => 'USD']],
            ]]]);
        });
        $result = app(\App\Portals\Finance\FinancePayPalSyncService::class)->transactions($account, \Carbon\CarbonImmutable::parse('2026-11-01T00:00:00Z'), \Carbon\CarbonImmutable::parse('2026-11-01T23:59:59Z'), 1);
        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['count']);
        $this->assertDatabaseCount('finance_sync_pages', 1);
        $this->assertDatabaseCount('finance_payment_transactions', 1);
        $this->assertDatabaseHas('finance_sync_logs', ['status' => 'failed', 'error_code' => 'response_unsigned', 'page_count' => 1]);
    }

    public function test_missing_api_total_is_not_reconstructed_and_monthly_balance_uses_fixed_rates(): void
    {
        $this->post('/finance/payment-accounts', $this->newPaymentAccountData())->assertRedirect();
        $account = FinancePaymentAccount::query()->sole();
        $includeTotal = false;
        Http::fake(function ($request) use ($account, &$includeTotal) {
            $row = ['currency' => 'USD', 'available_balance' => ['value' => '10'], 'withheld_balance' => ['value' => '2']];
            if ($includeTotal) {
                $row['total_balance'] = ['value' => '13'];
            }
            return $this->signedRelayResponse($request, $account, ['as_of_time' => '2026-11-03T01:00:00Z', 'balances' => [$row]]);
        });
        $service = app(\App\Portals\Finance\FinancePayPalSyncService::class);
        $this->assertTrue($service->balances($account, 1)['ok']);
        $this->assertNull(app(FinancePayPalBalanceService::class)->dashboard()['summary']['total']);
        $includeTotal = true;
        $this->assertTrue($service->balances($account, 1)['ok']);
        $this->post('/finance/settings/accounting/rate', ['month' => '2026-11', 'currency' => 'USD', 'cny_rate' => '7'])->assertRedirect();
        $dashboard = app(FinancePayPalBalanceService::class)->dashboard();
        $this->assertSame('91.0000000000000000', $dashboard['monthlyCny']['total']);
        $this->assertSame('2026-11', $dashboard['monthlyCny']['month']);
    }

    public function test_response_signature_matches_published_test_vector(): void
    {
        $message = \App\Portals\Finance\FinanceRelayClient::responseMessage('1700000000', '00000000000040008000000000000001', 200, '{"balances":[]}');
        $this->assertSame('c7a097e9d2187ce38d056de64f5830d104e061d60d91153fcd3099d5f407601a', hash_hmac('sha256', $message, 'test-key-not-production-with-32-bytes'));
    }

    private function signedRelayResponse($request, FinancePaymentAccount $account, array $payload, int $status = 200, ?int $timestamp = null)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) ($timestamp ?? now()->timestamp);
        $message = \App\Portals\Finance\FinanceRelayClient::responseMessage($timestamp, $request->header('X-Relay-Nonce')[0], $status, $body);

        return Http::response($body, $status, ['X-Relay-Response-Timestamp' => $timestamp, 'X-Relay-Response-Signature' => hash_hmac('sha256', $message, base64_decode($account->relay_hmac_secret, true))]);
    }

    private function paymentBalance(array $data): FinancePaymentBalance
    {
        return FinancePaymentBalance::create([
            'channel' => 'xuzong2', 'account' => 'A', 'currency' => 'USD',
            'withdrawable' => '1', 'pending' => '2', 'reserve' => '3', 'usd_rate' => '1',
            'as_of' => '2026-11-01 04:00:00', ...$data,
        ]);
    }

    private function recordRow(): array
    {
        return [...array_fill_keys(array_slice(FinanceCatalog::dataset('paypal-orders')['headers'], 0, 15), ''),
            '日期' => '2026-09-08', '時間' => '23:59:59', '交易 ID' => '0000123',
            '類型' => 'PayPal Checkout付款', '貨幣' => 'USD', '狀態' => 'Success', '總額' => '10.00'];
    }

    private function upload(Spreadsheet $book): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'finance-test-');
        $this->temporaryFiles[] = $path;
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'finance.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function readDownload(string $content): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'finance-export-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $content);

        $sheet = IOFactory::load($path)->getActiveSheet();
        // Streaming XLSX uses inline strings; normalize their reader representation
        // so the same value/type assertions cover both XLS and XLSX exports.
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            if ($cell->getDataType() === DataType::TYPE_INLINE) {
                $cell->setValueExplicit((string) $cell->getValue(), DataType::TYPE_STRING);
            }
        }

        return $sheet;
    }
}
