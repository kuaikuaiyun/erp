<?php

namespace Tests\Feature;

use App\Http\Middleware\MonitorFinancePerformance;
use App\Portals\Finance\FinanceExternalChanges;
use App\Portals\Finance\FinanceQueryDiagnostics;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FinanceQueryDiagnosticsTest extends TestCase
{
    private string $directory;
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/finance-query-'.bin2hex(random_bytes(6)));
        $this->logPath = $this->directory.'/query.log';
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'admin.database.connection' => null, 'finance.diagnostics.enabled' => true,
            'finance.diagnostics.log_path' => $this->logPath,
            'finance.summaries.external_change_path' => $this->directory.'/notifications']);
        DB::purge('sqlite');
    }

    protected function tearDown(): void
    {
        FinanceQueryDiagnostics::current()?->finish('test.end');
        DB::disconnect('sqlite');
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function request(string $path = '/finance/reports/losses'): Request
    {
        $request = Request::create('http://caiwu.kky.com'.$path);
        $this->app->instance('request', $request);
        return $request;
    }

    private function records(): array
    {
        if (! is_file($this->logPath)) return [];
        return array_map(function ($line) {
            $start = strpos($line, '{');
            return json_decode(substr($line, $start), true, 512, JSON_THROW_ON_ERROR);
        }, array_filter(explode("\n", trim(file_get_contents($this->logPath)))));
    }

    public function test_loss_query_writes_before_work_and_correlates_completed_stages_without_bindings(): void
    {
        $request = $this->request('/finance/reports/losses?month_from=2026-09&account=private-account&token=private-token');
        $response = (new MonitorFinancePerformance)->handle($request, function () {
            $this->assertSame('request.start', $this->records()[0]['event']);
            FinanceQueryDiagnostics::measure('report.test', function () {
                $this->assertSame('stage.start', $this->records()[1]['event']);
                DB::select('select ? as value', ['private-binding']);
            });
            return response('done');
        });
        $records = $this->records();
        $this->assertSame('request.end', end($records)['event']);
        $this->assertSame(1, end($records)['query_count']);
        $this->assertSame([$response->headers->get('X-Finance-Trace')], array_values(array_unique(array_column($records, 'trace_id'))));
        $this->assertSame([getmypid()], array_values(array_unique(array_column($records, 'pid'))));
        $log = file_get_contents($this->logPath);
        foreach (['private-account', 'private-token', 'private-binding'] as $secret) $this->assertStringNotContainsString($secret, $log);
    }

    public function test_notification_diagnostics_preserve_delivery_and_separate_commit_from_file_sync(): void
    {
        $request = $this->request();
        DB::connection()->getSchemaBuilder()->create('finance_summary_changes', function (Blueprint $table): void {
            $table->increments('id'); $table->string('source'); $table->text('payload'); $table->boolean('scopes_resolved');
        });
        FinanceQueryDiagnostics::start($request);
        (function () {
            $notifications = new FinanceExternalChanges;
            $ticket = $notifications->begin(DB::connection());
            $this->assertNotNull($ticket);
            $notifications->commit(DB::connection(), $ticket, [['source' => 'order', 'values' => ['reference' => 'private-order-data']]]);
            $this->assertTrue($notifications->drain(DB::connection()));
            $this->assertSame([], glob($notifications->directory(DB::connection()).'/change-*.json'));
            return response('done');
        })();
        FinanceQueryDiagnostics::current()?->finish('worker.end');
        $this->assertSame(1, DB::table('finance_summary_changes')->count());
        $this->assertSame('private-order-data', json_decode(DB::table('finance_summary_changes')->value('payload'), true)['reference']);
        $records = $this->records();
        foreach (['notification.insert', 'notification.commit', 'notification.fsync', 'notification.unlink'] as $stage) {
            $this->assertNotEmpty(array_filter($records, fn ($record) => $record['stage'] === $stage && $record['event'] === 'stage.start'), $stage);
            $this->assertNotEmpty(array_filter($records, fn ($record) => $record['stage'] === $stage && $record['event'] === 'stage.end'), $stage);
        }
        $summary = array_values(array_filter($records, fn ($record) => $record['event'] === 'notifications.summary'))[0];
        $this->assertSame(1, $summary['delivered']);
        $this->assertSame(0, $summary['cleanup_failed']);
        $this->assertStringNotContainsString('private-order-data', file_get_contents($this->logPath));
    }

    public function test_all_finance_menus_counts_and_exports_leave_notifications_to_the_worker(): void
    {
        DB::connection()->getSchemaBuilder()->create('finance_summary_changes', function (Blueprint $table): void {
            $table->increments('id'); $table->string('source'); $table->text('payload'); $table->boolean('scopes_resolved');
        });
        $notifications = app(FinanceExternalChanges::class);
        $ticket = $notifications->begin(DB::connection());
        $notifications->commit(DB::connection(), $ticket, [['source' => 'scope_user', 'values' => []]]);
        foreach (['/finance/records/paypal-orders', '/finance/reports/xuzong-credit',
            '/finance/reports/ye-settlement?settlement_total=1', '/finance/reports/worldpay-settlement/export',
            '/finance/settings/types'] as $path) {
            $request = $this->request($path);
            (new MonitorFinancePerformance)->handle($request, function () use ($notifications, $ticket) {
                $this->assertFalse($notifications->drain(DB::connection()));
                $this->assertFileExists($ticket['path']);
                $this->assertSame(0, DB::table('finance_summary_changes')->count());
                return response('reader');
            });
        }
        // The same durable notification is imported by the next CLI worker, without losing the source change.
        request()->attributes->remove('finance.report_read_only');
        $this->assertTrue($notifications->drain(DB::connection()));
        $this->assertSame(1, DB::table('finance_summary_changes')->count());
    }

    public function test_notification_totals_survive_the_detailed_log_budget(): void
    {
        $request = $this->request();
        DB::connection()->getSchemaBuilder()->create('finance_summary_changes', function (Blueprint $table): void {
            $table->increments('id'); $table->string('source'); $table->text('payload'); $table->boolean('scopes_resolved');
        });
        $notifications = app(FinanceExternalChanges::class);
        for ($i = 0; $i < 120; $i++) {
            $ticket = $notifications->begin(DB::connection());
            $notifications->commit(DB::connection(), $ticket, [['source' => 'scope_user', 'values' => []]]);
        }
        FinanceQueryDiagnostics::start($request);
        $this->assertTrue($notifications->drain(DB::connection()));
        FinanceQueryDiagnostics::current()?->finish('worker.end');
        $records = $this->records();
        $summary = array_values(array_filter($records, fn ($row) => $row['event'] === 'notifications.summary'));
        $this->assertCount(1, $summary);
        $this->assertSame(120, $summary[0]['processed']);
        $this->assertSame(3, $summary[0]['transactions']);
        $this->assertGreaterThan(0, end($records)['suppressed_events']);
    }

    public function test_exception_is_recorded_and_still_propagates_to_the_application(): void
    {
        try {
            (new MonitorFinancePerformance)->handle($this->request(), fn () => FinanceQueryDiagnostics::measure('report.failure',
                fn () => throw new \RuntimeException('private-exception-details')));
            $this->fail('The application exception must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('private-exception-details', $error->getMessage());
        }
        $records = $this->records();
        $this->assertSame('request.failed', end($records)['event']);
        $this->assertContains('stage.failed', array_column($records, 'event'));
        $this->assertStringNotContainsString('private-exception-details', file_get_contents($this->logPath));
    }

    public function test_trace_is_bounded_and_keeps_the_final_summary_after_the_budget_is_used(): void
    {
        $trace = FinanceQueryDiagnostics::start($this->request());
        for ($i = 0; $i < 300; $i++) $trace->event('test.progress');
        $trace->query("select * from entries where reference = 'private-literal' and id = 12345", 350);
        $trace->finish('request.end');
        $records = $this->records();
        $this->assertCount(201, $records);
        $this->assertGreaterThan(0, end($records)['suppressed_events']);
        $this->assertSame(1, end($records)['query_count']);
        $this->assertStringNotContainsString('private-literal', file_get_contents($this->logPath));
        $this->assertStringNotContainsString('12345', file_get_contents($this->logPath));
    }

    public function test_diagnostic_output_failure_does_not_break_the_query_and_logging_can_be_disabled(): void
    {
        config(['finance.diagnostics.log_path' => sys_get_temp_dir()]); // A directory cannot be opened as the log file.
        $response = (new MonitorFinancePerformance)->handle($this->request(), fn () => response('still works'));
        $this->assertSame('still works', $response->getContent());
        config(['finance.diagnostics.enabled' => false, 'finance.diagnostics.log_path' => $this->logPath]);
        $response = (new MonitorFinancePerformance)->handle($this->request(), fn () => response('disabled'));
        $this->assertFalse($response->headers->has('X-Finance-Trace'));
        $this->assertFileDoesNotExist($this->logPath);
    }
}
