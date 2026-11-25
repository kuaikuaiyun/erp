<?php

namespace Tests\Feature;

use App\Services\FinanceSync\FinanceSyncCatalog as Catalog;
use App\Services\FinanceSync\FinanceSyncPublisher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinanceSyncApiTest extends TestCase
{
    private const PATH = '/api/v1/finance-sync';

    private Connection $db;
    private ?Migration $migration = null;
    private array $fixtureTables = [];
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $mysql = getenv('FINANCE_SYNC_TEST_MYSQL_DATABASE');
        if ($mysql !== false && $mysql !== '') {
            // Opt in on a dedicated, disposable, empty schema only.
            self::assertMatchesRegularExpression('/^finance_sync_feature_test(?:_[a-z0-9]+)?$/D', $mysql);
            config(['database.default' => 'mysql', 'database.connections.mysql.database' => $mysql,
                'database.connections.mysql.url' => null]);
        } else {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null]);
        }
        $this->token = bin2hex(random_bytes(32));
        config([
            'admin.database.connection' => null,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.locale' => 'en',
            'finance_sync.enabled' => true,
            'finance_sync.require_https' => true,
            'finance_sync.token_sha256' => hash('sha256', $this->token),
            'finance_sync.token_expires_at' => 0,
            'finance_sync.previous_token_sha256' => '',
            'finance_sync.previous_token_expires_at' => 0,
            'finance_sync.rate_store' => 'array',
            'finance_sync.requests_per_minute' => 60,
            'finance_sync.include_account_email' => false,
            'finance_sync.include_sku_cost' => false,
            'finance_sync.source_timezone' => 'UTC',
        ]);

        $this->db = Catalog::connection();
        $schemaName = $this->db->getDriverName() === 'mysql' ? $this->db->getDatabaseName() : null;
        self::assertSame([], $this->db->getSchemaBuilder()->getTableListing($schemaName), 'The finance sync test database must be empty.');
        $this->makeSourceFixtures();
        $this->migration = require database_path('migrations/2026_11_25_230003_create_finance_sync_tables.php');
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->migration?->down();
        if (isset($this->db)) {
            foreach (array_reverse($this->fixtureTables) as $table) {
                $this->db->getSchemaBuilder()->drop($table);
            }
            DB::purge($this->db->getName());
        }
        parent::tearDown();
    }

    public function test_access_errors_use_the_contract_and_rotation_is_independent_of_ip(): void
    {
        $this->get('http://localhost'.self::PATH.'/snapshot/orders')
            ->assertForbidden()->assertJsonPath('code', 'HTTPS_REQUIRED');
        $this->get('https://localhost'.self::PATH.'/snapshot/orders')
            ->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer')->assertJsonPath('data', null)
            ->assertJsonStructure(['code', 'message', 'data', 'meta' => ['request_id']]);
        $this->api('/snapshot/orders')->assertServiceUnavailable()->assertJsonPath('code', 'SYNC_NOT_READY');
        $this->publish();
        $this->api('/snapshot/orders?limit=201')->assertUnprocessable()->assertJsonPath('code', 'INVALID_REQUEST');
        $this->api('/snapshot/orders?fields=buyer_email')->assertUnprocessable();
        $this->postJson('https://localhost'.self::PATH.'/snapshot/orders')->assertStatus(405)
            ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
        $this->api('/snapshot/fashion-orders')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');

        config([
            'finance_sync.previous_token_sha256' => hash('sha256', $this->token),
            'finance_sync.previous_token_expires_at' => time() + 600,
            'finance_sync.token_sha256' => hash('sha256', bin2hex(random_bytes(32))),
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.14'])->api('/snapshot/orders')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data', []);
        config(['finance_sync.previous_token_expires_at' => time() - 1]);
        $this->api('/snapshot/orders')->assertUnauthorized();
        config(['finance_sync.enabled' => false]);
        $this->api('/snapshot/orders')->assertNotFound();
    }

    public function test_limits_are_shared_by_the_client_across_ip_and_token_rotation(): void
    {
        $this->publish();
        config(['finance_sync.requests_per_minute' => 1]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.14'])->api('/snapshot/orders')->assertOk();

        $this->token = bin2hex(random_bytes(32));
        config(['finance_sync.token_sha256' => hash('sha256', $this->token)]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.12'])->api('/snapshot/stores')
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_snapshot_applies_each_allowlist_and_republishes_when_optional_fields_change(): void
    {
        foreach (Catalog::RESOURCES as $resource => [$table]) {
            $values = match ($resource) {
                'paypal-accounts' => ['account_email' => 'merchant@example.test', 'account_name' => 'Primary'],
                'tongtu-sku-mappings' => ['goods_current_cost_cny' => '12.75', 'cost_currency' => 'cny'],
                'order-items' => ['quantity' => '2.125', 'sale_weight_gram' => '130.125'],
                'orders' => ['payment_status' => '已付款', 'subscription_type' => '订阅复购2次', 'is_add_on' => '未加购',
                    'order_created_at' => '2025-01-02 03:04:05'],
                default => [],
            };
            $this->db->table($table)->insert(['id' => 1, 'internal_note' => 'private-raw-data', ...$values]);
        }
        $this->publish();

        foreach (Catalog::RESOURCES as $resource => [, $fields]) {
            $response = $this->api('/snapshot/'.$resource)->assertOk()->assertJsonPath('code', 'OK')->assertJsonPath('data.0.id', '1');
            $expected = match ($resource) {
                'orders' => [...$fields, 'shop_order_currency', 'shop_order_amount', 'merchant_order_number',
                    'payment_original_currency', 'payment_original_amount'],
                'order-payment-references' => [...$fields, 'payment_original_currency', 'payment_original_amount'],
                default => $fields,
            };
            self::assertEqualsCanonicalizing($expected, array_keys($response->json('data.0')));
            $response->assertDontSee('private-raw-data');
        }
        $this->api('/snapshot/orders')->assertJsonPath('data.0.is_add_on', false)
            ->assertJsonPath('data.0.payment_status', 'paid')->assertJsonPath('data.0.subscription_type', 'recurring_2')
            ->assertJsonPath('data.0.order_created_at', '2025-01-02T03:04:05+00:00');
        $this->api('/snapshot/order-items')->assertJsonPath('data.0.quantity', '2.125');
        $before = $this->api('/snapshot/paypal-accounts')->json('meta.changes_cursor');
        config(['finance_sync.include_account_email' => true, 'finance_sync.include_sku_cost' => true]);
        $this->api('/snapshot/paypal-accounts')->assertServiceUnavailable();
        $this->publish();
        $this->api('/snapshot/paypal-accounts')->assertJsonPath('data.0.account_email', 'merchant@example.test');
        $this->api('/snapshot/tongtu-sku-mappings')->assertJsonPath('data.0.goods_current_cost_cny', '12.75')
            ->assertJsonPath('data.0.cost_currency', 'CNY');
        $this->api('/changes/paypal-accounts?cursor='.$before)->assertUnprocessable()->assertJsonPath('code', 'INVALID_CURSOR');
    }

    public function test_snapshot_and_delta_pagination_cover_interleaved_writes_and_removals(): void
    {
        $accounts = $this->db->table(Catalog::table('paypal-accounts'));
        $accounts->insert([
            ['id' => 1, 'account_name' => 'Initial', 'is_enabled' => 1],
            ['id' => 2, 'account_name' => 'Secondary', 'is_enabled' => 1],
            ['id' => 3, 'account_name' => 'Deleted next', 'is_enabled' => 1],
        ]);
        $this->publish();
        $snapshot = $this->api('/snapshot/paypal-accounts?limit=1')->assertOk()
            ->assertJsonPath('data.0.id', '1')->assertJsonPath('meta.has_more', true);
        $anchor = $snapshot->json('meta.changes_cursor');

        (clone $accounts)->where('id', 1)->update(['account_name' => 'initial']);
        (clone $accounts)->whereIn('id', [2])->update(['is_enabled' => 0]);
        (clone $accounts)->where('id', 3)->delete();
        $accounts->insert(['id' => 4, 'account_name' => 'Added', 'is_enabled' => 1]);
        $this->publish();

        $this->api('/snapshot/paypal-accounts?limit=1&cursor='.$snapshot->json('meta.next_cursor'))
            ->assertJsonPath('data', [])->assertJsonPath('meta.changes_cursor', $anchor);
        $cursor = $anchor;
        foreach (['updated', 'disabled', 'deleted', 'created'] as $offset => $event) {
            $page = $this->api('/changes/paypal-accounts?limit=1&cursor='.$cursor)->assertOk()
                ->assertJsonPath('data.0.id', (string) ($offset + 1))->assertJsonPath('data.0.change_type', $event);
            $cursor = $page->json('meta.next_cursor');
        }
        $this->api('/changes/paypal-accounts?cursor='.$cursor)->assertJsonPath('data', [])->assertJsonPath('meta.next_cursor', $cursor);
        $this->api('/changes/stores?cursor='.$anchor)->assertUnprocessable();
        $this->api('/changes/paypal-accounts?cursor='.$anchor.'x')->assertUnprocessable();
        $this->api('/changes/paypal-accounts?cursor='.$anchor)->assertJsonPath('data.0.account_name', 'initial')
            ->assertJsonPath('data.2.change_type', 'deleted')->assertJsonMissingPath('data.2.account_name');

        $this->db->beginTransaction();
        (clone $accounts)->where('id', 4)->delete();
        $this->db->rollBack();
        self::assertSame(0, $this->publish());
        $this->api('/changes/paypal-accounts?cursor='.$cursor)->assertJsonPath('data', []);
    }

    public function test_concurrent_mysql_commit_is_left_pending_when_a_publish_batch_has_read_an_older_revision(): void
    {
        if ($this->db->getDriverName() !== 'mysql') {
            self::markTestSkipped('Requires the optional MySQL fixture database.');
        }

        $table = Catalog::table('paypal-accounts');
        $this->db->table($table)->insert(['id' => 1, 'account_name' => 'Initial']);
        $this->publish();
        $cursor = $this->api('/snapshot/paypal-accounts')->assertOk()->json('meta.changes_cursor');
        $this->db->table($table)->where('id', 1)->update(['account_name' => 'First commit']);

        config(['database.connections.finance_sync_writer' => config('database.connections.mysql')]);
        $writer = DB::connection('finance_sync_writer');
        $wrote = false;
        $this->db->listen(function (QueryExecuted $event) use ($writer, $table, &$wrote): void {
            if (! $wrote && $event->connection === $this->db && str_starts_with($event->sql, 'select *')
                && str_contains($event->sql, Catalog::PENDING)) {
                $wrote = true;
                $writer->table($table)->where('id', 1)->update(['account_name' => 'Concurrent commit']);
            }
        });

        try {
            $this->app->make(FinanceSyncPublisher::class)->run(2, 1);
            self::assertTrue($wrote);
            self::assertSame(2, (int) $this->db->table(Catalog::PENDING)->value('revision'));
            $this->publish();
            $this->api('/changes/paypal-accounts?cursor='.$cursor)->assertOk()
                ->assertJsonPath('data.0.account_name', 'Concurrent commit');
        } finally {
            DB::purge('finance_sync_writer');
        }
    }

    public function test_shopify_paid_usd_uses_the_successful_transaction_amount_and_leaves_unavailable_original_null(): void
    {
        $this->db->table(Catalog::table('orders'))->insert([
            'id' => 1,
            'paid_amount_usd' => '99',
            'order_created_at' => '2025-01-02 03:04:05',
            'shopify_payload' => json_encode([
                'currentTotalPriceSet' => ['shopMoney' => ['currencyCode' => 'USD', 'amount' => '99']],
                'createdAt' => '2025-01-02T03:04:05-05:00',
                'transactions' => [
                    ['id' => 'gid://shopify/OrderTransaction/1', 'kind' => 'SALE', 'status' => 'FAILURE',
                        'amountSet' => ['shopMoney' => ['currencyCode' => 'USD', 'amount' => '99']]],
                    ['id' => 'gid://shopify/OrderTransaction/2', 'kind' => 'CAPTURE', 'status' => 'SUCCESS',
                        'amountSet' => ['shopMoney' => ['currencyCode' => 'USD', 'amount' => '37.75']]],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->publish();
        $snapshot = $this->api('/snapshot/orders')->assertOk()->assertJsonPath('data.0.shop_order_amount', '99')
            ->assertJsonPath('data.0.paid_amount_usd', '37.75')->assertJsonPath('data.0.payment_original_currency', null)
            ->assertJsonPath('data.0.payment_original_amount', null)->assertJsonPath('data.0.order_created_at', '2025-01-02T08:04:05+00:00');
        $this->db->table(Catalog::table('orders'))->where('id', 1)->update(['order_created_at' => '2025-01-02 09:01:00']);
        $this->publish();
        $this->api('/changes/orders?cursor='.$snapshot->json('meta.changes_cursor'))
            ->assertOk()->assertJsonPath('data.0.order_created_at', '2025-01-02T09:01:00+00:00');
    }

    public function test_payment_amounts_follow_the_exact_reference_and_refresh_when_ledger_changes(): void
    {
        $orders = $this->db->table(Catalog::table('orders'));
        $orders->insert([
            'id' => 14, 'paypal_account_id' => 5, 'bill_number' => 'MAIN-1',
            'paid_amount_usd' => '91.5', 'total_shipping_usd' => '4.25',
            'shopify_payload' => json_encode([
                'currentTotalPriceSet' => ['shopMoney' => ['currencyCode' => 'EUR', 'amount' => '91.50']],
                'customAttributes' => [['key' => 'yx_order_id', 'value' => 'MERCHANT-14']],
                'customer' => ['email' => 'buyer@example.test'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->db->table(Catalog::table('order-payment-references'))->insert([
            ['id' => 19, 'logistics_order_id' => 14, 'reference_number' => 'MAIN-1', 'reference_type' => 'primary'],
            ['id' => 20, 'logistics_order_id' => 14, 'reference_number' => 'UP-1', 'reference_type' => 'upsell'],
        ]);
        $this->db->table(Catalog::PAYPAL_LEDGER)->insert([
            ['logistics_order_id' => 14, 'logistics_paypal_account_id' => 5, 'payment_transaction_id' => 'MAIN-1',
                'status' => 'S', 'currency_code' => 'EUR', 'original_amount' => '90.5'],
            ['logistics_order_id' => 14, 'logistics_paypal_account_id' => 9, 'payment_transaction_id' => 'UP-1',
                'status' => 'S', 'currency_code' => 'USD', 'original_amount' => '77.5'],
        ]);
        $this->publish();
        $this->api('/snapshot/orders')->assertOk()->assertJsonPath('data.0.shop_order_currency', 'EUR')
            ->assertJsonPath('data.0.merchant_order_number', 'MERCHANT-14')->assertJsonPath('data.0.paid_amount_usd', null)
            ->assertJsonPath('data.0.payment_original_amount', null)->assertDontSee('buyer@example.test');
        $references = $this->api('/snapshot/order-payment-references')->assertOk()
            ->assertJsonPath('data.0.reference_type', 'primary')->assertJsonPath('data.0.payment_original_amount', '90.5')
            ->assertJsonPath('data.1.reference_type', 'upsell')->assertJsonPath('data.1.payment_original_amount', null);
        $cursor = $references->json('meta.changes_cursor');

        $this->db->table(Catalog::PAYPAL_LEDGER)->insert([
            'logistics_order_id' => 14, 'logistics_paypal_account_id' => 5, 'payment_transaction_id' => 'UP-1',
            'status' => 'S', 'currency_code' => 'USD', 'original_amount' => '11.25',
        ]);
        $this->publish();
        $this->api('/changes/order-payment-references?cursor='.$cursor)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', '20')->assertJsonPath('data.0.change_type', 'updated')
            ->assertJsonPath('data.0.payment_original_currency', 'USD')->assertJsonPath('data.0.payment_original_amount', '11.25');
    }

    public function test_logistics_reads_are_paginated_and_do_not_expose_log_messages_or_context(): void
    {
        $this->db->table(Catalog::table('orders'))->insert([
            'id' => 25, 'tracking_number' => 'TRACK-25', 'logistics_status' => '运输中',
            'tracking_latest_event' => 'In transit, contact customer@example.test',
        ]);
        foreach ([31, 32] as $id) {
            $this->db->table('logistics_sync_logs')->insert([
                'id' => $id, 'logistics_order_id' => 25, 'source' => 'shopify', 'action' => 'sync',
                'status' => 'success', 'message' => 'private note', 'internal_note' => 'private context',
            ]);
        }
        $this->publish();
        $this->api('/orders/25/logistics')->assertOk()->assertJsonPath('data.logistics_status', 'in_transit')
            ->assertJsonPath('data.tracking_number', 'TRACK-25')->assertDontSee('customer@example.test');
        $first = $this->api('/orders/25/logistics-logs?limit=1')->assertOk()->assertJsonPath('data.0.id', '32')
            ->assertJsonPath('data.0.message', __('finance_sync.log_summary.success'))->assertDontSee('private');
        $this->api('/orders/25/logistics-logs?cursor='.$first->json('meta.next_cursor'))->assertOk()
            ->assertJsonPath('data.0.id', '31')->assertJsonPath('meta.next_cursor', null);
        $this->api('/orders/999/logistics')->assertNotFound();
    }

    public function test_trigger_rollback_preserves_source_rows_and_writes(): void
    {
        $this->db->table(Catalog::table('orders'))->insert(['id' => 41]);
        self::assertSame(1, $this->db->table(Catalog::PENDING)->count());
        $this->migration->down();
        $this->migration = null;
        self::assertFalse($this->db->getSchemaBuilder()->hasTable(Catalog::PENDING));
        $this->db->table(Catalog::table('orders'))->where('id', 41)->update(['operator_name' => 'Verified']);
        self::assertSame('Verified', $this->db->table(Catalog::table('orders'))->where('id', 41)->value('operator_name'));
    }

    private function api(string $path): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('https://localhost'.self::PATH.$path, ['Authorization' => 'Bearer '.$this->token]);
    }

    private function publish(): int
    {
        return $this->app->make(FinanceSyncPublisher::class)->run(2, 50);
    }

    private function makeSourceFixtures(): void
    {
        foreach (Catalog::RESOURCES as $resource => [$table]) {
            $columns = Catalog::columns($resource, true);
            $this->fixture($table, $resource === 'orders' ? [...$columns, ...Catalog::LOGISTICS] : $columns);
        }
        $this->fixture(Catalog::PAYPAL_LEDGER, [
            'id', 'logistics_order_id', 'logistics_paypal_account_id', 'payment_transaction_id', 'status',
            'currency_code', 'original_amount',
        ]);
        $this->fixture('logistics_sync_logs', [...Catalog::LOGS, 'message']);
    }

    private function fixture(string $table, array $columns): void
    {
        $this->db->getSchemaBuilder()->create($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->id();
            $blueprint->longText('internal_note')->nullable();
            foreach (array_diff($columns, ['id']) as $column) {
                if ($column === 'logistics_order_id') {
                    $blueprint->unsignedBigInteger($column)->nullable()->index();
                } else {
                    $blueprint->text($column)->nullable();
                }
            }
        });
        $this->fixtureTables[] = $table;
    }
}
