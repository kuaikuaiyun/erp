<?php

namespace Tests\Feature;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalImport;
use App\Portals\Logistics\Http\Controllers\PayPalImportController;
use App\Portals\Logistics\PayPalImportService;
use App\Portals\Logistics\PayPalRelayClient;
use App\Portals\Logistics\PayPalRelaySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogisticsPayPalRelaySyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'admin.database.connection' => null,
            'logistics.paypal.relay.ca_bundle' => null,
            'logistics.paypal.relay.initial_days' => 90,
            'logistics.paypal.relay.transaction_segment_days' => 30,
            'logistics.paypal.relay.dispute_segment_days' => 7,
            'logistics.paypal.relay.transaction_page_size' => 500,
            'logistics.paypal.relay.dispute_page_size' => 50,
        ]);
        app('db')->purge('sqlite');
        Storage::fake('local');

        Schema::create('logistics_paypal_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('account_name', 191);
            $table->string('merchant_id', 100)->nullable()->unique();
            $table->string('account_email', 191)->nullable()->unique();
            $table->string('environment', 20)->default('live');
            $table->boolean('is_enabled')->default(true);
            $table->string('relay_url', 500)->nullable();
            $table->char('relay_certificate_thumbprint_sha1', 40)->nullable();
            $table->string('relay_hmac_key_id', 64)->nullable();
            $table->text('relay_hmac_secret')->nullable();
            $table->dateTime('last_imported_at')->nullable();
            $table->dateTime('last_import_from')->nullable();
            $table->dateTime('last_import_to')->nullable();
            $table->string('last_import_status', 30)->nullable();
            $table->text('last_import_message')->nullable();
            $table->timestamps();
        });
        Schema::create('logistics_paypal_imports', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('logistics_paypal_account_id')->index();
            $table->string('upload_id', 64)->unique();
            $table->string('merchant_id', 100);
            $table->string('environment', 20)->nullable();
            $table->string('source_file', 255)->nullable();
            $table->char('payload_sha256', 64);
            $table->string('storage_path', 500);
            $table->dateTime('period_from')->nullable();
            $table->dateTime('period_to')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->string('status', 30)->index();
            $table->unsignedInteger('transaction_count')->default(0);
            $table->unsignedInteger('refund_count')->default(0);
            $table->unsignedInteger('dispute_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function test_relay_sync_builds_the_existing_schema_v2_contract_before_importing(): void
    {
        $account = $this->account();
        $received = [];
        Http::fake(function ($request) use ($account, &$received) {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $received[] = $body;

            return $this->signedRelayResponse($request, $account, match ($body['operation']) {
                'transaction_search' => [
                    'page' => (int) $body['query']['page'],
                    'total_pages' => 1,
                    'total_items' => 1,
                    'transaction_details' => [[
                        'transaction_info' => [
                            'transaction_id' => 'PAY-'.$body['query']['start_date'],
                            'transaction_event_code' => 'T0006',
                            'transaction_initiation_date' => $body['query']['start_date'],
                            'transaction_amount' => ['value' => '10.00', 'currency_code' => 'USD'],
                        ],
                    ]],
                ],
                'dispute_search' => ['items' => [], 'links' => []],
                default => throw new \RuntimeException('Unexpected relay operation'),
            });
        });
        $imports = $this->mock(PayPalImportService::class);
        $imports->shouldReceive('import')->once()->withArgs(function ($receivedAccount, $import, array $payload) use ($account): bool {
            $this->assertTrue($receivedAccount->is($account));
            $this->assertInstanceOf(LogisticsPayPalImport::class, $import);
            $this->assertSame(2, $payload['schema_version']);
            $this->assertSame($account->merchant_id, $payload['merchant_id']);
            $this->assertSame([], $payload['errors']);
            $this->assertSame(0, $payload['collection_summary']['errors']);
            $this->assertNotEmpty($payload['source_responses']['transaction_search']['segments']);
            $this->assertSame([], $payload['source_responses']['dispute_search']['detail_responses']);

            return true;
        })->andReturn([
            'status' => 'success',
            'transactions' => 4,
            'refunds' => 0,
            'disputes' => 0,
            'errors' => 0,
            'message' => 'Existing importer received the relay payload.',
        ]);

        $now = CarbonImmutable::parse('2026-11-07T00:00:00Z');
        $result = app(PayPalRelaySyncService::class)->sync($account, $now);

        $this->assertTrue($result['ok']);
        $this->assertSame('success', $result['status']);
        $this->assertDatabaseCount('logistics_paypal_imports', 1);
        $this->assertSame('2026-08-09T00:00:00Z', $received[0]['query']['start_date']);
        $this->assertSame('all', $received[0]['query']['fields']);
        $this->assertSame('N', $received[0]['query']['balance_affecting_records_only']);
        $this->assertContains('transaction_search', array_column($received, 'operation'));
        $this->assertContains('dispute_search', array_column($received, 'operation'));
    }

    public function test_failed_relay_collection_does_not_touch_existing_imports_or_cursor(): void
    {
        $cursor = CarbonImmutable::parse('2026-11-06T00:00:00Z');
        $account = $this->account([
            'last_imported_at' => $cursor,
            'last_import_from' => $cursor->subDay(),
            'last_import_to' => $cursor,
            'last_import_status' => 'success',
            'last_import_message' => 'Old VPS push completed.',
        ]);
        LogisticsPayPalImport::query()->create([
            'logistics_paypal_account_id' => $account->id,
            'upload_id' => 'legacy-upload-0001',
            'merchant_id' => $account->merchant_id,
            'environment' => 'live',
            'source_file' => 'legacy.json',
            'payload_sha256' => hash('sha256', 'legacy'),
            'storage_path' => 'paypal-imports/legacy.json',
            'period_from' => $cursor->subDay(),
            'period_to' => $cursor,
            'generated_at' => $cursor,
            'status' => 'success',
        ]);
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return Http::response('{"page":1,"total_pages":1,"total_items":0,"transaction_details":[]}', 200);
        });
        $imports = $this->mock(PayPalImportService::class);
        $imports->shouldNotReceive('import');

        $result = app(PayPalRelaySyncService::class)->sync($account, CarbonImmutable::parse('2026-11-07T00:00:00Z'));

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('2026-11-06T00:00:00Z', $requests[0]['query']['start_date']);
        $this->assertDatabaseCount('logistics_paypal_imports', 1);
        $account->refresh();
        $this->assertSame($cursor->format('Y-m-d H:i:s'), $account->last_import_to->format('Y-m-d H:i:s'));
        $this->assertSame('success', $account->last_import_status);
        $this->assertSame('Old VPS push completed.', $account->last_import_message);
    }

    public function test_dry_run_validates_the_relay_without_creating_an_import_or_touching_existing_data(): void
    {
        $account = $this->account();
        Http::fake(function ($request) use ($account) {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $this->signedRelayResponse($request, $account, match ($body['operation']) {
                'transaction_search' => [
                    'page' => (int) $body['query']['page'],
                    'total_pages' => 1,
                    'total_items' => 0,
                    'transaction_details' => [],
                ],
                'dispute_search' => ['items' => [], 'links' => []],
                default => throw new \RuntimeException('Unexpected relay operation'),
            });
        });
        $imports = $this->mock(PayPalImportService::class);
        $imports->shouldNotReceive('import');

        $result = app(PayPalRelaySyncService::class)->sync(
            $account,
            CarbonImmutable::parse('2026-11-07T00:00:00Z'),
            true,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['transactions']);
        $this->assertDatabaseCount('logistics_paypal_imports', 0);
        $account->refresh();
        $this->assertNull($account->last_imported_at);
        $this->assertNull($account->last_import_status);
    }

    public function test_initial_ninety_day_sync_does_not_issue_a_one_second_transaction_request(): void
    {
        $account = $this->account();
        $transactions = [];
        Http::fake(function ($request) use ($account, &$transactions) {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            if ($body['operation'] === 'transaction_search') {
                $transactions[] = $body['query'];

                return $this->signedRelayResponse($request, $account, [
                    'page' => (int) $body['query']['page'],
                    'total_pages' => 1,
                    'total_items' => 0,
                    'transaction_details' => [],
                ]);
            }
            if ($body['operation'] === 'dispute_search') {
                return $this->signedRelayResponse($request, $account, ['items' => [], 'links' => []]);
            }

            throw new \RuntimeException('Unexpected relay operation');
        });
        $imports = $this->mock(PayPalImportService::class);
        $imports->shouldNotReceive('import');

        $now = CarbonImmutable::parse('2026-11-09T03:29:03Z');
        $result = app(PayPalRelaySyncService::class)->sync($account, $now, true);

        $this->assertTrue($result['ok']);
        $this->assertCount(3, $transactions);
        $this->assertSame('2026-08-11T03:29:03Z', $transactions[0]['start_date']);
        $this->assertSame('2026-11-09T03:29:03Z', $transactions[2]['end_date']);
        foreach ($transactions as $transaction) {
            $this->assertNotSame($transaction['start_date'], $transaction['end_date']);
        }
        $this->assertDatabaseCount('logistics_paypal_imports', 0);
    }

    public function test_existing_signed_vps_push_contract_still_enters_the_unchanged_import_controller(): void
    {
        config(['logistics.paypal.import_secret' => 'legacy-import-secret']);
        $account = $this->account();
        $now = now('UTC')->format('Y-m-d\\TH:i:s\\Z');
        $payload = [
            'schema_version' => 2,
            'upload_id' => 'legacy-push-'.Str::uuid(),
            'merchant_id' => $account->merchant_id,
            'environment' => 'live',
            'generated_at' => $now,
            'period' => ['from' => $now, 'to' => $now],
            'source_responses' => [
                'transaction_search' => ['segments' => []],
                'dispute_search' => ['list_responses' => [], 'detail_responses' => []],
            ],
            'collection_summary' => ['transactions' => 0, 'disputes' => 0, 'errors' => 0],
            'errors' => [],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".hash('sha256', $json), 'legacy-import-secret');
        $request = Request::create('/api/paypal/import', 'POST', [], [], [], [
            'HTTP_X_PAYPAL_TIMESTAMP' => $timestamp,
            'HTTP_X_PAYPAL_NONCE' => $nonce,
            'HTTP_X_PAYPAL_SIGNATURE' => $signature,
            'HTTP_X_PAYPAL_FILENAME' => 'legacy-push.json',
            'CONTENT_TYPE' => 'application/json',
        ], $json);
        $imports = $this->mock(PayPalImportService::class);
        $imports->shouldReceive('import')->once()->andReturn([
            'status' => 'success', 'transactions' => 0, 'payments' => 0, 'payment_matched' => 0,
            'payment_unmatched' => 0, 'refunds' => 0, 'disputes' => 0,
            'matched' => 0, 'unmatched' => 0, 'errors' => 0, 'balances' => 0,
            'ledger_entries' => 0, 'message' => 'Legacy push accepted.',
        ]);

        $response = app(PayPalImportController::class)($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['accepted']);
        $this->assertDatabaseHas('logistics_paypal_imports', ['upload_id' => $payload['upload_id']]);
    }

    private function account(array $attributes = []): LogisticsPayPalAccount
    {
        return LogisticsPayPalAccount::query()->create([
            'account_name' => 'Relay test account '.Str::random(8),
            'merchant_id' => 'MERCHANT-'.Str::upper(Str::random(8)),
            'account_email' => Str::lower(Str::random(8)).'@example.test',
            'environment' => 'live',
            'is_enabled' => true,
            'relay_url' => 'https://relay.example.test/relay/v1/paypal/read',
            'relay_certificate_thumbprint_sha1' => str_repeat('A', 40),
            'relay_hmac_key_id' => 'erp-main',
            'relay_hmac_secret' => base64_encode('test-key-not-production-with-32-bytes'),
            ...$attributes,
        ]);
    }

    private function signedRelayResponse($request, LogisticsPayPalAccount $account, array $payload, int $status = 200)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = $request->header('X-Relay-Nonce')[0];
        $message = PayPalRelayClient::responseMessage($timestamp, $nonce, $status, $body);

        return Http::response($body, $status, [
            'X-Relay-Response-Timestamp' => $timestamp,
            'X-Relay-Response-Signature' => hash_hmac('sha256', $message, base64_decode($account->relay_hmac_secret, true)),
        ]);
    }
}
