<?php

namespace Tests\Feature;

use App\Portals\Finance\FinanceSummaryDataVersion;
use App\Portals\Finance\FinanceSummaryDependencies;
use App\Portals\Finance\FinanceSummarySourceCache;
use App\Portals\Finance\FinanceSummarySourceChanges;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Creates only an isolated disposable database on a loopback MySQL server. */
class FinanceScopedSourceMySqlTest extends TestCase
{
    public function test_retiring_summary_caches_requires_structured_storage_and_preserves_transactions(): void
    {
        $id = $this->record('2026-11-03', 'KEEP-SOURCE');
        $migration = require database_path('migrations/2026_11_21_000003_drop_retired_finance_summary_tables.php');
        try {
            $migration->up();
            $this->fail('Missing structured storage must prevent cache retirement.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('结构化汇总', $error->getMessage());
        }
        $schema = $this->db->getSchemaBuilder();
        $this->assertTrue($schema->hasTable('finance_summary_rows'));
        (require database_path('migrations/2026_11_21_000002_create_finance_summary_facts.php'))->up();
        if (! $schema->hasTable('finance_summary_buckets')) $schema->create('finance_summary_buckets', function (Blueprint $table): void {
            $table->id(); $table->json('payload')->nullable();
        });
        $migration->up();
        $migration->up();
        $this->assertFalse($schema->hasTable('finance_summary_rows'));
        $this->assertFalse($schema->hasTable('finance_summary_buckets'));
        $this->assertTrue($this->db->table('finance_records')->where('id', $id)->exists());
        $this->assertNull(app(\App\Portals\Finance\FinanceSummaryProjection::class)->stream('credit_card',
            ['from' => '2026-11-01', 'to' => '2026-11-30', 'timezone' => '-04:00'], false));
    }

    private ?string $databaseName = null;
    private ?string $cachePath = null;
    private Connection $db;

    public function test_indexed_dependency_lookup_preserves_legacy_matching_and_uses_reference_indexes(): void
    {
        (require database_path('migrations/2026_11_21_000005_create_finance_relational_sources.php'))->up();
        (require database_path('migrations/2026_11_21_000009_index_finance_dependency_references.php'))->up();
        $this->db->getSchemaBuilder()->table('finance_records', fn (Blueprint $table) => $table->index('reference'));
        $fixtures = [
            ['paypal-orders', 'PAY-TXN', ' BILL ', null, null],
            ['xuzong2-details', 'CARD-TXN', null, 'MERCHANT', 'SHOPIFY'],
            ['xuzong2-details', 'REF-ONLY', null, null, null],
            ['xuzong2-details', 'FASHION-TXN', null, null, null],
            ['xuzong2-details', '0', null, null, null],
            ['xuzong2-details', 'LONG-A', null, str_repeat('L', 191).'A', null],
            ['xuzong2-details', 'LONG-B', null, str_repeat('L', 191).'B', null],
        ];
        foreach ($fixtures as [$dataset, $reference, $bill, $merchant, $shopify]) {
            $id = $this->record('2026-11-03', $reference, $dataset);
            $this->db->table('finance_records')->where('id', $id)->update([
                'source_bill' => $bill, 'source_merchant' => $merchant, 'source_shopify' => $shopify,
            ]);
        }
        app(\App\Portals\Finance\FinanceSourceStorage::class)->setState('record', 'active');
        $orderId = $this->db->table('logistics_orders')->insertGetId(['transaction_id' => 'CARD-TXN', 'bill_number' => 'BILL', 'shopify_order_number' => 'SHOPIFY']);
        $this->db->table('logistics_order_payment_references')->insert(['logistics_order_id' => $orderId, 'reference_number' => 'REF-ONLY']);
        $fashionId = $this->db->table('fashion_logistics_orders')->insertGetId(['transaction_id' => 'FASHION-TXN']);
        $dependencies = app(FinanceSummaryDependencies::class);
        foreach ([['order', ['id' => $orderId, 'transaction_id' => 'MERCHANT']],
            ['order', ['id' => 999999, 'transaction_id' => 'REF-ONLY']],
            ['reference', ['reference_number' => 'BILL']], ['reference', ['reference_number' => '0']],
            ['reference', ['reference_number' => str_repeat('L', 191).'A']],
            ['reference', ['reference_number' => '']], ['reference', ['reference_number' => null]],
            ['item', ['logistics_order_id' => $orderId]], ['scope_credit_refund', ['logistics_order_id' => $orderId]],
            ['fashion', ['transaction_id' => 'FASHION-TXN']], ['fashion_item', ['fashion_logistics_order_id' => $fashionId]]] as [$source, $values]) {
            $legacy = $this->db->table('finance_records as f')->whereIn('f.dataset', FinanceSummarySourceChanges::DATASETS)
                ->whereRaw($dependencies->match($source === 'scope_credit_refund' ? 'item' : $source, $values, $this->db))
                ->when($source === 'scope_credit_refund', fn ($query) => $query->where('f.dataset', '<>', 'paypal-orders'))->pluck('id')->sort()->values()->all();
            $fast = $dependencies->sourceRecords($source, $values, $this->db);
            $this->assertNotNull($fast);
            $this->assertSame($legacy, $fast->pluck('id')->sort()->values()->all(), $source.' '.json_encode($values));
        }
        foreach (['source_bill_trimmed' => ['BILL', 'finance_dependency_bill_idx'],
            'source_merchant' => ['MERCHANT', 'finance_dependency_merchant_idx'],
            'source_shopify' => ['SHOPIFY', 'finance_dependency_shopify_idx']] as $column => [$value, $index]) {
            $plan = $this->db->selectOne('EXPLAIN SELECT id FROM finance_records WHERE '.$column.' = ?', [$value]);
            $this->assertStringContainsString($index, (string) $plan->possible_keys);
        }
    }

    public function test_worker_reports_pending_and_skips_projection_when_notification_delivery_fails(): void
    {
        $notifications = new class extends \App\Portals\Finance\FinanceExternalChanges {
            protected function openNotification(string $path, string $mode, ?string &$error)
            {
                if (str_starts_with(basename($path), 'change-')) { $error = 'Permission denied'; return false; }
                return parent::openNotification($path, $mode, $error);
            }
        };
        $this->app->instance(\App\Portals\Finance\FinanceExternalChanges::class, $notifications);
        $ticket = $notifications->begin($this->db);
        $notifications->commit($this->db, $ticket, [['source' => 'scope_user', 'values' => []]]);
        $paypal = \Mockery::mock(\App\Portals\Finance\FinancePayPalSettlementProjection::class);
        $paypal->shouldNotReceive('refresh');
        $this->app->instance(\App\Portals\Finance\FinancePayPalSettlementProjection::class, $paypal);
        $this->artisan('finance:summaries:refresh')->assertExitCode(1);
        $status = \Illuminate\Support\Facades\Cache::get('finance:summaries:worker-status');
        $this->assertStringContainsString('等待下轮重试', $status['stage']);
        $this->assertFileExists($ticket['path']);
        $this->assertSame('committed', json_decode(file_get_contents($ticket['path']), true)['state']);
    }

    public function test_relational_sources_round_trip_events_nulls_types_and_unrecognized_fields(): void
    {
        (require database_path('migrations/2026_11_21_000005_create_finance_relational_sources.php'))->up();
        $storage = app(\App\Portals\Finance\FinanceSourceStorage::class);
        $documents = [
            'record' => ['dataset' => 'xuzong-credit-imported-orders', 'document' => [
                '交易金额' => '001.2300', '备注' => '', '域名' => null, '_linked_logistics_id' => 123,
                '_xuzong_statement_import' => ['notice' => '待核对', 'events' => [
                    'EVENT-A' => ['time' => '2026-11-03 12:00:00', 'amounts' => ['退款金额' => '3.4500', '退款费用' => null]],
                ]],
                '_worldpay_events' => ['R1|CASE-A|退款' => ['Case ID' => 'CASE-A', '异常结单金额' => '-2.3000']],
                '_xuzong_refunds' => ['REF-A' => ['refund_id' => 'REF-A', 'amount' => '0', 'fee' => '0.1000']],
                'unrecognized' => ['empty' => [], 'flag' => false, 'positive' => true, 'number' => 1.25, '零' => 0, 'literal/key' => '值'],
            ]],
            'payment' => ['document' => ['transaction_info' => ['transaction_id' => 'TXN', 'transaction_amount' => ['currency_code' => 'USD', 'value' => '12.3400']],
                'cart_info' => ['item_details' => [['item_name' => '商品甲', 'item_quantity' => '2'], ['item_name' => '商品乙', 'item_quantity' => '1']]],
                'payer_info' => ['email_address' => 'test@example.com'], 'new_api_field' => ['nullable' => null]]],
            'statement' => ['document' => ['结算金额' => '-1.2300', '结算货币' => 'USD', '账单描述符' => 'wallet', '附加字段' => '原值']],
        ];
        foreach ($documents as $kind => $source) {
            $storage->write($kind, [1 => $source]);
            $loaded = $storage->load($kind, [1]);
            $this->assertSame($storage::hash($source['document']), $storage::hash($loaded[1]), $kind);
        }
        $this->assertSame(2, $this->db->table('finance_paypal_transaction_items')->count());
        $this->assertSame(1, $this->db->table('finance_xuzong_refund_events')->count());
        $this->assertFalse($storage->active('record'));
        $storage->write('record', [1 => ['dataset' => 'xuzong-credit-imported-orders', 'document' => ['交易金额' => '0']]]);
        $this->assertSame(['交易金额' => '0'], $storage->load('record', [1])[1]);
        $this->assertSame(0, $this->db->table('finance_xuzong_refund_events')->count());
        foreach ($storage::definitions() as $definition) {
            $this->assertNotContains('payload', $this->db->getSchemaBuilder()->getColumnListing($definition['table']));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('FINANCE_SCOPES_MYSQL_TESTS') !== '1') $this->markTestSkipped('Enable FINANCE_SCOPES_MYSQL_TESTS for isolated trigger tests.');
        $mysql = config('database.connections.mysql');
        if (! in_array($mysql['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)) $this->markTestSkipped('Loopback MySQL is required.');
        $mysql['url'] = null;
        config(['database.connections.scope_admin' => [...$mysql, 'database' => null]]);
        $this->databaseName = 'codex_finance_scopes_'.bin2hex(random_bytes(6));
        DB::connection('scope_admin')->statement('CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.scope_test' => [...$mysql, 'database' => $this->databaseName],
            'database.default' => 'scope_test', 'admin.database.connection' => 'scope_test']);
        $this->db = DB::connection('scope_test');
        $this->cachePath = storage_path('framework/testing/scoped-source-'.bin2hex(random_bytes(6)));
        config(['cache.stores.file.path' => $this->cachePath, 'finance.summaries.external_change_path' => $this->cachePath.'/external-changes']);
        app('cache')->forgetDriver('file');
        $this->createTables();
        foreach (['2026_11_13_000001_create_finance_summary_projections', '2026_11_13_000002_track_finance_summary_source_changes',
            '2026_11_13_000005_track_finance_statement_month_changes', '2026_11_20_000002_track_finance_live_source_updates',
            '2026_11_20_000003_scope_finance_source_invalidations', '2026_11_21_000006_enable_finance_application_tracking'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        $this->assertTrue(FinanceSummarySourceChanges::available($this->db));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->databaseName !== null) {
                DB::disconnect('scope_test');
                $this->assertMatchesRegularExpression('/^codex_finance_scopes_[a-f0-9]{12}$/D', $this->databaseName);
                DB::connection('scope_admin')->statement('DROP DATABASE `'.$this->databaseName.'`');
            }
            if ($this->cachePath !== null) File::deleteDirectory($this->cachePath);
        } finally { parent::tearDown(); }
    }

    public function test_structured_summary_preserves_missing_net_and_uses_numeric_sql_aggregation(): void
    {
        (require database_path('migrations/2026_11_21_000002_create_finance_summary_facts.php'))->up();
        $id = $this->record('2026-11-03', 'FACT-NET');
        $facts = app(\App\Portals\Finance\FinanceSummaryFacts::class);
        $filters = (new \ReflectionMethod($facts, 'filters'))->invoke($facts, 'credit_card', '2026-11', '-04:00');
        $version = (new \ReflectionMethod($facts, 'version'))->invoke($facts, 'credit_card', $filters);
        $money = array_fill_keys(array_keys(\App\Portals\Finance\FinanceSettlementService::MONEY), null);
        $base = $money + ['id' => $id, 'channel' => 'credit_card', 'payment_channel' => 'xuzong2', 'account' => 'A', 'account_key' => 'A',
            'currency' => 'USD', 'team' => '郑州', 'subscription' => '订阅', 'month' => '2026-11', 'success' => true, 'failed' => false,
            'gross' => '100', 'settlement' => '100', '_summary_amount_usd' => '100', '_summary_xuzong_detail' => true,
            '_summary_settlement_currency' => 'USD'];
        $rows = [array_replace($base, ['_summary_payable' => null]), array_replace($base, ['_summary_payable' => '90'])];
        $publication = $this->db->table('finance_summary_publications')->insertGetId(['month' => '2026-11', 'channel' => 'credit_card',
            'timezone' => '-04:00', 'revision' => 1, 'source_version' => $version, 'complete' => true, 'verified' => true]);
        $position = 0;
        $facts->insert($publication, $position, $rows);
        (require database_path('migrations/2026_11_21_000003_drop_retired_finance_summary_tables.php'))->up();
        $stream = app(\App\Portals\Finance\FinanceSummaryProjection::class)->stream('credit_card', $filters, false,
            paymentChannels: ['xuzong2'], aggregateTransactions: true);
        $summary = new \App\Portals\Finance\FinanceTransactionSummary;
        $expected = $summary->summarize($rows, 'credit_card', usd: true);
        $actual = $summary->summarize($stream, 'credit_card', usd: true);
        $this->assertSame($expected, $actual);
        $this->assertNull($summary->totalSummary($actual, 'credit_card', '2026-11')['payable']);
        $this->assertSame(2, $this->db->table('finance_summary_facts')->count());
        $this->assertNotContains('payload', $this->db->getSchemaBuilder()->getColumnListing('finance_summary_facts'));
        $this->assertNotContains('payload', $this->db->getSchemaBuilder()->getColumnListing('finance_summary_amounts'));
        $this->assertNull($facts->stream('credit_card', [...$filters, 'conditions' => [
            ['field' => 'note', 'operator' => 'contains', 'value' => '备注'],
        ]], false, null, ['xuzong2']));
    }

    public function test_structured_summary_restores_native_currency_without_copying_whole_rows(): void
    {
        (require database_path('migrations/2026_11_21_000002_create_finance_summary_facts.php'))->up();
        $id = $this->record('2026-11-03', 'FACT-NATIVE');
        $facts = app(\App\Portals\Finance\FinanceSummaryFacts::class);
        $filters = (new \ReflectionMethod($facts, 'filters'))->invoke($facts, 'credit_card', '2026-11', 'Asia/Shanghai');
        $version = (new \ReflectionMethod($facts, 'version'))->invoke($facts, 'credit_card', $filters);
        $money = array_fill_keys(array_keys(\App\Portals\Finance\FinanceSettlementService::MONEY), null);
        $row = array_replace($money, ['id' => $id, 'channel' => 'credit_card', 'payment_channel' => 'worldpay', 'account' => 'Worldpay',
            'account_key' => 'Worldpay', 'currency' => 'EUR', 'team' => '深圳', 'subscription' => '', 'month' => '2026-11',
            'success' => true, 'failed' => false, 'gross' => '80', 'settlement' => '100', 'fee' => '2.50',
            '_summary_settlement_currency' => 'USD', '_summary_native_currency' => 'EUR', '_summary_native_values' => ['settlement' => '80', 'fee' => '2']]);
        $publication = $this->db->table('finance_summary_publications')->insertGetId(['month' => '2026-11', 'channel' => 'credit_card',
            'timezone' => 'Asia/Shanghai', 'revision' => 1, 'source_version' => $version, 'complete' => true, 'verified' => true]);
        $position = 0; $facts->insert($publication, $position, [$row]);
        foreach ([false, true] as $native) {
            $summary = new \App\Portals\Finance\FinanceTransactionSummary;
            $expectedRow = $native ? \App\Portals\Finance\FinanceSummarySourceCache::nativeRow($row) : $row;
            $expected = $summary->summarize([$expectedRow], 'worldpay');
            $actual = $summary->summarize($facts->stream('credit_card', $filters, $native, null, ['worldpay'], true), 'worldpay');
            $this->assertSame($expected, $actual);
        }
        $this->assertSame(1, $this->db->table('finance_summary_facts')->count());
        $this->assertSame(2, $this->db->table('finance_summary_amounts')->count());
        $canonical = iterator_to_array($facts->stream('credit_card', $filters, false, null, ['worldpay']), false);
        $restored = array_map(\App\Portals\Finance\FinanceSummarySourceCache::nativeRow(...), $canonical);
        $this->assertSame($summary->summarize([\App\Portals\Finance\FinanceSummarySourceCache::nativeRow($row)], 'worldpay'),
            $summary->summarize($restored, 'worldpay'));

        // Equal numeric amounts need no extra amount row, but retain native currency.
        $sameAmounts = array_replace($row, ['_summary_native_values' => []]);
        $facts->insert($publication, $position, [$sameAmounts]);
        foreach ([false, true] as $aggregate) {
            $rows = iterator_to_array($facts->stream('credit_card', $filters, true, null, ['worldpay'], $aggregate), false);
            $this->assertNotEmpty($rows);
            foreach ($rows as $item) $this->assertSame('EUR', $item['_summary_settlement_currency']);
        }
        $this->assertSame(2, $position);
        $this->assertSame(3, $this->db->table('finance_summary_amounts')->count());
        $this->db->table('finance_summary_publications')->where('id', $publication)->update(['verified' => false]);
        $this->assertNull($facts->stream('credit_card', $filters, true, null, ['worldpay'], true));
    }

    private function createTables(): void
    {
        $tables = [
            'finance_records' => ['dataset', 'account', 'reference', 'raw_type', 'domain', 'currency', 'status', 'occurred_at'],
            'finance_settlement_supplements' => ['finance_record_id'],
            'finance_payment_transactions' => ['payment_account_id', 'finance_record_id', 'transaction_id', 'event_code'],
            'finance_credit_settlement_entries' => ['payment_account_id', 'platform', 'settlement_date', 'reference', 'kind'],
            'finance_payment_accounts' => ['activity_account', 'account_name', 'account_type', 'main_currency', 'status', 'card_platform', 'deleted_at'],
            'finance_transaction_types' => ['raw_type', 'category'],
            'finance_credit_usd_rates' => ['currency', 'scope', 'starts_on', 'ends_on', 'usd_rate'],
            'finance_monthly_exchange_rates' => ['month', 'channel', 'currency', 'cny_rate'],
            'logistics_orders' => ['bill_number', 'transaction_id', 'payment_gateway_order_id', 'shopify_order_number',
                'assistant_domain_store_id', 'source_shop_domain', 'paid_amount_usd'],
            'logistics_order_payment_references' => ['logistics_order_id', 'reference_number', 'reference_type'],
            'logistics_credit_card_refunds' => ['logistics_order_id', 'refund_amount_usd', 'refund_reason'],
            'logistics_order_items' => ['logistics_order_id', 'quantity', 'tongtu_sku_mapping_id'],
            'logistics_tongtu_sku_mappings' => ['product_guide_cost_cny', 'goods_weight_gram'],
            'fashion_logistics_orders' => ['transaction_id', 'assistant_domain_store_id', 'source_domain'],
            'fashion_logistics_order_items' => ['fashion_logistics_order_id'],
            'assistant_domain_stores' => ['domain', 'shop_name', 'owner_user_id', 'user_name', 'team', 'shop_status'],
            'assistant_domain_store_owner_histories' => ['assistant_domain_store_id'],
            (new \App\Models\AdminUser)->getTable() => ['name', 'username'],
            config('admin.database.role_users_table') => ['user_id', 'role_id', 'is_primary'],
            config('admin.database.roles_table') => ['name', 'slug', 'parent_id'],
        ];
        foreach ($tables as $name => $columns) $this->db->getSchemaBuilder()->create($name, function (Blueprint $table) use ($columns): void {
            $table->engine = 'InnoDB';
            $table->id();
            foreach ($columns as $column) $table->string($column, 191)->nullable();
            $table->json('payload')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    private function record(string $date, string $reference, string $dataset = 'xuzong2-details'): int
    {
        return $this->db->table('finance_records')->insertGetId(['dataset' => $dataset, 'account' => 'A', 'reference' => $reference,
            'occurred_at' => $date.' 12:00:00', 'currency' => 'USD', 'status' => 'SUCCESS', 'payload' => '{"amount":"10"}',
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function version(string $channel, string $from, ?string $to = null): string
    {
        return app(FinanceSummaryDataVersion::class)->forRange($channel, ['from' => $from, 'to' => $to ?? $from, 'timezone' => 'UTC']);
    }

    public function test_timestamp_only_updates_and_worker_acknowledgements_keep_cached_versions(): void
    {
        $id = $this->record('2026-11-03', 'UNCHANGED');
        $first = $this->version('credit_card', '2026-11-01', '2026-11-07');
        $detailVersion = app(FinanceSummaryDataVersion::class)->forCache(['credit_card']);
        $count = $this->db->table('finance_summary_changes')->count();
        $periods = $this->db->table('finance_summary_periods')->get()->toJson();
        $this->db->table('finance_records')->where('id', $id)->update(['payload' => '{"amount":"10"}', 'updated_at' => '2026-11-20 12:00:00']);
        $this->assertSame($count, $this->db->table('finance_summary_changes')->count());
        $this->assertSame($periods, $this->db->table('finance_summary_periods')->get()->toJson());
        $this->assertSame($first, $this->version('credit_card', '2026-11-01', '2026-11-07'));
        $this->assertSame($detailVersion, app(FinanceSummaryDataVersion::class)->forCache(['credit_card']));
        app(FinanceSummaryDependencies::class)->resolve($this->db);
        $this->assertSame(0, $this->db->table('finance_summary_changes')->count());
        $this->assertSame($first, $this->version('credit_card', '2026-11-01', '2026-11-07'));
        $this->assertSame($detailVersion, app(FinanceSummaryDataVersion::class)->forCache(['credit_card']));
    }



    public function test_detail_counts_refresh_on_real_edits_but_keep_other_channels_and_old_query_results(): void
    {
        $id = $this->record('2026-11-03', 'DETAIL-COUNT');
        $versions = app(FinanceSummaryDataVersion::class);
        $paypal = $versions->forCache(['paypal']);
        $worldpay = $versions->forCache(['worldpay']);
        $ye = $versions->forCache(['ye']);
        $cache = app(\App\Portals\Finance\FinanceReportCache::class);
        $count = fn () => $this->db->table('finance_records')->where('status', 'FAILED')->count();
        $this->assertSame(0, $cache->remember('failed-count', ['credit_card'], $count));
        $this->db->table('finance_records')->where('id', $id)->update(['status' => 'FAILED']);
        $this->assertNull($cache->get('failed-count', ['credit_card']));
        $this->assertSame(1, $cache->remember('failed-count', ['credit_card'], $count));
        $this->assertSame($paypal, $versions->forCache(['paypal']));
        $this->assertSame($worldpay, $versions->forCache(['worldpay']));
        $this->assertSame($ye, $versions->forCache(['ye']));
        $cache->remember('different-date-query', ['credit_card'], fn () => 30);
        app(FinanceSummaryDependencies::class)->resolve($this->db);
        $this->assertSame(1, $cache->remember('failed-count', ['credit_card'], fn () => $this->fail('Returning to the query must reuse its result.')));
    }

    public function test_one_edit_invalidates_its_date_range_but_preserves_other_months_days_and_paypal(): void
    {
        $id = $this->record('2026-11-03', 'EDIT');
        $this->record('2026-09-03', 'HISTORY');
        $versions = [$this->version('credit_card', '2026-11-01', '2026-11-07'), $this->version('credit_card', '2026-11-08', '2026-11-14'),
            $this->version('credit_card', '2026-09-01', '2026-09-30'), $this->version('paypal', '2026-11-01', '2026-11-30')];
        $this->db->table('finance_records')->where('id', $id)->update(['payload' => '{"amount":"20"}']);
        $this->assertNotSame($versions[0], $this->version('credit_card', '2026-11-01', '2026-11-07'));
        $this->assertSame($versions[1], $this->version('credit_card', '2026-11-08', '2026-11-14'));
        $this->assertSame($versions[2], $this->version('credit_card', '2026-09-01', '2026-09-30'));
        $this->assertSame($versions[3], $this->version('paypal', '2026-11-01', '2026-11-30'));
    }

    public function test_cross_month_refund_updates_its_original_payment_without_invalidating_unrelated_history(): void
    {
        $payment = $this->record('2026-08-03', 'PAY', 'paypal-orders');
        $refund = $this->record('2026-11-03', 'REFUND', 'paypal-orders');
        foreach ([[$payment, 'PAY', ''], [$refund, 'REFUND', 'PAY']] as [$id, $reference, $parent]) {
            $this->db->table('finance_payment_transactions')->insert(['finance_record_id' => $id, 'payment_account_id' => 1,
                'transaction_id' => $reference, 'event_code' => $parent ? 'T1107' : 'T0006',
                'raw_payload' => json_encode(['transaction_info' => ['paypal_reference_id' => $parent, 'invoice_id' => 'INVOICE']])]);
        }
        $august = $this->version('paypal', '2026-08-01', '2026-08-07');
        $september = $this->version('paypal', '2026-09-01', '2026-09-30');
        $november = $this->version('paypal', '2026-11-01', '2026-11-07');
        $this->db->table('finance_payment_transactions')->where('finance_record_id', $refund)->update([
            'raw_payload' => json_encode(['transaction_info' => ['paypal_reference_id' => 'PAY', 'invoice_id' => 'INVOICE', 'amount' => '3']]),
        ]);
        $this->assertNotSame($august, $this->version('paypal', '2026-08-01', '2026-08-07'));
        $this->assertNotSame($november, $this->version('paypal', '2026-11-01', '2026-11-07'));
        $this->assertSame($september, $this->version('paypal', '2026-09-01', '2026-09-30'));
        $this->db->table('finance_payment_transactions')->where('finance_record_id', $refund)->delete();
        $afterDelete = $this->version('paypal', '2026-08-01', '2026-08-07');
        $this->assertNotSame($august, $afterDelete);
        app(FinanceSummaryDependencies::class)->resolve($this->db);
        $this->assertSame($afterDelete, $this->version('paypal', '2026-08-01', '2026-08-07'));
    }

    public function test_dependency_edits_map_only_to_related_financial_dates(): void
    {
        $this->record('2026-11-03', 'LOGISTICS-PAY');
        $id = $this->db->table('logistics_orders')->insertGetId(['bill_number' => 'BILL', 'transaction_id' => 'LOGISTICS-PAY', 'paid_amount_usd' => '10']);
        $before = $this->version('credit_card', '2026-11-01', '2026-11-07');
        $history = $this->version('credit_card', '2026-09-01', '2026-09-30');
        $this->db->table('logistics_orders')->where('id', $id)->update(['paid_amount_usd' => '20']);
        $after = $this->version('credit_card', '2026-11-01', '2026-11-07');
        $this->assertNotSame($before, $after);
        $this->assertSame($history, $this->version('credit_card', '2026-09-01', '2026-09-30'));
        app(FinanceSummaryDependencies::class)->resolve($this->db);
        $this->assertSame($after, $this->version('credit_card', '2026-11-01', '2026-11-07'));
    }

    public function test_complete_month_can_reuse_parts_with_different_valid_scope_versions(): void
    {
        $id = $this->record('2026-11-03', 'PARTS');
        $cache = app(FinanceSummarySourceCache::class);
        $filters = ['timezone' => 'UTC'];
        foreach ([1, 8, 15, 22, 29] as $day) {
            $from = sprintf('2026-11-%02d', $day);
            $to = min(sprintf('2026-11-%02d', $day + 6), '2026-11-30');
            iterator_to_array($cache->stream('credit_card', [...$filters, 'from' => $from, 'to' => $to], false, fn () => yield ['id' => $day, 'team' => 'A']));
        }
        $month = [...$filters, 'from' => '2026-11-01', 'to' => '2026-11-30'];
        $this->assertTrue($cache->ready('credit_card', $month));
        $this->db->table('finance_records')->where('id', $id)->update(['payload' => '{"amount":"20"}']);
        $this->assertFalse($cache->ready('credit_card', $month));
        $this->assertTrue($cache->ready('credit_card', [...$filters, 'from' => '2026-11-08', 'to' => '2026-11-14']));
        iterator_to_array($cache->stream('credit_card', [...$filters, 'from' => '2026-11-01', 'to' => '2026-11-07'], false, fn () => yield ['id' => 101, 'team' => 'A']));
        $rows = iterator_to_array($cache->stream('credit_card', $month, false, fn () => $this->fail('Valid date parts must be reused.')), false);
        $this->assertSame([29, 22, 15, 8, 101], array_column($rows, 'id'));
    }

    public function test_moving_and_deleting_a_record_invalidates_both_old_and_new_dates(): void
    {
        $id = $this->record('2026-11-03', 'MOVE');
        $first = $this->version('credit_card', '2026-11-01', '2026-11-07');
        $second = $this->version('credit_card', '2026-11-08', '2026-11-14');
        $third = $this->version('credit_card', '2026-11-15', '2026-11-21');
        $this->db->table('finance_records')->where('id', $id)->update(['occurred_at' => '2026-11-10 12:00:00']);
        $this->assertNotSame($first, $this->version('credit_card', '2026-11-01', '2026-11-07'));
        $moved = $this->version('credit_card', '2026-11-08', '2026-11-14');
        $this->assertNotSame($second, $moved);
        $this->db->table('finance_records')->where('id', $id)->delete();
        $this->assertNotSame($moved, $this->version('credit_card', '2026-11-08', '2026-11-14'));
        $this->assertSame($third, $this->version('credit_card', '2026-11-15', '2026-11-21'));
    }

    public function test_case_only_reference_edits_are_not_lost_under_a_case_insensitive_database_collation(): void
    {
        $id = $this->record('2026-11-03', 'Case-Sensitive-Reference');
        $before = $this->version('credit_card', '2026-11-01', '2026-11-07');
        $this->db->table('finance_records')->where('id', $id)->update(['reference' => 'case-sensitive-reference']);
        $this->assertNotSame($before, $this->version('credit_card', '2026-11-01', '2026-11-07'));
    }

    public function test_a_shared_business_order_updates_sibling_payments_but_preserves_unrelated_dates(): void
    {
        $id = $this->record('2026-11-03', 'PRIMARY');
        $this->record('2026-11-10', 'ADDON');
        $order = $this->db->table('logistics_orders')->insertGetId(['transaction_id' => 'PRIMARY']);
        $this->db->table('logistics_order_payment_references')->insert(['logistics_order_id' => $order, 'reference_number' => 'ADDON']);
        $first = $this->version('credit_card', '2026-11-01', '2026-11-07');
        $second = $this->version('credit_card', '2026-11-08', '2026-11-14');
        $third = $this->version('credit_card', '2026-11-15', '2026-11-21');
        $this->db->table('finance_records')->where('id', $id)->update(['status' => 'FAILED']);
        $this->assertNotSame($first, $this->version('credit_card', '2026-11-01', '2026-11-07'));
        $this->assertNotSame($second, $this->version('credit_card', '2026-11-08', '2026-11-14'));
        $this->assertSame($third, $this->version('credit_card', '2026-11-15', '2026-11-21'));
    }

    public function test_alternating_report_variants_keeps_each_previous_calculation_after_unrelated_edits(): void
    {
        $id = $this->record('2026-09-03', 'OTHER-MONTH');
        $cache = app(FinanceSummarySourceCache::class);
        $variants = [
            ['summary_settlement_only' => true, 'logistics_costs' => false],
            ['summary_native_settlement' => true, 'logistics_costs' => true], ['logistics_costs' => true],
        ];
        foreach ($variants as $variant) iterator_to_array($cache->stream('credit_card', [
            'from' => '2026-11-01', 'to' => '2026-11-07', ...$variant,
        ], false, fn () => yield ['id' => 1, 'team' => 'A']));
        $this->db->table('finance_records')->where('id', $id)->update(['payload' => '{"amount":"30"}']);
        foreach ([...$variants, ...array_reverse($variants)] as $variant) {
            $filters = ['from' => '2026-11-01', 'to' => '2026-11-07', ...$variant];
            $this->assertTrue($cache->ready('credit_card', $filters));
            $rows = iterator_to_array($cache->stream('credit_card', $filters, false,
                fn () => $this->fail('Unchanged report inputs must not be recalculated when switching back.')), false);
            $this->assertCount(1, $rows);
        }
    }

    public function test_logistics_credit_refunds_refresh_only_the_related_credit_inputs(): void
    {
        $this->record('2026-11-03', 'CARD-PAYMENT');
        $order = $this->db->table('logistics_orders')->insertGetId(['transaction_id' => 'CARD-PAYMENT']);
        $before = $this->version('credit_card', '2026-11-01', '2026-11-07');
        $other = $this->version('credit_card', '2026-11-08', '2026-11-14');
        $paypal = $this->version('paypal', '2026-11-01', '2026-11-30');
        $this->db->table('logistics_credit_card_refunds')->insert(['logistics_order_id' => $order, 'refund_amount_usd' => '3', 'refund_reason' => 'RDR']);
        $this->assertNotSame($before, $this->version('credit_card', '2026-11-01', '2026-11-07'));
        $this->assertSame($other, $this->version('credit_card', '2026-11-08', '2026-11-14'));
        $this->assertSame($paypal, $this->version('paypal', '2026-11-01', '2026-11-30'));
    }

    public function test_xuzong_edits_preserve_worldpay_and_ye_calculations_for_the_same_dates(): void
    {
        $id = $this->record('2026-11-03', 'XUZONG-ONLY');
        $versions = app(FinanceSummaryDataVersion::class);
        $filters = ['from' => '2026-11-01', 'to' => '2026-11-07', 'timezone' => 'UTC'];
        $worldpay = $versions->forRange('credit_card', [...$filters, 'summary_payment_channels' => ['worldpay']]);
        $ye = $versions->forRange('credit_card', [...$filters, 'summary_payment_channels' => ['ye']]);
        $xu = $versions->forRange('credit_card', [...$filters, 'summary_payment_channels' => ['xuzong1', 'xuzong2']]);
        $this->db->table('finance_records')->where('id', $id)->update(['payload' => '{"amount":"20"}']);
        $this->assertNotSame($xu, $versions->forRange('credit_card', [...$filters, 'summary_payment_channels' => ['xuzong1', 'xuzong2']]));
        $this->assertSame($worldpay, $versions->forRange('credit_card', [...$filters, 'summary_payment_channels' => ['worldpay']]));
        $this->assertSame($ye, $versions->forRange('credit_card', [...$filters, 'summary_payment_channels' => ['ye']]));
    }



    public function test_deleting_an_account_keeps_invalidation_dates_after_its_records_are_removed(): void
    {
        $account = $this->db->table('finance_payment_accounts')->insertGetId(['activity_account' => 'A',
            'account_name' => 'Worldpay legacy', 'account_type' => 'credit_card', 'status' => 'enabled']);
        $id = $this->record('2026-11-03', 'DELETED-ACCOUNT');
        $filters = ['from' => '2026-11-01', 'to' => '2026-11-07', 'timezone' => 'UTC', 'summary_payment_channels' => ['worldpay']];
        $before = app(FinanceSummaryDataVersion::class)->forRange('credit_card', $filters);
        $this->db->table('finance_payment_accounts')->where('id', $account)->update(['deleted_at' => now()]);
        $this->db->table('finance_records')->where('id', $id)->delete();
        $after = app(FinanceSummaryDataVersion::class)->forRange('credit_card', $filters);
        $this->assertNotSame($before, $after);
        app(FinanceSummaryDependencies::class)->resolve($this->db);
        $this->assertSame($after, app(FinanceSummaryDataVersion::class)->forRange('credit_card', $filters));
    }
    public function test_application_tracking_works_without_installing_any_triggers_and_rolls_back_with_its_write(): void
    {
        $this->assertSame(0, $this->db->table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')->count());
        $id = $this->record('2026-11-03', 'APP-TRACKED');
        $version = $this->version('credit_card', '2026-11-03');
        try {
            $this->db->transaction(function () use ($id): void {
                $this->db->table('finance_records')->where('id', $id)->update(['reference' => 'ROLLED-BACK']);
                throw new \RuntimeException('test rollback');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('test rollback', $error->getMessage());
        }
        $this->assertSame('APP-TRACKED', $this->db->table('finance_records')->where('id', $id)->value('reference'));
        $this->assertSame($version, $this->version('credit_card', '2026-11-03'));
        $this->db->table('finance_records')->where('id', $id)->update(['reference' => 'COMMITTED']);
        $this->assertNotSame($version, $this->version('credit_card', '2026-11-03'));
    }

    public function test_external_saves_do_not_write_finance_sql_or_hold_extra_source_locks(): void
    {
        $this->record('2026-11-03', 'INDEPENDENT-SOURCE');
        $id = $this->db->table('logistics_orders')->insertGetId(['transaction_id' => 'INDEPENDENT-SOURCE', 'paid_amount_usd' => '10']);
        $versions = app(FinanceSummaryDataVersion::class);
        $first = $versions->forCache(['credit_card']);
        $notifications = app(\App\Portals\Finance\FinanceExternalChanges::class);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $this->db->transaction(function () use ($id): void {
            $this->db->table('logistics_orders')->where('id', $id)->update(['paid_amount_usd' => '20']);
            $this->assertSame(1, $this->db->transactionLevel());
        });
        foreach ($this->db->getQueryLog() as $entry) {
            $this->assertStringNotContainsString('finance_', strtolower($entry['query']));
            $this->assertStringNotContainsString('for update', strtolower($entry['query']));
        }
        $this->assertSame('20', $this->db->table('logistics_orders')->where('id', $id)->value('paid_amount_usd'));
        $this->assertNotEmpty(glob($notifications->directory($this->db).'/change-*.json'));
        $failQueue = true;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\QueryExecuted::class,
            function ($event) use (&$failQueue): void {
                if ($failQueue && $event->connection === $this->db && preg_match('/^insert into `finance_summary_changes`/i', $event->sql)) {
                    throw new \RuntimeException('test: finance queue unavailable');
                }
            });
        $this->assertNull($versions->forCache(['credit_card']), 'Do not reuse old results while the notification cannot be delivered.');
        $this->assertSame('20', $this->db->table('logistics_orders')->where('id', $id)->value('paid_amount_usd'));
        $this->assertNotEmpty(glob($notifications->directory($this->db).'/change-*.json'));
        $failQueue = false;
        $changed = $versions->forCache(['credit_card']);
        $this->assertNotNull($changed); $this->assertNotSame($first, $changed);
        $this->assertSame([], glob($notifications->directory($this->db).'/change-*.json'));
        app(FinanceSummaryDependencies::class)->resolve($this->db);
        $this->assertSame($changed, $versions->forCache(['credit_card']));
    }

    public function test_external_reference_changes_and_deletes_refresh_old_and_new_matches_only(): void
    {
        $this->record('2026-11-03', 'OLD-SOURCE-REF');
        $this->record('2026-11-10', 'NEW-SOURCE-REF');
        $this->record('2026-09-03', 'UNRELATED-SOURCE-REF');
        $id = $this->db->table('logistics_orders')->insertGetId(['transaction_id' => 'OLD-SOURCE-REF']);
        $old = $this->version('credit_card', '2026-11-03');
        $new = $this->version('credit_card', '2026-11-10');
        $history = $this->version('credit_card', '2026-09-03');
        $this->db->table('logistics_orders')->where('id', $id)->update(['transaction_id' => 'NEW-SOURCE-REF']);
        $this->assertNotSame($old, $this->version('credit_card', '2026-11-03'));
        $changed = $this->version('credit_card', '2026-11-10');
        $this->assertNotSame($new, $changed);
        $this->assertSame($history, $this->version('credit_card', '2026-09-03'));
        $this->db->table('logistics_orders')->where('id', $id)->delete();
        $this->assertNotSame($changed, $this->version('credit_card', '2026-11-10'));
        $this->assertSame($history, $this->version('credit_card', '2026-09-03'));
    }

    public function test_external_unchanged_values_do_not_rebuild_financial_caches(): void
    {
        $this->record('2026-11-03', 'SOURCE-UNCHANGED');
        $id = $this->db->table('logistics_orders')->insertGetId(['transaction_id' => 'SOURCE-UNCHANGED', 'paid_amount_usd' => '10']);
        $versions = app(FinanceSummaryDataVersion::class);
        $before = $versions->forCache(['credit_card']);
        $this->db->table('logistics_orders')->where('id', $id)->update(['paid_amount_usd' => '10', 'updated_at' => '2026-11-21 12:00:00']);
        $this->assertSame([], glob(app(\App\Portals\Finance\FinanceExternalChanges::class)->directory($this->db).'/change-*.json'));
        $this->assertSame($before, $versions->forCache(['credit_card']));
    }

    public function test_missing_finance_queue_cannot_prevent_an_external_business_save(): void
    {
        $this->record('2026-11-03', 'OFFLINE-FINANCE');
        $id = $this->db->table('logistics_orders')->insertGetId(['transaction_id' => 'OFFLINE-FINANCE', 'paid_amount_usd' => '10']);
        $before = app(FinanceSummaryDataVersion::class)->forCache(['credit_card']);
        $this->db->getSchemaBuilder()->rename('finance_summary_changes', 'finance_queue_temporarily_offline');
        try {
            $this->db->transaction(fn () => $this->db->table('logistics_orders')->where('id', $id)->update(['paid_amount_usd' => '30']));
            $this->assertSame('30', $this->db->table('logistics_orders')->where('id', $id)->value('paid_amount_usd'));
        } finally { $this->db->getSchemaBuilder()->rename('finance_queue_temporarily_offline', 'finance_summary_changes'); }
        $this->assertNotSame($before, app(FinanceSummaryDataVersion::class)->forCache(['credit_card']));
    }

    public function test_external_rollback_discards_its_notification_and_orphaned_commits_are_recovered(): void
    {
        $this->record('2026-11-03', 'ROLLED-BACK-SOURCE');
        $id = $this->db->table('logistics_orders')->insertGetId(['transaction_id' => 'ROLLED-BACK-SOURCE', 'paid_amount_usd' => '10']);
        $versions = app(FinanceSummaryDataVersion::class);
        $before = $versions->forCache(['credit_card']);
        $notifications = app(\App\Portals\Finance\FinanceExternalChanges::class);
        $this->db->beginTransaction();
        $this->db->table('logistics_orders')->where('id', $id)->update(['paid_amount_usd' => '50']);
        $this->assertNull($versions->forCache(['credit_card']), 'Do not reuse another transaction view for an uncommitted source.');
        $this->db->rollBack();
        $this->assertSame('10', $this->db->table('logistics_orders')->where('id', $id)->value('paid_amount_usd'));
        $this->assertSame([], glob($notifications->directory($this->db).'/change-*.json'));
        $this->assertSame($before, $versions->forCache(['credit_card']));
        $ticket = $notifications->begin($this->db);
        fclose($ticket['handle']); // Simulate an exited writer between its commit and notification callback.
        $recovered = $versions->forCache(['credit_card']);
        $this->assertNotSame($before, $recovered);
        $this->assertSame([], glob($notifications->directory($this->db).'/change-*.json'));
        app(FinanceSummaryDependencies::class)->resolve($this->db);
        $this->assertSame($recovered, $versions->forCache(['credit_card']));
    }

    public function test_normal_migrations_and_tracking_work_without_trigger_or_super_privileges(): void
    {
        $username = 'codex_scope_'.bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(24));
        $admin = DB::connection('scope_admin');
        $this->assertMatchesRegularExpression('/^codex_scope_[a-f0-9]{12}$/D', $username);
        $identity = $admin->getPdo()->quote($username)."@'127.0.0.1'";
        $admin->unprepared('CREATE USER '.$identity.' IDENTIFIED BY '.$admin->getPdo()->quote($password));
        try {
            $grantDatabase = str_replace('_', '\\_', $this->databaseName);
            $admin->unprepared('GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON `'.$grantDatabase.'`.* TO '.$identity);
            config(['database.connections.scope_restricted' => [...config('database.connections.scope_test'), 'host' => '127.0.0.1', 'username' => $username, 'password' => $password],
                'admin.database.connection' => 'scope_restricted', 'database.default' => 'scope_restricted']);
            $restricted = DB::connection('scope_restricted');
            $grants = implode(' ', array_map(fn ($row) => implode(' ', (array) $row), $restricted->select('SHOW GRANTS FOR CURRENT_USER')));
            $this->assertStringNotContainsString('TRIGGER', $grants);
            $this->assertStringNotContainsString('SUPER', $grants);
            (require database_path('migrations/2026_11_21_000005_create_finance_relational_sources.php'))->up();
            (require database_path('migrations/2026_11_20_000003_scope_finance_source_invalidations.php'))->up();
            (require database_path('migrations/2026_11_21_000006_enable_finance_application_tracking.php'))->up();
            $id = $restricted->table('finance_records')->insertGetId(['dataset' => 'xuzong2-details', 'account' => 'A',
                'reference' => 'RESTRICTED', 'occurred_at' => '2026-11-03 12:00:00', 'payload' => '{"amount":"10"}']);
            $first = $this->version('credit_card', '2026-11-03');
            $restricted->table('finance_records')->where('id', $id)->update(['payload' => '{"amount":"20"}']);
            $this->assertNotSame($first, $this->version('credit_card', '2026-11-03'));
            $restricted->getSchemaBuilder()->table('finance_records', function (Blueprint $table): void {
                $table->boolean('finance_is_conflux_order')->virtualAs("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$._card_channel')) = 'ye', 0)");
                $table->index(['finance_is_conflux_order', 'account', 'reference'], 'finance_conflux_order_match_idx');
            });
            (require database_path('migrations/2026_11_21_000007_retire_finance_source_json.php'))->up();
            $this->assertFalse($restricted->getSchemaBuilder()->hasColumn('finance_records', 'payload'));
            $record = \App\Models\FinanceRecord::findOrFail($id);
            $this->assertSame('20', $record->payload['amount']);
            $beforeEdit = $this->version('credit_card', '2026-11-03');
            $record->update(['payload' => ['amount' => '30', 'CaseSensitive' => 'AbC']]);
            $this->assertNotSame($beforeEdit, $this->version('credit_card', '2026-11-03'));
            $this->assertSame(1, \App\Models\FinanceRecord::where('payload->CaseSensitive', 'AbC')->whereNull('payload->absent')->count());
            $this->assertSame(0, \App\Models\FinanceRecord::where('payload->CaseSensitive', 'abc')->count());
        } finally {
            DB::disconnect('scope_restricted');
            config(['admin.database.connection' => 'scope_test', 'database.default' => 'scope_test']);
            $admin->unprepared('DROP USER '.$identity);
        }
    }


}
