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
    private ?string $databaseName = null;
    private ?string $cachePath = null;
    private Connection $db;

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
        config(['cache.stores.file.path' => $this->cachePath]);
        app('cache')->forgetDriver('file');
        $this->createTables();
        foreach (['2026_11_13_000001_create_finance_summary_projections', '2026_11_13_000002_track_finance_summary_source_changes',
            '2026_11_13_000005_track_finance_statement_month_changes', '2026_11_20_000002_track_finance_live_source_updates',
            '2026_11_20_000003_scope_finance_source_invalidations'] as $migration) {
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

    public function test_missing_tracking_disables_persistent_reuse_and_repair_invalidates_pre_gap_files(): void
    {
        $id = $this->record('2026-11-03', 'TRACKING-GAP');
        $before = $this->version('credit_card', '2026-11-01', '2026-11-07');
        $this->db->unprepared('DROP TRIGGER finance_scope_record_update');
        $this->app->instance('request', \Illuminate\Http\Request::create('/finance/reports/summary'));
        $this->assertNull(app(FinanceSummaryDataVersion::class)->forRange('credit_card', ['from' => '2026-11-01', 'to' => '2026-11-07']));
        $this->db->table('finance_records')->where('id', $id)->update(['payload' => '{"amount":"50"}']);
        (require database_path('migrations/2026_11_20_000003_scope_finance_source_invalidations.php'))->up();
        $this->assertNotSame($before, $this->version('credit_card', '2026-11-01', '2026-11-07'));
    }

    public function test_an_old_worker_cannot_delete_unresolved_input_changes_during_deployment(): void
    {
        $this->record('2026-11-03', 'PENDING-CHANGE');
        try {
            $this->db->table('finance_summary_changes')->delete();
            $this->fail('Unresolved source changes must survive an old worker acknowledgement.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('must be resolved before acknowledgement', $error->getMessage());
        }
        $this->assertGreaterThan(0, $this->db->table('finance_summary_changes')->count());
        app(FinanceSummaryDependencies::class)->resolve($this->db);
        $this->assertSame(0, $this->db->table('finance_summary_changes')->count());
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
}
