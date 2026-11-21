<?php

namespace Tests\Feature;

use App\Portals\Finance\FinanceLiveSourceChanges;
use App\Portals\Finance\FinanceSummarySourceChanges;
use App\Portals\Finance\FinanceSummaryTriggers;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class FinanceSummaryTriggerInstallTest extends TestCase
{
    private function mysqlError(int $code): QueryException
    {
        $error = new \PDOException('trigger installation rejected');
        $error->errorInfo = ['HY000', $code, 'trigger installation rejected'];
        return new QueryException('mysql', 'CREATE TRIGGER test', [], $error);
    }

    public function test_binlog_permission_failure_stops_before_any_existing_trigger_is_dropped(): void
    {
        foreach ([FinanceSummarySourceChanges::class, FinanceLiveSourceChanges::class] as $installer) {
            $db = Mockery::mock(Connection::class);
            $db->shouldReceive('getDriverName')->andReturn('mysql');
            $db->shouldReceive('unprepared')->once()->with(Mockery::on(fn ($sql) => str_starts_with($sql, 'CREATE TRIGGER `finance_install_probe_')))
                ->andThrow($this->mysqlError(1419));
            try {
                $installer::install($db);
                $this->fail('A denied privilege probe must stop installation.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('MySQL 1419', $error->getMessage());
                $this->assertStringContainsString('log_bin_trust_function_creators', $error->getMessage());
            }
        }
    }

    public function test_scoped_migration_checks_privileges_before_creating_or_altering_tables(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('getDriverName')->andReturn('mysql');
        $db->shouldReceive('unprepared')->once()->andThrow($this->mysqlError(1419));
        DB::shouldReceive('connection')->once()->andReturn($db);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MySQL 1419');
        (require database_path('migrations/2026_11_20_000003_scope_finance_source_invalidations.php'))->up();
    }

    public function test_permission_errors_are_no_longer_silently_marked_as_installed(): void
    {
        foreach ([1142, 1227, 1419] as $code) {
            $db = Mockery::mock(Connection::class);
            $db->shouldReceive('unprepared')->once()->andThrow($this->mysqlError($code));
            try {
                FinanceSummaryTriggers::install($db, 'CREATE TRIGGER test');
                $this->fail('Migration must fail when required tracking cannot be installed.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('MySQL '.$code, $error->getMessage());
            }
        }
    }

    public function test_existing_triggers_remain_idempotent_and_other_sql_errors_propagate(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('unprepared')->once()->with('duplicate')->andThrow($this->mysqlError(1359));
        FinanceSummaryTriggers::install($db, 'duplicate');
        $db->shouldReceive('unprepared')->once()->with('invalid')->andThrow($this->mysqlError(1064));
        $this->expectException(QueryException::class);
        FinanceSummaryTriggers::install($db, 'invalid');
    }
}
