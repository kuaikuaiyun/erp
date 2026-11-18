<?php

namespace Tests\Feature;

use App\Console\Commands\ApplyFinanceIndexes;
use App\Portals\Finance\FinanceIndexMigrationDeferred;
use App\Portals\Finance\FinanceOnlineIndexMigration;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Event;
use Mockery;
use PDO;
use PDOException;
use Tests\TestCase;

class FinanceOnlineIndexMigrationTest extends TestCase
{
    private Connection $db;
    private Builder $schema;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Mockery::mock(PDO::class);
        $this->schema = Mockery::mock(Builder::class);
        $this->schema->shouldReceive('hasColumn', 'hasIndex')->byDefault()->andReturn(false);
        $this->db = Mockery::mock(Connection::class);
        $this->db->shouldReceive('getDriverName')->andReturn('mysql');
        $this->db->shouldReceive('getDatabaseName')->andReturn('finance_test');
        $this->db->shouldReceive('getTablePrefix')->andReturn('');
        $this->db->shouldReceive('transactionLevel')->andReturn(0);
        $this->db->shouldReceive('pretending')->andReturn(false)->byDefault();
        $this->db->shouldReceive('getSchemaBuilder')->andReturn($this->schema);
        $this->db->shouldReceive('getPdo')->andReturn($this->pdo);
        $this->db->shouldReceive('getQueryGrammar')->andReturn(new MySqlGrammar($this->db));
        $workbooks = Mockery::mock(FinanceWorkbookService::class);
        $workbooks->shouldReceive('database')->andReturn($this->db);
        $this->app->instance(FinanceWorkbookService::class, $workbooks);
    }

    private function migration(): FinanceOnlineIndexMigration
    {
        return require database_path('migrations/'.ApplyFinanceIndexes::MIGRATIONS[0].'.php');
    }

    private function lock(bool $available = true): void
    {
        $this->db->shouldReceive('selectOne')->with('SELECT GET_LOCK(?, 0) AS acquired', Mockery::type('array'))
            ->once()->andReturn((object) ['acquired' => (int) $available]);
        if ($available) {
            $this->db->shouldReceive('selectOne')->with('SELECT RELEASE_LOCK(?) AS released', Mockery::type('array'))
                ->once()->andReturn((object) ['released' => 1]);
        }
    }

    private function idle(): void
    {
        $this->db->shouldReceive('selectOne')->with(Mockery::pattern('/INNODB_TRX|COMMAND <>/'), ['finance_test'])->andReturn(null);
    }

    private function settings(): void
    {
        $this->db->shouldReceive('selectOne')->with('SELECT @@SESSION.lock_wait_timeout AS metadata_timeout, @@SESSION.innodb_lock_wait_timeout AS row_timeout')
            ->once()->andReturn((object) ['metadata_timeout' => 300, 'row_timeout' => 50]);
        foreach (['lock_wait_timeout = 1', 'innodb_lock_wait_timeout = 1', 'lock_wait_timeout = 300', 'innodb_lock_wait_timeout = 50'] as $setting) {
            $this->db->shouldReceive('statement')->with('SET SESSION '.$setting)->once()->andReturn(true);
        }
        $this->db->shouldReceive('selectOne')->with(Mockery::pattern('/SELECT ENGINE/'), ['finance_test', 'finance_records'])
            ->andReturn((object) ['engine' => 'InnoDB']);
    }

    public function test_busy_summary_skips_before_issuing_alter(): void
    {
        $this->lock(false);
        $this->pdo->shouldNotReceive('exec');
        ob_start();
        try { $this->assertFalse($this->migration()->shouldRun()); }
        finally { $message = ob_get_clean(); }
        $this->assertStringContainsString('未排队改表', $message);
    }

    public function test_open_transaction_defers_and_releases_summary_lock(): void
    {
        $this->lock();
        $this->db->shouldReceive('selectOne')->with(Mockery::pattern('/INNODB_TRX/'), ['finance_test'])
            ->once()->andReturn((object) ['connection_id' => 42]);
        $this->db->shouldReceive('selectOne')->with(Mockery::pattern('/COMMAND <>/'), ['finance_test'])->once()->andReturn(null);
        $this->pdo->shouldNotReceive('exec');
        ob_start();
        try { $this->assertFalse($this->migration()->shouldRun()); }
        finally { ob_end_clean(); }
    }

    public function test_online_ddl_restores_session_settings_and_is_additive(): void
    {
        $this->lock(); $this->idle(); $this->settings();
        $sql = [];
        $this->pdo->shouldReceive('exec')->twice()->andReturnUsing(function ($statement) use (&$sql): int { $sql[] = $statement; return 0; });
        $this->migration()->up();
        $this->assertStringContainsString('ADD COLUMN `finance_has_statement_notice`', $sql[0]);
        $this->assertStringContainsString('ADD INDEX `finance_statement_notice_page_idx`', $sql[1]);
        foreach ($sql as $statement) {
            $this->assertStringEndsWith('ALGORITHM=INPLACE, LOCK=NONE', $statement);
        }
    }

    public function test_partial_migration_resumes_only_the_missing_index(): void
    {
        $this->schema->shouldReceive('hasColumn')->with('finance_records', 'finance_has_statement_notice')->andReturn(true);
        $this->lock(); $this->idle(); $this->settings();
        $this->pdo->shouldReceive('exec')->once()->with(Mockery::pattern('/ADD INDEX.*LOCK=NONE$/'))->andReturn(0);
        $this->migration()->up();
    }

    public function test_racing_lock_timeout_is_deferred_without_faking_success(): void
    {
        $this->lock(); $this->idle(); $this->settings();
        $exception = new PDOException('lock timeout');
        $exception->errorInfo = ['HY000', 1205, 'lock timeout'];
        $this->pdo->shouldReceive('exec')->once()->andThrow($exception);
        $this->expectException(FinanceIndexMigrationDeferred::class);
        $this->migration()->up();
    }

    public function test_unsupported_online_ddl_never_falls_back_to_copy(): void
    {
        $this->lock(); $this->idle(); $this->settings();
        $exception = new PDOException('LOCK=NONE is not supported');
        $exception->errorInfo = ['0A000', 1846, 'LOCK=NONE is not supported'];
        $this->pdo->shouldReceive('exec')->once()->andThrow($exception);
        $this->expectException(PDOException::class);
        $this->migration()->up();
    }

    public function test_pretend_only_logs_sql_without_acquiring_locks_or_executing_ddl(): void
    {
        $this->db->shouldReceive('pretending')->andReturn(true);
        $this->db->shouldNotReceive('selectOne');
        $this->pdo->shouldNotReceive('exec');
        $this->db->shouldReceive('statement')->twice()->with(Mockery::pattern('/^ALTER TABLE .*LOCK=NONE$/'))->andReturn(true);
        $this->migration()->up();
    }

    public function test_migrator_does_not_record_deferred_indexes_as_applied(): void
    {
        $this->lock(false);
        $migrator = app('migrator');
        $migrator->getRepository()->createRepository();
        ob_start();
        try { $migrator->run([database_path('migrations/'.ApplyFinanceIndexes::MIGRATIONS[0].'.php')]); }
        finally { ob_end_clean(); }
        $this->assertSame([], $migrator->getRepository()->getRan());
    }

    public function test_batch_keeps_lease_until_migration_history_is_recorded(): void
    {
        $historyRecorded = false;
        $this->db->shouldReceive('selectOne')->with('SELECT GET_LOCK(?, 0) AS acquired', Mockery::type('array'))
            ->once()->andReturn((object) ['acquired' => 1]);
        $this->db->shouldReceive('selectOne')->with('SELECT RELEASE_LOCK(?) AS released', Mockery::type('array'))
            ->once()->andReturnUsing(function () use (&$historyRecorded) {
                $this->assertTrue($historyRecorded, 'The lease must cover the migration history write.');
                return (object) ['released' => 1];
            });
        $this->idle(); $this->settings();
        $this->pdo->shouldReceive('exec')->twice()->andReturn(0);
        ob_start();
        try {
            $migration = $this->migration();
            $this->assertTrue($migration->shouldRun());
            $migration->up();
        } finally { ob_end_clean(); }
        $historyRecorded = true;
        Event::dispatch(new MigrationsEnded('up', []));
    }

    public function test_missing_process_visibility_defers_instead_of_running_unchecked_ddl(): void
    {
        $this->lock();
        $this->db->shouldReceive('selectOne')->with(Mockery::pattern('/INNODB_TRX/'), ['finance_test'])
            ->once()->andThrow(new \RuntimeException('PROCESS permission denied'));
        $this->pdo->shouldNotReceive('exec');
        ob_start();
        try { $this->assertFalse($this->migration()->shouldRun()); }
        finally { $message = ob_get_clean(); }
        $this->assertStringContainsString('事务或连接状态', $message);
    }

    public function test_restricted_database_login_defers_for_even_a_sleeping_peer_connection(): void
    {
        $this->lock();
        $exception = new PDOException('PROCESS permission denied');
        $exception->errorInfo = ['42000', 1227, 'PROCESS permission denied'];
        $this->db->shouldReceive('selectOne')->with(Mockery::pattern('/INNODB_TRX/'), ['finance_test'])->once()->andThrow($exception);
        $this->db->shouldReceive('selectOne')->with(Mockery::pattern('/AND \(DB = \? OR DB IS NULL\)/'), ['finance_test'])
            ->once()->andReturn((object) ['connection_id' => 42]);
        $this->db->shouldReceive('selectOne')->with(Mockery::pattern('/COMMAND <>/'), ['finance_test'])->once()->andReturn(null);
        $this->pdo->shouldNotReceive('exec');
        ob_start();
        try { $this->assertFalse($this->migration()->shouldRun()); }
        finally { ob_end_clean(); }
    }

    public function test_already_completed_schema_is_not_altered_again(): void
    {
        $this->schema->shouldReceive('hasColumn', 'hasIndex')->andReturn(true);
        $this->lock(); $this->idle(); $this->settings();
        $this->pdo->shouldNotReceive('exec');
        $this->migration()->up();
    }

    public function test_retry_command_handles_only_one_allowlisted_migration(): void
    {
        $this->lock(false);
        app('migrator')->getRepository()->createRepository();
        $this->artisan('finance:indexes:apply')->expectsOutputToContain('本次未执行')->assertSuccessful();
        $this->assertSame([], app('migrator')->getRepository()->getRan());
    }
}
