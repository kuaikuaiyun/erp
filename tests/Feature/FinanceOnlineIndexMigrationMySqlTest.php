<?php

namespace Tests\Feature;

use App\Console\Commands\ApplyFinanceIndexes;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/** Opt-in tests create and remove their own empty database on a loopback MySQL server. */
class FinanceOnlineIndexMigrationMySqlTest extends TestCase
{
    private ?string $testDatabase = null;
    private Connection $db;
    private array $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('FINANCE_DDL_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Set FINANCE_DDL_MYSQL_TESTS=1 for isolated MySQL DDL tests.');
        }
        $this->mysql = config('database.connections.mysql');
        if (! in_array($this->mysql['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->markTestSkipped('DDL integration tests only run against a loopback server.');
        }
        $this->mysql['url'] = null;
        config(['database.connections.finance_ddl_admin' => [...$this->mysql, 'database' => null]]);
        $this->testDatabase = 'codex_finance_ddl_'.bin2hex(random_bytes(6));
        DB::connection('finance_ddl_admin')->statement('CREATE DATABASE `'.$this->testDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config([
            'database.connections.finance_ddl_test' => [...$this->mysql, 'database' => $this->testDatabase],
            'database.connections.finance_ddl_blocker' => [...$this->mysql, 'database' => $this->testDatabase],
            'database.default' => 'finance_ddl_test', 'admin.database.connection' => 'finance_ddl_test',
        ]);
        $this->db = DB::connection('finance_ddl_test');
        $schema = $this->db->getSchemaBuilder();
        $schema->create('finance_records', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id(); $table->string('dataset', 40); $table->string('account', 191);
            $table->string('reference', 191); $table->string('currency', 10); $table->string('raw_type', 191)->nullable();
            $table->dateTime('occurred_at'); $table->dateTime('updated_at'); $table->json('payload');
        });
        $schema->create('logistics_orders', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id(); $table->boolean('finance_is_non_paypal');
            $table->string('collection_account', 191); $table->string('payment_status', 100);
        });
        $schema->create('finance_payment_transactions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id(); $table->unsignedBigInteger('payment_account_id'); $table->unsignedBigInteger('finance_record_id');
            $table->string('event_code', 40); $table->string('transaction_id', 100);
        });
        $this->db->table('finance_records')->insert([
            ['dataset' => 'xuzong2-details', 'account' => 'A', 'reference' => 'PAY-1', 'currency' => 'USD',
                'occurred_at' => '2026-11-04 00:00:00', 'updated_at' => '2026-11-04 00:00:00',
                'payload' => json_encode(['_card_channel' => 'ye', '交易金额' => '123.45', '_xuzong_statement_import' => ['notice' => 'test']], JSON_UNESCAPED_UNICODE)],
            ['dataset' => 'xuzong-credit-imported-orders', 'account' => 'B', 'reference' => 'PAY-2', 'currency' => 'USD',
                'occurred_at' => '2026-11-04 00:00:00', 'updated_at' => '2026-11-04 00:00:00',
                'payload' => json_encode(['商户订单号' => 'Mixed-Case-123', '_legacy_order_id' => 'LEGACY-1', '交易金额' => '99.99'], JSON_UNESCAPED_UNICODE)],
        ]);
        app('migrator')->getRepository()->createRepository();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->testDatabase !== null) {
                DB::disconnect('finance_ddl_blocker');
                DB::disconnect('finance_ddl_test');
                $this->assertMatchesRegularExpression('/^codex_finance_ddl_[a-f0-9]{12}$/D', $this->testDatabase);
                DB::connection('finance_ddl_admin')->statement('DROP DATABASE `'.$this->testDatabase.'`');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function paths(): array
    {
        return array_map(fn ($name) => database_path('migrations/'.$name.'.php'), ApplyFinanceIndexes::MIGRATIONS);
    }

    private function settings(): array
    {
        return (array) $this->db->selectOne('SELECT @@SESSION.lock_wait_timeout AS metadata_timeout, @@SESSION.innodb_lock_wait_timeout AS row_timeout');
    }

    private function assertLockReleased(): void
    {
        $name = hash('sha256', 'finance:summaries:refresh:'.$this->testDatabase);
        $this->assertNull($this->db->selectOne('SELECT IS_USED_LOCK(?) AS owner', [$name])->owner);
    }

    public function test_real_mysql_applies_all_six_migrations_idempotently_without_changing_source_data(): void
    {
        $before = $this->db->table('finance_records')->orderBy('id')->pluck('payload')->all();
        $settings = $this->settings();
        app('migrator')->run($this->paths());
        $this->assertEqualsCanonicalizing(ApplyFinanceIndexes::MIGRATIONS, app('migrator')->getRepository()->getRan());
        $schema = $this->db->getSchemaBuilder();
        $this->assertTrue($schema->hasIndex('finance_records', 'finance_statement_notice_page_idx'));
        $this->assertTrue($schema->hasIndex('finance_records', 'finance_conflux_order_match_idx'));
        $this->assertTrue($schema->hasIndex('finance_records', 'finance_xuzong_merchant_lookup_idx'));
        $this->assertTrue($schema->hasIndex('finance_records', 'finance_xuzong_legacy_lookup_idx'));
        $this->assertTrue($schema->hasIndex('logistics_orders', 'finance_credit_filter_choices_idx'));
        $this->assertTrue($schema->hasIndex('finance_payment_transactions', 'finance_payment_account_events_idx'));
        $row = $this->db->table('finance_records')->where('reference', 'PAY-1')->first();
        $this->assertSame(1, (int) $row->finance_has_statement_notice);
        $this->assertSame(1, (int) $row->finance_is_conflux_order);
        $this->assertSame('Mixed-Case-123', $this->db->table('finance_records')->where('reference', 'PAY-2')->value('finance_xuzong_merchant_number'));
        app('migrator')->run($this->paths());
        $this->assertCount(6, app('migrator')->getRepository()->getRan());
        $this->assertSame($before, $this->db->table('finance_records')->orderBy('id')->pluck('payload')->all());
        $this->assertSame($settings, $this->settings());
        $this->assertLockReleased();
    }

    public function test_real_open_transaction_defers_without_queuing_ddl_then_retry_applies_only_one_migration(): void
    {
        $blocker = DB::connection('finance_ddl_blocker');
        $blocker->beginTransaction();
        $blocker->table('finance_records')->first();
        usleep(250000);
        $started = microtime(true);
        ob_start();
        try { app('migrator')->run([$this->paths()[0]]); }
        finally { $output = ob_get_clean(); }
        $this->assertLessThan(1, microtime(true) - $started);
        $this->assertStringContainsString('未提交 ALTER TABLE', $output);
        $this->assertSame([], app('migrator')->getRepository()->getRan());
        $this->assertFalse($this->db->getSchemaBuilder()->hasColumn('finance_records', 'finance_has_statement_notice'));
        $this->assertSame(2, $blocker->table('finance_records')->count());
        $this->assertLockReleased();
        $blocker->commit();
        usleep(250000);
        $this->artisan('finance:indexes:apply')->assertSuccessful();
        $this->assertSame([ApplyFinanceIndexes::MIGRATIONS[0]], app('migrator')->getRepository()->getRan());
        $this->assertTrue($this->db->getSchemaBuilder()->hasIndex('finance_records', 'finance_statement_notice_page_idx'));
        $this->assertFalse($this->db->getSchemaBuilder()->hasColumn('finance_records', 'finance_is_conflux_order'));
        $this->assertLockReleased();
    }

    public function test_real_metadata_lock_race_is_skipped_without_failing_deployment_and_can_resume(): void
    {
        $blocker = DB::connection('finance_ddl_blocker');
        $pdo = new class('mysql:host='.$this->mysql['host'].';port='.$this->mysql['port'].';dbname='.$this->testDatabase.';charset=utf8mb4',
            $this->mysql['username'], $this->mysql['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO {
            public $beforeAlter;
            public function exec(string $statement): int|false
            {
                if ($this->beforeAlter && str_starts_with($statement, 'ALTER TABLE')) {
                    $callback = $this->beforeAlter;
                    $this->beforeAlter = null;
                    $callback();
                }
                return parent::exec($statement);
            }
        };
        $this->db->setPdo($pdo);
        $settings = $this->settings();
        $pdo->beforeAlter = function () use ($blocker): void {
            $blocker->beginTransaction();
            $blocker->table('finance_records')->first();
        };
        $started = microtime(true);
        ob_start();
        try {
            app('migrator')->run([$this->paths()[0]]);
            $this->assertLessThan(3, microtime(true) - $started);
        } finally {
            $output = ob_get_clean();
            $blocker->rollBack();
        }
        $this->assertStringContainsString('改表期间出现并发事务', $output);
        $this->assertSame([], app('migrator')->getRepository()->getRan());
        $this->assertFalse($this->db->getSchemaBuilder()->hasColumn('finance_records', 'finance_has_statement_notice'));
        $this->assertSame($settings, $this->settings());
        $this->assertLockReleased();
        usleep(250000);
        app('migrator')->run([$this->paths()[0]]);
        $this->assertTrue($this->db->getSchemaBuilder()->hasIndex('finance_records', 'finance_statement_notice_page_idx'));
        $this->assertSame(2, $this->db->table('finance_records')->count());
    }
}
